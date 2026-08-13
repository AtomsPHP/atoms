<?php

declare(strict_types=1);

namespace App\Atoms;

use App\Pdo\SurfaceAudit;
use Atoms\Atom;

/**
 * Probe — a fixture Atom whose whole purpose is auditing the `db()->pdo()`
 * surface (M1 "make the userland PDO surface honest").
 *
 * This is a SEPARATE type rather than a method added to Vault, for the same
 * reason Room/Boot/Scheduler are: it writes and reads a lot of rows through
 * the audit/differential machinery, and it must not perturb any other
 * fixture's residency counters or table contents.
 *
 * The entry points below are added one milestone step at a time
 * (see /docs m1-design.md §7). Only `surfaceAudit()` exists as of Step 1;
 * `comparatorSanity()`, `differential()` and `capProbe()` are added by later
 * steps. The class stays extensible: new methods are added here, not by
 * reshaping this one.
 */
final class Probe extends Atom
{
    /**
     * The reflection tripwire (M1 §1): asserts every public member of \PDO
     * and \PDOStatement is genuinely declared on our subclasses, that the
     * pinned FETCH_* / ATTR_* / PARAM_* / ... constants match the runtime exactly,
     * and that every pinned FETCH_* value is refused or shaped correctly.
     *
     * Deliberately NOT wrapped in a try/catch: a \Throwable escaping the
     * audit must surface as an atom_exception turn failure (conformance
     * check 26), not a summarized "audit unavailable" result.
     *
     * @return array{
     *     ok: bool,
     *     php: string,
     *     violations: list<array{rule: string, member: string, detail: string}>,
     *     counts: array<string, int>,
     *     members_checked: list<string>,
     *     allowlist: list<array{id: string, asserted: bool}>
     * }
     */
    public function surfaceAudit(): array
    {
        return SurfaceAudit::run($this->db()->pdo());
    }
}
