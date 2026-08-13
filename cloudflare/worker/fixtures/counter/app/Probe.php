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
 * The entry points below were added one milestone step at a time
 * (see /docs m1-design.md §7). `surfaceAudit()` is Step 1 (conformance check
 * 26); `comparatorSanity()`, `differentialGroups()` and `differential()` are
 * Step 2, the differential harness (checks 27-28); `ping()` and `capProbe()`
 * are Step 5, the result-set size guard (check 29). The class stays
 * extensible: new methods are added here, not by reshaping this one.
 */
final class Probe extends Atom
{
    /**
     * A plain liveness probe with no PDO involvement at all — the "residency
     * survived" leg of conformance check 29 (29d), the same pattern checks
     * 7/8/10 use after a deliberate failure.
     */
    public function ping(): string
    {
        return 'pong';
    }

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
     * brand new every time. A case that mutates CONNECTION-level state would
     * otherwise leak into every later group's cases — a dependency on GROUP
     * DECLARATION ORDER that has nothing to do with the PDO surface under
     * test. Renormalizing `ATTR_ERRMODE` (the one attribute ours accepts a
     * genuine alternate value for, and every case in this matrix relies on
     * exceptions being on) keeps every group's starting state identical, the
     * same guarantee `Schema::reset()` already gives the DATA.
     *
     * M1 review F-7 (MAJOR, fixed): this used to ALSO force-set
     * `ATTR_DEFAULT_FETCH_MODE` to `FETCH_BOTH` before every group ran —
     * which made `pdo.attr.default_fetch_mode` (which reads that very
     * attribute) pass by construction regardless of what AtomsPDO's
     * constructor actually defaults to, not because the default is
     * genuinely FETCH_BOTH. That is gone: `ATTR_DEFAULT_FETCH_MODE` is left
     * exactly as the constructor set it, so `pdo.attr.default_fetch_mode`
     * measures the real default. The one case that legitimately CHANGES it
     * (`pdo.setattr.default_fetch_mode`) now restores the prior value itself
     * before returning (design pattern: set → read → restore, identical on
     * both sides), so cross-group leakage is prevented at the case that
     * causes it rather than by a blanket reset that could paper over a
     * regression in the very thing being measured.
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
        $ours->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $comparator = Comparator::build();

        return Differential::run($group, $ours, $comparator);
    }

    /**
     * Result-set size guard exercise (M1 design §4.4, conformance check 29).
     * Builds a result set of exactly `$rows` rows through a recursive CTE —
     * CPU cost only, no writes, so this never perturbs any table or the
     * residency's durable state. `$cap` selects the shape:
     *
     * - `'rows'`  — `$rows` narrow rows, exercising ATOMS_SQL_MAX_ROWS.
     * - `'bytes'` — `$rows` rows padded to `$padBytes` bytes each (a
     *   deterministic `zeroblob()`/`hex()` pattern, never `randomblob()`, so
     *   the byte count is exact and reproducible), exercising
     *   ATOMS_SQL_MAX_RESULT_BYTES independent of the row cap.
     *
     * Returns `{ok, rowCount}` on success. On a caught `\PDOException` (the
     * bridge's `sql_result_too_large` reaches PHP as a
     * {@see \Atoms\Cf\BridgeSqlException}), returns `{ok:false, code,
     * message, sqlstate, cap, limit}`, with `code` parsed out of the
     * message's `SQLSTATE[...] [code] ...` shape (kept as a SECONDARY
     * assertion) and `cap`/`limit` (M1 review F-14, FIXED) read directly off
     * `BridgeSqlException::getDetail()` — the same raw `cap`/`limit` fields
     * `bridge.js` put in the wire reply, which used to be discarded before
     * ever reaching PHP. `code` is the Atoms error code, not the SQLSTATE
     * (see F-28 for `->getCode()`, which IS the SQLSTATE).
     *
     * @return array{ok: true, rowCount: int}|array{ok: false, code: ?string, message: string, sqlstate: ?string, cap: ?string, limit: ?int}
     */
    public function capProbe(string $cap, int $rows, int $padBytes = 0): array
    {
        $pdo = $this->db()->pdo();

        try {
            if ($cap === 'bytes') {
                $stmt = $pdo->prepare(
                    'WITH RECURSIVE seq(n) AS (SELECT 1 UNION ALL SELECT n+1 FROM seq WHERE n < ?) '
                    . "SELECT n, replace(hex(zeroblob(?)), '0', 'x') AS pad FROM seq"
                );
                $stmt->execute([$rows, $padBytes]);
            } else {
                $stmt = $pdo->prepare(
                    'WITH RECURSIVE seq(n) AS (SELECT 1 UNION ALL SELECT n+1 FROM seq WHERE n < ?) SELECT n FROM seq'
                );
                $stmt->execute([$rows]);
            }

            return ['ok' => true, 'rowCount' => count($stmt->fetchAll())];
        } catch (\PDOException $e) {
            $code = null;
            if (preg_match('/^SQLSTATE\[[^\]]*\]\s*\[([^\]]+)\]/', $e->getMessage(), $m)) {
                $code = $m[1];
            }

            $detail = $e instanceof \Atoms\Cf\BridgeSqlException ? $e->getDetail() : [];

            return [
                'ok' => false,
                'code' => $code,
                'message' => $e->getMessage(),
                'sqlstate' => is_array($e->errorInfo) ? ($e->errorInfo[0] ?? null) : null,
                'cap' => isset($detail['cap']) ? (string) $detail['cap'] : null,
                'limit' => isset($detail['limit']) ? (int) $detail['limit'] : null,
            ];
        }
    }

    /**
     * M1 review F-15 (MINOR, fixed): a RUN-mode leg for check 29.
     * `PDO::exec()` drives `sql.exec` in MODE_RUN, and `bridge.js`'s
     * MODE_RUN branch (the `else` of the rows/run split) drains the cursor
     * and discards every row WITHOUT any cap check at all — verified
     * directly against `src/bridge.js` before writing this: the row-cap
     * and byte-cap checks live ONLY inside the `mode === 'rows'` branch. So
     * a statement that would generate far more than `ATOMS_SQL_MAX_ROWS`
     * rows in rows mode must still SUCCEED here, proving by direct
     * exercise (not by reading the source and trusting it) that the caps
     * are a rows-mode-only guard, by design, not a blanket statement-size
     * limit.
     *
     * @return array{ok: true, rowsWritten: int}
     */
    public function capProbeRunMode(int $rows): array
    {
        $pdo = $this->db()->pdo();

        $rowsWritten = $pdo->exec(
            'WITH RECURSIVE seq(n) AS (SELECT 1 UNION ALL SELECT n+1 FROM seq WHERE n < '
            . $rows . ') SELECT n FROM seq'
        );

        return ['ok' => true, 'rowsWritten' => $rowsWritten];
    }
}
