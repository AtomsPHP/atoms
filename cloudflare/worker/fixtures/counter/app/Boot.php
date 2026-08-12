<?php

declare(strict_types=1);

namespace App\Atoms;

use App\Jobs\Notify;
use Atoms\Atom;

/**
 * Boot — a fixture Atom whose whole purpose is `onActivation()`.
 *
 * `onActivation()` is customer code on the legal ABI, so it may call
 * `$this->app()` and `$this->dispatch()` like any other method. It runs during
 * the activation gate, before any turn exists, which is a genuinely different
 * moment from every other fixture's calls: the host has to have opened a
 * callback window (a budget and a delivery collector) BEFORE `php.run()`
 * starts, and has to await those deliveries inside the activation event rather
 * than letting them outlive it (mvp-spec.md §The turn deadline,
 * §AtomDurableObject lifecycle).
 *
 * This is a SEPARATE type rather than a hook added to Counter/Vault/Room,
 * for the same reason Room is: checks 3/11/12/16/17 assert exact residency
 * counters and exact listener record counts that a new dispatch on those types
 * would perturb.
 *
 * The dispatch is unconditional and is deliberately NOT wrapped in a try/catch.
 * A runtime that cannot serve `dispatch()` from `onActivation()` must fail this
 * Atom's activation loudly — the defect this fixture exists to pin (a
 * `bad_host_message` refusal that escaped as a bootstrap fatal) bricked the
 * residency permanently, and swallowing it here would hide exactly that.
 * Consequence, stated so it is not mistaken for a bug: with no callback channel
 * configured, Boot does not activate at all. Only conformance check 16 uses it,
 * and that check skips without a listener.
 */
final class Boot extends Atom
{
    /**
     * Any invocable method will do — the check asserts on what `onActivation()`
     * already did by the time this answers. The activation count comes back
     * with it so the check can see the hook actually ran.
     */
    public function ping(): array
    {
        return [
            'pong' => true,
            'activations' => $this->activationCount(),
        ];
    }

    private function activationCount(): int
    {
        $rows = $this->db()->query('SELECT count(*) AS n FROM boot_activations');

        return (int) ($rows[0]['n'] ?? 0);
    }

    /**
     * Read durable state, write durable state, and dispatch a job — in that
     * order, so the job is dispatched from a hook that has already proved the
     * SQL bridge works during activation.
     */
    protected function onActivation(): void
    {
        $seen = $this->activationCount();

        $this->db()->execute(
            'INSERT INTO boot_activations (atom_id, activated_at) VALUES (?, ?)',
            [$this->id, date('Y-m-d H:i:s')]
        );

        $this->dispatch(new Notify($this->id, 'boot:' . $seen));
    }
}
