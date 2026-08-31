<?php

/**
 * `Atoms\Runtime\AtomContext` for the Cloudflare MVP — everything the customer's
 * Atom is allowed to reach, and the exact boundary of what the MVP implements.
 *
 * `db()`, `config()`, `app()`, `dispatch()`, `broadcast()` and `timers()` are
 * all real.
 *
 * No declare(strict_types=1) — see the note in host.php.
 */

namespace Atoms\Cf;

use Atoms\Database;
use Atoms\Runtime\AtomContext;
use Atoms\Serialization\Serializer;
use Atoms\Timers\Timers;
use Atoms\Websocket\JsonFrame;

final class CfAtomContext implements AtomContext
{
    /** @var BridgeDatabase */
    private $db;

    /** @var SqlBridge */
    private $bridge;

    /** @var array{type: string, id: string} */
    private $identity;

    /** @var Serializer */
    private $serializer;

    /** @var CallbackAppProxy|null lazily built; one per Atom, like db()->pdo() */
    private $appProxy = null;

    /** @var CfTimers|null lazily built; one per Atom, like db()->pdo() and $appProxy */
    private $timers = null;

    /**
     * @param BridgeDatabase $db
     * @param SqlBridge $bridge the same instance $db shares with AtomsPDO, so
     *                          app()'s transaction guard and dispatch()'s
     *                          buffer-on-commit both observe one source of
     *                          truth for "is a transaction open"
     * @param array{type: string, id: string} $identity
     */
    public function __construct(BridgeDatabase $db, SqlBridge $bridge, array $identity)
    {
        $this->db = $db;
        $this->bridge = $bridge;
        $this->identity = $identity;
        $this->serializer = new Serializer();
    }

    /**
     * One database per Atom, shared by every turn of this residency.
     */
    public function db(): Database
    {
        return $this->db;
    }

    /**
     * Resolved by the host from an allowlisted view of the Worker's `env`
     * (mvp-spec.md §Sync ops). Unknown keys are null, not an error — same as the
     * platform runtime.
     */
    public function config(string $key): mixed
    {
        $reply = host_sync(['op' => 'config.get', 'key' => $key]);

        if (!array_key_exists('value', $reply)) {
            return null;
        }

        return int64_decode($reply['value']);
    }

    /**
     * Reverse RPC into the monolith's Methods class, over the signed
     * `app.call` park op. See {@see CallbackAppProxy} for the wire shape, the
     * transaction guard, and the documented result-hydration gap.
     */
    public function app(): object
    {
        if ($this->appProxy === null) {
            $this->appProxy = new CallbackAppProxy($this->identity, $this->bridge, $this->serializer);
        }

        return $this->appProxy;
    }

    /**
     * Normalize the arguments and hand the frame to the bridge. Buffered on
     * commit / dropped on rollback inside a transaction; delivered immediately
     * (fire-and-forget) otherwise.
     *
     * @param string $job
     * @param array<string, mixed> $args
     */
    public function dispatch(string $job, array $args = []): void
    {
        $class = ltrim(trim($job), '\\');

        if ($class === '') {
            throw JobNotEncodable::unencodable($job, 'the job class name is empty');
        }

        $normalized = [];
        foreach ($args as $name => $value) {
            if (!is_string($name)) {
                throw JobNotEncodable::unencodable($class, sprintf(
                    'argument %s is positional; dispatch() takes constructor arguments by name',
                    var_export($name, true)
                ));
            }

            $normalized[$name] = $this->serializer->normalize($value);
        }

        $this->enqueueJob($class, $normalized);
    }

    /**
     * The one place the `{"job":FQCN,"args":{...}}` frame is built and crossed.
     * `$args` is already normalized.
     *
     * @param string $class
     * @param array<string, mixed> $args
     */
    private function enqueueJob($class, array $args): void
    {
        $body = json_encode(
            ['job' => $class, 'args' => $args === [] ? new \stdClass() : $args],
            JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
        );

        if ($body === false) {
            throw JobNotEncodable::unencodable($class, json_last_error_msg());
        }

        $reply = host_sync_raw(['op' => 'dispatch.enqueue', 'body' => $body, 'job' => $class]);

        if (!is_array($reply) || $reply['ok'] !== true) {
            $error = isset($reply['error']) && is_array($reply['error']) ? $reply['error'] : [];
            $atom = $this->identity['type'] . '/' . $this->identity['id'];

            throw CallbackChannel::exceptionFor($error, $atom, $class);
        }
    }

    /**
     * The GUEST builds the entire wire frame — `json_encode()` here, never a
     * structure handed to JS for `JSON.stringify()` — so a wide integer inside
     * `$payload` stays exact all the way to the client (mvp-spec.md's int64 rule).
     * The host never parses or re-encodes `$frame`: string in, same string out,
     * fanned to every socket tagged for `$channel`.
     *
     * `normalize()` is idempotent over its own output, so normalizing `$payload`
     * here and letting {@see JsonFrame::encode()} normalize the assembled envelope
     * again produces byte-identical output.
     *
     * @param array<string, mixed> $payload
     */
    public function broadcast(string $channel, array $payload): void
    {
        try {
            $frame = JsonFrame::encode(
                [
                    'kind' => 'broadcast',
                    'channel' => $channel,
                    'payload' => $this->serializer->normalize($payload),
                ],
                $this->serializer
            );
        } catch (\JsonException $e) {
            // Re-wrapped to \RuntimeException (this method's contract type);
            // json_last_error_msg() is unusable after a throwing encode, so the
            // message comes off the exception.
            throw new \RuntimeException(sprintf(
                'Atoms: could not encode the broadcast payload for channel %s: %s.',
                $channel,
                $e->getMessage()
            ));
        }

        // A sync op ('!' door): broadcasting does not park, so it works
        // identically from an invoke turn, a ws turn, or (later) an
        // alarm/queue turn — it needs no request context.
        // An over-cap fan-out is a refusal (ws_fanout_limit -> \RuntimeException
        // via host_sync()), never a truncated send.
        host_sync(['op' => 'ws.broadcast', 'channel' => $channel, 'frame' => $frame]);
    }

    /**
     * Named one-shot timers backed by the host's `__atoms_timers` table and
     * a single multiplexed Durable Object alarm.
     */
    public function timers(): Timers
    {
        if ($this->timers === null) {
            $this->timers = new CfTimers($this->identity);
        }

        return $this->timers;
    }
}
