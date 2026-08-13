<?php

/**
 * A \PDOException whose ->getCode() is the SQLSTATE string (M1 design §3
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
     * @param string $message
     * @param array{0: string, 1: int|null, 2: string|null} $errorInfo
     */
    public function __construct($message, array $errorInfo)
    {
        parent::__construct($message);

        $this->errorInfo = $errorInfo;
        $this->code = $errorInfo[0];
    }
}
