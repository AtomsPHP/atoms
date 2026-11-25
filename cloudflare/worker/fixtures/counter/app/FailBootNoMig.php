<?php

declare(strict_types=1);

namespace App\Atoms;

use Atoms\Atom;

/**
 * FailBootNoMig — fixture with NO migrations whose onActivation() throws
 * before persisting any customer data.
 *
 * After a failed first invoke, SQLite holds only host-created `__atoms_meta`
 * (with diagnostic `constructions` alone) and an empty `__atoms_timers` —
 * identical to legacy constructor-only metadata — so conformance tests can
 * verify under real workerd that `DELETE` discards purely diagnostic
 * scaffolding with `{"destroyed": false}` and leaves storage empty.
 */
final class FailBootNoMig extends Atom
{
    protected function onActivation(): void
    {
        // When addressed with a `sqlitecache-` prefix, create a customer table
        // whose name starts with `sqlite` (no underscore) and insert a row
        // before throwing, proving inspectStorage() matches reserved prefixes
        // literally rather than treating '_' as a SQLite LIKE wildcard.
        if (str_starts_with($this->id, 'sqlitecache-')) {
            $this->db()->execute('CREATE TABLE IF NOT EXISTS sqlitecache (id INTEGER PRIMARY KEY, val TEXT NOT NULL)');
            $this->db()->execute('INSERT INTO sqlitecache (val) VALUES (?)', ['customer-row']);
        }

        throw new \RuntimeException('deliberate no-migration activation failure');
    }

    public function ping(): string
    {
        return 'never';
    }
}
