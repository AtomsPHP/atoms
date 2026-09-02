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
 * than letting them outlive it (runtime-spec.md §The turn deadline,
 * §AtomDurableObject lifecycle).
 *
 * This is a SEPARATE type rather than a hook added to Counter/Vault/Room,
 * for the same reason Room is: checks 3/11/12/16/17 assert exact residency
 * counters and exact listener record counts that a new dispatch on those types
 * would perturb.
 *
 * The app() call and the dispatch() are BOTH unconditional and deliberately
 * NOT wrapped in a try/catch. A runtime that cannot serve `app()`/`dispatch()`
 * from `onActivation()` must fail this Atom's activation loudly — the defect
 * this fixture exists to pin (a `bad_host_message` refusal that escaped as a
 * bootstrap fatal) bricked the residency permanently, and swallowing it here
 * would hide exactly that. The `app()` leg additionally proves the activation
 * budget is a FRESH `ATOMS_TURN_DEADLINE_MS` measured from after wasm boot and
 * migrations, not whatever those left of it: with the tiny deadline check 16
 * runs under (2000ms), an activation budget still charged for boot would leave
 * app() no time and throw `TurnDeadlineExceeded` here, failing activation.
 * Consequence, stated so it is not mistaken for a bug: Boot is a channel-required
 * fixture — with no callback channel configured it does not activate at all.
 * Only conformance check 16 uses it, and that check skips without a listener,
 * so no channel-less run ever reaches this hook.
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
     * Read durable state, call the monolith over the callback channel, write
     * durable state, and dispatch a job — in that order. The `app()` call comes
     * first so the round trip it makes (a full signed callback POST) happens on
     * the activation budget, which check 16 relies on being freshly stamped
     * after boot+migrations; the job is dispatched last, from a hook that has
     * already proved both the callback channel and the SQL bridge work during
     * activation.
     */
    protected function onActivation(): void
    {
        $seen = $this->activationCount();

        // Exercises the activation callback WINDOW's app() path (park op
        // `app.call`), the leg check 16 asserts the monolith saw. The Method
        // name matches the conformance listener's `echoBig` handler.
        $this->app()->echoBig(1);

        $this->db()->execute(
            'INSERT INTO boot_activations (atom_id, activated_at) VALUES (?, ?)',
            [$this->id, date('Y-m-d H:i:s')]
        );

        $this->dispatch(Notify::class, ['atomId' => $this->id, 'note' => 'boot:' . $seen]);
    }
}
