<?php

/**
 * The two doors between the PHP guest and the JS host.
 *
 * There is exactly ONE wasm import (php-wasm's `post_message_to_js`); the host
 * dispatches on the first byte of the message string:
 *
 *   '!'  SYNC  — the JS handler answers synchronously, writing the reply into
 *               guest memory. No Asyncify unwind. Used for sql.exec, config.get
 *               and log.
 *   '~'  PARK  — the JS handler receives the message plus a `reply(str)`
 *               callback and decides *when* and *from which JS stack frame* the
 *               guest resumes. Used for turn.await and the tx.* ops (resuming
 *               inside `ctx.storage.transactionSync(cb)` is the whole reason
 *               this build is Asyncify and not JSPI).
 *
 * Wire shape (mvp-spec.md §PHP↔JS protocol): the request is a JSON object with
 * an `op`; every reply is a JSON object carrying either `ok: true` (plus
 * op-specific fields) or `ok: false, error: {code, message}`.
 *
 * NOTE: no `declare(strict_types=1)` anywhere under runtime/. A declare() must
 * be the very first statement of a file, and these files may be composed by the
 * host; the pre-MVP spike hit hard fatals on exactly this.
 * The verbatim atoms-core files keep their own declare() and are therefore only
 * ever `require`d, never concatenated.
 */

namespace Atoms\Cf;

/** First byte selecting the synchronous door. */
const DOOR_SYNC = '!';

/** First byte selecting the parking (Asyncify-suspending) door. */
const DOOR_PARK = '~';

/** How much of a malformed reply to quote back in an exception message. */
const HOST_ERROR_EXCERPT = 400;

/**
 * Raw door call: encode, cross, decode, and validate only that the reply is a
 * well-formed envelope. `ok: false` is returned to the caller, not thrown.
 *
 * @param string $door one of DOOR_SYNC / DOOR_PARK
 * @param array<string, mixed> $req
 * @return array<string, mixed> the decoded envelope (has an `ok` key)
 * @throws \RuntimeException when the request cannot be encoded or the reply is
 *                           not a JSON object with an `ok` key
 */
function host_call($door, array $req)
{
    $encoded = json_encode($req, JSON_UNESCAPED_SLASHES);
    if ($encoded === false) {
        // Most often a binary/invalid-UTF-8 SQL binding. Corrupting it silently
        // (JSON_INVALID_UTF8_SUBSTITUTE) would be worse than failing.
        throw new \RuntimeException(sprintf(
            'Atoms: could not encode host request for op "%s": %s. '
            . 'Binary values do not cross the MVP bridge; store them base64-encoded.',
            isset($req['op']) ? (string) $req['op'] : '?',
            json_last_error_msg()
        ));
    }

    $reply = \post_message_to_js($door . $encoded);

    $out = json_decode((string) $reply, true);
    if (!is_array($out) || !array_key_exists('ok', $out)) {
        throw new \RuntimeException(sprintf(
            'Atoms: malformed host reply for op "%s" on door "%s": %s',
            isset($req['op']) ? (string) $req['op'] : '?',
            $door,
            substr((string) $reply, 0, HOST_ERROR_EXCERPT)
        ));
    }

    return $out;
}

/**
 * Synchronous host call that throws on `ok: false`.
 *
 * @param array<string, mixed> $req
 * @return array<string, mixed>
 * @throws \RuntimeException
 */
function host_sync(array $req)
{
    return host_checked(host_call(DOOR_SYNC, $req), $req);
}

/**
 * Synchronous host call that hands the raw envelope back, `ok: false` included.
 * Used by the SQL path, which maps `sql_error` onto a \PDOException carrying a
 * real errorInfo() triple rather than a bare \RuntimeException.
 *
 * @param array<string, mixed> $req
 * @return array<string, mixed>
 */
function host_sync_raw(array $req)
{
    return host_call(DOOR_SYNC, $req);
}

/**
 * Parking host call that throws on `ok: false`. The guest stack is suspended
 * (Asyncify) until the host invokes its resume callback.
 *
 * @param array<string, mixed> $req
 * @return array<string, mixed>
 * @throws \RuntimeException
 */
function host_park(array $req)
{
    return host_checked(host_call(DOOR_PARK, $req), $req);
}

/**
 * Parking host call that hands the raw envelope back, `ok: false` included.
 * Used by the callback channel (`app.call`), which maps the reply's
 * `error.code` onto a typed exception (TurnDeadlineExceeded, CallbackFailed,
 * ...) rather than a generic \RuntimeException from host_park().
 *
 * @param array<string, mixed> $req
 * @return array<string, mixed>
 */
function host_park_raw(array $req)
{
    return host_call(DOOR_PARK, $req);
}

/**
 * Turn an `ok: false` envelope into an exception; pass `ok: true` through.
 *
 * @param array<string, mixed> $reply
 * @param array<string, mixed> $req
 * @return array<string, mixed>
 * @throws \RuntimeException
 */
function host_checked(array $reply, array $req)
{
    if ($reply['ok'] === true) {
        return $reply;
    }

    $error = isset($reply['error']) && is_array($reply['error']) ? $reply['error'] : [];

    throw new \RuntimeException(sprintf(
        'Atoms host op "%s" failed [%s]: %s',
        isset($req['op']) ? (string) $req['op'] : '?',
        isset($error['code']) ? (string) $error['code'] : 'unknown',
        isset($error['message']) ? (string) $error['message'] : 'no message'
    ));
}

/**
 * Structured server-side log line. Best effort: logging must never be the reason
 * a turn fails, so transport problems are swallowed here (and only here).
 *
 * @param string $level
 * @param array<string, mixed> $fields
 */
function host_log($level, array $fields)
{
    try {
        host_call(DOOR_SYNC, ['op' => 'log', 'level' => (string) $level, 'fields' => $fields]);
    } catch (\Throwable $ignored) {
        // Deliberately swallowed — see above.
    }
}
