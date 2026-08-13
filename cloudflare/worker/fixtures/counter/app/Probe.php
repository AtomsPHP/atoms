<?php

declare(strict_types=1);

namespace App\Atoms;

use App\Pdo\Comparator;
use App\Pdo\Differential;
use App\Pdo\Cases;
use App\Pdo\Schema;
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
 * (see /docs m1-design.md §7). `surfaceAudit()` is Step 1; `comparatorSanity()`,
 * `differentialGroups()` and `differential()` are Step 2 (the differential
 * harness). `capProbe()` is added by a later step. The class stays
 * extensible: new methods are added here, not by reshaping this one.
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

    /**
     * The differential harness's answer to "the comparator could be your
     * own shim" (M1 design §2.3): builds a fresh native in-guest
     * `new \PDO('sqlite::memory:')` and runs its five structural sanity
     * gates. Construction failure is reported as ok:false with every gate
     * false rather than propagating — "we could not verify our own
     * compatibility claims" must be a legible result, never a 500.
     *
     * @return array{ok: bool, gates: array<string, bool>, detail?: string}
     */
    public function comparatorSanity(): array
    {
        try {
            $comparator = Comparator::build();
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'gates' => ['S1' => false, 'S2' => false, 'S3' => false, 'S4' => false, 'S5' => false],
                'detail' => sprintf('failed to construct the comparator: %s: %s', get_class($e), $e->getMessage()),
            ];
        }

        return Comparator::sanity($comparator);
    }

    /**
     * The ordered group list the differential harness's case matrix is
     * chunked into — one HTTP invoke per group (orchestrator override on M1
     * design §2 report flow: a single 95+-case turn risks the CI turn
     * deadline). Derived from {@see Cases}, never duplicated here, so the
     * group list can never drift from what `differential()` actually runs.
     *
     * @return list<string>
     */
    public function differentialGroups(): array
    {
        return Cases::groups();
    }

    /**
     * Run ONE group of the differential matrix (M1 design §2) against both
     * `Atoms\Cf\AtomsPDO` (this residency's `db()->pdo()`) and a fresh
     * native comparator. The DO-side tables are cleared and reseeded first,
     * so every call — regardless of what a previous group's cases wrote —
     * starts from identical, reproducible state.
     *
     * `$ours` is CACHED on this residency's `BridgeDatabase` (one connection
     * per Atom, like a real PDO handle) and reused across every group's
     * call, unlike `$comparator`, which {@see Comparator::build()} makes
     * brand new every time. A case that mutates CONNECTION-level state
     * (`pdo.setattr.default_fetch_mode` sets `ATTR_DEFAULT_FETCH_MODE` to
     * FETCH_NUM) would otherwise leak into every later group's cases — a
     * dependency on GROUP DECLARATION ORDER that has nothing to do with the
     * PDO surface under test. Renormalizing the connection-level attributes
     * any case in this matrix is allowed to change (`ATTR_ERRMODE` has no
     * other value ours accepts, so resetting it is a no-op defence in depth)
     * keeps every group's starting state identical, the same guarantee
     * `Schema::reset()` already gives the DATA.
     *
     * @return array{
     *     group: string, php: string,
     *     comparator: array{ok: bool, gates: array<string, bool>},
     *     summary: array<string, int>,
     *     cases: list<array<string, mixed>>
     * }
     */
    public function differential(string $group): array
    {
        $ours = $this->db()->pdo();
        Schema::reset($ours);
        $ours->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_BOTH);
        $ours->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $comparator = Comparator::build();

        return Differential::run($group, $ours, $comparator);
    }
}
