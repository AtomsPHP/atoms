<?php

/**
 * `Atoms\Runtime\AtomContext` for the Cloudflare MVP — everything the customer's
 * Atom is allowed to reach, and the exact boundary of what the MVP implements.
 *
 * `db()` and `config()` are real. `app()`, `dispatch()` and `broadcast()` are
 * out of scope for the MVP and throw {@see AtomsNotSupported} naming the
 * limitation (mvp-spec.md §Scope: "These are explicit stubs, never silent
 * no-ops"). A customer Atom that calls them fails its turn with a clear
 * `atom_exception` envelope rather than appearing to have sent something.
 *
 * No declare(strict_types=1) — see the note in host.php.
 */

namespace Atoms\Cf;

use Atoms\AtomJob;
use Atoms\Database;
use Atoms\Runtime\AtomContext;
use Atoms\Timers\Timers;

final class CfAtomContext implements AtomContext
{
    /** @var BridgeDatabase */
    private $db;

    public function __construct(BridgeDatabase $db)
    {
        $this->db = $db;
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

    public function app(): object
    {
        throw new AtomsNotSupported(
            'Atom::app()',
            'Reverse RPC into the monolith needs a control plane and a callback channel; '
            . 'the Cloudflare MVP Worker is single-tenant and has neither.'
        );
    }

    public function dispatch(AtomJob $job): void
    {
        throw new AtomsNotSupported(
            'Atom::dispatch()',
            sprintf(
                'Job dispatch (%s) needs the monolith queue seam, which the Cloudflare MVP does not implement.',
                get_class($job)
            )
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function broadcast(string $channel, array $payload): void
    {
        throw new AtomsNotSupported(
            'Atom::broadcast()',
            sprintf(
                'WebSockets are out of scope for the Cloudflare MVP, so there is nothing subscribed to "%s".',
                $channel
            )
        );
    }

    // Replaced by the timers wave.
    public function timers(): Timers
    {
        throw new AtomsNotSupported(
            'Atom::timers()',
            'M2 wave 0 adds Atoms\Timers\Timers to the AtomContext ABI so atoms-core '
            . 'vendors cleanly, but a later M2 wave is what actually schedules timers '
            . 'on this Worker runtime.'
        );
    }
}
