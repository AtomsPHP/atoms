<?php

declare(strict_types=1);

namespace App\Atoms;

use App\Jobs\Notify;
use Atoms\Atom;

/**
 * Counter — a fixture Atom for conformance testing.
 *
 * Demonstrates:
 * - SQL updates and queries
 * - in-memory state across turns within a residency
 * - lifecycle hooks
 * - array serialization
 */
final class Counter extends Atom
{
    /**
     * Calibration for spin() below: iterations between clock reads, and the
     * work budget per requested millisecond. Fixture test data, not
     * operational capacity values — nothing the platform does is tuned by
     * them, and the conformance check they serve passes at any value that
     * keeps the turn well inside the Durable Object's CPU limit.
     */
    private const SPIN_CHUNK = 4096;
    private const SPIN_ITERATIONS_PER_MS = 60000;

    private int $turnsThisResidency = 0;

    /**
     * Increment the counter by the given amount and return the new value.
     */
    public function increment(int $by): int
    {
        $this->turnsThisResidency++;

        $this->db()->execute(
            'UPDATE counter_state SET value = value + ? WHERE id = ?',
            [$by, 1]
        );

        $rows = $this->db()->query('SELECT value FROM counter_state WHERE id = ?', [1]);
        return (int) ($rows[0]['value'] ?? 0);
    }

    /**
     * Slowly increment the counter, so conformance check 11 can prove that two
     * concurrent invokes of one Atom serialize.
     *
     * The delay is deliberately *not* driven by the clock alone. On deployed
     * workerd the wall clock does not advance inside a synchronous run —
     * Cloudflare freezes time between I/O operations — so the obvious
     * `while (hrtime(true) - $start < $target)` spins forever in production:
     * measured 2026-08-04, every `slowIncrement` ran until the Durable Object
     * hit its CPU limit ("Durable Object exceeded its CPU time limit and was
     * reset", cpuTime 30000ms) and the request became a 500, at any $delayMs,
     * while the same loop passed under `wrangler dev`. The loop is therefore
     * bounded by a work budget as well; the clock test is kept so it still
     * exits on time wherever time does advance.
     *
     * Serialization itself does not depend on how long this takes: a turn is
     * one synchronous run of the guest and the Durable Object holds a turn
     * mutex. The budget only has to keep the first turn running while the
     * second request arrives, and stay far below the CPU limit.
     */
    public function slowIncrement(int $by, int $delayMs): int
    {
        $this->turnsThisResidency++;

        $this->spin($delayMs);

        $this->db()->execute(
            'UPDATE counter_state SET value = value + ? WHERE id = ?',
            [$by, 1]
        );

        $rows = $this->db()->query('SELECT value FROM counter_state WHERE id = ?', [1]);
        return (int) ($rows[0]['value'] ?? 0);
    }

    /**
     * Get the current counter value.
     */
    public function getValue(): int
    {
        $this->turnsThisResidency++;

        return $this->readValue();
    }

    /**
     * Get statistics as an array (exercises Serializer arrays).
     *
     * `turnsThisResidency` counts every turn this residency has served,
     * including this one — it is the conformance suite's observable for warm
     * residency (checks 3 and 12), so every invocable method must bump it.
     */
    public function getStats(): array
    {
        $this->turnsThisResidency++;

        return [
            'turnsThisResidency' => $this->turnsThisResidency,
            'currentValue' => $this->readValue(),
            'activations' => $this->readActivations(),
            'timestamp' => time(),
        ];
    }

    /**
     * How many times onActivation() has run against this Atom's durable state.
     * Proves the lifecycle hook fired, and that it fires again after eviction.
     */
    public function getActivations(): int
    {
        $this->turnsThisResidency++;

        return $this->readActivations();
    }

    /**
     * Throw an uncaught exception, so the turn fails the way a real customer
     * bug would (conformance check 8: `atom_exception`, residency survives).
     */
    public function boom(string $message): void
    {
        $this->turnsThisResidency++;

        throw new \RuntimeException($message);
    }

    /**
     * dispatch() a job to notify the monolith, and return normally (conformance
     * check 16: dispatch() delivered, signed, kind=job — and the Atom's own
     * response is unaffected by the delivery running alongside it).
     */
    public function notify(string $note): string
    {
        $this->turnsThisResidency++;

        $this->dispatch(Notify::class, ['atomId' => $this->id, 'note' => $note]);

        return 'notified:' . $note;
    }

    /**
     * Try to read a host-owned table. The JS bridge rejects it before it
     * reaches the Durable Object's SQLite (conformance check 10); the rejection
     * arrives here as a \PDOException, which this method reports rather than
     * rethrows so the check can assert on it.
     *
     * @return array{rejected: bool, message: string}
     */
    public function readReserved(): array
    {
        $this->turnsThisResidency++;

        try {
            $this->db()->query('SELECT value FROM __atoms_meta WHERE key = ?', ['atom_type']);
        } catch (\PDOException $e) {
            return ['rejected' => true, 'message' => $e->getMessage()];
        }

        return ['rejected' => false, 'message' => ''];
    }

    /**
     * The same rejection, reached through SQL that a lexical guard is easy to
     * get wrong on: an apostrophe inside a `--` comment, followed by a second
     * statement (`sql.exec` runs them all). A scanner that pairs quotes without
     * knowing about comments desynchronises from SQLite's tokenizer here and
     * blanks the `UPDATE` out of the text it checks, while SQLite skips the
     * comment and runs it — writing `__atoms_meta.atom_type` and 409-ing every
     * future activation of this Atom.
     *
     * @return array{rejected: bool, message: string}
     */
    public function readReservedViaComment(): array
    {
        $this->turnsThisResidency++;

        try {
            $this->db()->execute(
                "SELECT 1; -- it's fine\nUPDATE __atoms_meta SET value = 'Bogus' WHERE key = 'atom_type'"
            );
        } catch (\PDOException $e) {
            return ['rejected' => true, 'message' => $e->getMessage()];
        }

        return ['rejected' => false, 'message' => ''];
    }

    /**
     * Report what the guest clock does across pure computation and across a
     * host round trip. Kept in the fixture because it is the only way to see
     * the frozen-clock behaviour that `slowIncrement` is written around, and
     * because the answer is a property of the deployed platform rather than of
     * this code — a future runtime change is visible here immediately.
     *
     * @return array{iterations: int, spinNs: int, sqlNs: int, checksum: int}
     */
    public function clockProbe(int $iterations): array
    {
        $this->turnsThisResidency++;

        $t0 = hrtime(true);
        $checksum = 0;
        for ($i = 0; $i < $iterations; $i++) {
            $checksum += $i;
        }
        $t1 = hrtime(true);
        $this->readValue();
        $t2 = hrtime(true);

        return [
            'iterations' => $iterations,
            'spinNs' => $t1 - $t0,
            'sqlNs' => $t2 - $t1,
            'checksum' => $checksum,
        ];
    }

    /**
     * Burn a bounded amount of work, aiming at $delayMs but never trusting the
     * clock to end the loop (see slowIncrement()). The clock is read once per
     * chunk rather than once per iteration so that a frozen clock costs a
     * predictable amount of work and a live clock still ends the spin promptly.
     */
    private function spin(int $delayMs): void
    {
        if ($delayMs <= 0) {
            return;
        }

        $startNs = hrtime(true);
        $targetNs = $delayMs * 1_000_000;
        $budget = $delayMs * self::SPIN_ITERATIONS_PER_MS;

        for ($done = 0; $done < $budget; $done += self::SPIN_CHUNK) {
            $checksum = 0;
            for ($i = 0; $i < self::SPIN_CHUNK; $i++) {
                $checksum += $i;
            }
            if ((hrtime(true) - $startNs) >= $targetNs) {
                return;
            }
        }
    }

    /**
     * Report exactly what `$this->config()` resolves for a set of keys.
     *
     * Conformance check 42 uses it for the deny list: the shared-secret names
     * come back null even where the Worker's `ATOMS_CONFIG_ENV_KEYS` lists
     * them, while an allowlisted control key resolves — which is what makes
     * the null answer meaningful.
     *
     * @param list<string> $keys
     * @return array<string, mixed>
     */
    public function configProbe(array $keys): array
    {
        $seen = [];

        foreach ($keys as $key) {
            $seen[(string) $key] = $this->config((string) $key);
        }

        return $seen;
    }

    /** Read the durable counter without counting a turn. */
    private function readValue(): int
    {
        $rows = $this->db()->query('SELECT value FROM counter_state WHERE id = ?', [1]);

        return (int) ($rows[0]['value'] ?? 0);
    }

    /** Rows written by onActivation(), one per residency. */
    private function readActivations(): int
    {
        $rows = $this->db()->query('SELECT count(*) AS n FROM counter_activations');

        return (int) ($rows[0]['n'] ?? 0);
    }

    /**
     * Lifecycle hook: called on activation.
     */
    protected function onActivation(): void
    {
        $this->db()->execute(
            'INSERT INTO counter_activations (atom_id, activated_at) VALUES (?, ?)',
            [$this->id, date('Y-m-d H:i:s')]
        );
    }
}
