<?php

/**
 * A failure in the activation path — before the guest ever parks at
 * `turn.await`, so before there is any turn-result envelope to carry it.
 *
 * These carry one of the spec's error codes (`atom_not_found` or `internal`,
 * runtime-spec.md §Turn-result envelope) so the host can classify the failure from
 * the `log` line that {@see bootstrap.php} emits before rethrowing. The rethrow
 * is deliberate: an activation that did not complete leaves no Atom to serve
 * turns, and the spec's answer to that is a poisoned residency the host
 * discards and re-activates from durable state.
 *
 * No declare(strict_types=1) — see the note in host.php.
 */

namespace Atoms\Cf;

class BootstrapError extends \RuntimeException
{
    /** @var string one of the runtime-spec error codes */
    private $atomsCode;

    /**
     * @param string $atomsCode 'atom_not_found' | 'internal'
     * @param string $message
     * @param \Throwable|null $previous
     */
    public function __construct($atomsCode, $message, ?\Throwable $previous = null)
    {
        $this->atomsCode = (string) $atomsCode;

        parent::__construct($message, 0, $previous);
    }

    /**
     * @return string
     */
    public function atomsCode()
    {
        return $this->atomsCode;
    }
}
