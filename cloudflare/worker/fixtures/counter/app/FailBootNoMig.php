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
        throw new \RuntimeException('deliberate no-migration activation failure');
    }

    public function ping(): string
    {
        return 'never';
    }
}
