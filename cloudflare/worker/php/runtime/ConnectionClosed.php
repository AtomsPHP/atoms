<?php

/**
 * Thrown by {@see CfConnection::send()} when the host reports `ws_conn_gone`
 * — the connection id no longer resolves to a socket, so the message was not
 * delivered (design doc §5). A customer that does not care catches this:
 *
 *     try { $conn->send($msg); } catch (ConnectionClosed $e) {}
 *
 * A plain `\RuntimeException`, not a catalog entry: the catalog
 * (`packages/core/resources/errors.json`) is binding on the PHP packages, and
 * the `Atoms\Cf` prelude has never carried `ATOMS-E###` codes — neither does
 * {@see AtomsNotSupported}.
 *
 * Honesty caveat (design doc §5, `php/README.md` §Documented leaks): the host
 * can only detect "gone" when the id resolves to no socket at all. If the
 * socket is mid-teardown, `ws.send()` can still succeed and the frame is
 * silently dropped by the platform — so the ABSENCE of this exception means
 * *accepted for delivery*, never *delivered*.
 *
 * No declare(strict_types=1) — see the note in host.php.
 */

namespace Atoms\Cf;

class ConnectionClosed extends \RuntimeException
{
    /** @var string */
    public $connId = '';

    /** @param string $connId */
    public function __construct($connId)
    {
        $this->connId = (string) $connId;

        parent::__construct(sprintf(
            'Atoms: connection %s is no longer open; the message was not sent.',
            $this->connId
        ));
    }
}
