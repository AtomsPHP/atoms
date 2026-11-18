<?php

declare(strict_types=1);

namespace Atoms\Testing;

use Atoms\Database;
use Atoms\Sqlite\SqliteDatabase;
use Atoms\Timers\Timers;

final class FakeTimers implements Timers
{
    private readonly Database $database;

    private int $sequence = 0;

    /** @var list<string> */
    private array $cancelled = [];

    public function __construct(?Database $database = null)
    {
        $this->database = $database ?? SqliteDatabase::open(':memory:');
        $this->database->execute(
            'CREATE TEMP TABLE __atoms_timers (
                name TEXT PRIMARY KEY, seq INTEGER NOT NULL, due TEXT NOT NULL, timezone TEXT NOT NULL
            ) WITHOUT ROWID',
        );
    }

    public function schedule(string $name, \DateTimeImmutable $at): void
    {
        $this->database->execute(
            'INSERT INTO temp.__atoms_timers (name, seq, due, timezone) VALUES (?, ?, ?, ?)
             ON CONFLICT(name) DO UPDATE SET due = excluded.due, timezone = excluded.timezone',
            [$name, ++$this->sequence, $at->format('Y-m-d\TH:i:s.uP'), $at->getTimezone()->getName()],
        );
    }

    public function cancel(string $name): void
    {
        $this->database->execute('DELETE FROM temp.__atoms_timers WHERE name = ?', [$name]);
        $this->cancelled[] = $name;
    }

    public function scheduledAt(string $name): ?\DateTimeImmutable
    {
        $rows = $this->database->query('SELECT due, timezone FROM temp.__atoms_timers WHERE name = ?', [$name]);

        return $rows === [] ? null : $this->deadline($rows[0]);
    }

    /** @return array<string, \DateTimeImmutable> Pending timers by name. */
    public function scheduled(): array
    {
        $scheduled = [];
        foreach ($this->database->query('SELECT name, due, timezone FROM temp.__atoms_timers ORDER BY seq') as $row) {
            $scheduled[$row['name']] = $this->deadline($row);
        }

        return $scheduled;
    }

    /** @return list<string> Every name passed to cancel(), including calls inside rolled-back transactions. */
    public function cancelled(): array
    {
        return $this->cancelled;
    }

    /** @param array<string, mixed> $row */
    private function deadline(array $row): \DateTimeImmutable
    {
        return (new \DateTimeImmutable($row['due']))->setTimezone(new \DateTimeZone($row['timezone']));
    }
}
