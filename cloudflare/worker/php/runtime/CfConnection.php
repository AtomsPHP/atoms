<?php

/**
 * `Atoms\Websocket\Connection` for the Cloudflare MVP.
 *
 * Holds a STRING AND NOTHING ELSE — no host object, no index into a host
 * array, no residency-scoped handle. That is the guest-side half of the
 * attachment rule: even if a `Connection` somehow outlived its residency (it
 * cannot — the interpreter is destroyed on every wake), the worst it could
 * name is a connection that no longer exists, which is a defined, typed
 * failure (`ConnectionClosed`) rather than a wrong delivery.
 *
 * No declare(strict_types=1) — see the note in host.php.
 */

namespace Atoms\Cf;

use Atoms\Websocket\Connection;
use Atoms\Websocket\JsonFrame;

final class CfConnection implements Connection
{
    /** @var string */
    private $connId;

    /** @param string $connId */
    public function __construct($connId)
    {
        $this->connId = $connId;
    }

    public function id(): string
    {
        return $this->connId;
    }

    /**
     * Text if `$payload` is valid UTF-8, binary otherwise — `json_encode()`
     * refuses invalid UTF-8 outright (host.php's
     * `host_call()`), so bytes that are not valid UTF-8 have to cross as
     * base64 or not at all. This is the one rule that makes `send($rawBytes)`
     * always work and `send(json_encode(...))` always arrive as text.
     *
     * `ws_conn_gone` is a typed, catchable failure ({@see ConnectionClosed}),
     * never a silent drop: `send(): void` gives the caller no other way to
     * learn a message was not delivered.
     */
    public function send(string $payload): void
    {
        $req = ['op' => 'ws.send', 'conn' => $this->connId];

        if (preg_match('//u', $payload) === 1) {
            $req['payload'] = $payload;
        } else {
            $req['payload_b64'] = base64_encode($payload);
        }

        $reply = host_sync_raw($req);

        if (!is_array($reply) || $reply['ok'] !== true) {
            $error = isset($reply['error']) && is_array($reply['error']) ? $reply['error'] : [];
            $code = isset($error['code']) ? (string) $error['code'] : '';

            if ($code === 'ws_conn_gone') {
                throw new ConnectionClosed($this->connId);
            }

            throw new \RuntimeException(sprintf(
                'Atoms: ws.send for connection %s failed [%s]: %s',
                $this->connId,
                $code !== '' ? $code : 'unknown',
                isset($error['message']) ? (string) $error['message'] : 'no message'
            ));
        }
    }

    /**
     * Encode through the shared frame encoder, then hand the bytes to
     * {@see self::send()} — so sendJson() inherits send()'s UTF-8 rule, size cap
     * and {@see ConnectionClosed} and cannot drift from it.
     */
    public function sendJson(array $payload): void
    {
        $this->send(JsonFrame::encode($payload));
    }

    /**
     * Idempotent by nature: asking an already-gone connection to close got
     * the outcome the caller wanted, so the host answers `ok:true` either
     * way and this never throws on that account.
     */
    public function close(int $code = 1000, string $reason = ''): void
    {
        host_sync(['op' => 'ws.close', 'conn' => $this->connId, 'code' => $code, 'reason' => $reason]);
    }
}
