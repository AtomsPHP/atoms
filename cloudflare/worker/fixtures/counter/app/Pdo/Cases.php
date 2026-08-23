<?php

declare(strict_types=1);

namespace App\Pdo;

use App\Pdo\Fixtures\LateRow;
use App\Pdo\Fixtures\PrivateRow;
use App\Pdo\Fixtures\Promoted;
use App\Pdo\Fixtures\Row;

/**
 * The differential harness's case matrix (M1 design §2.8).
 *
 * Every case is ONE closure taking a single `\PDO $pdo` — the identical
 * closure runs against `Atoms\Cf\AtomsPDO` (via `db()->pdo()`) and a native
 * in-guest `new \PDO('sqlite::memory:')` (the {@see Comparator}). There is
 * no "ours" branch and no "theirs" branch anywhere below: that is the
 * single most important structural property of this file.
 *
 * Every SELECT carries a deterministic ORDER BY (or is scoped to exactly
 * one seeded row) — row order is never normalized away, so an ordering bug
 * is never hidden by a shape-only comparison. Seed data (see {@see Schema}):
 * `probe_rows` has three rows, k='a'/'b'/'c' (== id order):
 *   a: i=1  r=1.5  s='hello' n=NULL
 *   b: i=2  r=2.0  s='world' n='nb'
 *   c: i=3  r=3.25 s=''      n='nc'
 * `probe_wide` starts empty; wide-int cases write their own rows.
 *
 * A case's outcome (a returned value, or an exception that propagates out
 * of the closure) is what {@see Differential} captures and classifies —
 * neither is privileged: whichever the same closure produces on each side
 * is what gets compared. A case that wants to compare something OTHER than
 * "did it throw" (an exception's ->getCode(), a captured output buffer, a
 * connection's errorCode() after a caught statement failure) catches
 * internally and returns the captured thing as an ordinary value; that
 * conversion is a property of the CASE, never of the harness. A case that
 * opens an output buffer or a transaction and might throw before closing it
 * cleans up in a `finally`, so one case's failure never corrupts the next
 * case's starting state.
 */
final class Cases
{
    private function __construct()
    {
    }

    /**
     * @return list<string> group names, in declaration order — the single
     *     source of truth Probe::differentialGroups() returns, so the group
     *     list can never drift from what all() actually contains.
     */
    public static function groups(): array
    {
        $seen = [];
        $out = [];

        foreach (self::all() as $case) {
            if (!isset($seen[$case['group']])) {
                $seen[$case['group']] = true;
                $out[] = $case['group'];
            }
        }

        return $out;
    }

    /**
     * @return list<array{
     *     id: string, group: string, member: string, title: string,
     *     sqlstate_strict: bool, informational: bool, run: \Closure
     * }>
     */
    public static function all(): array
    {
        return array_merge(
            self::connectionStatements(),
            self::connectionAttributes(),
            self::connectionQuoting(),
            self::transactions(),
            self::idsAndCounts(),
            self::binding(),
            self::fetchModes(),
            self::valuesAndRoundTrips(),
            self::errors(),
            self::duplicateColumns(),
            self::statementMisc(),
            // M1 review round 2, R5: the one group whose case CLOSURES are
            // defined in a file WITHOUT declare(strict_types=1) — see
            // CasesWeak's own docblock for why that is load-bearing rather
            // than incidental.
            CasesWeak::all()
        );
    }

    /**
     * @param bool $sqlstateStrict when both sides throw, also require
     *     matching SQLSTATEs (design §2.5) — only for engine-produced
     *     errors (constraint violation, syntax error, FETCH_KEY_PAIR
     *     arity).
     * @param bool $informational never compared; both sides' outcomes are
     *     recorded but the case is always classified 'informational'
     *     (design §2.5 — PDOStatement::rowCount() after a SELECT).
     */
    private static function c(
        string $id,
        string $group,
        string $member,
        string $title,
        \Closure $run,
        bool $sqlstateStrict = false,
        bool $informational = false
    ): array {
        return [
            'id' => $id,
            'group' => $group,
            'member' => $member,
            'title' => $title,
            'sqlstate_strict' => $sqlstateStrict,
            'informational' => $informational,
            'run' => $run,
        ];
    }

    // ---------------------------------------------------------- statements

    private static function connectionStatements(): array
    {
        $g = 'Connection — statements';

        return [
            self::c(
                'pdo.exec.update_matching',
                $g,
                'PDO::exec()',
                'UPDATE matching exactly one row',
                static fn (\PDO $p) => $p->exec("UPDATE probe_rows SET s = 'x1' WHERE k = 'a'")
            ),
            self::c(
                'pdo.exec.update_no_match',
                $g,
                'PDO::exec()',
                'UPDATE matching no row',
                static fn (\PDO $p) => $p->exec("UPDATE probe_rows SET s = 'x2' WHERE k = 'no-such-key'")
            ),
            self::c(
                'pdo.exec.ddl',
                $g,
                'PDO::exec()',
                'exec() return value for a CREATE TABLE, self-contained with a known preceding INSERT',
                static function (\PDO $p) {
                    // M1 review F-12 (MINOR, fixed): the old closure used
                    // `CREATE TABLE IF NOT EXISTS`, so its return value
                    // depended on whether probe_tmp_ddl already existed from
                    // a PRIOR run against this same connection — and, more
                    // subtly, on whatever DML happened to run immediately
                    // before this case elsewhere in its group, since
                    // exec()'s return for a non-DML statement is sqlite's
                    // own STALE sqlite3_changes(). Self-contained now: a
                    // dedicated marker table is dropped/recreated/inserted
                    // into immediately beforehand (an INSERT affecting
                    // EXACTLY one row, deterministic regardless of run
                    // history), then the table under test is dropped (so
                    // the CREATE always actually creates, never a
                    // pre-existing no-op) and (re)created with a FIXED name
                    // — identical text on both sides, since it is the same
                    // closure. Both sides' stale-changes() semantics are
                    // compared DELIBERATELY, not accidentally.
                    $p->exec('DROP TABLE IF EXISTS probe_tmp_ddl_marker');
                    $p->exec('CREATE TABLE probe_tmp_ddl_marker (x INTEGER)');
                    $p->exec('INSERT INTO probe_tmp_ddl_marker (x) VALUES (1)');
                    $p->exec('DROP TABLE IF EXISTS probe_tmp_ddl');

                    return $p->exec('CREATE TABLE probe_tmp_ddl (x INTEGER)');
                }
            ),
            self::c(
                'pdo.prepare.positional',
                $g,
                'PDO::prepare()',
                'positional placeholder round-trip',
                static function (\PDO $p) {
                    $stmt = $p->prepare('SELECT k FROM probe_rows WHERE i = ? ORDER BY k');
                    $stmt->execute([2]);

                    return $stmt->fetchAll(\PDO::FETCH_COLUMN);
                }
            ),
            self::c(
                'pdo.prepare.named_colon',
                $g,
                'PDO::prepare()',
                'named placeholder, key given with leading colon',
                static function (\PDO $p) {
                    $stmt = $p->prepare('SELECT k FROM probe_rows WHERE i = :i ORDER BY k');
                    $stmt->execute([':i' => 2]);

                    return $stmt->fetchAll(\PDO::FETCH_COLUMN);
                }
            ),
            self::c(
                'pdo.prepare.named_bare',
                $g,
                'PDO::prepare()',
                'named placeholder, key given without the colon',
                static function (\PDO $p) {
                    $stmt = $p->prepare('SELECT k FROM probe_rows WHERE i = :i ORDER BY k');
                    $stmt->execute(['i' => 3]);

                    return $stmt->fetchAll(\PDO::FETCH_COLUMN);
                }
            ),
            self::c(
                'pdo.prepare.driver_options.empty',
                $g,
                'PDO::prepare()',
                'an empty driver-options array is always accepted',
                static function (\PDO $p) {
                    $stmt = $p->prepare('SELECT 1', []);
                    $stmt->execute();

                    return $stmt->fetchColumn();
                }
            ),
            self::c(
                'pdo.prepare.driver_options.timeout',
                $g,
                'PDO::prepare()',
                'ATTR_TIMEOUT as a driver option',
                static function (\PDO $p) {
                    $stmt = $p->prepare('SELECT 1', [\PDO::ATTR_TIMEOUT => 5]);
                    if ($stmt === false) {
                        return 'false';
                    }
                    $stmt->execute();

                    return $stmt->fetchColumn();
                }
            ),
            self::c(
                'pdo.prepare.driver_options.cursor_scroll',
                $g,
                'PDO::prepare()',
                'ATTR_CURSOR => CURSOR_SCROLL as a driver option',
                static function (\PDO $p) {
                    $stmt = $p->prepare('SELECT 1', [\PDO::ATTR_CURSOR => \PDO::CURSOR_SCROLL]);

                    return $stmt === false ? 'false' : 'statement';
                }
            ),
            self::c(
                'pdo.prepare.driver_options.statement_class',
                $g,
                'PDO::prepare()',
                'ATTR_STATEMENT_CLASS as a driver option',
                static function (\PDO $p) {
                    $stmt = $p->prepare('SELECT 1', [\PDO::ATTR_STATEMENT_CLASS => ['PDOStatement']]);

                    return $stmt === false ? 'false' : 'statement';
                }
            ),
            self::c(
                'pdo.query.no_mode',
                $g,
                'PDO::query()',
                'no fetch mode argument uses the connection default',
                static fn (\PDO $p) => $p->query('SELECT k, i FROM probe_rows ORDER BY k')->fetchAll()
            ),
            self::c(
                'pdo.query.assoc',
                $g,
                'PDO::query()',
                'explicit FETCH_ASSOC argument',
                static fn (\PDO $p) => $p->query('SELECT k, i FROM probe_rows ORDER BY k', \PDO::FETCH_ASSOC)->fetchAll()
            ),
            self::c(
                'pdo.query.column_index',
                $g,
                'PDO::query()',
                'FETCH_COLUMN with a column-index argument',
                static fn (\PDO $p) => $p->query('SELECT k, i FROM probe_rows ORDER BY k', \PDO::FETCH_COLUMN, 1)->fetchAll()
            ),
            self::c(
                'pdo.query.class_args',
                $g,
                'PDO::query()',
                'FETCH_CLASS with a class name and constructor args',
                static function (\PDO $p) {
                    $stmt = $p->query(
                        'SELECT k, i, r, s, n FROM probe_rows ORDER BY k',
                        \PDO::FETCH_CLASS,
                        Row::class,
                        ['query-tag']
                    );

                    return $stmt->fetchAll();
                }
            ),
            self::c(
                'pdo.query.insert_returns_statement',
                $g,
                'PDO::query()',
                'query() on an INSERT still returns a statement, rowCount=1 columnCount=0',
                static function (\PDO $p) {
                    $stmt = $p->query(
                        "INSERT INTO probe_rows (k, i, r, s, n) VALUES ('zzz-query-insert', 99, 9.9, 'ins', NULL)"
                    );

                    return [$stmt->rowCount(), $stmt->columnCount(), $stmt->fetchAll()];
                }
            ),
        ];
    }

    // ----------------------------------------------- identity / attributes

    private static function connectionAttributes(): array
    {
        $g = 'Connection — identity/attributes';

        return [
            self::c(
                'pdo.getAvailableDrivers',
                $g,
                'PDO::getAvailableDrivers()',
                'the declared static shadow answers identically to the parent',
                static fn (\PDO $p) => $p::getAvailableDrivers()
            ),
            self::c(
                'pdo.attr.driver_name',
                $g,
                'PDO::getAttribute()',
                'ATTR_DRIVER_NAME',
                static fn (\PDO $p) => $p->getAttribute(\PDO::ATTR_DRIVER_NAME)
            ),
            self::c(
                'pdo.attr.errmode',
                $g,
                'PDO::getAttribute()',
                'ATTR_ERRMODE',
                static fn (\PDO $p) => $p->getAttribute(\PDO::ATTR_ERRMODE)
            ),
            self::c(
                'pdo.attr.default_fetch_mode',
                $g,
                'PDO::getAttribute()',
                'ATTR_DEFAULT_FETCH_MODE — the ASSOC-vs-BOTH default',
                static fn (\PDO $p) => $p->getAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE)
            ),
            self::c(
                'pdo.attr.persistent',
                $g,
                'PDO::getAttribute()',
                'ATTR_PERSISTENT',
                static fn (\PDO $p) => $p->getAttribute(\PDO::ATTR_PERSISTENT)
            ),
            self::c(
                'pdo.attr.case',
                $g,
                'PDO::getAttribute()',
                'ATTR_CASE',
                static fn (\PDO $p) => $p->getAttribute(\PDO::ATTR_CASE)
            ),
            self::c(
                'pdo.attr.oracle_nulls',
                $g,
                'PDO::getAttribute()',
                'ATTR_ORACLE_NULLS',
                static fn (\PDO $p) => $p->getAttribute(\PDO::ATTR_ORACLE_NULLS)
            ),
            self::c(
                'pdo.attr.server_version',
                $g,
                'PDO::getAttribute()',
                'ATTR_SERVER_VERSION',
                static fn (\PDO $p) => $p->getAttribute(\PDO::ATTR_SERVER_VERSION)
            ),
            self::c(
                'pdo.attr.client_version',
                $g,
                'PDO::getAttribute()',
                'ATTR_CLIENT_VERSION',
                static fn (\PDO $p) => $p->getAttribute(\PDO::ATTR_CLIENT_VERSION)
            ),
            self::c(
                'pdo.attr.timeout',
                $g,
                'PDO::getAttribute()',
                'ATTR_TIMEOUT',
                static fn (\PDO $p) => $p->getAttribute(\PDO::ATTR_TIMEOUT)
            ),
            self::c(
                'pdo.attr.autocommit',
                $g,
                'PDO::getAttribute()',
                'ATTR_AUTOCOMMIT',
                static fn (\PDO $p) => $p->getAttribute(\PDO::ATTR_AUTOCOMMIT)
            ),
            self::c(
                'pdo.attr.emulate_prepares',
                $g,
                'PDO::getAttribute()',
                'ATTR_EMULATE_PREPARES',
                static fn (\PDO $p) => $p->getAttribute(\PDO::ATTR_EMULATE_PREPARES)
            ),
            self::c(
                'pdo.setattr.errmode_exception',
                $g,
                'PDO::setAttribute()',
                'ATTR_ERRMODE => ERRMODE_EXCEPTION is always accepted',
                static fn (\PDO $p) => $p->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION)
            ),
            self::c(
                'pdo.setattr.errmode_silent',
                $g,
                'PDO::setAttribute()',
                'ATTR_ERRMODE => ERRMODE_SILENT — we cannot honour a non-exception errmode',
                static fn (\PDO $p) => $p->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_SILENT)
            ),
            self::c(
                'pdo.setattr.default_fetch_mode',
                $g,
                'PDO::setAttribute()',
                'ATTR_DEFAULT_FETCH_MODE round-trip through getAttribute(), then restored',
                static function (\PDO $p) {
                    // M1 review F-7: this connection is shared across every
                    // group's cases (Probe::differential()), so a case that
                    // changes connection-level state must restore it itself
                    // — set -> read -> restore, identical on both sides —
                    // rather than relying on a blanket reset elsewhere that
                    // would also hide a regression in the DEFAULT this same
                    // attribute reports (see pdo.attr.default_fetch_mode).
                    //
                    // M1 review round 2, R6: the restore is now in a
                    // `finally` — before this, an exception between the set
                    // and the restore (there is none TODAY, but a future
                    // regression could add one) would leave FETCH_NUM
                    // permanently in place for every later group sharing
                    // this connection, corrupting cases far from this one.
                    $prior = $p->getAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE);
                    try {
                        $set = $p->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_NUM);
                        $after = $p->getAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE);

                        return [$set, $after];
                    } finally {
                        $p->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, $prior);
                    }
                }
            ),
            self::c(
                'pdo.setattr.case_upper',
                $g,
                'PDO::setAttribute()',
                'ATTR_CASE => CASE_UPPER',
                static fn (\PDO $p) => $p->setAttribute(\PDO::ATTR_CASE, \PDO::CASE_UPPER)
            ),
            self::c(
                'pdo.setattr.stringify_fetches',
                $g,
                'PDO::setAttribute()',
                'ATTR_STRINGIFY_FETCHES => true',
                static fn (\PDO $p) => $p->setAttribute(\PDO::ATTR_STRINGIFY_FETCHES, true)
            ),
        ];
    }

    // ------------------------------------------------------------ quoting

    private static function connectionQuoting(): array
    {
        $g = 'Connection — quoting';

        return [
            self::c('pdo.quote.plain', $g, 'PDO::quote()', 'a plain string', static fn (\PDO $p) => $p->quote('hello')),
            self::c(
                'pdo.quote.apostrophe',
                $g,
                'PDO::quote()',
                'a string containing an apostrophe is doubled, not backslash-escaped',
                static fn (\PDO $p) => $p->quote("O'Reilly")
            ),
            self::c('pdo.quote.empty', $g, 'PDO::quote()', 'the empty string', static fn (\PDO $p) => $p->quote('')),
            self::c(
                'pdo.quote.param_int',
                $g,
                'PDO::quote()',
                'quote() with PARAM_INT',
                static fn (\PDO $p) => $p->quote('42', \PDO::PARAM_INT)
            ),
            self::c(
                'pdo.quote.param_bool',
                $g,
                'PDO::quote()',
                'quote() with PARAM_BOOL',
                static fn (\PDO $p) => $p->quote(true, \PDO::PARAM_BOOL)
            ),
            self::c(
                'pdo.quote.param_null',
                $g,
                'PDO::quote()',
                'quote() with PARAM_NULL',
                static fn (\PDO $p) => $p->quote(null, \PDO::PARAM_NULL)
            ),
            self::c(
                'pdo.quote.param_lob',
                $g,
                'PDO::quote()',
                'quote() with PARAM_LOB',
                static fn (\PDO $p) => $p->quote('x', \PDO::PARAM_LOB)
            ),
            self::c(
                'pdo.quote.unknown_type',
                $g,
                'PDO::quote()',
                'quote() with an unrecognized $type',
                static fn (\PDO $p) => $p->quote('x', 999)
            ),
            self::c(
                'pdo.quote.nul_byte',
                $g,
                'PDO::quote()',
                'quote() on a value containing a NUL byte',
                static fn (\PDO $p) => $p->quote("a\0b")
            ),
            self::c(
                'pdo.quote.utf8',
                $g,
                'PDO::quote()',
                'multi-byte UTF-8 content survives byte for byte',
                static fn (\PDO $p) => $p->quote('héllo €')
            ),
        ];
    }

    // -------------------------------------------------------- transactions

    private static function transactions(): array
    {
        $g = 'Transactions';

        return [
            self::c(
                'tx.begin_commit',
                $g,
                'PDO::beginTransaction() / PDO::commit()',
                'a clean begin+commit',
                static function (\PDO $p) {
                    $p->beginTransaction();
                    $p->exec("UPDATE probe_rows SET s = 'tx-commit' WHERE k = 'a'");
                    $ok = $p->commit();

                    return [$ok, $p->inTransaction()];
                }
            ),
            self::c(
                'tx.begin_rollback',
                $g,
                'PDO::beginTransaction() / PDO::rollBack()',
                'a clean begin+rollback',
                static function (\PDO $p) {
                    $p->beginTransaction();
                    $p->exec("UPDATE probe_rows SET s = 'tx-rollback' WHERE k = 'a'");
                    $ok = $p->rollBack();

                    return [$ok, $p->inTransaction()];
                }
            ),
            self::c(
                'tx.in_transaction_flags',
                $g,
                'PDO::inTransaction()',
                'flag sequence: false, true, false',
                static function (\PDO $p) {
                    $flags = [$p->inTransaction()];
                    $p->beginTransaction();
                    $flags[] = $p->inTransaction();
                    $p->rollBack();
                    $flags[] = $p->inTransaction();

                    return $flags;
                }
            ),
            self::c(
                'tx.nested_begin',
                $g,
                'PDO::beginTransaction()',
                'a second beginTransaction() while one is already open',
                static function (\PDO $p) {
                    $p->beginTransaction();
                    try {
                        $p->beginTransaction();

                        return 'unexpectedly-nested';
                    } finally {
                        // Always leave the connection clean for the next case
                        // in this group, regardless of the assertion above.
                        $p->rollBack();
                    }
                }
            ),
            self::c(
                'tx.commit_without_begin',
                $g,
                'PDO::commit()',
                'commit() with no open transaction',
                static fn (\PDO $p) => $p->commit(),
                true
            ),
            self::c(
                'tx.rollback_without_begin',
                $g,
                'PDO::rollBack()',
                'rollBack() with no open transaction',
                static fn (\PDO $p) => $p->rollBack(),
                true
            ),
            self::c(
                'tx.read_your_own_write',
                $g,
                'PDO::beginTransaction()',
                'a write is visible to a read inside the same open transaction',
                static function (\PDO $p) {
                    $p->beginTransaction();
                    $p->exec("UPDATE probe_rows SET s = 'tx-read' WHERE k = 'b'");
                    $stmt = $p->query("SELECT s FROM probe_rows WHERE k = 'b'");
                    $val = $stmt->fetchColumn();
                    $p->commit();

                    return $val;
                }
            ),
        ];
    }

    // ----------------------------------------------------- ids and counts

    private static function idsAndCounts(): array
    {
        $g = 'Ids and counts';

        return [
            self::c(
                'id.last_insert_id.string_type',
                $g,
                'PDO::lastInsertId()',
                'the PDO contract is a string, never an int — TYPE and CONTENT both compared',
                static function (\PDO $p) {
                    $p->exec("INSERT INTO probe_rows (k, i, r, s, n) VALUES ('idtype', 100, 1.0, 'x', NULL)");
                    $id = $p->lastInsertId();

                    // M1 review F-20 (NIT, fixed): this used to return only
                    // [gettype($id), is_numeric($id)] — a SHAPE-only
                    // comparison that would pass even if the actual id value
                    // were wrong on one side. Returning $id itself makes the
                    // comparison include content, per design §2.5's "nothing
                    // is compared shape-only" invariant.
                    return [gettype($id), $id];
                }
            ),
            self::c(
                'id.last_insert_id.survives_reads',
                $g,
                'PDO::lastInsertId()',
                'lastInsertId() across an intervening SELECT and a no-match UPDATE',
                static function (\PDO $p) {
                    $p->exec("INSERT INTO probe_rows (k, i, r, s, n) VALUES ('idsurv', 101, 1.0, 'x', NULL)");
                    $immediate = $p->lastInsertId();
                    $p->query('SELECT count(*) AS n FROM probe_rows');
                    $p->exec("UPDATE probe_rows SET s = s WHERE k = 'no-such-key'");
                    $after = $p->lastInsertId();

                    return [$immediate, $after];
                }
            ),
            self::c(
                'id.last_insert_id.with_name',
                $g,
                'PDO::lastInsertId()',
                'lastInsertId() with a sequence name argument',
                static function (\PDO $p) {
                    $p->exec("INSERT INTO probe_rows (k, i, r, s, n) VALUES ('idname', 102, 1.0, 'x', NULL)");

                    return $p->lastInsertId('some_seq');
                }
            ),
            self::c(
                'count.rowcount.insert',
                $g,
                'PDOStatement::rowCount()',
                'after an INSERT',
                static function (\PDO $p) {
                    $stmt = $p->prepare("INSERT INTO probe_rows (k, i, r, s, n) VALUES ('rcinsert', 200, 1.0, 'x', NULL)");
                    $stmt->execute();

                    return $stmt->rowCount();
                }
            ),
            self::c(
                'count.rowcount.update_match',
                $g,
                'PDOStatement::rowCount()',
                'after an UPDATE matching one row',
                static function (\PDO $p) {
                    $stmt = $p->prepare("UPDATE probe_rows SET s = 'rcupd' WHERE k = 'a'");
                    $stmt->execute();

                    return $stmt->rowCount();
                }
            ),
            self::c(
                'count.rowcount.update_no_match',
                $g,
                'PDOStatement::rowCount()',
                'after an UPDATE matching no row',
                static function (\PDO $p) {
                    $stmt = $p->prepare("UPDATE probe_rows SET s = 'rcupd2' WHERE k = 'no-such-key'");
                    $stmt->execute();

                    return $stmt->rowCount();
                }
            ),
            self::c(
                'count.rowcount.delete_none',
                $g,
                'PDOStatement::rowCount()',
                'after a DELETE matching no row',
                static function (\PDO $p) {
                    $stmt = $p->prepare("DELETE FROM probe_rows WHERE k = 'no-such-key'");
                    $stmt->execute();

                    return $stmt->rowCount();
                }
            ),
            self::c(
                'count.rowcount.select',
                $g,
                'PDOStatement::rowCount()',
                'after a SELECT — UNDEFINED by PDO\'s own contract; recorded, never compared',
                static function (\PDO $p) {
                    $stmt = $p->prepare("SELECT k FROM probe_rows WHERE k = 'no-such-select'");
                    $stmt->execute();

                    return $stmt->rowCount();
                },
                false,
                true
            ),
            self::c(
                'count.columncount.before_execute',
                $g,
                'PDOStatement::columnCount()',
                'before execute()',
                static fn (\PDO $p) => $p->prepare('SELECT k, i FROM probe_rows')->columnCount()
            ),
            self::c(
                'count.columncount.two_cols',
                $g,
                'PDOStatement::columnCount()',
                'a two-column, non-empty result',
                static function (\PDO $p) {
                    $stmt = $p->prepare('SELECT k, i FROM probe_rows WHERE k = ?');
                    $stmt->execute(['a']);

                    return $stmt->columnCount();
                }
            ),
            self::c(
                'count.columncount.empty_result',
                $g,
                'PDOStatement::columnCount()',
                'columnCount() on a two-column result with ZERO rows',
                static function (\PDO $p) {
                    $stmt = $p->prepare('SELECT k, i FROM probe_rows WHERE k = ?');
                    $stmt->execute(['no-such-key']);

                    return $stmt->columnCount();
                }
            ),
            self::c(
                'count.columncount.of_update',
                $g,
                'PDOStatement::columnCount()',
                'an UPDATE statement has zero columns',
                static function (\PDO $p) {
                    $stmt = $p->prepare("UPDATE probe_rows SET s = s WHERE k = 'a'");
                    $stmt->execute();

                    return $stmt->columnCount();
                }
            ),
        ];
    }

    // ---------------------------------------------------------------- binding

    private static function binding(): array
    {
        $g = 'Binding';

        // A minimal, table-independent probe used across most of this
        // group: bind the SAME value to both placeholders and read back
        // both the value and its SQLite storage class, in one row.
        $vt = static function (\PDO $p, $value, int $type): array {
            $stmt = $p->prepare('SELECT ? AS v, typeof(?) AS t');
            $stmt->bindValue(1, $value, $type);
            $stmt->bindValue(2, $value, $type);
            $stmt->execute();

            return $stmt->fetch(\PDO::FETCH_ASSOC);
        };

        return [
            self::c(
                'bind.value.default_str',
                $g,
                'PDOStatement::bindValue()',
                'default PARAM_STR leaves an already-string value untouched',
                static fn (\PDO $p) => $vt($p, '42', \PDO::PARAM_STR)
            ),
            self::c(
                'bind.value.param_int_from_string',
                $g,
                'PDOStatement::bindValue()',
                'PARAM_INT should coerce a numeric string to int',
                static fn (\PDO $p) => $vt($p, '42', \PDO::PARAM_INT)
            ),
            self::c(
                'bind.value.param_int_from_float',
                $g,
                'PDOStatement::bindValue()',
                'PARAM_INT should truncate a float',
                static fn (\PDO $p) => $vt($p, 3.5, \PDO::PARAM_INT)
            ),
            self::c(
                'bind.value.param_bool_true',
                $g,
                'PDOStatement::bindValue()',
                'PARAM_BOOL true becomes integer 1',
                static fn (\PDO $p) => $vt($p, true, \PDO::PARAM_BOOL)
            ),
            self::c(
                'bind.value.param_bool_empty_string',
                $g,
                'PDOStatement::bindValue()',
                'PARAM_BOOL on an empty string should coerce through bool to integer 0',
                static fn (\PDO $p) => $vt($p, '', \PDO::PARAM_BOOL)
            ),
            self::c(
                'bind.value.param_null_ignores_value',
                $g,
                'PDOStatement::bindValue()',
                'PARAM_NULL should ignore the given value entirely and bind NULL',
                static fn (\PDO $p) => $vt($p, 'ignored-value', \PDO::PARAM_NULL)
            ),
            self::c(
                'bind.value.param_str_from_int',
                $g,
                'PDOStatement::bindValue()',
                'PARAM_STR should stringify an int',
                static fn (\PDO $p) => $vt($p, 7, \PDO::PARAM_STR)
            ),
            self::c(
                'bind.value.param_str_from_float',
                $g,
                'PDOStatement::bindValue()',
                'PARAM_STR should stringify a float',
                static fn (\PDO $p) => $vt($p, 1.0, \PDO::PARAM_STR)
            ),
            self::c(
                'bind.value.param_lob',
                $g,
                'PDOStatement::bindValue()',
                'bindValue() with PARAM_LOB',
                static fn (\PDO $p) => $vt($p, 'binval', \PDO::PARAM_LOB)
            ),
            self::c(
                'bind.value.wide_int_param_int',
                $g,
                'PDOStatement::bindValue()',
                'a >2^53-1 int bound as PARAM_INT and read back DIRECTLY hits the same int64 wall as a stored value',
                static fn (\PDO $p) => $vt($p, PHP_INT_MAX, \PDO::PARAM_INT)
            ),
            self::c(
                'bind.value.wide_int_param_str',
                $g,
                'PDOStatement::bindValue()',
                'the documented workaround: bind the wide integer AS TEXT and it round-trips exactly',
                static fn (\PDO $p) => $vt($p, (string) PHP_INT_MAX, \PDO::PARAM_STR)
            ),
            self::c(
                'bind.value.wide_int_min',
                $g,
                'PDOStatement::bindValue()',
                'the NEGATIVE end of the wide-int band (PHP_INT_MIN) bound as PARAM_INT and read back DIRECTLY',
                static fn (\PDO $p) => $vt($p, PHP_INT_MIN, \PDO::PARAM_INT)
            ),
            self::c(
                'bind.param.by_reference',
                $g,
                'PDOStatement::bindParam()',
                'by-reference binding',
                static function (\PDO $p) {
                    $var = 'ref-val';
                    $stmt = $p->prepare('SELECT ? AS v');
                    $stmt->bindParam(1, $var);
                    $stmt->execute();

                    return $stmt->fetch(\PDO::FETCH_ASSOC);
                }
            ),
            self::c(
                'bind.param.reference_changed_before_execute',
                $g,
                'PDOStatement::bindParam()',
                'the reference is read at execute() time, not at bind time',
                static function (\PDO $p) {
                    $var = 'before';
                    $stmt = $p->prepare('SELECT ? AS v');
                    $stmt->bindParam(1, $var);
                    $var = 'after';
                    $stmt->execute();

                    return $stmt->fetch(\PDO::FETCH_ASSOC);
                }
            ),
            self::c(
                'bind.execute_array.types',
                $g,
                'PDOStatement::execute()',
                'real PDO stringifies EVERYTHING passed to execute() — a PERMANENT pinned deviation',
                static function (\PDO $p) {
                    $stmt = $p->prepare('SELECT ? AS v, typeof(?) AS t');
                    $stmt->execute([42, 42]);

                    return $stmt->fetch(\PDO::FETCH_ASSOC);
                }
            ),
            self::c(
                'bind.execute_array.replaces_bound',
                $g,
                'PDOStatement::execute()',
                'an array passed to execute() replaces a previously bound value',
                static function (\PDO $p) {
                    $stmt = $p->prepare('SELECT ? AS v');
                    $stmt->bindValue(1, 'bound-val');
                    $stmt->execute(['array-val']);

                    return $stmt->fetch(\PDO::FETCH_ASSOC);
                }
            ),
            self::c(
                'bind.execute_empty_array_keeps_bound',
                $g,
                'PDOStatement::execute()',
                'execute() called with an explicit empty array, over a previously bound value',
                static function (\PDO $p) {
                    $stmt = $p->prepare('SELECT ? AS v');
                    $stmt->bindValue(1, 'kept-val');
                    $stmt->execute([]);

                    return $stmt->fetch(\PDO::FETCH_ASSOC);
                }
            ),
            self::c(
                'bind.named.missing',
                $g,
                'PDOStatement::execute()',
                'execute() with a named placeholder left unsupplied',
                static function (\PDO $p) {
                    $stmt = $p->prepare('SELECT :a AS a, :b AS b');
                    $stmt->execute([':a' => 1]);

                    return $stmt->fetch(\PDO::FETCH_ASSOC);
                }
            ),
            self::c(
                'bind.named.extra',
                $g,
                'PDOStatement::execute()',
                'execute() with an extra, unused named binding',
                static function (\PDO $p) {
                    $stmt = $p->prepare('SELECT :a AS a');
                    $stmt->execute([':a' => 1, ':b' => 2]);

                    return $stmt->fetch(\PDO::FETCH_ASSOC);
                }
            ),
            self::c(
                'bind.named.reuse',
                $g,
                'PDOStatement::execute()',
                'the same named placeholder used twice in one statement',
                static function (\PDO $p) {
                    $stmt = $p->prepare('SELECT :a AS a1, :a AS a2');
                    $stmt->execute([':a' => 5]);

                    return $stmt->fetch(\PDO::FETCH_ASSOC);
                }
            ),
            self::c(
                'bind.value.position_zero',
                $g,
                'PDOStatement::bindValue()',
                'bindValue(0, ...) — position must be >= 1',
                static function (\PDO $p) {
                    $stmt = $p->prepare('SELECT ? AS v');
                    $stmt->bindValue(0, 'x');

                    return 'unreachable';
                }
            ),
            self::c(
                'bind.mixed_named_and_positional',
                $g,
                'PDOStatement::execute()',
                'a named and a positional placeholder bound in the SAME statement',
                static function (\PDO $p) {
                    // M1 review round 2, R7 (orchestrator decision: do NOT
                    // implement real PDO's ordinal model): real PDO executes
                    // this via SQLite's own shared ordinal sequence (:k is
                    // ordinal 1, the sole ? is ordinal 2 — the same fact
                    // debugDumpParams()'s docblock measures for NUMBERING
                    // purposes only). NamedParams::rewrite() treats a
                    // bindings array with BOTH an int key and a string key as
                    // entirely named (isPositional() requires every key be
                    // int), so the ? placeholder is left unresolved and its
                    // bound value (keyed 2) is reported as an unused named
                    // binding — refused, not the ordinal answer.
                    $stmt = $p->prepare('SELECT :k AS k, ? AS v FROM probe_rows WHERE k = :k LIMIT 1');
                    $stmt->bindValue(':k', 'a', \PDO::PARAM_STR);
                    $stmt->bindValue(2, 'positional-val', \PDO::PARAM_STR);
                    $stmt->execute();

                    return $stmt->fetch(\PDO::FETCH_ASSOC);
                }
            ),
        ];
    }

    // ------------------------------------------------------------ fetch modes

    private static function fetchModes(): array
    {
        $g = 'Fetch modes';

        return [
            self::c(
                'fetch.assoc',
                $g,
                'PDOStatement::fetch()',
                'FETCH_ASSOC over a three-row result',
                static fn (\PDO $p) => $p->query('SELECT k, i FROM probe_rows ORDER BY k')->fetchAll(\PDO::FETCH_ASSOC)
            ),
            self::c(
                'fetch.num',
                $g,
                'PDOStatement::fetch()',
                'FETCH_NUM',
                static fn (\PDO $p) => $p->query('SELECT k, i FROM probe_rows ORDER BY k')->fetchAll(\PDO::FETCH_NUM)
            ),
            self::c(
                'fetch.both',
                $g,
                'PDOStatement::fetch()',
                'FETCH_BOTH',
                static fn (\PDO $p) => $p->query('SELECT k, i FROM probe_rows ORDER BY k')->fetchAll(\PDO::FETCH_BOTH)
            ),
            self::c(
                'fetch.obj',
                $g,
                'PDOStatement::fetch()',
                'FETCH_OBJ',
                static fn (\PDO $p) => $p->query('SELECT k, i FROM probe_rows ORDER BY k')->fetchAll(\PDO::FETCH_OBJ)
            ),
            self::c(
                'fetch.default_mode',
                $g,
                'PDOStatement::fetchAll()',
                'fetchAll() called with no mode argument, so it uses the connection\'s ATTR_DEFAULT_FETCH_MODE',
                static fn (\PDO $p) => $p->query('SELECT k, i FROM probe_rows ORDER BY k')->fetchAll()
            ),
            self::c(
                'fetch.column.default',
                $g,
                'PDOStatement::fetchAll()',
                'FETCH_COLUMN with no index defaults to column 0',
                static fn (\PDO $p) => $p->query('SELECT k, i FROM probe_rows ORDER BY k')->fetchAll(\PDO::FETCH_COLUMN)
            ),
            self::c(
                'fetch.column.index_1',
                $g,
                'PDOStatement::fetchAll()',
                'FETCH_COLUMN with an explicit index argument',
                static fn (\PDO $p) => $p->query('SELECT k, i FROM probe_rows ORDER BY k')->fetchAll(\PDO::FETCH_COLUMN, 1)
            ),
            self::c(
                'fetch.column.out_of_range',
                $g,
                'PDOStatement::fetchColumn()',
                'fetchColumn() with a column index past the end of the result set',
                static function (\PDO $p) {
                    $stmt = $p->query('SELECT k FROM probe_rows ORDER BY k LIMIT 1');

                    return $stmt->fetchColumn(5);
                }
            ),
            self::c(
                'fetch.key_pair',
                $g,
                'PDOStatement::fetchAll()',
                'FETCH_KEY_PAIR over exactly two columns',
                static fn (\PDO $p) => $p->query('SELECT k, i FROM probe_rows ORDER BY k')->fetchAll(\PDO::FETCH_KEY_PAIR)
            ),
            self::c(
                'fetch.key_pair.three_columns',
                $g,
                'PDOStatement::fetchAll()',
                'FETCH_KEY_PAIR over a THREE-column result set',
                static fn (\PDO $p) => $p->query('SELECT k, i, r FROM probe_rows ORDER BY k')->fetchAll(\PDO::FETCH_KEY_PAIR)
            ),
            self::c(
                'fetch.lazy',
                $g,
                'PDOStatement::fetch()',
                'FETCH_LAZY',
                static fn (\PDO $p) => $p->query('SELECT k FROM probe_rows ORDER BY k LIMIT 1')->fetch(\PDO::FETCH_LAZY)
            ),
            self::c(
                'fetch.bound.default_type',
                $g,
                'PDOStatement::bindColumn() / FETCH_BOUND',
                'default column type',
                static function (\PDO $p) {
                    $stmt = $p->prepare('SELECT k, i FROM probe_rows WHERE k = ? LIMIT 1');
                    $stmt->execute(['b']);
                    $stmt->bindColumn(2, $out);
                    $ok = $stmt->fetch(\PDO::FETCH_BOUND);

                    return [$ok, $out];
                }
            ),
            self::c(
                'fetch.bound.param_int',
                $g,
                'PDOStatement::bindColumn() / FETCH_BOUND',
                'PARAM_INT column type',
                static function (\PDO $p) {
                    $stmt = $p->prepare('SELECT k, i FROM probe_rows WHERE k = ? LIMIT 1');
                    $stmt->execute(['b']);
                    $stmt->bindColumn(2, $out, \PDO::PARAM_INT);
                    $ok = $stmt->fetch(\PDO::FETCH_BOUND);

                    return [$ok, $out];
                }
            ),
            self::c(
                'fetch.bound.unknown_column',
                $g,
                'PDOStatement::bindColumn()',
                'bindColumn() with an unknown column name',
                static function (\PDO $p) {
                    $stmt = $p->prepare('SELECT k FROM probe_rows LIMIT 1');
                    $stmt->execute();
                    $stmt->bindColumn('nope', $x);

                    return 'unreachable';
                }
            ),
            self::c(
                'fetch.class.plain',
                $g,
                'PDOStatement::fetchAll()',
                'FETCH_CLASS, no constructor args',
                static function (\PDO $p) {
                    $stmt = $p->prepare('SELECT k, i, r, s, n FROM probe_rows WHERE k = :k');
                    $stmt->execute([':k' => 'a']);

                    return $stmt->fetchAll(\PDO::FETCH_CLASS, Row::class);
                }
            ),
            self::c(
                'fetch.class.ctor_args',
                $g,
                'PDOStatement::fetchAll()',
                'FETCH_CLASS with constructor args',
                static function (\PDO $p) {
                    $stmt = $p->prepare('SELECT k, i, r, s, n FROM probe_rows WHERE k = :k');
                    $stmt->execute([':k' => 'a']);

                    return $stmt->fetchAll(\PDO::FETCH_CLASS, Row::class, ['ctor-tag']);
                }
            ),
            self::c(
                'fetch.class.props_late',
                $g,
                'PDOStatement::fetchAll()',
                'FETCH_CLASS | FETCH_PROPS_LATE — properties set AFTER the constructor runs',
                static function (\PDO $p) {
                    $stmt = $p->prepare('SELECT k, i FROM probe_rows WHERE k = :k');
                    $stmt->execute([':k' => 'a']);

                    return $stmt->fetchAll(\PDO::FETCH_CLASS | \PDO::FETCH_PROPS_LATE, LateRow::class);
                }
            ),
            self::c(
                'fetch.class.private_props',
                $g,
                'PDOStatement::fetchAll()',
                'FETCH_CLASS writes private/protected declared props AND makes an unmatched column dynamic (measured E13)',
                static function (\PDO $p) {
                    $stmt = $p->prepare('SELECT i AS id, s AS v, 9 AS zz FROM probe_rows WHERE k = :k');
                    $stmt->execute([':k' => 'a']);
                    $rows = $stmt->fetchAll(\PDO::FETCH_CLASS, PrivateRow::class);

                    return $rows[0]->dump();
                }
            ),
            self::c(
                'fetch.class.promoted_ctor',
                $g,
                'PDOStatement::fetchAll()',
                'FETCH_CLASS into a promoted-constructor class — the ctor\'s defaults overwrite the hydrated props (measured E13)',
                static function (\PDO $p) {
                    $stmt = $p->prepare('SELECT k, i FROM probe_rows WHERE k = :k');
                    $stmt->execute([':k' => 'a']);
                    $rows = $stmt->fetchAll(\PDO::FETCH_CLASS, Promoted::class);

                    return [$rows[0]->k, $rows[0]->i];
                }
            ),
            self::c(
                'fetch.classtype',
                $g,
                'PDOStatement::fetchAll()',
                'FETCH_CLASS | FETCH_CLASSTYPE — the class name comes from the first column',
                static function (\PDO $p) {
                    $stmt = $p->prepare(
                        "SELECT 'App\\\\Pdo\\\\Fixtures\\\\Row' AS classname, k, i, r, s, n FROM probe_rows WHERE k = :k"
                    );
                    $stmt->execute([':k' => 'b']);

                    return $stmt->fetchAll(\PDO::FETCH_CLASS | \PDO::FETCH_CLASSTYPE);
                }
            ),
            self::c(
                'fetch.into',
                $g,
                'PDOStatement::setFetchMode() / fetch()',
                'FETCH_INTO hydrates an existing object in place',
                static function (\PDO $p) {
                    $obj = new Row();
                    $stmt = $p->prepare('SELECT k, i, r, s, n FROM probe_rows WHERE k = :k');
                    $stmt->execute([':k' => 'c']);
                    $stmt->setFetchMode(\PDO::FETCH_INTO, $obj);
                    $ok = $stmt->fetch();

                    return [$ok, $obj->k, $obj->i];
                }
            ),
            self::c(
                'fetch.func',
                $g,
                'PDOStatement::fetchAll()',
                'FETCH_FUNC calls the given function with each column as a positional arg',
                static function (\PDO $p) {
                    $stmt = $p->prepare('SELECT k, i FROM probe_rows WHERE k = :k');
                    $stmt->execute([':k' => 'a']);

                    return $stmt->fetchAll(\PDO::FETCH_FUNC, static fn ($k, $i) => [$k, $i]);
                }
            ),
            self::c(
                'fetch.func.via_fetch',
                $g,
                'PDOStatement::fetch()',
                'FETCH_FUNC on fetch() (not fetchAll())',
                static function (\PDO $p) {
                    $stmt = $p->prepare('SELECT k, i FROM probe_rows WHERE k = :k');
                    $stmt->execute([':k' => 'a']);

                    return $stmt->fetch(\PDO::FETCH_FUNC);
                }
            ),
            self::c(
                'fetch.group.assoc',
                $g,
                'PDOStatement::fetchAll()',
                'FETCH_GROUP | FETCH_ASSOC',
                static fn (\PDO $p) => $p->query('SELECT k, i FROM probe_rows ORDER BY k')->fetchAll(\PDO::FETCH_GROUP | \PDO::FETCH_ASSOC)
            ),
            self::c(
                'fetch.group.num',
                $g,
                'PDOStatement::fetchAll()',
                'FETCH_GROUP | FETCH_NUM',
                static fn (\PDO $p) => $p->query('SELECT k, i FROM probe_rows ORDER BY k')->fetchAll(\PDO::FETCH_GROUP | \PDO::FETCH_NUM)
            ),
            self::c(
                'fetch.group.column',
                $g,
                'PDOStatement::fetchAll()',
                'FETCH_GROUP | FETCH_COLUMN',
                static fn (\PDO $p) => $p->query('SELECT k, i FROM probe_rows ORDER BY k')->fetchAll(\PDO::FETCH_GROUP | \PDO::FETCH_COLUMN)
            ),
            self::c(
                'fetch.unique.assoc',
                $g,
                'PDOStatement::fetchAll()',
                'FETCH_UNIQUE | FETCH_ASSOC — first column is the key, row is the value',
                static fn (\PDO $p) => $p->query('SELECT k, i FROM probe_rows ORDER BY k')->fetchAll(\PDO::FETCH_UNIQUE | \PDO::FETCH_ASSOC)
            ),
            self::c(
                'fetch.unique.column',
                $g,
                'PDOStatement::fetchAll()',
                'FETCH_UNIQUE | FETCH_COLUMN',
                static fn (\PDO $p) => $p->query('SELECT k, i FROM probe_rows ORDER BY k')->fetchAll(\PDO::FETCH_UNIQUE | \PDO::FETCH_COLUMN)
            ),
            self::c(
                'fetch.object.stdclass',
                $g,
                'PDOStatement::fetchObject()',
                'no class argument yields stdClass',
                static function (\PDO $p) {
                    $stmt = $p->prepare('SELECT k, i FROM probe_rows WHERE k = :k');
                    $stmt->execute([':k' => 'a']);

                    return $stmt->fetchObject();
                }
            ),
            self::c(
                'fetch.object.class_args',
                $g,
                'PDOStatement::fetchObject()',
                'a class name and constructor args',
                static function (\PDO $p) {
                    $stmt = $p->prepare('SELECT k, i, r, s, n FROM probe_rows WHERE k = :k');
                    $stmt->execute([':k' => 'a']);

                    return $stmt->fetchObject(Row::class, ['obj-tag']);
                }
            ),
            self::c(
                'fetch.after_fetch_all',
                $g,
                'PDOStatement::fetch()',
                'fetch() after fetchAll() has exhausted the cursor returns false',
                static function (\PDO $p) {
                    $stmt = $p->query('SELECT k FROM probe_rows ORDER BY k');
                    $all = $stmt->fetchAll(\PDO::FETCH_ASSOC);
                    $again = $stmt->fetch(\PDO::FETCH_ASSOC);

                    return [$all, $again];
                }
            ),
            self::c(
                'fetch.on_empty_result',
                $g,
                'PDOStatement::fetch() / fetchAll()',
                'a query matching zero rows',
                static function (\PDO $p) {
                    $stmt = $p->prepare('SELECT k FROM probe_rows WHERE k = :k');
                    $stmt->execute([':k' => 'no-such-key']);

                    return [$stmt->fetch(\PDO::FETCH_ASSOC), $stmt->fetchAll(\PDO::FETCH_ASSOC)];
                }
            ),
            self::c(
                'fetch.ori_prior',
                $g,
                'PDOStatement::fetch()',
                'FETCH_ORI_PRIOR on a forward-only cursor',
                static function (\PDO $p) {
                    $stmt = $p->prepare('SELECT k FROM probe_rows ORDER BY k');
                    $stmt->execute();
                    $stmt->fetch();

                    return $stmt->fetch(\PDO::FETCH_ASSOC, \PDO::FETCH_ORI_PRIOR);
                }
            ),
            self::c(
                'fetch.ori_first',
                $g,
                'PDOStatement::fetch()',
                'FETCH_ORI_FIRST on a forward-only cursor',
                static function (\PDO $p) {
                    $stmt = $p->prepare('SELECT k FROM probe_rows ORDER BY k');
                    $stmt->execute();
                    $stmt->fetch();

                    return $stmt->fetch(\PDO::FETCH_ASSOC, \PDO::FETCH_ORI_FIRST);
                }
            ),
            self::c(
                'fetch.ori_last',
                $g,
                'PDOStatement::fetch()',
                'FETCH_ORI_LAST on a forward-only cursor',
                static function (\PDO $p) {
                    $stmt = $p->prepare('SELECT k FROM probe_rows ORDER BY k');
                    $stmt->execute();
                    $stmt->fetch();

                    return $stmt->fetch(\PDO::FETCH_ASSOC, \PDO::FETCH_ORI_LAST);
                }
            ),
            self::c(
                'fetch.ori_abs',
                $g,
                'PDOStatement::fetch()',
                'FETCH_ORI_ABS on a forward-only cursor',
                static function (\PDO $p) {
                    $stmt = $p->prepare('SELECT k FROM probe_rows ORDER BY k');
                    $stmt->execute();
                    $stmt->fetch();

                    return $stmt->fetch(\PDO::FETCH_ASSOC, \PDO::FETCH_ORI_ABS, 1);
                }
            ),
            self::c(
                'fetch.ori_rel',
                $g,
                'PDOStatement::fetch()',
                'FETCH_ORI_REL on a forward-only cursor',
                static function (\PDO $p) {
                    $stmt = $p->prepare('SELECT k FROM probe_rows ORDER BY k');
                    $stmt->execute();
                    $stmt->fetch();

                    return $stmt->fetch(\PDO::FETCH_ASSOC, \PDO::FETCH_ORI_REL, 1);
                }
            ),
            self::c(
                'fetch.iterator',
                $g,
                'PDOStatement::getIterator()',
                'a plain foreach over the statement, using whatever the connection\'s default fetch mode is',
                static function (\PDO $p) {
                    $stmt = $p->prepare('SELECT k FROM probe_rows ORDER BY k');
                    $stmt->execute();
                    $out = [];
                    foreach ($stmt as $row) {
                        $out[] = $row;
                    }

                    return $out;
                }
            ),
            self::c(
                'fetch.set_fetch_mode.column_index',
                $g,
                'PDOStatement::setFetchMode()',
                'FETCH_COLUMN with an index argument',
                static function (\PDO $p) {
                    $stmt = $p->prepare('SELECT k, i FROM probe_rows ORDER BY k');
                    $stmt->execute();
                    $stmt->setFetchMode(\PDO::FETCH_COLUMN, 1);

                    return $stmt->fetchAll();
                }
            ),
            self::c(
                'fetch.set_fetch_mode.class',
                $g,
                'PDOStatement::setFetchMode()',
                'FETCH_CLASS with a class name argument',
                static function (\PDO $p) {
                    $stmt = $p->prepare('SELECT k, i, r, s, n FROM probe_rows ORDER BY k');
                    $stmt->execute();
                    $stmt->setFetchMode(\PDO::FETCH_CLASS, Row::class);

                    return $stmt->fetchAll();
                }
            ),
            self::c(
                'fetch.set_fetch_mode.into',
                $g,
                'PDOStatement::setFetchMode()',
                'FETCH_INTO with an object argument',
                static function (\PDO $p) {
                    $obj = new Row();
                    $stmt = $p->prepare('SELECT k, i, r, s, n FROM probe_rows WHERE k = :k');
                    $stmt->execute([':k' => 'a']);
                    $stmt->setFetchMode(\PDO::FETCH_INTO, $obj);

                    return $stmt->fetch();
                }
            ),
        ];
    }

    // -------------------------------------------------- values / round-trips

    private static function valuesAndRoundTrips(): array
    {
        $g = 'Values and round-trips';

        // Deliberately an INLINE SQL LITERAL, not a bound parameter: binding
        // goes through PDOStatement::bindValue()'s default PARAM_STR, whose
        // OWN type-coercion behaviour is what the Binding group tests. This
        // group means to isolate pure wire-level value fidelity — what
        // comes back for a literal the SQL engine itself typed — so a
        // literal is embedded directly in the statement text and neither
        // side ever binds anything for these cases.
        $literal = static function (\PDO $p, $value): string {
            if ($value === null) {
                return 'NULL';
            }
            if (is_int($value)) {
                return (string) $value;
            }
            if (is_float($value)) {
                $s = sprintf('%.17G', $value);
                // Force REAL literal syntax even for an integral float
                // (2.0 must not render as the bare integer literal "2").
                if (!preg_match('/[.eE]/', $s)) {
                    $s .= '.0';
                }

                return $s;
            }

            // Real pdo_sqlite ignores $type entirely for quote() (design §3
            // F-24) and PARAM_STR is a no-op for an already-correct escape,
            // so this is safe on both sides regardless of quote()'s other
            // bugs under non-default types.
            return $p->quote((string) $value, \PDO::PARAM_STR);
        };

        $vt = static function (\PDO $p, $value) use ($literal): array {
            $lit = $literal($p, $value);
            $stmt = $p->query("SELECT {$lit} AS v, typeof({$lit}) AS t");

            return $stmt->fetch(\PDO::FETCH_ASSOC);
        };

        return [
            self::c('value.null', $g, 'value round-trip', 'NULL', static fn (\PDO $p) => $vt($p, null)),
            self::c('value.int', $g, 'value round-trip', 'an ordinary int', static fn (\PDO $p) => $vt($p, 42)),
            self::c('value.float', $g, 'value round-trip', 'a non-integral float', static fn (\PDO $p) => $vt($p, 1.5)),
            self::c(
                'value.float_integral',
                $g,
                'value round-trip',
                'a REAL holding an integral value (2.0) — measured: real returns float(2), our wire loses the float-ness',
                static fn (\PDO $p) => $vt($p, 2.0)
            ),
            self::c('value.text', $g, 'value round-trip', 'an ordinary string', static fn (\PDO $p) => $vt($p, 'hello')),
            self::c(
                'value.text_utf8',
                $g,
                'value round-trip',
                'multi-byte UTF-8 text',
                static fn (\PDO $p) => $vt($p, 'héllo €')
            ),
            self::c('value.text_empty', $g, 'value round-trip', 'the empty string', static fn (\PDO $p) => $vt($p, '')),
            self::c(
                'value.bool_written_as_int',
                $g,
                'value round-trip',
                'PARAM_BOOL true, bound explicitly, becomes integer 1 on both sides',
                static function (\PDO $p) {
                    $stmt = $p->prepare('SELECT ? AS v, typeof(?) AS t');
                    $stmt->bindValue(1, true, \PDO::PARAM_BOOL);
                    $stmt->bindValue(2, true, \PDO::PARAM_BOOL);
                    $stmt->execute();

                    return $stmt->fetch(\PDO::FETCH_ASSOC);
                }
            ),
            self::c(
                'value.typeof_column',
                $g,
                'value round-trip',
                'SQLite storage class of every seeded column type, read from a real row (not a bound literal)',
                static function (\PDO $p) {
                    $stmt = $p->prepare(
                        'SELECT typeof(i) AS ti, typeof(r) AS tr, typeof(s) AS ts, typeof(n) AS tn FROM probe_rows WHERE k = :k'
                    );
                    $stmt->execute([':k' => 'a']);

                    return $stmt->fetch(\PDO::FETCH_ASSOC);
                }
            ),
            self::c(
                'int.safe.roundtrip',
                $g,
                'value round-trip',
                '±2^31 and ±(2^53-1) — inside the JS-safe-integer band, no precision loss possible',
                static function (\PDO $p) {
                    $stmt = $p->prepare('SELECT ? AS lo, ? AS hi, ? AS neg');
                    $stmt->bindValue(1, -2147483648, \PDO::PARAM_INT);
                    $stmt->bindValue(2, 9007199254740991, \PDO::PARAM_INT);
                    $stmt->bindValue(3, -9007199254740991, \PDO::PARAM_INT);
                    $stmt->execute();

                    return $stmt->fetch(\PDO::FETCH_ASSOC);
                }
            ),
            self::c(
                'int.wide.direct_read',
                $g,
                'value round-trip',
                'a stored >2^53-1 INTEGER read WITHOUT a CAST — workerd loses precision before Atoms code runs; refused (mvp-spec.md Appendix item 1)',
                static function (\PDO $p) {
                    $p->exec("DELETE FROM probe_wide WHERE k = 'direct'");
                    $p->exec("INSERT INTO probe_wide (k, v) VALUES ('direct', 9223372036854775807)");
                    $stmt = $p->prepare('SELECT v FROM probe_wide WHERE k = ?');
                    $stmt->execute(['direct']);

                    return $stmt->fetchColumn();
                }
            ),
            self::c(
                'int.wide.cast_read',
                $g,
                'value round-trip',
                'the documented supported path: SELECT CAST(v AS TEXT) for a wide integer written as an inline literal',
                static function (\PDO $p) {
                    $p->exec("DELETE FROM probe_wide WHERE k = 'wide-cast'");
                    $p->exec("INSERT INTO probe_wide (k, v) VALUES ('wide-cast', 9223372036854775807)");
                    $stmt = $p->prepare('SELECT CAST(v AS TEXT) AS v FROM probe_wide WHERE k = ?');
                    $stmt->execute(['wide-cast']);

                    return $stmt->fetchColumn();
                }
            ),
            self::c(
                'int.wide.write_exact',
                $g,
                'value round-trip',
                'a wide integer written through a BOUND PARAMETER (not an inline literal) round-trips exactly via CAST',
                static function (\PDO $p) {
                    $p->exec("DELETE FROM probe_wide WHERE k = 'wide-exact'");
                    $stmt = $p->prepare('INSERT INTO probe_wide (k, v) VALUES (?, ?)');
                    $stmt->execute(['wide-exact', 9223372036854775807]);
                    $read = $p->prepare('SELECT CAST(v AS TEXT) AS v FROM probe_wide WHERE k = ?');
                    $read->execute(['wide-exact']);

                    return $read->fetchColumn();
                }
            ),
            self::c(
                'int.wide.negative_direct_read',
                $g,
                'value round-trip',
                'the NEGATIVE end of the wide-int band: a stored PHP_INT_MIN read WITHOUT a CAST — same int64 wall, opposite sign',
                static function (\PDO $p) {
                    $p->exec("DELETE FROM probe_wide WHERE k = 'direct-neg'");
                    $p->exec('INSERT INTO probe_wide (k, v) VALUES (\'direct-neg\', ' . PHP_INT_MIN . ')');
                    $stmt = $p->prepare('SELECT v FROM probe_wide WHERE k = ?');
                    $stmt->execute(['direct-neg']);

                    return $stmt->fetchColumn();
                }
            ),
            self::c(
                'int.wide.negative_cast_read',
                $g,
                'value round-trip',
                'the NEGATIVE end of the wide-int band, via CAST(v AS TEXT) — the documented supported path',
                static function (\PDO $p) {
                    $p->exec("DELETE FROM probe_wide WHERE k = 'wide-cast-neg'");
                    $p->exec('INSERT INTO probe_wide (k, v) VALUES (\'wide-cast-neg\', ' . PHP_INT_MIN . ')');
                    $stmt = $p->prepare('SELECT CAST(v AS TEXT) AS v FROM probe_wide WHERE k = ?');
                    $stmt->execute(['wide-cast-neg']);

                    return $stmt->fetchColumn();
                }
            ),
            self::c(
                'int.wide.negative_write_exact',
                $g,
                'value round-trip',
                'PHP_INT_MIN written through a BOUND PARAMETER round-trips exactly via CAST',
                static function (\PDO $p) {
                    $p->exec("DELETE FROM probe_wide WHERE k = 'wide-exact-neg'");
                    $stmt = $p->prepare('INSERT INTO probe_wide (k, v) VALUES (?, ?)');
                    $stmt->execute(['wide-exact-neg', PHP_INT_MIN]);
                    $read = $p->prepare('SELECT CAST(v AS TEXT) AS v FROM probe_wide WHERE k = ?');
                    $read->execute(['wide-exact-neg']);

                    return $read->fetchColumn();
                }
            ),
        ];
    }

    // ---------------------------------------------------------------- errors

    private static function errors(): array
    {
        $g = 'Errors';

        return [
            self::c(
                'err.unique_violation.sqlstate',
                $g,
                'PDOStatement::execute()',
                'a UNIQUE violation',
                static function (\PDO $p) {
                    $p->exec("INSERT INTO probe_rows (k, i, r, s, n) VALUES ('a', 1, 1.0, 'dup', NULL)");

                    return true;
                },
                true
            ),
            self::c(
                'err.unique_violation.exception_code',
                $g,
                'PDOException::getCode()',
                'real PDO\'s getCode() IS the SQLSTATE string; ours defaults to int 0',
                static function (\PDO $p) {
                    try {
                        $p->exec("INSERT INTO probe_rows (k, i, r, s, n) VALUES ('a', 1, 1.0, 'dup2', NULL)");

                        return 'no-exception';
                    } catch (\Throwable $e) {
                        return $e->getCode();
                    }
                }
            ),
            self::c(
                'err.not_null_violation',
                $g,
                'PDOStatement::execute()',
                'a NOT NULL violation',
                static function (\PDO $p) {
                    $p->exec("INSERT INTO probe_rows (k, i, r, s, n) VALUES (NULL, 1, 1.0, 'x', NULL)");

                    return true;
                },
                true
            ),
            self::c(
                'err.syntax_error',
                $g,
                'PDO::exec()',
                'a SQL syntax error',
                static function (\PDO $p) {
                    $p->exec('SELEKT this is not sql');

                    return true;
                },
                true
            ),
            self::c(
                'err.errorinfo_clean_after_success',
                $g,
                'PDO::errorInfo()',
                'a clean 00000/null/null triple after a successful operation',
                static function (\PDO $p) {
                    $p->exec("UPDATE probe_rows SET s = s WHERE k = 'a'");

                    return $p->errorInfo();
                }
            ),
            self::c(
                'err.statement_error_does_not_leak_to_connection',
                $g,
                'PDO::errorCode()',
                'PDO::errorCode() on the CONNECTION after a STATEMENT failure',
                static function (\PDO $p) {
                    // M1 review round 2, R4: made order-independent. prepare()
                    // itself now resets this connection's error state to
                    // clean (measured), so preparing the failing statement
                    // FIRST — before anything else touches the connection —
                    // means this case's starting point is deterministic
                    // regardless of what any earlier case in this shared
                    // connection left behind, rather than merely assuming a
                    // prior case happened to leave it clean.
                    $stmt = $p->prepare("INSERT INTO probe_rows (k, i, r, s, n) VALUES ('a', 1, 1.0, 'dup3', NULL)");
                    try {
                        $stmt->execute();
                    } catch (\Throwable $e) {
                        // expected — checking the CONNECTION's state afterwards
                    }

                    return $p->errorCode();
                }
            ),
            self::c(
                'err.connection_state_after_begin_commit',
                $g,
                'PDO::errorCode()',
                'a clean beginTransaction()/commit() does NOT reset a stale connection error state',
                static function (\PDO $p) {
                    // M1 review round 2, R4 (measured): unlike prepare()/
                    // quote()/getAttribute()/a successful statement execute()
                    // (all of which DO reset), beginTransaction()/commit()/
                    // rollBack() leave a stale connection error state alone
                    // on success. Seeds its own known-dirty state first,
                    // INSIDE this closure, so the case does not depend on
                    // what any earlier case left behind (order-independent).
                    try {
                        $p->query('SELEKT 1');
                    } catch (\Throwable $e) {
                        // expected — seeding a stale HY000
                    }

                    $p->beginTransaction();
                    $p->commit();

                    return $p->errorCode();
                }
            ),
            self::c(
                'err.connection_state_after_prepare',
                $g,
                'PDO::errorCode()',
                'prepare() resets a stale connection error state to clean',
                static function (\PDO $p) {
                    try {
                        $p->query('SELEKT 1');
                    } catch (\Throwable $e) {
                        // expected — seeding a stale HY000
                    }

                    $p->prepare('SELECT 1');

                    return $p->errorCode();
                }
            ),
            self::c(
                'err.connection_state_after_successful_statement_execute',
                $g,
                'PDO::errorCode()',
                'a successful STATEMENT execute() does NOT reset a stale CONNECTION error state',
                static function (\PDO $p) {
                    // The statement is prepared BEFORE the dirty seed below —
                    // prepare() itself resets (see
                    // err.connection_state_after_prepare) — so the dirty
                    // state this case seeds survives to be observed. Measured
                    // (this case's own first run): a successful STATEMENT
                    // execute() leaves it alone too, symmetric with
                    // err.statement_error_does_not_leak_to_connection — the
                    // connection's own triple changes only through its OWN
                    // direct operations, never through a statement's
                    // execute() in either direction.
                    $stmt = $p->prepare('SELECT 1');

                    try {
                        $p->query('SELEKT 1');
                    } catch (\Throwable $e) {
                        // expected — seeding a stale HY000 AFTER prepare()
                    }

                    $stmt->execute();

                    return $p->errorCode();
                }
            ),
            self::c(
                'err.connection_state_after_quote',
                $g,
                'PDO::errorCode()',
                'quote() resets a stale connection error state to clean',
                static function (\PDO $p) {
                    try {
                        $p->query('SELEKT 1');
                    } catch (\Throwable $e) {
                        // expected — seeding a stale HY000
                    }

                    $p->quote('x');

                    return $p->errorCode();
                }
            ),
            self::c(
                'err.errorcode_after_statement_failure',
                $g,
                'PDOStatement::errorCode()',
                'PDOStatement::errorCode() after its own failed execute()',
                static function (\PDO $p) {
                    $stmt = $p->prepare("INSERT INTO probe_rows (k, i, r, s, n) VALUES ('a', 1, 1.0, 'dup4', NULL)");
                    try {
                        $stmt->execute();
                    } catch (\Throwable $e) {
                        // expected
                    }

                    return $stmt->errorCode();
                }
            ),
            self::c(
                'err.connection_errorcode_after_failed_query',
                $g,
                'PDO::errorCode()',
                'query() is a CONNECTION-level entry point — its own failure updates $pdo->errorCode()',
                static function (\PDO $p) {
                    try {
                        $p->query('SELEKT 1');
                    } catch (\Throwable $e) {
                        // expected — checking the CONNECTION's state afterwards
                    }

                    return $p->errorCode();
                }
            ),
        ];
    }

    // ------------------------------------------------------ duplicate columns

    private static function duplicateColumns(): array
    {
        $g = 'Duplicate columns';

        $dupSql = "SELECT a.k AS k, a.i AS i, b.k AS k, b.i AS i FROM probe_rows a, probe_rows b "
            . "WHERE a.k = 'a' AND b.k = 'b'";

        // Exactly two columns, both named "k" — FETCH_KEY_PAIR's own arity
        // requirement (exactly 2 columns) plus a duplicate name, so it
        // exercises the KEY_PAIR-specific guard (M1 review round 2, R1)
        // rather than the "wrong column count" guard already covered by
        // fetch.key_pair.three_columns.
        $dupKeyPairSql = "SELECT a.k AS k, b.k AS k FROM probe_rows a, probe_rows b WHERE a.k = 'a' AND b.k = 'b'";

        return [
            self::c(
                'dup.assoc',
                $g,
                'PDOStatement::fetch()',
                'FETCH_ASSOC over duplicate column names',
                static fn (\PDO $p) => $p->query($dupSql)->fetch(\PDO::FETCH_ASSOC)
            ),
            self::c(
                'dup.obj',
                $g,
                'PDOStatement::fetch()',
                'FETCH_OBJ over duplicate column names',
                static fn (\PDO $p) => $p->query($dupSql)->fetch(\PDO::FETCH_OBJ)
            ),
            self::c(
                'dup.num',
                $g,
                'PDOStatement::fetch()',
                'FETCH_NUM over duplicate column names',
                static fn (\PDO $p) => $p->query($dupSql)->fetch(\PDO::FETCH_NUM)
            ),
            self::c(
                'dup.both',
                $g,
                'PDOStatement::fetch()',
                'FETCH_BOTH over duplicate column names',
                static fn (\PDO $p) => $p->query($dupSql)->fetch(\PDO::FETCH_BOTH)
            ),
            self::c(
                'dup.named',
                $g,
                'PDOStatement::fetch()',
                'FETCH_NAMED over duplicate column names',
                static fn (\PDO $p) => $p->query($dupSql)->fetch(\PDO::FETCH_NAMED)
            ),
            self::c(
                'dup.column_count',
                $g,
                'PDOStatement::columnCount()',
                'columnCount() over a result set with duplicate column names',
                static fn (\PDO $p) => $p->query($dupSql)->columnCount()
            ),
            self::c(
                'dup.fetch_column_method',
                $g,
                'PDOStatement::fetchColumn()',
                'fetchColumn() with the default index over duplicate column names',
                static fn (\PDO $p) => $p->query($dupSql)->fetchColumn()
            ),
            self::c(
                'dup.fetch_column_index_1',
                $g,
                'PDOStatement::fetchColumn()',
                'fetchColumn(1) over duplicate column names',
                static fn (\PDO $p) => $p->query($dupSql)->fetchColumn(1)
            ),
            self::c(
                'dup.key_pair',
                $g,
                'PDOStatement::fetchAll()',
                'FETCH_KEY_PAIR over exactly two duplicate-named columns',
                static fn (\PDO $p) => $p->query($dupKeyPairSql)->fetchAll(\PDO::FETCH_KEY_PAIR)
            ),
            self::c(
                'dup.fetch_func',
                $g,
                'PDOStatement::fetchAll()',
                'FETCH_FUNC over duplicate column names',
                static fn (\PDO $p) => $p->query($dupSql)->fetchAll(
                    \PDO::FETCH_FUNC,
                    static fn ($a, $b, $c, $d) => [$a, $b, $c, $d]
                )
            ),
            self::c(
                'dup.bound_by_index',
                $g,
                'PDOStatement::bindColumn() / FETCH_BOUND',
                'FETCH_BOUND binding a column BY INDEX over duplicate column names',
                static function (\PDO $p) use ($dupSql) {
                    $stmt = $p->query($dupSql);
                    $stmt->bindColumn(3, $out);
                    $ok = $stmt->fetch(\PDO::FETCH_BOUND);

                    return [$ok, $out];
                }
            ),
            self::c(
                'dup.group_assoc',
                $g,
                'PDOStatement::fetchAll()',
                'FETCH_GROUP | FETCH_ASSOC over duplicate column names',
                static fn (\PDO $p) => $p->query($dupSql)->fetchAll(\PDO::FETCH_GROUP | \PDO::FETCH_ASSOC)
            ),
            self::c(
                'dup.classtype',
                $g,
                'PDOStatement::fetchAll()',
                'FETCH_CLASS | FETCH_CLASSTYPE where the CLASSNAME column itself is duplicated',
                static function (\PDO $p) {
                    $stmt = $p->query(
                        "SELECT 'App\\Pdo\\Fixtures\\Row' AS classname, 'bogus-classname' AS classname, "
                        . "k, i, r, s, n FROM probe_rows WHERE k = 'a'"
                    );

                    return $stmt->fetchAll(\PDO::FETCH_CLASS | \PDO::FETCH_CLASSTYPE);
                }
            ),
            self::c(
                'dup.aliased',
                $g,
                'PDOStatement::fetch()',
                'a duplicate-name join, aliased DISTINCTLY on each side',
                static function (\PDO $p) {
                    $stmt = $p->query(
                        "SELECT a.k AS k1, b.k AS k2 FROM probe_rows a, probe_rows b WHERE a.k = 'a' AND b.k = 'b'"
                    );

                    return $stmt->fetch(\PDO::FETCH_ASSOC);
                }
            ),
        ];
    }

    // ----------------------------------------------------------- statement misc

    private static function statementMisc(): array
    {
        $g = 'Statement misc';

        return [
            self::c(
                'stmt.queryString',
                $g,
                'PDOStatement::$queryString',
                'the SQL as prepared, :name placeholders intact (allowlist A1)',
                static fn (\PDO $p) => $p->prepare('SELECT :a AS a, :b AS b')->queryString
            ),
            self::c(
                'stmt.queryString.is_writable',
                $g,
                'PDOStatement::$queryString',
                'post-construction EXTERNAL reassignment of $queryString, measured on this build: refused on BOTH sides (not a pinned deviation)',
                static function (\PDO $p) {
                    $stmt = $p->prepare('SELECT 1 AS one');
                    try {
                        $stmt->queryString = 'nope';

                        return 'writable';
                    } catch (\Throwable $e) {
                        return 'readonly';
                    }
                }
            ),
            self::c(
                'stmt.close_cursor_then_fetch',
                $g,
                'PDOStatement::closeCursor()',
                'fetch() after closeCursor() returns false',
                static function (\PDO $p) {
                    $stmt = $p->query('SELECT k FROM probe_rows ORDER BY k');
                    $stmt->closeCursor();

                    return $stmt->fetch(\PDO::FETCH_ASSOC);
                }
            ),
            self::c(
                'stmt.next_rowset',
                $g,
                'PDOStatement::nextRowset()',
                'nextRowset() after a single-result-set query',
                static fn (\PDO $p) => $p->query('SELECT 1')->nextRowset(),
                false
            ),
            self::c(
                'stmt.get_attribute',
                $g,
                'PDOStatement::getAttribute()',
                'getAttribute(ATTR_CURSOR) on a prepared statement',
                static fn (\PDO $p) => $p->prepare('SELECT 1')->getAttribute(\PDO::ATTR_CURSOR)
            ),
            self::c(
                'stmt.set_attribute',
                $g,
                'PDOStatement::setAttribute()',
                'setAttribute(ATTR_CURSOR, CURSOR_FWDONLY) on a prepared statement',
                static fn (\PDO $p) => $p->prepare('SELECT 1')->setAttribute(\PDO::ATTR_CURSOR, \PDO::CURSOR_FWDONLY)
            ),
            self::c(
                'stmt.get_column_meta',
                $g,
                'PDOStatement::getColumnMeta()',
                'PDOStatement::getColumnMeta()',
                static function (\PDO $p) {
                    $stmt = $p->query('SELECT 1 AS one');

                    return $stmt->getColumnMeta(0);
                }
            ),
            self::c(
                'stmt.debug_dump_params.named_and_positional',
                $g,
                'PDOStatement::debugDumpParams()',
                'debugDumpParams() with a bound named param and a bound positional param, exact captured-output byte comparison',
                static function (\PDO $p) {
                    $stmt = $p->prepare('SELECT * FROM probe_rows WHERE k = :k AND i = ?');
                    // M1 review F-4: the SOLE `?` is SQLite ordinal 2 (:k is
                    // ordinal 1), so bindValue(2, ...) — not (1, ...) — is
                    // the placeholder's actual bind number.
                    $stmt->bindValue(':k', 'a', \PDO::PARAM_STR);
                    $stmt->bindValue(2, 1, \PDO::PARAM_INT);
                    ob_start();
                    try {
                        $stmt->debugDumpParams();

                        return ob_get_clean();
                    } catch (\Throwable $e) {
                        ob_end_clean();

                        throw $e;
                    }
                }
            ),
            self::c(
                'stmt.debug_dump_params.positional_bound',
                $g,
                'PDOStatement::debugDumpParams()',
                'debugDumpParams() with two bound positional params, exact captured-output byte comparison',
                static function (\PDO $p) {
                    $stmt = $p->prepare('SELECT * FROM probe_rows WHERE k = ? AND i = ?');
                    $stmt->bindValue(1, 'a', \PDO::PARAM_STR);
                    $stmt->bindValue(2, 1, \PDO::PARAM_INT);
                    ob_start();
                    try {
                        $stmt->debugDumpParams();

                        return ob_get_clean();
                    } catch (\Throwable $e) {
                        ob_end_clean();

                        throw $e;
                    }
                }
            ),
            self::c(
                'stmt.debug_dump_params.no_params',
                $g,
                'PDOStatement::debugDumpParams()',
                'debugDumpParams() with nothing bound, exact captured-output byte comparison',
                static function (\PDO $p) {
                    $stmt = $p->prepare('SELECT 1');
                    ob_start();
                    try {
                        $stmt->debugDumpParams();

                        return ob_get_clean();
                    } catch (\Throwable $e) {
                        ob_end_clean();

                        throw $e;
                    }
                }
            ),
            self::c(
                'stmt.debug_dump_params.via_execute_positional',
                $g,
                'PDOStatement::debugDumpParams()',
                'debugDumpParams() after params supplied via execute() (positional array), exact captured-output byte comparison',
                static function (\PDO $p) {
                    $stmt = $p->prepare('SELECT ? AS a, ? AS b');
                    $stmt->execute(['x', 'y']);
                    ob_start();
                    try {
                        $stmt->debugDumpParams();

                        return ob_get_clean();
                    } catch (\Throwable $e) {
                        ob_end_clean();

                        throw $e;
                    }
                }
            ),
            self::c(
                'stmt.debug_dump_params.via_execute_named',
                $g,
                'PDOStatement::debugDumpParams()',
                'debugDumpParams() after params supplied via execute() (named array), exact captured-output byte comparison',
                static function (\PDO $p) {
                    $stmt = $p->prepare('SELECT :a AS a');
                    $stmt->execute([':a' => 'x']);
                    ob_start();
                    try {
                        $stmt->debugDumpParams();

                        return ob_get_clean();
                    } catch (\Throwable $e) {
                        ob_end_clean();

                        throw $e;
                    }
                }
            ),
            self::c(
                'stmt.debug_dump_params.rebind_int_string_alias',
                $g,
                'PDOStatement::debugDumpParams()',
                'debugDumpParams() after a positional param bound by int and rebound by its equal string key, exact captured-output byte comparison (audit F24)',
                static function (\PDO $p) {
                    $stmt = $p->prepare('SELECT * FROM probe_rows WHERE i = ?');
                    // Our shim treats int 1 and string '1' as the same
                    // parameter (PHP coerces them to one array key), so this
                    // dumps ONE param. Native pdo_sqlite registers two and
                    // dumps both — see the pinned deviation below.
                    $stmt->bindValue(1, 'a', \PDO::PARAM_STR);
                    $stmt->bindValue('1', 'b', \PDO::PARAM_STR);
                    ob_start();
                    try {
                        $stmt->debugDumpParams();

                        return ob_get_clean();
                    } catch (\Throwable $e) {
                        ob_end_clean();

                        throw $e;
                    }
                }
            ),
            self::c(
                'stmt.sqlite_create_function',
                $g,
                'PDO::sqliteCreateFunction()',
                'a real sqlite_create_function extension the guest cannot register a callback with',
                static fn (\PDO $p) => $p->sqliteCreateFunction('probe_fn', static fn ($x) => $x)
            ),
            self::c(
                'stmt.sqlite_create_aggregate',
                $g,
                'PDO::sqliteCreateAggregate()',
                'same reasoning as sqlite_create_function',
                static fn (\PDO $p) => $p->sqliteCreateAggregate(
                    'probe_agg',
                    static function (&$ctx, $rowNumber, $value) {
                        $ctx = ($ctx ?? 0) + $value;

                        return $ctx;
                    },
                    static fn ($ctx, $rowNumber) => $ctx ?? 0
                )
            ),
            self::c(
                'stmt.sqlite_create_collation',
                $g,
                'PDO::sqliteCreateCollation()',
                'same reasoning as sqlite_create_function',
                static fn (\PDO $p) => $p->sqliteCreateCollation('probe_col', static fn ($a, $b) => strcmp($a, $b))
            ),
            self::c(
                'stmt.pragma_column_count',
                $g,
                'PDOStatement::columnCount()',
                'columnCount() on an intercepted PRAGMA result',
                // M1 review round 3, R1 (FIX): the bridge's rows-mode PRAGMA
                // replies previously omitted `columns` entirely, so
                // columnCount() on `PRAGMA user_version` answered 0 where real
                // pdo_sqlite answers 1. columnCount() only (not fetchColumn())
                // deliberately, so this case cannot flake on the VALUE — our
                // user_version lives in __atoms_meta while the comparator has
                // its own native user_version, and those need not agree.
                static fn (\PDO $p) => $p->query('PRAGMA user_version')->columnCount()
            ),
        ];
    }
}
