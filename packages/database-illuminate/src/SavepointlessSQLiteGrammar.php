<?php

declare(strict_types=1);

namespace Atoms\DatabaseIlluminate;

use Illuminate\Database\Query\Grammars\SQLiteGrammar;

/**
 * The stock SQLite query grammar minus savepoints.
 *
 * The Cloudflare runtime refuses SAVEPOINT / RELEASE / ROLLBACK TO outright
 * (workerd owns transactions through storage.transactionSync() and rejects
 * transaction-control SQL — measured, not assumed). Reporting
 * savepoints as unsupported makes Illuminate's ManagesTransactions degrade to
 * counter-only nesting — begin once, run inner callbacks inline, commit once —
 * which is exactly the semantics Atoms\Database::transaction() already
 * guarantees, on every runtime. The trade: an inner transaction() that
 * throws-and-is-caught cannot roll back only its own writes.
 */
final class SavepointlessSQLiteGrammar extends SQLiteGrammar
{
    public function supportsSavepoints()
    {
        return false;
    }
}
