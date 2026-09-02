<?php

declare(strict_types=1);

namespace App\Atoms;

use Atoms\Atom;
use Atoms\Cf\CfConnection;
use Atoms\Cf\ConnectionClosed;
use Atoms\Websocket\Connection;
use Atoms\Websocket\Message;

/**
 * Room — a fixture Atom for WebSocket conformance testing.
 *
 * A SEPARATE type from Counter/Vault, not new methods added to either:
 * conformance checks 3/11/12 assert exact `turnsThisResidency` values that a
 * new invocable method on Counter would perturb.
 *
 * `onMessage()`'s protocol, driven by the frame:
 *   - any BINARY frame     -> echoed back verbatim, `stats().lastBinary = true`
 *   - "echo:<text>"        -> `send('echo:' . $text)`
 *   - "bcast:<ch>:<text>"  -> `broadcast($ch, ['text' => $text])`
 *   - "bcasttx:<ch>:<t>"   -> the same broadcast, from inside a COMMITTED
 *                             `db()->transaction()` (the documented
 *                             transaction-send hazard)
 *   - "id?"                -> `send('id:' . $conn->id())`
 *   - "poke:<connId>"      -> `send()` on a Connection for that id, catching
 *                             ConnectionClosed and recording the outcome
 *   - "json:<text>"        -> `sendJson()` with a payload carrying a slash and
 *                             an int past 2^53-1, to pin the encoding rules
 *   - a frame starting `{` or `[` -> `sendJson(['echo' => $msg->json()])`,
 *                             catching \JsonException into a `jsonerr` reply
 *                             and `stats().lastJsonError`
 *
 * `stats()` is invocable over plain `/invoke` — how the suite observes the
 * WebSocket side through the route it already trusts.
 */
final class Room extends Atom
{
    /**
     * @var array<string, Connection> in-memory only, exactly like every other
     *      in-memory Atom property: a cache, wiped on every wake.
     */
    private array $live = [];

    /** Reset to 0 by construction on every new residency — proves onConnect did NOT re-run after a wake. */
    private int $connectsThisResidency = 0;

    /** Set by the most recent binary frame this residency has seen. */
    private bool $lastBinary = false;

    /** Outcome of the most recent "poke:<connId>" — 'ok', 'ConnectionClosed', or null before the first poke. */
    private ?string $lastPoke = null;

    /** Class of the most recent structured-frame decode failure, or null before the first one. */
    private ?string $lastJsonError = null;

    public function onConnect(Connection $conn, array $params): void
    {
        $this->connectsThisResidency++;

        $this->db()->execute(
            'INSERT INTO room_events (kind, conn_id, detail) VALUES (?, ?, ?)',
            ['connect', $conn->id(), (string) json_encode($params, JSON_UNESCAPED_SLASHES)]
        );

        $this->live[$conn->id()] = $conn;

        $conn->send((string) json_encode(
            ['kind' => 'welcome', 'conn' => $conn->id(), 'params' => $params],
            JSON_UNESCAPED_SLASHES
        ));
    }

    public function onMessage(Connection $conn, Message $msg): void
    {
        $this->db()->execute(
            'INSERT INTO room_events (kind, conn_id, detail) VALUES (?, ?, ?)',
            ['message', $conn->id(), $msg->isBinary() ? sprintf('(%d binary bytes)', strlen($msg->payload())) : $msg->payload()]
        );

        if ($msg->isBinary()) {
            $this->lastBinary = true;
            $conn->send($msg->payload());
            return;
        }

        $payload = $msg->payload();

        // Structured frames first, so a JSON frame is never mistaken for one of
        // the string verbs below. The catch is NOT optional: run_ws_turn()
        // swallows an uncaught throwable and the peer would see nothing at all,
        // which would make the failure path unobservable from a test.
        if (str_starts_with($payload, '{') || str_starts_with($payload, '[')) {
            try {
                $conn->sendJson(['echo' => $msg->json()]);
            } catch (\JsonException $e) {
                $this->lastJsonError = 'JsonException';
                $conn->send('jsonerr');
            }
            return;
        }

        if (str_starts_with($payload, 'json:')) {
            // A slash and an integer past 2^53-1 in one frame: the first pins
            // JSON_UNESCAPED_SLASHES, the second pins that the guest builds the
            // frame itself rather than letting the host re-encode it.
            $conn->sendJson([
                'kind' => 'json',
                'text' => substr($payload, 5),
                'path' => 'a/b',
                'n' => 9007199254740993,
            ]);
            return;
        }

        if (str_starts_with($payload, 'echo:')) {
            $conn->send('echo:' . substr($payload, 5));
            return;
        }

        if (str_starts_with($payload, 'bcasttx:')) {
            $rest = substr($payload, 8);
            $sep = strpos($rest, ':');
            if ($sep !== false) {
                $channel = substr($rest, 0, $sep);
                $text = substr($rest, $sep + 1);
                // broadcast() from INSIDE a committed transaction. `ws.*` are
                // sync ops and the host's transaction guard only rejects park
                // ops, so this executes immediately rather than at commit —
                // the documented hazard in runtime-spec.md §WebSocket ops inside a
                // transaction, and the measurement its appendix records. The
                // conformance suite asserts the frame is delivered; the hazard
                // (a frame already gone when the transaction rolls back) is
                // exactly why the runtime does not pretend to buffer it.
                $this->db()->transaction(function () use ($channel, $text): void {
                    $this->db()->execute(
                        'INSERT INTO room_events (kind, conn_id, detail) VALUES (?, ?, ?)',
                        ['bcasttx', '', $text]
                    );

                    $this->broadcast($channel, ['text' => $text]);
                });
            }
            return;
        }

        if (str_starts_with($payload, 'bcast:')) {
            $rest = substr($payload, 6);
            $sep = strpos($rest, ':');
            if ($sep !== false) {
                $channel = substr($rest, 0, $sep);
                $text = substr($rest, $sep + 1);
                $this->broadcast($channel, ['text' => $text]);
            }
            return;
        }

        if ($payload === 'id?') {
            $conn->send('id:' . $conn->id());
            return;
        }

        if (str_starts_with($payload, 'poke:')) {
            $targetId = substr($payload, 5);
            // A fresh CfConnection, not a $live lookup: a Connection holds
            // nothing but its id string, so the two resolve
            // identically at send()-time through the host's own connId ->
            // socket index — this is what lets poke reach ANY id the caller
            // names, including one this Atom's $live no longer tracks (e.g.
            // the target already disconnected and forgot itself below).
            $target = new CfConnection($targetId);
            try {
                $target->send('poke');
                $this->lastPoke = 'ok';
            } catch (ConnectionClosed $e) {
                $this->lastPoke = 'ConnectionClosed';
            }
            return;
        }
    }

    public function onDisconnect(Connection $conn): void
    {
        $this->db()->execute(
            'INSERT INTO room_events (kind, conn_id, detail) VALUES (?, ?, ?)',
            ['disconnect', $conn->id(), null]
        );

        unset($this->live[$conn->id()]);
    }

    /**
     * @return array{connects: int, messages: int, disconnects: int, lastPoke: string|null, lastBinary: bool, connectsThisResidency: int, lastJsonError: string|null}
     */
    public function stats(): array
    {
        return [
            'connects' => $this->countEvents('connect'),
            'messages' => $this->countEvents('message'),
            'disconnects' => $this->countEvents('disconnect'),
            'lastPoke' => $this->lastPoke,
            'lastBinary' => $this->lastBinary,
            'connectsThisResidency' => $this->connectsThisResidency,
            'lastJsonError' => $this->lastJsonError,
        ];
    }

    private function countEvents(string $kind): int
    {
        $rows = $this->db()->query('SELECT count(*) AS n FROM room_events WHERE kind = ?', [$kind]);

        return (int) ($rows[0]['n'] ?? 0);
    }
}
