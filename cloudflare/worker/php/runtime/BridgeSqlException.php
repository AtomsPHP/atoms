<?php

/**
 * A \PDOException whose ->getCode() is the SQLSTATE string (design §3
 * F-28).
 *
 * Measured: real PDO's own exceptions answer getCode() with the SQLSTATE
 * (e.g. `'23000'`), not an int — and an ordinary \PDOException subclass CAN
 * set that itself, by assigning `$this->code` in its own constructor before
 * calling the parent's (verified in-guest: \Exception::$code has no
 * declared type, so a subclass assigning a string to it round-trips through
 * getCode() exactly). \PDOException itself does not do this — its own
 * constructor leaves the parent \Exception's int `0` in place — so
 * {@see SqlBridge}'s `failure()` raises this subclass instead, everywhere a
 * `sql.exec` reply comes back `ok: false`.
 *
 * No declare(strict_types=1) — see the note in host.php.
 */

namespace Atoms\Cf;

class BridgeSqlException extends \PDOException
{
    /**
     * The host's error reply for
     * `sql_result_too_large` carries `detail.cap`/`detail.limit` all the
     * way to `SqlBridge::failure()` (spread into `reply.error` by
     * `bridge.js`'s `fail()`) — reading only `code`/`message`/`sqlstate`
     * out of that object before building the exception would silently drop
     * `cap`/`limit` at the LAST step, never reaching PHP even though the
     * wire carried them the whole way.
     * This is an ADDITIVE third constructor argument (default `[]`, so a
     * call site may omit it) that preserves the raw error object
     * so callers like {@see \App\Atoms\Probe::capProbe()} can read
     * `getDetail()['cap']` directly instead of parsing it back out of the
     * exception message.
     *
     * @var array<string, mixed>
     */
    private $detail = [];

    /**
     * @param string $message
     * @param array{0: string, 1: int|null, 2: string|null} $errorInfo
     * @param array<string, mixed> $detail the raw error object the host sent
     *     (code/message plus whatever else it spread in, e.g. cap/limit)
     */
    public function __construct($message, array $errorInfo, array $detail = [])
    {
        parent::__construct($message);

        $this->errorInfo = $errorInfo;
        $this->code = $errorInfo[0];
        $this->detail = $detail;
    }

    /**
     * @return array<string, mixed>
     */
    public function getDetail(): array
    {
        return $this->detail;
    }
}
