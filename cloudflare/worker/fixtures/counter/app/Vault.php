<?php

declare(strict_types=1);

namespace App\Atoms;

use App\Jobs\Notify;
use Atoms\Atom;

/**
 * Vault — a fixture Atom for int64 and transaction testing.
 *
 * Demonstrates:
 * - int64 boundary cases through arguments, SQL, and results
 * - db()->transaction() with rollback
 * - db()->pdo() direct access
 */
final class Vault extends Atom
{
    /**
     * Store a big (potentially int64) value by key.
     */
    public function putBig(string $key, int $value): void
    {
        $this->db()->execute(
            'INSERT OR REPLACE INTO vault_data (key, value) VALUES (?, ?)',
            [$key, $value]
        );
    }

    /**
     * Retrieve a big value by key.
     *
     * The CAST is load-bearing, not cosmetic. MEASURED (wrangler 4.118,
     * 2026-08-04): `ctx.storage.sql` hands an INTEGER wider than 2^53-1 to JS
     * as a double, so the exact value is already gone before the bridge sees
     * it — `SELECT 9223372036854775807` comes back as 9223372036854775808.
     * The host refuses such a value with a typed `int64_precision` error rather
     * than returning a quietly wrong integer, so a wide integer must leave
     * SQLite as TEXT. SQLite renders it exactly; PHP's (int) cast on the
     * decimal string is lossless on this 64-bit build.
     */
    public function getBig(string $key): int
    {
        $rows = $this->db()->query(
            'SELECT CAST(value AS TEXT) AS value FROM vault_data WHERE key = ?',
            [$key]
        );
        return (int) ($rows[0]['value'] ?? 0);
    }

    /**
     * Write inside a transaction and read the same row back from inside that
     * same transaction, returning what the *uncommitted* read saw
     * (conformance check 6: read-your-own-write).
     */
    public function putAndReadInTransaction(string $key, int $value): int
    {
        return $this->db()->transaction(function ($db) use ($key, $value) {
            $db->execute(
                'INSERT OR REPLACE INTO vault_data (key, value) VALUES (?, ?)',
                [$key, $value]
            );

            $rows = $db->query(
                'SELECT CAST(value AS TEXT) AS value FROM vault_data WHERE key = ?',
                [$key]
            );

            return (int) ($rows[0]['value'] ?? 0);
        });
    }

    /**
     * Write inside a transaction, read the write back from inside that same
     * transaction (so it is genuinely observed), then throw — which must make
     * the host discard the write set (conformance check 7).
     *
     * @return array{observed: int, rolledBack: bool}
     */
    public function putReadThenFail(string $key, int $value): array
    {
        $observed = null;

        try {
            $this->db()->transaction(function ($db) use ($key, $value, &$observed) {
                $db->execute(
                    'INSERT OR REPLACE INTO vault_data (key, value) VALUES (?, ?)',
                    [$key, $value]
                );

                $rows = $db->query(
                    'SELECT CAST(value AS TEXT) AS value FROM vault_data WHERE key = ?',
                    [$key]
                );

                $observed = (int) ($rows[0]['value'] ?? 0);

                throw new \RuntimeException('Deliberate failure after an observed write.');
            });
        } catch (\RuntimeException $e) {
            return ['observed' => (int) $observed, 'rolledBack' => true];
        }

        return ['observed' => (int) $observed, 'rolledBack' => false];
    }

    /**
     * Append a ledger row through `db()->pdo()` and return the rowid PDO
     * reports, which crosses the bridge as `last_insert_rowid`
     * (conformance check 9's lastInsertId leg, and a second AtomsPDO exercise).
     */
    public function appendLedger(string $key, int $value): int
    {
        $pdo = $this->db()->pdo();

        $stmt = $pdo->prepare('INSERT INTO vault_ledger (key, value) VALUES (?, ?)');
        $stmt->execute([$key, $value]);

        return (int) $pdo->lastInsertId();
    }

    /**
     * Append a ledger row, then do other work, then ask PDO for the rowid.
     *
     * Regression cover: `appendLedger()` reads `lastInsertId()` with nothing in
     * between, which hid a bug where the host reported `last_insert_rowid: 0`
     * for every statement that wrote no rows — so any read (or an UPDATE that
     * matched nothing) between the INSERT and the read of the id silently reset
     * it to 0. Real PDO/SQLite keeps it until the next successful insert.
     *
     * @return array{immediate: int, afterRead: int}
     */
    public function appendLedgerThenRead(string $key, int $value): array
    {
        $pdo = $this->db()->pdo();

        $stmt = $pdo->prepare('INSERT INTO vault_ledger (key, value) VALUES (?, ?)');
        $stmt->execute([$key, $value]);
        $immediate = (int) $pdo->lastInsertId();

        // A plain read, and a write that matches no row: both report
        // rows_written = 0, and neither may disturb the reported rowid.
        $this->db()->query('SELECT count(*) AS n FROM vault_ledger');
        $this->db()->execute(
            'UPDATE vault_data SET value = value WHERE key = ?',
            ['no-such-key-' . $key]
        );

        return ['immediate' => $immediate, 'afterRead' => (int) $pdo->lastInsertId()];
    }

    /**
     * Open a transaction through the documented `db()->pdo()` surface, write,
     * and return without committing — the shape of an ordinary application bug
     * (a forgotten `commit()`, or an exception caught above this frame).
     *
     * The turn must come back as `atom_exception` with the write discarded, and
     * the Atom must keep serving turns. It used to strand the guest at a park
     * the host refuses while a transaction is open, which destroyed the
     * residency and, because the failure is deterministic, destroyed it again
     * on every retry.
     */
    public function leakTransaction(string $key, int $value): string
    {
        $pdo = $this->db()->pdo();
        $pdo->beginTransaction();

        $this->db()->execute(
            'INSERT OR REPLACE INTO vault_data (key, value) VALUES (?, ?)',
            [$key, $value]
        );

        return 'returned with the transaction still open';
    }

    /**
     * Return a value nested past what `json_encode()` will carry, so the turn
     * boundary itself fails. That must be a typed turn error, not a
     * \RuntimeException thrown out of the parked loop (which would unwind
     * php.run() and poison the residency).
     *
     * @return array<int|string, mixed>
     */
    public function returnDeeplyNested(int $depth): array
    {
        $node = ['leaf' => true];

        for ($i = 0; $i < $depth; $i++) {
            $node = [$node];
        }

        return $node;
    }

    /**
     * Read a ledger row back by rowid, exact for wide integers (see getBig()).
     *
     * @return array{id: int, key: string, value: int}|null
     */
    public function readLedger(int $id): ?array
    {
        $rows = $this->db()->query(
            'SELECT id, key, CAST(value AS TEXT) AS value FROM vault_ledger WHERE id = ?',
            [$id]
        );

        if ($rows === []) {
            return null;
        }

        return [
            'id' => (int) $rows[0]['id'],
            'key' => (string) $rows[0]['key'],
            'value' => (int) $rows[0]['value'],
        ];
    }

    /**
     * Transfer a value between two keys using db()->transaction().
     * Demonstrates rollback by forcing a failure after a write.
     */
    public function transfer(string $fromKey, string $toKey, int $amount, bool $shouldFail = false): bool
    {
        try {
            $this->db()->transaction(function ($db) use ($fromKey, $toKey, $amount, $shouldFail) {
                // Deduct from source
                $db->execute(
                    'UPDATE vault_data SET value = value - ? WHERE key = ?',
                    [$amount, $fromKey]
                );

                // Add to destination
                $db->execute(
                    'UPDATE vault_data SET value = value + ? WHERE key = ?',
                    [$amount, $toKey]
                );

                // Force failure if requested (to test rollback)
                if ($shouldFail) {
                    throw new \RuntimeException('Simulated transfer failure for rollback testing');
                }
            });
            return true;
        } catch (\RuntimeException $e) {
            // Transaction was rolled back
            return false;
        }
    }

    /**
     * Method using db()->pdo() directly.
     * Demonstrates that the PDO shim works for customer code.
     */
    public function queryWithPdo(string $key): array
    {
        $pdo = $this->db()->pdo();
        $stmt = $pdo->prepare('SELECT key, value FROM vault_data WHERE key = ? LIMIT 1');
        $stmt->execute([$key]);
        $result = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        return $result;
    }

    /**
     * Round-trip a value through $this->app() (conformance check 13:
     * int64-exact app() calls). The in-suite listener's `echoBig` handler
     * echoes the argument back textually, so the value that comes back must
     * equal what went in even at the int64 boundary.
     */
    public function echoViaApp(int $value): int
    {
        return $this->app()->echoBig($value);
    }

    /**
     * Call app() from inside an open transaction. Must throw before any
     * request leaves the Worker (conformance check 14): the guest-side guard
     * in CallbackAppProxy fires before the write below is even attempted.
     */
    public function appInsideTransaction(): mixed
    {
        return $this->db()->transaction(function () {
            $this->db()->execute(
                'INSERT OR REPLACE INTO vault_data (key, value) VALUES (?, ?)',
                ['app-inside-tx', 1]
            );

            return $this->app()->echoBig(1);
        });
    }

    /**
     * Call app() against an endpoint the conformance listener never answers,
     * uncaught (conformance check 15a: deadline overrun). With a small
     * ATOMS_TURN_DEADLINE_MS the turn budget exhausts and $this->app() throws;
     * left uncaught, the turn reports turn_deadline_exceeded.
     */
    public function stallViaApp(): mixed
    {
        return $this->app()->stall();
    }

    /**
     * The same stalled call, caught — then a second app() call, also caught
     * (conformance check 15b: the budget latches). A customer who degrades
     * gracefully on a slow monolith must not be punished for it: the turn is
     * an ordinary 200, and the second call fails immediately without another
     * round trip once the budget is exhausted.
     */
    public function stallCaught(): string
    {
        try {
            $this->app()->stall();

            return 'unexpected-success';
        } catch (\RuntimeException $e) {
            // Expected: the turn budget exhausted while awaiting stall().
        }

        try {
            $this->app()->echoBig(1);

            return 'second-call-unexpectedly-succeeded';
        } catch (\RuntimeException $e) {
            return 'stall-caught-budget-latched';
        }
    }

    /**
     * dispatch() a job inside a transaction, then optionally fail (conformance
     * check 17). The job must be exactly as durable as the row next to it:
     * delivered on commit, dropped on rollback.
     */
    public function transferAndNotify(bool $fail): bool
    {
        $this->db()->transaction(function () use ($fail) {
            $this->db()->execute(
                'INSERT OR REPLACE INTO vault_data (key, value) VALUES (?, ?)',
                ['transfer-and-notify', 1]
            );

            $this->dispatch(new Notify($this->id, $fail ? 'transfer-failed' : 'transfer-ok'));

            if ($fail) {
                throw new \RuntimeException('Deliberate transferAndNotify failure for rollback testing.');
            }
        });

        return true;
    }

    /**
     * dispatch() a job outside any transaction, then throw (conformance check
     * 17). The documented asymmetry: a job dispatched outside a transaction is
     * as durable as a non-transactional write, so it is delivered even though
     * the turn that dispatched it goes on to fail.
     */
    public function notifyThenThrow(): void
    {
        $this->dispatch(new Notify($this->id, 'notify-then-throw'));

        throw new \RuntimeException('Deliberate notifyThenThrow failure.');
    }

    /**
     * Lifecycle hook: ensure tables are populated with test data.
     */
    protected function onActivation(): void
    {
        // Initialize with test values if they don't exist
        $this->db()->execute(
            'INSERT OR IGNORE INTO vault_data (key, value) VALUES (?, ?)',
            ['balance_a', 1000000]
        );
        $this->db()->execute(
            'INSERT OR IGNORE INTO vault_data (key, value) VALUES (?, ?)',
            ['balance_b', 2000000]
        );
    }
}
