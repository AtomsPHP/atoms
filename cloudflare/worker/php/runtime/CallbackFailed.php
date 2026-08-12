<?php

/**
 * ATOMS-E083 — the callback reached the host doors but did not succeed:
 * transport failure, per-call timeout, a non-200 status, or a response body
 * that could not be decoded. Everything the host's `app.call` reply can
 * report except a transaction conflict (E082) or a deadline overrun (reused
 * E061) lands here (design doc §1.2, §9.1).
 *
 * No declare(strict_types=1) — see the note in host.php.
 */

namespace Atoms\Cf;

use Atoms\Errors\ErrorCode;

final class CallbackFailed extends CallbackError
{
    /**
     * A host-reported failure code/message (callback_timeout,
     * callback_transport, callback_too_large, callback_body_invalid,
     * dispatch_limit, or anything else the host's door can answer with).
     *
     * @param string $atom "{type}/{id}"
     * @param string $method
     * @param string $hostCode
     * @param string $hostMessage
     */
    public static function forOp($atom, $method, $hostCode, $hostMessage): self
    {
        return new self(ErrorCode::CallbackRequestFailed, [
            'atom' => (string) $atom,
            'method' => (string) $method,
            'reason' => sprintf('%s (%s)', (string) $hostMessage, (string) $hostCode),
        ]);
    }

    /**
     * @param string $atom
     * @param string $method
     * @param int $status
     * @param string $body
     */
    public static function status($atom, $method, $status, $body): self
    {
        return new self(ErrorCode::CallbackRequestFailed, [
            'atom' => (string) $atom,
            'method' => (string) $method,
            'reason' => sprintf('the monolith answered HTTP %d: %s', (int) $status, self::excerpt($body)),
        ]);
    }

    /**
     * @param string $atom
     * @param string $method
     * @param string $reason
     */
    public static function malformedResponse($atom, $method, $reason): self
    {
        return new self(ErrorCode::CallbackRequestFailed, [
            'atom' => (string) $atom,
            'method' => (string) $method,
            'reason' => (string) $reason,
        ]);
    }

    /**
     * The outbound request body itself could not be built (json_encode()
     * failed on the args — e.g. a lone surrogate that survived normalization).
     *
     * @param string $atom
     * @param string $method
     * @param string $reason
     */
    public static function unencodable($atom, $method, $reason): self
    {
        return new self(ErrorCode::CallbackRequestFailed, [
            'atom' => (string) $atom,
            'method' => (string) $method,
            'reason' => sprintf('the request could not be encoded: %s', (string) $reason),
        ]);
    }

    /**
     * @param string $text
     * @param int $limit
     * @return string
     */
    private static function excerpt($text, $limit = 200)
    {
        $ascii = preg_replace('/[^\x20-\x7E]/', '?', (string) $text);
        $ascii = $ascii === null ? '' : $ascii;

        return strlen($ascii) > $limit ? substr($ascii, 0, $limit) . '...' : $ascii;
    }
}
