<?php

/**
 * The \PDOStatement half of the documented-leaky `db()->pdo()` surface,
 * hardened from the phase-2 spike's `php/pdo_shim.php` and filled per
 * design §3 — see the F-numbered items cited inline below.
 *
 * The Atoms customer ABI declares `Database::pdo(): \PDO`, so the object handed
 * to customer code must genuinely be a \PDO / \PDOStatement — hence the
 * subclass. No driver backs this statement: every member either routes to the
 * {@see SqlBridge} (and therefore to `ctx.storage.sql` in the Durable Object),
 * hydrates purely from the buffered `{column: value}` rows the bridge already
 * returned, or throws {@see AtomsNotSupported}. Nothing is ever answered by
 * the carrier connection, and nothing silently no-ops.
 *
 * `$stmt->queryString` (consistent with Allowlist.php's `why`
 * and php/README.md): the
 * CONSTRUCTOR's own first write, to a property PHP has never seen written
 * before, succeeds unconditionally (asserted by the reflection tripwire's
 * allowlist entry A1) — the `try`/`catch` below is defensive, not because
 * this build is measured to need it. The genuine, in-guest-measured
 * difference is in POST-CONSTRUCTION EXTERNAL reassignment: on THIS php-wasm
 * 8.3 build, a second write from OUTSIDE the class is refused on BOTH
 * `AtomsStatement` and a real driver-backed `\PDOStatement`
 * (`stmt.queryString.is_writable` observes `match` in the differential
 * matrix, not the deviation an 8.4-desktop measurement predicted before this
 * build was ever exercised).
 *
 * Duplicate result-set columns (design §2.7, Branch A): `$this->columns`
 * carries the SOURCE-ORDER column names, duplicates preserved, from
 * `cursor.columnNames` (bridge.js) — the one place the wire's true arity
 * survives, since the `{column: value}` row maps have already collapsed
 * duplicate names (last value wins). Every mode that needs that true arity
 * consults it through THE one guard — {@see \Atoms\Cf\FetchMode::refuseDuplicateColumns()}
 * (audit F23) — refusing precisely, rather than silently answering with
 * the wrong arity, whenever it reports a duplicate.
 *
 * No declare(strict_types=1) — see the note in host.php.
 */

namespace Atoms\Cf;

class AtomsStatement extends \PDOStatement
{
    /** OR-able fetch-mode flag bits, stripped off before switching on the base mode. */
    const MODE_FLAGS = \PDO::FETCH_GROUP | \PDO::FETCH_UNIQUE | \PDO::FETCH_CLASSTYPE | \PDO::FETCH_PROPS_LATE;

    /** @var SqlBridge */
    private $bridge;

    /** @var string the SQL as prepared, with `:name` placeholders intact */
    private $sql;

    /** @var array<int|string, mixed> values bound with bindValue() */
    private $boundValues = [];

    /** @var array<int|string, mixed> references bound with bindParam(), read at execute() time (F-1) */
    private $boundRefs = [];

    /** @var array<int|string, int> PDO::PARAM_* given at bind time, keyed like boundValues/boundRefs */
    private $boundTypes = [];

    /**
     * @var list<int|string> keys in the order they were FIRST bound
     *     (bindValue()/bindParam()) — debugDumpParams() must
     *     list params in bind order, which boundValues/boundRefs alone
     *     cannot reconstruct once bindValue()/bindParam() calls interleave
     *     (a param moves between those two arrays on rebind but keeps its
     *     original array position; a SEPARATE array can't recover cross-array
     *     chronology). Measured against real PDO: rebinding an already-bound
     *     param does NOT move it — first-bind position wins — so this never
     *     removes a key once added.
     *
     *     A key counts as bound if it is present in either map (audit F24:
     *     PHP coerces '1' and 1 to the same array key, so a strict in_array()
     *     against THIS list would double-count a rebind through the other
     *     spelling).
     */
    private $boundOrder = [];

    /** @var array<int, mixed> bindColumn() targets, keyed by 0-based column index (F-2) */
    private $boundColumns = [];

    /** @var array<int, int> bindColumn() PDO::PARAM_* types, same keys as $boundColumns */
    private $boundColumnTypes = [];

    /**
     * @var list<array{param: int|string, type: int, paramno: int|null, positional: bool}>|null
     *     debugDumpParams() bookkeeping for the MOST RECENT execute($params)
     *     call ONLY — null means "derive from
     *     boundOrder/boundValues/boundRefs/boundTypes instead" (the
     *     bindValue()/bindParam() path unchanged from before). Deliberately a
     *     SEPARATE array from boundValues/boundTypes/boundOrder: those feed
     *     {@see currentBindings()}, which is F-12's pinned native-typed
     *     execute() binding path — this array must never be read from there,
     *     or debugDumpParams()'s bookkeeping-only PARAM_STR retype would
     *     silently become a REAL retype of what actually gets bound.
     */
    private $executeParamsDebug;

    /** @var list<array<string, mixed>> rows from the most recent execute() */
    private $rows = [];

    /** @var int cursor into $rows */
    private $cursor = 0;

    /** @var int rows_written reported by the most recent execute() */
    private $rowsWritten = 0;

    /**
     * @var list<string> source-order column names from the most recent
     *     execute(), duplicates preserved (Branch A, design §2.7)
     */
    private $columns = [];

    /** @var int one of the PDO::FETCH_* modes (optionally OR'd with MODE_FLAGS) this statement defaults to */
    private $fetchMode;

    /** @var list<mixed> extra args for the default mode (column index / class+ctorArgs / object) */
    private $fetchModeArgs = [];

    /**
     * This STATEMENT's own errorCode()/errorInfo() triple (design §3
     * F-27): set only by THIS statement's own execute() calls, never by
     * anything the connection does — the mirror of {@see AtomsPDO}'s own
     * connection-scoped triple. Real PDO scopes error state to the handle
     * that failed (measured: after a statement execute() fails with a
     * UNIQUE violation, `$stmt->errorCode()` is `'23000'` while
     * `$pdo->errorCode()` is still `'00000'`).
     *
     * @var array{0: string, 1: int|null, 2: string|null}
     */
    private $errorInfo = [SqlBridge::SQLSTATE_OK, null, null];

    /**
     * @param SqlBridge $bridge
     * @param string $sql
     * @param int $fetchMode default fetch mode inherited from the AtomsPDO
     */
    public function __construct(SqlBridge $bridge, $sql, $fetchMode = \PDO::FETCH_BOTH)
    {
        $this->bridge = $bridge;
        $this->sql = (string) $sql;
        $this->fetchMode = $fetchMode;

        try {
            // Best effort: PDO exposes queryString as a driver-owned read-only
            // property, so this write is allowed to fail.
            $this->queryString = $this->sql;
        } catch (\Throwable $ignored) {
        }
    }

    /**
     * @param array<int|string, mixed>|null $params
     * @return bool
     */
    public function execute(?array $params = null): bool
    {
        // PDO semantics: an array passed to execute() replaces bound values —
        // EXCEPT an empty array, which keeps them (design §3 F-13,
        // measured: real pdo_sqlite does not treat `execute([])` as "clear
        // everything").
        //
        // An array IS used as-is (native PHP types, no PARAM_STR coercion) —
        // a PERMANENT pinned deviation (design §3 F-12): real pdo_sqlite
        // stringifies EVERYTHING passed to execute(), which would push wide
        // integers through as TEXT literals and defeat inlineWideIntegers(),
        // putting conformance check 9's int64 exactness at the mercy of
        // column affinity. bindValue()/bindParam() go through {@see coerce()}
        // instead (F-11), which is where PDO's real per-type coercion lives.
        //
        // execute([]) is DELIBERATELY treated the SAME as a non-empty array
        // here (i.e. NOT specially routed through currentBindings()) —
        // design §3 F-13 proposed the opposite (keep previously bound
        // values) from an 8.4-desktop measurement, but measuring in-guest,
        // against THIS build's real pdo_sqlite (the referee, per this
        // milestone's whole premise) shows execute([]) does NOT reuse a
        // bound value: the placeholder comes back unbound (real SQLite's
        // own default is NULL for an unbound parameter). The desktop
        // measurement does not hold on this php-wasm/SQLite build, so the
        // measured-in-guest behaviour is what is implemented, not the
        // proposal.
        $bindings = $params === null ? $this->currentBindings() : $params;

        // debugDumpParams() bookkeeping ONLY — never
        // read by currentBindings() or anything that reaches the bridge, so
        // this cannot affect F-12's pinned native-typed execute() binding.
        // Recorded from $params (not $bindings — currentBindings() coerces,
        // and this must reflect what execute() was actually GIVEN) whenever
        // an explicit array is passed; left alone (falls back to the
        // bindValue()/bindParam() bookkeeping) on execute(null).
        if ($params !== null) {
            $this->recordExecuteParamsForDebug($params);
        } else {
            $this->executeParamsDebug = null;
        }

        list($sql, $positional) = NamedParams::rewrite($this->sql, $bindings);

        try {
            $result = $this->bridge->exec($sql, $positional, SqlBridge::MODE_ROWS);
        } catch (\PDOException $e) {
            $this->errorInfo = is_array($e->errorInfo) && $e->errorInfo !== []
                ? $e->errorInfo
                : ['HY000', null, $e->getMessage()];
            throw $e;
        }

        $this->errorInfo = [SqlBridge::SQLSTATE_OK, null, null];
        $this->rows = $result['rows'];
        $this->columns = $result['columns'];
        $this->rowsWritten = $result['rows_written'];
        $this->cursor = 0;

        // A successful STATEMENT execute() does NOT
        // reset the CONNECTION's errorCode()/errorInfo() triple — measured
        // directly against real pdo_sqlite (err.connection_state_after_
        // successful_statement_execute). The connection's triple changes
        // only through its OWN direct operations (exec()/query()/
        // lastInsertId()/prepare()/quote()/getAttribute(), all in
        // AtomsPDO); a statement's execute() — success OR failure — never
        // touches it, symmetric with F-27's existing "failure does not leak
        // to the connection" rule.

        return true;
    }

    /**
     * debugDumpParams() bookkeeping for an execute($params) array
     * (measured): real PDO registers every element execute()
     * was given, REPLACING whatever bindValue()/bindParam() had bound
     * before (matching this shim's own actual-binding semantics — an array
     * passed to execute() already replaces bound values, design §3 F-13)
     * and reports EVERY one of them as PARAM_STR regardless of its bind-time
     * type or PHP type — the debug-dump mirror of F-12's real
     * stringify-everything behaviour, kept OUT of actual binding on purpose
     * (see F-12's docblock above). A NAMED key arriving via execute()
     * (`:name` or bare `name`) is measured to dump with `paramno` = its
     * 0-based position in the array, NOT `-1` — `-1` is what a NAMED param
     * bound directly via bindValue()/bindParam() dumps with instead; this is
     * a genuine, measured difference between the two entry points, not an
     * inconsistency to paper over.
     *
     * @param array<int|string, mixed> $params
     */
    private function recordExecuteParamsForDebug(array $params)
    {
        $positional = true;
        foreach ($params as $key => $ignored) {
            if (!is_int($key)) {
                $positional = false;
                break;
            }
        }

        $out = [];
        $index = 0;
        foreach ($params as $key => $ignored) {
            $out[] = [
                'param' => $key,
                'type' => \PDO::PARAM_STR,
                'paramno' => $index,
                'positional' => $positional,
            ];
            $index++;
        }

        $this->executeParamsDebug = $out;
    }

    /**
     * Merge bindValue() values and bindParam() references (read NOW, at
     * execute() time — design §3 F-1) into one bindings array, each coerced
     * per its bind-time PDO::PARAM_* type (F-11).
     *
     * @return array<int|string, mixed>
     */
    private function currentBindings()
    {
        $out = [];

        foreach ($this->boundValues as $param => $value) {
            $out[$param] = self::coerce($value, $this->boundTypes[$param] ?? \PDO::PARAM_STR);
        }

        foreach ($this->boundRefs as $param => &$ref) {
            $out[$param] = self::coerce($ref, $this->boundTypes[$param] ?? \PDO::PARAM_STR);
        }
        unset($ref);

        return $out;
    }

    /**
     * Bind-time value coercion, per PDO::PARAM_* (design §3 F-11,
     * measured): PARAM_INT '42'->42, '42abc'->42, 3.5->3 (PHP's own (int)
     * cast already has exactly this leading-numeric truncation semantics);
     * PARAM_BOOL true->1, ''->0 (bool cast then int); PARAM_NULL ignores the
     * value entirely; PARAM_STR (the untyped default too) 7->'7', 1.0->'1',
     * 3.5->'3.5' (PHP's own (string) cast already matches).
     *
     * @param mixed $value
     * @param int $type
     * @return mixed
     */
    private static function coerce($value, $type)
    {
        switch ($type) {
            case \PDO::PARAM_NULL:
                return null;

            case \PDO::PARAM_BOOL:
                return ((bool) $value) ? 1 : 0;

            case \PDO::PARAM_INT:
                return (int) $value;

            default:
                return (string) $value;
        }
    }

    /**
     * @param int|string $param 1-based position or `:name` / `name`
     * @param mixed $value
     * @param int $type
     * @return bool
     */
    public function bindValue(string|int $param, $value, $type = \PDO::PARAM_STR): bool
    {
        // Measured: real PDO validates an int
        // $param is a POSITION (1-based; SQLite has no ordinal 0) and raises
        // a \ValueError, not a \PDOException, before considering $type or
        // $value at all. `string|int $param` above (matching real PDOStatement's
        // own declared type) additionally means a $param of neither type is
        // now a \TypeError at the call boundary instead of silently becoming
        // a bogus array key debugDumpParams() would later have to render.
        if (is_int($param) && $param < 1) {
            throw new \ValueError('PDOStatement::bindValue(): Argument #1 ($param) must be greater than or equal to 1');
        }

        if ($type === \PDO::PARAM_LOB) {
            throw new AtomsNotSupported(
                'PDO::PARAM_LOB',
                'Binary values do not cross the MVP JSON bridge; store them base64-encoded as text.'
            );
        }

        // A param already present in either map is a rebind, not a new
        // binding: PHP coerces '1' and 1 to the same array key, so checking
        // the maps (not a strict in_array against boundOrder) is what makes
        // a rebind through the other spelling update in place rather than
        // dump twice (audit F24).
        if (!array_key_exists($param, $this->boundValues) && !array_key_exists($param, $this->boundRefs)) {
            $this->boundOrder[] = $param;
        }

        unset($this->boundRefs[$param]);
        $this->boundValues[$param] = $value;
        $this->boundTypes[$param] = $type;

        return true;
    }

    /**
     * By-reference binding (design §3 F-1): no driver handle is needed to
     * honour this — the reference is stored and read at execute() time,
     * which is when real PDO reads it too (measured: changing the variable
     * AFTER bindParam() but BEFORE execute() is reflected in the bound
     * value).
     *
     * @param int|string $param
     * @param mixed $var
     * @param int $type
     * @param int $maxLength
     * @param mixed $driverOptions
     * @return bool
     */
    #[\ReturnTypeWillChange]
    public function bindParam(string|int $param, &$var, $type = \PDO::PARAM_STR, $maxLength = 0, $driverOptions = null)
    {
        // Same position validation as bindValue()
        // above, and for the same reason.
        if (is_int($param) && $param < 1) {
            throw new \ValueError('PDOStatement::bindParam(): Argument #1 ($param) must be greater than or equal to 1');
        }

        if ($type === \PDO::PARAM_LOB) {
            throw new AtomsNotSupported(
                'PDO::PARAM_LOB',
                'Binary values do not cross the MVP JSON bridge; store them base64-encoded as text.'
            );
        }

        // Same rebind rule as bindValue() above.
        if (!array_key_exists($param, $this->boundValues) && !array_key_exists($param, $this->boundRefs)) {
            $this->boundOrder[] = $param;
        }

        unset($this->boundValues[$param]);
        $this->boundRefs[$param] =& $var;
        $this->boundTypes[$param] = $type;

        return true;
    }

    /**
     * Store a bindColumn() target (design §3 F-2). Resolved to a 0-based
     * column index NOW, against `$this->columns` (Branch A) — an unknown
     * column throws AT BIND TIME, matching real pdo_sqlite (measured:
     * `PDOException HY000 "Did not find column name '...' in the defined
     * columns; it will not be bound"`), not deferred to the next fetch().
     *
     * @param int|string $column
     * @param mixed $var
     * @param int $type
     * @param int $maxLength
     * @param mixed $driverOptions
     * @return bool
     */
    #[\ReturnTypeWillChange]
    public function bindColumn($column, &$var, $type = \PDO::PARAM_STR, $maxLength = 0, $driverOptions = null)
    {
        $index = $this->resolveColumnIndex($column);

        $this->boundColumns[$index] =& $var;
        $this->boundColumnTypes[$index] = $type;

        return true;
    }

    /**
     * @param int|string $column 1-based position (int) or column name (string)
     * @return int 0-based index into $this->columns
     * @throws \PDOException when the column is not in the current result set
     */
    private function resolveColumnIndex($column)
    {
        if (is_int($column)) {
            $index = $column - 1;
            if ($index >= 0 && array_key_exists($index, $this->columns)) {
                return $index;
            }

            throw new \PDOException(sprintf(
                "SQLSTATE[HY000]: General error: Did not find column number %d in the defined columns; "
                . "it will not be bound",
                $column
            ));
        }

        $name = (string) $column;
        $index = array_search($name, $this->columns, true);
        if ($index !== false) {
            return $index;
        }

        throw new \PDOException(sprintf(
            "SQLSTATE[HY000]: General error: Did not find column name '%s' in the defined columns; "
            . "it will not be bound",
            $name
        ));
    }

    /**
     * @param int $mode
     * @param int $cursorOrientation
     * @param int $cursorOffset
     * @return mixed the row, or false when the cursor is exhausted
     */
    #[\ReturnTypeWillChange]
    public function fetch($mode = \PDO::FETCH_DEFAULT, $cursorOrientation = \PDO::FETCH_ORI_NEXT, $cursorOffset = 0)
    {
        if ($cursorOrientation !== \PDO::FETCH_ORI_NEXT) {
            // Design §3 F-9, the decisive measurement: on a forward-only
            // cursor real pdo_sqlite IGNORES the orientation entirely
            // (FETCH_ORI_PRIOR returned the NEXT row; FIRST/LAST/ABS/REL
            // either repeated that or returned false once exhausted).
            // Implementing scrolling over our buffer would either diverge
            // from the driver we claim to be, or claim a capability the
            // comparator can never verify. Refusing is strictly more honest
            // than the driver's own silent wrong-row behaviour.
            throw new AtomsNotSupported(
                'PDOStatement::fetch() with a scrollable cursor',
                'Real pdo_sqlite ignores the orientation on a forward-only cursor rather than honouring it '
                . '(measured); this runtime refuses instead of reproducing a silent wrong answer.'
            );
        }

        if ($mode === \PDO::FETCH_FUNC) {
            // Design §3 F-7 (measured): real PDO's fetch() (not
            // fetchAll()) with FETCH_FUNC is a ValueError, not a PDOException
            // — a different exception family, and the classifier requires
            // family fidelity for a refusal pair.
            throw new \ValueError('Can only use PDO::FETCH_FUNC in PDOStatement::fetchAll()');
        }

        list($base, $flags, $args) = $this->resolveMode($mode);

        if (!array_key_exists($this->cursor, $this->rows)) {
            return false;
        }

        $row = $this->rows[$this->cursor];
        $this->cursor++;

        return $this->hydrateOneRow($row, $base, $flags, $args);
    }

    /**
     * @param int $mode
     * @param mixed ...$args
     * @return array<int|string, mixed>
     */
    #[\ReturnTypeWillChange]
    public function fetchAll($mode = \PDO::FETCH_DEFAULT, ...$args)
    {
        $explicitMode = $mode;

        if ($mode === \PDO::FETCH_DEFAULT) {
            $mode = $this->fetchMode;
            $args = $this->fetchModeArgs;
        }

        $base = $mode & ~self::MODE_FLAGS;
        $flags = $mode & self::MODE_FLAGS;
        $hasUnique = ($mode & \PDO::FETCH_UNIQUE) === \PDO::FETCH_UNIQUE;
        $hasGroup = !$hasUnique && ($mode & \PDO::FETCH_GROUP) === \PDO::FETCH_GROUP;

        // Design §3 F-5 (measured E13): fetchAll(FETCH_INTO, $obj) is an
        // ArgumentCountError in real PDO — FETCH_INTO only makes sense for a
        // single row at a time. Checked on the EXPLICIT mode argument only:
        // a connection/statement default of FETCH_INTO (from setFetchMode())
        // reaching fetchAll() with no arguments is not this case.
        if ($explicitMode !== \PDO::FETCH_DEFAULT && $base === \PDO::FETCH_INTO) {
            throw new \ArgumentCountError(sprintf(
                'PDOStatement::fetchAll() expects exactly 1 argument for the fetch mode provided, %d given',
                1 + count($args)
            ));
        }

        // Design §3 F-7 (measured): fetchAll()-only.
        if ($base === \PDO::FETCH_FUNC) {
            if (count($args) !== 1 || !is_callable($args[0])) {
                throw new AtomsNotSupported(
                    'PDOStatement::fetchAll(FETCH_FUNC, ...)',
                    'FETCH_FUNC requires exactly one callable argument.'
                );
            }
            // FETCH_FUNC calls $fn with EVERY column
            // as a positional arg (`...array_values($row)`) — under
            // duplicate column names the collapsed `$row` has fewer entries
            // than the true arity, so the callable would be invoked with too
            // few (and wrong) positional values instead of the true row.
            FetchMode::refuseDuplicateColumns('PDO::FETCH_FUNC', $this->columns);

            $fn = $args[0];
            $remaining = array_slice($this->rows, $this->cursor);
            $this->cursor = count($this->rows);

            $out = [];
            foreach ($remaining as $row) {
                $out[] = $fn(...array_values($row));
            }

            return $out;
        }

        // Design §3 F-26: real requires EXACTLY two columns and
        // throws (measured: `PDOException HY000 "...requires the result set
        // to contain exactly 2 columns."`); the errorInfo triple is set (and
        // getCode() is the SQLSTATE, matching real — measured) so a caller
        // inspecting either gets a real answer, not an invented one.
        if ($base === \PDO::FETCH_KEY_PAIR) {
            if (count($this->columns) !== 2) {
                $message = 'SQLSTATE[HY000]: PDO::FETCH_KEY_PAIR fetch mode requires the result set to '
                    . 'contain exactly 2 columns.';

                throw new BridgeSqlException($message, ['HY000', null, $message]);
            }

            // Two DISTINCT columns can still share a
            // name (`SELECT a.k AS k, b.k AS k ...`) — the count()===2 check
            // above says nothing about that. FETCH_KEY_PAIR pairs the row's
            // FIRST value with its SECOND positionally; once the wire's
            // `{column: value}` collapse has folded two same-named columns
            // into one key (last value wins), `array_values($row)` no longer
            // has two entries to pair, so refuse instead of pairing a
            // collapsed value with itself or with null.
            FetchMode::refuseDuplicateColumns('PDO::FETCH_KEY_PAIR', $this->columns);

            $remaining = array_slice($this->rows, $this->cursor);
            $this->cursor = count($this->rows);

            $out = [];
            foreach ($remaining as $row) {
                $values = array_values($row);
                $out[$values[0]] = $values[1];
            }

            return $out;
        }

        // Design §3 F-8 (measured E13, and directly against the in-guest
        // comparator): FETCH_GROUP groups by the FIRST
        // column's value into a LIST per key (several rows can share a
        // group); FETCH_UNIQUE keys by the first column directly, ONE value
        // per key (the caller is asserting uniqueness). Both reshape the
        // REMAINING columns per the base mode (ASSOC/NUM/BOTH/COLUMN).
        if ($hasUnique || $hasGroup) {
            if (!in_array($base, [\PDO::FETCH_ASSOC, \PDO::FETCH_NUM, \PDO::FETCH_BOTH, \PDO::FETCH_COLUMN], true)) {
                throw new AtomsNotSupported(
                    sprintf('PDOStatement::fetchAll() with FETCH_GROUP/FETCH_UNIQUE and mode %d', $base),
                    'Only FETCH_ASSOC, FETCH_NUM, FETCH_BOTH and FETCH_COLUMN combine with FETCH_GROUP/FETCH_UNIQUE.'
                );
            }
            // The grouping/unique KEY is
            // `$values[0]` — the FIRST column, always taken positionally —
            // regardless of $base, including FETCH_ASSOC, so this refuses
            // for EVERY base mode FETCH_GROUP/FETCH_UNIQUE can combine with,
            // strictly more than the NEEDS_TRUE_ARITY table checks for the
            // plain per-row modes elsewhere.
            FetchMode::refuseDuplicateColumns($hasUnique ? 'PDO::FETCH_UNIQUE' : 'PDO::FETCH_GROUP', $this->columns);

            $remaining = array_slice($this->rows, $this->cursor);
            $this->cursor = count($this->rows);

            $out = [];
            foreach ($remaining as $row) {
                $values = array_values($row);
                $key = $values[0] ?? null;
                $restAssoc = array_slice($row, 1, null, true);
                $restValues = array_slice($values, 1);

                switch ($base) {
                    case \PDO::FETCH_ASSOC:
                        $shaped = $restAssoc;
                        break;
                    case \PDO::FETCH_NUM:
                        $shaped = array_values($restValues);
                        break;
                    case \PDO::FETCH_COLUMN:
                        $shaped = $restValues[0] ?? null;
                        break;
                    default: // FETCH_BOTH
                        $shaped = [];
                        $i = 0;
                        foreach ($restAssoc as $col => $val) {
                            $shaped[$col] = $val;
                            $shaped[$i] = $val;
                            $i++;
                        }
                }

                if ($hasUnique) {
                    $out[$key] = $shaped;
                } else {
                    $out[$key][] = $shaped;
                }
            }

            return $out;
        }

        $remaining = array_slice($this->rows, $this->cursor);
        $this->cursor = count($this->rows);

        $out = [];
        foreach ($remaining as $row) {
            $out[] = $this->hydrateOneRow($row, $base, $flags, $args);
        }

        return $out;
    }

    /**
     * Shared per-row shaping for {@see fetch()} and {@see fetchAll()}'s
     * default path — one place that dispatches every fetch mode this shim
     * implements, so the two entry points can never disagree.
     *
     * @param array<string, mixed> $row
     * @param int $base mode with MODE_FLAGS already stripped
     * @param int $flags the OR'd-in FETCH_CLASSTYPE/FETCH_PROPS_LATE bits (0 elsewhere)
     * @param list<mixed> $args
     * @return mixed
     */
    private function hydrateOneRow(array $row, $base, $flags, array $args)
    {
        switch ($base) {
            case \PDO::FETCH_BOUND:
                // Design §3 F-2 (measured): values arrive per the bound
                // column's own PDO::PARAM_* type; default PARAM_STR
                // stringifies.
                //
                // `$values[$index]` below reads the
                // COLLAPSED row positionally, by the ORIGINAL 0-based index
                // bindColumn() resolved against `$this->columns` — once a
                // duplicate name has folded two columns into one entry, that
                // index no longer lines up with `$values`, for a
                // bindColumn()-BY-INDEX target and (despite
                // resolveColumnIndex() correctly finding the FIRST
                // occurrence for a bindColumn()-BY-NAME target) for a
                // BY-NAME target just the same, since the value at that
                // resolved index is still read from the same collapsed
                // array. Refuse unconditionally whenever ANY duplicate
                // exists in this result set, not only when the specific
                // bound column is one of the duplicates — a later positional
                // shift from an EARLIER duplicate would silently misalign
                // every bound column after it.
                FetchMode::refuseDuplicateColumns('PDOStatement::bindColumn()/FETCH_BOUND', $this->columns);

                $values = array_values($row);
                foreach ($this->boundColumns as $index => &$ref) {
                    $value = array_key_exists($index, $values) ? $values[$index] : null;
                    $type = $this->boundColumnTypes[$index] ?? \PDO::PARAM_STR;
                    $ref = $value === null ? null : self::coerce($value, $type);
                }
                unset($ref);

                return true;

            case \PDO::FETCH_NAMED:
                // Design §3 F-6: with UNIQUE columns this is measured
                // byte-identical to FETCH_ASSOC. With duplicates, the values
                // that would need grouping are already gone from the wire
                // (last-wins collapse) — refuse rather than answer wrong.
                FetchMode::refuseDuplicateColumns('PDO::FETCH_NAMED', $this->columns);

                return $row;

            case \PDO::FETCH_CLASS:
                $classType = ($flags & \PDO::FETCH_CLASSTYPE) === \PDO::FETCH_CLASSTYPE;
                $propsLate = ($flags & \PDO::FETCH_PROPS_LATE) === \PDO::FETCH_PROPS_LATE;

                // FETCH_CLASSTYPE consumes the FIRST
                // column, positionally (`hydrateObject()`'s own
                // `$values[0]`), as the class name — the same positional
                // fragility as FETCH_BOUND above, specifically for the one
                // column whose value picks WHICH CLASS gets instantiated.
                if ($classType) {
                    FetchMode::refuseDuplicateColumns('PDO::FETCH_CLASSTYPE', $this->columns);
                }

                return $this->hydrateObject($row, $args[0] ?? 'stdClass', $args[1] ?? null, $propsLate, $classType);

            case \PDO::FETCH_INTO:
                if (!isset($args[0]) || !is_object($args[0])) {
                    throw new AtomsNotSupported(
                        'PDOStatement fetch mode FETCH_INTO without an object',
                        'setFetchMode(PDO::FETCH_INTO, $object) must be called first.'
                    );
                }

                return $this->hydrateInto($row, $args[0]);

            case \PDO::FETCH_COLUMN:
                FetchMode::refuseDuplicateColumns(\PDO::FETCH_COLUMN, $this->columns);
                $index = $args[0] ?? 0;
                $values = array_values($row);

                return array_key_exists($index, $values) ? $values[$index] : null;
        }

        FetchMode::refuseDuplicateColumns($base, $this->columns);

        return FetchMode::shape($row, FetchMode::assertSupported($base, 'PDOStatement fetch mode'));
    }

    /**
     * newInstanceWithoutConstructor() -> assign declared props (private/
     * protected via reflection; unmatched columns become dynamic public
     * props) -> invoke the constructor — UNLESS FETCH_PROPS_LATE, which
     * reverses the last two steps (design §3 F-3/F-4, measured: a class
     * with promoted constructor properties came back NULL because the ctor's
     * OWN defaults overwrote what the hydrator had just written — so props
     * FIRST is the one that must run by default).
     *
     * @param array<string, mixed> $row
     * @param string $class
     * @param list<mixed>|null $ctorArgs
     * @param bool $propsLate
     * @param bool $classType consume the first column as the class name (FETCH_CLASSTYPE)
     * @return object
     */
    private function hydrateObject(array $row, $class, $ctorArgs, $propsLate, $classType)
    {
        if ($classType) {
            $values = array_values($row);
            $class = (string) ($values[0] ?? 'stdClass');
            $row = array_slice($row, 1, null, true);

            // FETCH_CLASSTYPE's class name is DATA, not a customer-given
            // literal — measured directly against the in-guest comparator:
            // when the value in that column does not resolve to a real
            // class, real PDO silently falls back to stdClass (the classname
            // column is still consumed/dropped) rather than throwing. This
            // differs from an explicit fetchAll(FETCH_CLASS, 'Bad') call,
            // which setFetchMode() already validates eagerly.
            if (!class_exists($class)) {
                $class = 'stdClass';
            }
        }

        if (!is_string($class) || !class_exists($class)) {
            throw new \PDOException(sprintf(
                'SQLSTATE[HY000]: General error: could not find user-supplied class "%s"',
                is_string($class) ? $class : gettype($class)
            ));
        }

        $reflection = new \ReflectionClass($class);
        $obj = $reflection->newInstanceWithoutConstructor();

        $assign = function () use ($reflection, $obj, $row) {
            foreach ($row as $column => $value) {
                $name = (string) $column;
                if ($reflection->hasProperty($name)) {
                    $property = $reflection->getProperty($name);
                    $property->setAccessible(true);
                    $property->setValue($obj, $value);
                } else {
                    // Unmatched column: real PDO makes it a dynamic public
                    // property (measured E13).
                    $obj->$name = $value;
                }
            }
        };

        $invokeCtor = static function () use ($reflection, $obj, $ctorArgs) {
            $ctor = $reflection->getConstructor();
            if ($ctor === null) {
                return;
            }
            if ($ctorArgs === null) {
                $ctor->invoke($obj);
            } else {
                $ctor->invokeArgs($obj, array_values($ctorArgs));
            }
        };

        if ($propsLate) {
            $invokeCtor();
            $assign();
        } else {
            $assign();
            $invokeCtor();
        }

        return $obj;
    }

    /**
     * FETCH_INTO: hydrate the SAME pre-constructed object every call — no
     * constructor invocation, unlike FETCH_CLASS (measured: the object's own
     * state from its original construction is left alone apart from the
     * columns this row supplies).
     *
     * @param array<string, mixed> $row
     * @param object $obj
     * @return object the SAME object (measured: real PDO returns object
     *     identity, not a copy)
     */
    private function hydrateInto(array $row, $obj)
    {
        $reflection = new \ReflectionClass($obj);

        foreach ($row as $column => $value) {
            $name = (string) $column;
            if ($reflection->hasProperty($name)) {
                $property = $reflection->getProperty($name);
                $property->setAccessible(true);
                $property->setValue($obj, $value);
            } else {
                $obj->$name = $value;
            }
        }

        return $obj;
    }

    /**
     * @param string|null $class
     * @param array<int, mixed>|null $constructorArgs
     * @return object|false
     */
    #[\ReturnTypeWillChange]
    public function fetchObject($class = 'stdClass', $constructorArgs = null)
    {
        if (!array_key_exists($this->cursor, $this->rows)) {
            return false;
        }

        $row = $this->rows[$this->cursor];
        $this->cursor++;

        return $this->hydrateObject($row, $class === null ? 'stdClass' : $class, $constructorArgs, false, false);
    }

    /**
     * @param int $column 0-based column index
     * @return mixed the value, or false when the cursor is exhausted
     */
    #[\ReturnTypeWillChange]
    public function fetchColumn($column = 0)
    {
        // This entry point shapes a result
        // exactly like hydrateOneRow()'s FETCH_COLUMN case, but reaches
        // the wire directly — without this guard it would silently answer
        // the wrong column's value under duplicates instead of refusing
        // like every other FETCH_COLUMN path. The guard
        // lives in ONE place (FetchMode::refuseDuplicateColumns()), so an
        // entry point can only skip it by never calling it at all.
        FetchMode::refuseDuplicateColumns(\PDO::FETCH_COLUMN, $this->columns);

        if (!array_key_exists($this->cursor, $this->rows)) {
            return false;
        }

        $values = array_values($this->rows[$this->cursor]);

        // Design §3 F-21 (measured): real PDO throws
        // `ValueError: Invalid column index` for an out-of-range index;
        // silently returning null instead would be a wrong answer hiding
        // inside a surface that must stay honest.
        if (!array_key_exists($column, $values)) {
            throw new \ValueError('Invalid column index');
        }

        $this->cursor++;

        return $values[$column];
    }

    /**
     * PDO's contract: affected rows for INSERT/UPDATE/DELETE, and undefined
     * (SQLite reports a stale sqlite3_changes()) for SELECT. This reports
     * exactly what real pdo_sqlite's sqlite3_changes() would (bridge.js runs
     * `SELECT changes()` on the same connection right after the statement).
     */
    public function rowCount(): int
    {
        return $this->rowsWritten;
    }

    /**
     * Design §3 F-29 (measured): the true column count from
     * `$this->columns` (Branch A) — exact even for a zero-row result (real
     * pdo_sqlite reports the real column count for an empty SELECT; a
     * row-keys-based implementation would answer 0, wrongly), and exact
     * under duplicate column names too (arity preserved, unlike a fetched row).
     */
    public function columnCount(): int
    {
        return count($this->columns);
    }

    public function closeCursor(): bool
    {
        $this->rows = [];
        $this->cursor = 0;

        return true;
    }

    /**
     * Design §3 F-17: validates and stores the mode (+ its args)
     * that FETCH_DEFAULT resolves to on every subsequent fetch()/fetchAll()/
     * getIterator() call — the SAME machinery {@see AtomsPDO::query()} uses
     * for its own fetch-mode arguments (F-14), since query() is defined
     * purely in terms of this method.
     *
     * @param int $mode
     * @param mixed ...$args
     * @return bool
     */
    #[\ReturnTypeWillChange]
    public function setFetchMode($mode, ...$args)
    {
        $base = $mode & ~self::MODE_FLAGS;

        switch ($base) {
            case \PDO::FETCH_ASSOC:
            case \PDO::FETCH_NUM:
            case \PDO::FETCH_BOTH:
            case \PDO::FETCH_OBJ:
            case \PDO::FETCH_BOUND:
            case \PDO::FETCH_NAMED:
                if ($args !== []) {
                    throw new AtomsNotSupported(
                        sprintf('PDOStatement::setFetchMode() with extra arguments for mode %d', $base),
                        'This mode takes no extra arguments.'
                    );
                }
                break;

            case \PDO::FETCH_COLUMN:
                if (count($args) > 1 || (isset($args[0]) && !is_int($args[0]))) {
                    throw new AtomsNotSupported(
                        'PDOStatement::setFetchMode(FETCH_COLUMN, ...) with invalid arguments',
                        'FETCH_COLUMN takes at most one integer column-index argument.'
                    );
                }
                break;

            case \PDO::FETCH_CLASS:
                if (!isset($args[0]) || !is_string($args[0])) {
                    throw new \TypeError('PDOStatement::setFetchMode(): Argument #2 ($className) must be of type string');
                }
                if (!class_exists($args[0])) {
                    throw new \PDOException(sprintf(
                        'SQLSTATE[HY000]: General error: could not find user-supplied class "%s"',
                        $args[0]
                    ));
                }
                if (isset($args[1]) && $args[1] !== null && !is_array($args[1])) {
                    throw new \TypeError('PDOStatement::setFetchMode(): Argument #3 ($constructorArgs) must be of type ?array');
                }
                break;

            case \PDO::FETCH_INTO:
                if (!isset($args[0]) || !is_object($args[0])) {
                    throw new \TypeError('PDOStatement::setFetchMode(): Argument #2 ($object) must be of type object');
                }
                break;

            default:
                throw new AtomsNotSupported(
                    sprintf('PDOStatement::setFetchMode() with mode %d', $mode),
                    'Supported modes are FETCH_ASSOC, FETCH_NUM, FETCH_BOTH, FETCH_OBJ, FETCH_BOUND, FETCH_NAMED, '
                    . 'FETCH_COLUMN, FETCH_CLASS and FETCH_INTO.'
                );
        }

        $this->fetchMode = $mode;
        $this->fetchModeArgs = $args;

        return true;
    }

    /**
     * @return array{0: int, 1: int, 2: list<mixed>} [base mode, flag bits, args]
     */
    private function resolveMode($mode)
    {
        if ($mode === \PDO::FETCH_DEFAULT) {
            return [$this->fetchMode & ~self::MODE_FLAGS, $this->fetchMode & self::MODE_FLAGS, $this->fetchModeArgs];
        }

        return [$mode & ~self::MODE_FLAGS, $mode & self::MODE_FLAGS, []];
    }

    /**
     * Buffered result set, so foreach over the statement is exact. Uses the
     * connection/statement default fetch mode, same as a plain fetchAll().
     */
    public function getIterator(): \Iterator
    {
        return new \ArrayIterator($this->fetchAll());
    }

    /**
     * @return string|null this STATEMENT's own SQLSTATE (design §3 F-27)
     */
    #[\ReturnTypeWillChange]
    public function errorCode()
    {
        return $this->errorInfo[0];
    }

    /**
     * @return array{0: string, 1: int|null, 2: string|null}
     */
    #[\ReturnTypeWillChange]
    public function errorInfo()
    {
        return $this->errorInfo;
    }

    // ---------------------------------------------------------------------
    // Deliberately unsupported. Each throws; none of them silently no-ops or
    // falls through to a carrier connection.
    // ---------------------------------------------------------------------

    /**
     * @param int $name
     * @return mixed
     */
    #[\ReturnTypeWillChange]
    public function getAttribute($name)
    {
        // Design §3 F-23 (measured: real pdo_sqlite refuses this too,
        // SQLSTATE[IM001]) — AtomsNotSupported's third constructor arg
        // makes ours carry the SAME SQLSTATE, so this is refused_by_both
        // with a matching triple rather than needing a loosened comparison.
        throw new AtomsNotSupported(
            'PDOStatement::getAttribute()',
            'There is no driver-owned statement handle to read attributes from — real pdo_sqlite refuses this too.',
            'IM001'
        );
    }

    /**
     * @param int $attribute
     * @param mixed $value
     * @return bool
     */
    #[\ReturnTypeWillChange]
    public function setAttribute($attribute, $value)
    {
        throw new AtomsNotSupported(
            'PDOStatement::setAttribute()',
            'There is no driver-owned statement handle to configure — real pdo_sqlite refuses this too.',
            'IM001'
        );
    }

    /**
     * @param int $column
     * @return array<string, mixed>|false
     */
    #[\ReturnTypeWillChange]
    public function getColumnMeta($column)
    {
        throw new AtomsNotSupported(
            'PDOStatement::getColumnMeta()',
            'Even the column NAMES this bridge can report are not enough — native_type, pdo_type, flags, '
            . 'table, len and precision would all have to be invented.'
        );
    }

    /**
     * @return bool
     */
    #[\ReturnTypeWillChange]
    public function nextRowset()
    {
        throw new AtomsNotSupported(
            'PDOStatement::nextRowset()',
            'The bridge returns a single result set per statement — real pdo_sqlite refuses this too.',
            'IM001'
        );
    }

    /**
     * Build real PDO's exact `debugDumpParams()` text (design §3 F-10)
     * and send it through the log door at debug level. Real PDO writes to
     * stdout, which in this runtime is not delivered anywhere useful (there
     * is one `php.run()` per residency that never returns); ADDITIONALLY,
     * when an output buffer is active, this echoes into it — a caller who
     * wraps the call in `ob_start()`/`ob_get_clean()` (as the conformance
     * matrix does) gets PDO's exact text back, byte for byte.
     *
     * Positional numbering, measured directly against real pdo_sqlite
     * (PHP 8.4/8.3, in-guest comparator): PDO does not
     * count `?` occurrences at all — a positional bindValue($n, ...)'s `$n`
     * is 1-based across SQLite's OWN placeholder ordinals (named AND
     * positional placeholders share one ordinal sequence, left to right in
     * the SQL text), and debugDumpParams() prints `paramno` = `$n - 1`
     * verbatim — e.g. on `... WHERE id = :id AND v = ?` the sole `?` is
     * SQLite ordinal 2, so `bindValue(2, ...)` is the only valid call for
     * it and it dumps as `Position #1`/`paramno=1` (2 - 1). No SQL parsing
     * is needed to reproduce this: the bound key already IS the ordinal.
     *
     * This ordinal fact is used ONLY for the bindValue()/bindParam()
     * numbering above — it does NOT mean this runtime implements real PDO's
     * general ordinal placeholder model for MIXING named and positional
     * placeholders at execute() time: a statement
     * bound with both a `:name` and a positional key is refused
     * (`NamedParams::rewrite()`, HY093 — see `bind.mixed_named_and_positional`,
     * pinned `refused_by_us`), not resolved via SQLite's shared ordinal
     * sequence.
     *
     * Measured: params supplied via an execute()
     * array are ALSO dumped. `$this->executeParamsDebug`
     * (set by {@see recordExecuteParamsForDebug()}) takes over the WHOLE
     * dump when the most recent `execute()` call was given an explicit
     * array — matching this shim's own actual-binding semantics, where an
     * array passed to execute() replaces bound values entirely (design §3
     * F-13) — and every one of its entries reports PARAM_STR and a REAL
     * `paramno` (never `-1`, even for a named key), both measured against
     * real PDO. `bindValue()`/`bindParam()`'s own bookkeeping is used only
     * when the statement's most recent `execute()` call was `execute(null)`.
     *
     * @return void
     */
    #[\ReturnTypeWillChange]
    public function debugDumpParams()
    {
        $entries = [];

        if ($this->executeParamsDebug !== null) {
            $entries = $this->executeParamsDebug;
        } else {
            foreach ($this->boundOrder as $param) {
                if (array_key_exists($param, $this->boundValues) || array_key_exists($param, $this->boundRefs)) {
                    $entries[] = [
                        'param' => $param,
                        'type' => $this->boundTypes[$param] ?? \PDO::PARAM_STR,
                        'paramno' => null, // bindValue()/bindParam()'s own numbering, computed below
                        'positional' => is_int($param),
                    ];
                }
            }
        }

        $text = 'SQL: [' . strlen($this->sql) . '] ' . $this->sql . "\n";
        $text .= 'Params:  ' . count($entries) . "\n";

        foreach ($entries as $entry) {
            $param = $entry['param'];
            $type = $entry['type'];

            if ($entry['positional']) {
                $paramno = $entry['paramno'] !== null ? $entry['paramno'] : ((int) $param - 1);
                $text .= 'Key: Position #' . $paramno . ":\n";
                $text .= 'paramno=' . $paramno . "\n";
                $text .= "name=[0] \"\"\n";
                $text .= "is_param=1\n";
                $text .= 'param_type=' . $type . "\n";
                continue;
            }

            $name = ($param !== '' && $param[0] === ':') ? $param : (':' . $param);
            // Measured: a NAMED key arriving via an
            // execute() array dumps with its ARRAY POSITION as paramno, not
            // -1 — bindValue()/bindParam()'s own bookkeeping (entry['paramno']
            // === null here) is the only path that still dumps -1.
            $paramno = $entry['paramno'] !== null ? $entry['paramno'] : -1;
            $text .= 'Key: Name: [' . strlen($name) . '] ' . $name . "\n";
            $text .= 'paramno=' . $paramno . "\n";
            $text .= 'name=[' . strlen($name) . '] "' . $name . "\"\n";
            $text .= "is_param=1\n";
            $text .= 'param_type=' . $type . "\n";
        }

        host_log('debug', ['msg' => 'atoms.pdo.debug_dump_params', 'sql' => $this->sql, 'dump' => $text]);

        if (ob_get_level() > 0) {
            echo $text;
        }
    }
}
