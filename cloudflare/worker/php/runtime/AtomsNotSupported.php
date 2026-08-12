<?php

/**
 * The MVP's one honest failure mode.
 *
 * As of M2 this is raised from exactly one place: the parts of the PDO surface
 * a hand-written subclass over a Durable Object's SQLite cannot serve —
 * `AtomsPDO`/`AtomsStatement`'s attribute, cursor and driver-level members
 * (see `php/README.md` §Documented leaks and limits). That restriction is
 * permanent for this runtime, not a stub awaiting a later milestone: `app()`,
 * `dispatch()`, `broadcast()`, the WebSocket handlers and timers are all
 * implemented (mvp-spec.md §The callback channel, §The WebSocket seam,
 * §Timers). It is never a silent no-op and never a carrier-database answer
 * (mvp-spec.md §Scope, §PHP-side db()).
 *
 * It extends \PDOException so that it satisfies the PDO surface's declared
 * failure type; \PDOException extends \RuntimeException, so a customer Atom
 * catching \RuntimeException around a non-PDO call still behaves sanely.
 *
 * No declare(strict_types=1) — see the note in host.php.
 */

namespace Atoms\Cf;

class AtomsNotSupported extends \PDOException
{
    /** The MVP feature that was asked for, e.g. "PDO::getAttribute". */
    public $feature = '';

    /**
     * @param string $feature the unsupported member or capability
     * @param string $why one sentence naming the MVP limitation
     */
    public function __construct($feature, $why)
    {
        $this->feature = (string) $feature;

        parent::__construct(sprintf(
            '%s is not supported by the Atoms Cloudflare MVP runtime. %s',
            (string) $feature,
            (string) $why
        ));

        // SQLSTATE 0A000 — "feature not supported". PDO consumers that inspect
        // errorInfo() get a real triple rather than an empty one.
        $this->errorInfo = ['0A000', 0, $this->getMessage()];
    }
}
