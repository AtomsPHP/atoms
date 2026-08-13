# PDO compatibility for the Atoms Cloudflare runtime

**Generated — do not edit by hand.** Produced by `cloudflare/worker/scripts/gen-pdo-matrix.mjs` from a conformance run's differential report; conformance check 30 fails if this file and a fresh run disagree. Measured on php-wasm PHP 8.3.32, against `ctx.storage.sql`, with a native in-guest `pdo_sqlite` connection as the comparator.

## How to read this

| Class | Meaning |
|---|---|
| `supported` | Identical to native pdo_sqlite. |
| `refused` | Atoms raises a typed `PDOException`; native pdo_sqlite answers. Never a wrong answer. |
| `refused by both` | Both raise; the SQLSTATEs are noted where they differ. |
| `comparator-only refusal` | Atoms answers a value; native pdo_sqlite raises instead — the opposite asymmetry from `refused`. |
| `differs` | Both answer, and the answers differ. Documented below with the reason. |
| `undefined` | PDO's own contract leaves it undefined (see `PDOStatement::rowCount()` on a SELECT). |

## Connection — statements

| Member | Case | Status | Notes |
|---|---|---|---|
| `PDO::exec()` | exec() return value for a CREATE TABLE, self-contained with a known preceding INSERT | supported |  |
| `PDO::exec()` | UPDATE matching exactly one row | supported |  |
| `PDO::exec()` | UPDATE matching no row | supported |  |
| `PDO::prepare()` | ATTR_CURSOR => CURSOR_SCROLL as a driver option | refused | prepare() throws for ANY non-empty $options array; real pdo_sqlite returns false for CURSOR_SCROLL (sqlite has no scrollable cursor). Refusing loudly is honest; use no driver options and PDO::FETCH_ORI_NEXT only. |
| `PDO::prepare()` | an empty driver-options array is always accepted | supported |  |
| `PDO::prepare()` | ATTR_STATEMENT_CLASS as a driver option | refused | prepare() throws for ANY non-empty $options array; real pdo_sqlite accepts ATTR_STATEMENT_CLASS. We always return an AtomsStatement regardless, so accepting the option would be a silent lie about the returned class. |
| `PDO::prepare()` | ATTR_TIMEOUT as a driver option | refused | prepare() throws for ANY non-empty $options array today; real pdo_sqlite silently accepts and ignores ATTR_TIMEOUT (measured). There is no driver-owned statement handle for an option to configure, so accepting-and-ignoring would be a silent lie about what the option did. |
| `PDO::prepare()` | named placeholder, key given without the colon | supported |  |
| `PDO::prepare()` | named placeholder, key given with leading colon | supported |  |
| `PDO::prepare()` | positional placeholder round-trip | supported |  |
| `PDO::query()` | explicit FETCH_ASSOC argument | supported |  |
| `PDO::query()` | FETCH_CLASS with a class name and constructor args | differs | query() with FETCH_CLASS + a class name + constructor args is FILLED (design §3 F-14, forwards to setFetchMode() then execute()) and hydrates App\Pdo\Fixtures\Row objects correctly. The one remaining difference is the SAME already-pinned root cause as value.float_integral: row 'b's REAL column (r=2.0) round-trips as PHP int(2) on our side, float(2) on real's, because our wire's encodeInt64Deep() cannot distinguish a whole-number REAL from an INTEGER once workerd hands both to JS as the same `number` type. Not a new limitation — an existing one now reachable through a newly-filled path. |
| `PDO::query()` | FETCH_COLUMN with a column-index argument | supported |  |
| `PDO::query()` | query() on an INSERT still returns a statement, rowCount=1 columnCount=0 | supported |  |
| `PDO::query()` | no fetch mode argument uses the connection default | supported |  |

## Connection — identity/attributes

| Member | Case | Status | Notes |
|---|---|---|---|
| `PDO::getAttribute()` | ATTR_AUTOCOMMIT | refused by both | Both sides refuse getAttribute(ATTR_AUTOCOMMIT): real pdo_sqlite answers SQLSTATE[IM001] (measured) and ours throws AtomsNotSupported. Refused_by_both, matching the driver's own posture exactly. |
| `PDO::getAttribute()` | ATTR_CASE | supported |  |
| `PDO::getAttribute()` | ATTR_CLIENT_VERSION | refused | getAttribute(ATTR_CLIENT_VERSION) is refused permanently for the same reason as ATTR_SERVER_VERSION: there is no client library on this side of the wire whose version would be truthful to report (design §3 F-22). |
| `PDO::getAttribute()` | ATTR_DEFAULT_FETCH_MODE — the ASSOC-vs-BOTH default | supported |  |
| `PDO::getAttribute()` | ATTR_DRIVER_NAME | supported |  |
| `PDO::getAttribute()` | ATTR_EMULATE_PREPARES | refused by both | Both sides refuse getAttribute(ATTR_EMULATE_PREPARES): real pdo_sqlite answers SQLSTATE[IM001] (measured) and ours throws AtomsNotSupported. Refused_by_both, matching the driver's own posture exactly. |
| `PDO::getAttribute()` | ATTR_ERRMODE | supported |  |
| `PDO::getAttribute()` | ATTR_ORACLE_NULLS | supported |  |
| `PDO::getAttribute()` | ATTR_PERSISTENT | supported |  |
| `PDO::getAttribute()` | ATTR_SERVER_VERSION | refused | getAttribute(ATTR_SERVER_VERSION) is refused permanently: the guest's SQLite build and the Durable Object's SQLite build are two different binaries, so any single answer would misrepresent one of them (design §3 F-22). |
| `PDO::getAttribute()` | ATTR_TIMEOUT | refused by both | Both sides refuse getAttribute(ATTR_TIMEOUT): real pdo_sqlite itself answers SQLSTATE[IM001] (measured) and ours throws AtomsNotSupported — a genuine refused_by_both, not merely a gap on our side. No fix needed; the driver we claim to be doesn't answer this either. |
| `PDO::getAvailableDrivers()` | the declared static shadow answers identically to the parent | supported |  |
| `PDO::setAttribute()` | ATTR_CASE => CASE_UPPER | refused | M1 review F-17 (stale why fixed to describe the CURRENT code): ATTR_CASE IS implemented (design §3 F-22) — setAttribute(ATTR_CASE, CASE_NATURAL) succeeds — but CASE_UPPER/CASE_LOWER are refused with AtomsNotSupported because column-name case folding on fetch is not implemented; fetched keys are always the query's own case. Real pdo_sqlite accepts CASE_UPPER and reshapes subsequent fetch() keys to upper-case, hence refused_by_us. This is a deliberate, permanent restriction, not an unfilled gap. |
| `PDO::setAttribute()` | ATTR_DEFAULT_FETCH_MODE round-trip through getAttribute(), then restored | supported |  |
| `PDO::setAttribute()` | ATTR_ERRMODE => ERRMODE_EXCEPTION is always accepted | supported |  |
| `PDO::setAttribute()` | ATTR_ERRMODE => ERRMODE_SILENT — we cannot honour a non-exception errmode | refused | setAttribute(ATTR_ERRMODE, ...) accepts only ERRMODE_EXCEPTION today; the bridge reports every failure by throwing, so ERRMODE_SILENT/WARNING cannot be honoured. Real pdo_sqlite accepts the switch (though nothing on our side could implement silent/warning modes truthfully). |
| `PDO::setAttribute()` | ATTR_STRINGIFY_FETCHES => true | refused | setAttribute(ATTR_STRINGIFY_FETCHES, ...) is not implemented at all today; real pdo_sqlite accepts it. Not yet filled. |

## Connection — quoting

| Member | Case | Status | Notes |
|---|---|---|---|
| `PDO::quote()` | a string containing an apostrophe is doubled, not backslash-escaped | supported |  |
| `PDO::quote()` | the empty string | supported |  |
| `PDO::quote()` | quote() on a value containing a NUL byte | refused | quote("a\0b") now THROWS a \PDOException on our side (design §3 F-24's rewrite: quote() always quotes and ignores $type, EXCEPT this one case); real pdo_sqlite silently TRUNCATES the value at the NUL byte and returns "'a'" (measured). This is a deliberate posture, not a gap: silently truncating a value is the one thing this surface must not do, so ours refuses loudly rather than reproducing the truncation — encode NUL-bearing data (e.g. base64) before quoting it. |
| `PDO::quote()` | quote() with PARAM_BOOL | refused by both | M1 review F-5 (RECLASSIFIED): AtomsPDO::quote() now declares the SAME parameter type real \PDO::quote() does (`string $string, int $type = PARAM_STR`). Under Cases.php's declare(strict_types=1), passing the raw PHP bool `true` for that `string $string` parameter throws \TypeError at the argument boundary on BOTH sides now — real's before its own $type-ignoring quote logic ever runs, ours before its always-quote body ever runs. Same mechanism, same family, on both sides: a genuine refused_by_both, not a comparator-only quirk. Coerce to string yourself (e.g. `$b ? '1' : '0'`) before calling quote() if you need a boolean quoted. |
| `PDO::quote()` | quote() with PARAM_INT | supported |  |
| `PDO::quote()` | quote() with PARAM_LOB | supported |  |
| `PDO::quote()` | quote() with PARAM_NULL | refused by both | M1 review F-5 (RECLASSIFIED): same mechanism as pdo.quote.param_bool. AtomsPDO::quote() now declares `string $string` like real \PDO::quote() does, so passing the raw PHP `null` for it throws \TypeError at the argument boundary on BOTH sides under Cases.php's declare(strict_types=1) — a genuine refused_by_both. Coerce to an empty string yourself before calling quote() if you need a NULL-standing-in-for-empty value quoted. |
| `PDO::quote()` | a plain string | supported |  |
| `PDO::quote()` | quote() with an unrecognized $type | supported |  |
| `PDO::quote()` | multi-byte UTF-8 content survives byte for byte | supported |  |

## Transactions

| Member | Case | Status | Notes |
|---|---|---|---|
| `PDO::beginTransaction() / PDO::commit()` | a clean begin+commit | supported |  |
| `PDO::beginTransaction() / PDO::rollBack()` | a clean begin+rollback | supported |  |
| `PDO::commit()` | commit() with no open transaction | refused by both | commit() with no open transaction throws on both sides — a genuine refused_by_both, both a bare PDOException with no SQLSTATE captured by either driver for this condition on this build. |
| `PDO::inTransaction()` | flag sequence: false, true, false | supported |  |
| `PDO::beginTransaction()` | a second beginTransaction() while one is already open | refused by both | A second beginTransaction() while one is already open throws on both sides (design-expected refused_by_both): ours raises a plain PDOException with no SQLSTATE set, real raises one with no SQLSTATE either on this build — messages differ (never compared) but both are unmistakably "already an active transaction" errors. |
| `PDO::beginTransaction()` | a write is visible to a read inside the same open transaction | supported |  |
| `PDO::rollBack()` | rollBack() with no open transaction | refused by both | rollBack() with no open transaction throws on both sides — a genuine refused_by_both, both a bare PDOException with no SQLSTATE captured by either driver for this condition on this build. |

## Ids and counts

| Member | Case | Status | Notes |
|---|---|---|---|
| `PDOStatement::columnCount()` | before execute() | supported |  |
| `PDOStatement::columnCount()` | columnCount() on a two-column result with ZERO rows | supported |  |
| `PDOStatement::columnCount()` | an UPDATE statement has zero columns | supported |  |
| `PDOStatement::columnCount()` | a two-column, non-empty result | supported |  |
| `PDOStatement::rowCount()` | after a DELETE matching no row | supported |  |
| `PDOStatement::rowCount()` | after an INSERT | supported |  |
| `PDOStatement::rowCount()` | after a SELECT — UNDEFINED by PDO's own contract; recorded, never compared | undefined | Undefined by PDO's own contract, and measured proof that real pdo_sqlite is not even self-consistent: after an INSERT, a SELECT matching zero rows reported rowCount() === 1 (a stale sqlite3_changes). Comparing it would compare two different flavours of undefined; both observed values are recorded here instead. |
| `PDOStatement::rowCount()` | after an UPDATE matching one row | supported |  |
| `PDOStatement::rowCount()` | after an UPDATE matching no row | supported |  |
| `PDO::lastInsertId()` | the PDO contract is a string, never an int — TYPE and CONTENT both compared | supported |  |
| `PDO::lastInsertId()` | lastInsertId() across an intervening SELECT and a no-match UPDATE | supported |  |
| `PDO::lastInsertId()` | lastInsertId() with a sequence name argument | supported |  |

## Binding

| Member | Case | Status | Notes |
|---|---|---|---|
| `PDOStatement::execute()` | an array passed to execute() replaces a previously bound value | supported |  |
| `PDOStatement::execute()` | real PDO stringifies EVERYTHING passed to execute() — a PERMANENT pinned deviation | differs | execute([42, 42]) binds the array elements with their NATIVE PHP types on our side (int 42 stays INTEGER); real pdo_sqlite stringifies EVERY value passed to execute() regardless of its PHP type (measured — design §3 F-12). This is a DELIBERATE, PERMANENT pinned deviation, not a gap: matching real's stringify-everything behaviour would push wide integers through as TEXT literals and defeat inlineWideIntegers()'s int64 exactness. Use bindValue() with an explicit type when you need PDO's stringification. |
| `PDOStatement::execute()` | execute() called with an explicit empty array, over a previously bound value | refused | execute([]) is treated as an explicit (empty) replacement of any bound values, so the placeholder is left unsupplied. Design §3 F-13 proposed the OPPOSITE fix (reuse bound values) from an 8.4-desktop measurement, but measured IN-GUEST — against this build's own real pdo_sqlite, the referee this milestone uses — execute([]) does NOT reuse a bound value either (it answers the unbound placeholder as NULL, its own SQLite default); the desktop measurement does not hold here. What differs is what workerd does with the resulting placeholder-count mismatch: ctx.storage.sql.exec() throws (sql_error) when given fewer bindings than '?' marks, where real SQLite's C API silently binds NULL for an unsupplied parameter. This is the SAME failure family as bind.named.missing (design §3 F-33): SQLite's own silent-NULL leniency here is a silent-wrong-answer risk, so refusing is not merely unfilled — pass execute(null) (no arguments) to keep bound values, which does match. |
| `PDOStatement::execute()` | a named and a positional placeholder bound in the SAME statement | refused | A statement binding both a named (:k) and a positional (?) placeholder throws SQLSTATE[HY093] on our side: NamedParams::rewrite() treats a bindings array containing ANY non-int key as entirely named, so the sole ? is never resolved and its bound value is reported as an unused named binding. Real pdo_sqlite executes this via SQLite's own shared ordinal sequence (named and positional placeholders share one ordinal, left to right in the SQL text) and answers a row. Orchestrator decision (M1 review round 2, R7): implementing that ordinal model is out of scope for this milestone; bind every placeholder in a statement the SAME way (all positional, or all named) to avoid this refusal. |
| `PDOStatement::execute()` | execute() with an extra, unused named binding | refused by both | An extra, unused named binding throws on both sides, but with different SQLSTATEs: ours reports HY093 ("not present in the statement") at rewrite time, real reports HY000 ("column index out of range", measured) at bind time. Both refuse the ambiguous input; only the SQLSTATE differs. |
| `PDOStatement::execute()` | execute() with a named placeholder left unsupplied | refused | A missing named binding throws SQLSTATE[HY093] on our side; real SQLite silently binds the unsupplied parameter to NULL without complaining (measured — design §3 F-33). This is a DELIBERATE strictness, not merely a gap: SQLite's silent-NULL behaviour here is itself the kind of silent-wrong-answer failure mode this milestone exists to eliminate, so ours refuses loudly instead of guessing NULL was intended. |
| `PDOStatement::execute()` | the same named placeholder used twice in one statement | differs | execute([':a' => 5]) binds the same named placeholder used twice; real pdo_sqlite stringifies the value via execute()'s array path (the same stringify-everything behaviour as bind.execute_array.types / design §3 F-12), giving TEXT '5' at both positions, while ours preserves the native PHP int 5. Same permanent, pinned root cause as bind.execute_array.types — bindValue() with an explicit type sidesteps it. |
| `PDOStatement::bindParam()` | by-reference binding | supported |  |
| `PDOStatement::bindParam()` | the reference is read at execute() time, not at bind time | supported |  |
| `PDOStatement::bindValue()` | default PARAM_STR leaves an already-string value untouched | supported |  |
| `PDOStatement::bindValue()` | PARAM_BOOL on an empty string should coerce through bool to integer 0 | supported |  |
| `PDOStatement::bindValue()` | PARAM_BOOL true becomes integer 1 | supported |  |
| `PDOStatement::bindValue()` | PARAM_INT should truncate a float | supported |  |
| `PDOStatement::bindValue()` | PARAM_INT should coerce a numeric string to int | supported |  |
| `PDOStatement::bindValue()` | bindValue() with PARAM_LOB | refused | bindValue($p, $v, PARAM_LOB) throws unconditionally today; binary values do not cross the JSON bridge, so this is refused rather than silently mangled. Real pdo_sqlite accepts it and stores it as a normal string. Store the value base64-encoded as text instead. |
| `PDOStatement::bindValue()` | PARAM_NULL should ignore the given value entirely and bind NULL | supported |  |
| `PDOStatement::bindValue()` | PARAM_STR should stringify a float | supported |  |
| `PDOStatement::bindValue()` | PARAM_STR should stringify an int | supported |  |
| `PDOStatement::bindValue()` | bindValue(0, ...) — position must be >= 1 | refused by both | bindValue(0, ...) throws a \ValueError on both sides ('must be greater than or equal to 1') — SQLite/PDO positions are 1-based, and this shim now validates the same range real PDO does (M1 review round 2, R3) rather than silently accepting a bogus position that would only surface as a confusing failure later. |
| `PDOStatement::bindValue()` | the NEGATIVE end of the wide-int band (PHP_INT_MIN) bound as PARAM_INT and read back DIRECTLY | refused | M1 review F-11: the NEGATIVE end of the wide-int band was previously unexercised. Binding PHP_INT_MIN and reading it straight back (no CAST) hits the SAME int64_precision wall as bind.value.wide_int_param_int, just on the negative side of zero (mvp-spec.md Appendix item 1): workerd's SQL->JS conversion loses precision for any value in the ambiguous magnitude band regardless of sign. Bind the wide integer as a string instead (see bind.value.wide_int_param_str, which matches) and CAST(... AS TEXT) when reading it back. |
| `PDOStatement::bindValue()` | a >2^53-1 int bound as PARAM_INT and read back DIRECTLY hits the same int64 wall as a stored value | refused | Binding PHP_INT_MAX and reading it straight back (no CAST) hits the same int64_precision wall a stored wide integer does (mvp-spec.md Appendix item 1): workerd's SQL->JS conversion loses precision for any value in the ambiguous band regardless of how it entered SQLite. Bind the wide integer as a string instead (see bind.value.wide_int_param_str, which matches) and CAST(... AS TEXT) when reading it back. |
| `PDOStatement::bindValue()` | the documented workaround: bind the wide integer AS TEXT and it round-trips exactly | supported |  |

## Fetch modes

| Member | Case | Status | Notes |
|---|---|---|---|
| `PDOStatement::fetch()` | fetch() after fetchAll() has exhausted the cursor returns false | supported |  |
| `PDOStatement::fetch()` | FETCH_ASSOC over a three-row result | supported |  |
| `PDOStatement::fetch()` | FETCH_BOTH | supported |  |
| `PDOStatement::bindColumn() / FETCH_BOUND` | default column type | supported |  |
| `PDOStatement::bindColumn() / FETCH_BOUND` | PARAM_INT column type | supported |  |
| `PDOStatement::bindColumn()` | bindColumn() with an unknown column name | refused by both | bindColumn('nope', ...) throws PDOException on both sides for an unknown column name: real pdo_sqlite (measured HY000, "Did not find column name 'nope' in the defined columns; it will not be bound") and ours (design §3 F-2, resolved against Branch A's `columns` list — HY000, same message shape). Both refuse; a genuine refused_by_both, not a gap — bindColumn() by name and by index against a known column IS filled and matches (see fetch.bound.default_type/fetch.bound.param_int). |
| `PDOStatement::fetchAll()` | FETCH_CLASS with constructor args | supported |  |
| `PDOStatement::fetchAll()` | FETCH_CLASS, no constructor args | supported |  |
| `PDOStatement::fetchAll()` | FETCH_CLASS writes private/protected declared props AND makes an unmatched column dynamic (measured E13) | supported |  |
| `PDOStatement::fetchAll()` | FETCH_CLASS into a promoted-constructor class — the ctor's defaults overwrite the hydrated props (measured E13) | supported |  |
| `PDOStatement::fetchAll()` | FETCH_CLASS \| FETCH_PROPS_LATE — properties set AFTER the constructor runs | supported |  |
| `PDOStatement::fetchAll()` | FETCH_CLASS \| FETCH_CLASSTYPE — the class name comes from the first column | differs | FETCH_CLASS \| FETCH_CLASSTYPE is FILLED (design §3 F-4) and correctly falls back to stdClass when the data-supplied class name does not resolve (measured directly against the in-guest comparator), matching real pdo_sqlite exactly for THIS case's own classname column. The one remaining difference is the SAME already-pinned root cause as value.float_integral: row 'b's REAL column (r=2.0) round-trips as PHP int(2) on our side, float(2) on real's, because our wire's encodeInt64Deep() cannot distinguish a whole-number REAL from an INTEGER once workerd hands both to JS as the same `number` type. Not a new limitation — an existing one now reachable through a newly-filled path. |
| `PDOStatement::fetchAll()` | FETCH_COLUMN with no index defaults to column 0 | supported |  |
| `PDOStatement::fetchAll()` | FETCH_COLUMN with an explicit index argument | supported |  |
| `PDOStatement::fetchColumn()` | fetchColumn() with a column index past the end of the result set | refused by both | fetchColumn(5) on a one-column result now throws \ValueError('Invalid column index') on our side (design §3 F-21, FILLED — matches real's exact exception family, measured); real pdo_sqlite throws the SAME family for the same reason. A genuine refused_by_both — an exception pair can never classify as 'match' under this harness's taxonomy even when the behaviour is now identical, since match is reserved for two answered values. |
| `PDOStatement::fetchAll()` | fetchAll() called with no mode argument, so it uses the connection's ATTR_DEFAULT_FETCH_MODE | supported |  |
| `PDOStatement::fetchAll()` | FETCH_FUNC calls the given function with each column as a positional arg | supported |  |
| `PDOStatement::fetch()` | FETCH_FUNC on fetch() (not fetchAll()) | refused by both | fetch(FETCH_FUNC) (not fetchAll()) now throws \ValueError('Can only use PDO::FETCH_FUNC in PDOStatement::fetchAll()') on our side — the SAME family AND wording real pdo_sqlite throws (design §3 F-7, FILLED). A genuine refused_by_both: FETCH_FUNC itself IS implemented (fetchAll(FETCH_FUNC, $callback) matches), this pair is real PDO's own restriction that FETCH_FUNC is fetchAll()-only, correctly reproduced rather than merely refused with the wrong family. |
| `PDOStatement::fetchAll()` | FETCH_GROUP \| FETCH_ASSOC | supported |  |
| `PDOStatement::fetchAll()` | FETCH_GROUP \| FETCH_COLUMN | supported |  |
| `PDOStatement::fetchAll()` | FETCH_GROUP \| FETCH_NUM | supported |  |
| `PDOStatement::setFetchMode() / fetch()` | FETCH_INTO hydrates an existing object in place | supported |  |
| `PDOStatement::getIterator()` | a plain foreach over the statement, using whatever the connection's default fetch mode is | supported |  |
| `PDOStatement::fetchAll()` | FETCH_KEY_PAIR over exactly two columns | supported |  |
| `PDOStatement::fetchAll()` | FETCH_KEY_PAIR over a THREE-column result set | refused by both | fetchAll(FETCH_KEY_PAIR) over three columns now throws (design §3 F-26, FIX): real pdo_sqlite requires EXACTLY two columns and throws PDOException HY000 (measured); ours now checks `count($this->columns)` (Branch A) and throws the same family with a real errorInfo triple + getCode() as the SQLSTATE (design §3 F-28's pattern). A genuine refused_by_both, not a gap — FETCH_KEY_PAIR over exactly two columns IS filled and matches (see fetch.key_pair). |
| `PDOStatement::fetch()` | FETCH_LAZY | refused | fetch(FETCH_LAZY) throws unconditionally and permanently: real pdo_sqlite returns a PDORow, an internal class bound to a live statement that userland code cannot construct, so there is nothing on our side to reproduce it with (design §3 F-18, mandated to stay throwing). |
| `PDOStatement::fetch()` | FETCH_NUM | supported |  |
| `PDOStatement::fetch()` | FETCH_OBJ | supported |  |
| `PDOStatement::fetchObject()` | a class name and constructor args | supported |  |
| `PDOStatement::fetchObject()` | no class argument yields stdClass | supported |  |
| `PDOStatement::fetch() / fetchAll()` | a query matching zero rows | supported |  |
| `PDOStatement::fetch()` | FETCH_ORI_ABS on a forward-only cursor | refused | Same root cause and same permanent posture as fetch.ori_prior (design §3 F-9). |
| `PDOStatement::fetch()` | FETCH_ORI_FIRST on a forward-only cursor | refused | Same root cause and same permanent posture as fetch.ori_prior (design §3 F-9). |
| `PDOStatement::fetch()` | FETCH_ORI_LAST on a forward-only cursor | refused | Same root cause and same permanent posture as fetch.ori_prior (design §3 F-9). |
| `PDOStatement::fetch()` | FETCH_ORI_PRIOR on a forward-only cursor | refused | fetch() with any orientation other than FETCH_ORI_NEXT throws unconditionally and PERMANENTLY: real pdo_sqlite has no scrollable cursor and, measured, silently IGNORES the orientation and returns the NEXT row regardless — implementing scrolling over our buffer would either diverge from the driver or claim a capability the comparator itself does not have (design §3 F-9, the decisive measurement). Refusing is strictly more honest than the driver's own silent wrong-row behaviour. |
| `PDOStatement::fetch()` | FETCH_ORI_REL on a forward-only cursor | refused | Same root cause and same permanent posture as fetch.ori_prior (design §3 F-9). |
| `PDOStatement::setFetchMode()` | FETCH_CLASS with a class name argument | differs | setFetchMode(FETCH_CLASS, Row::class) then fetchAll() is FILLED (design §3 F-17) and hydrates all three rows correctly. The one remaining difference is the SAME already-pinned root cause as value.float_integral: row 'b's REAL column (r=2.0) round-trips as PHP int(2) on our side, float(2) on real's, because our wire's encodeInt64Deep() cannot distinguish a whole-number REAL from an INTEGER once workerd hands both to JS as the same `number` type. Not a new limitation — an existing one now reachable through a newly-filled path. |
| `PDOStatement::setFetchMode()` | FETCH_COLUMN with an index argument | supported |  |
| `PDOStatement::setFetchMode()` | FETCH_INTO with an object argument | supported |  |
| `PDOStatement::fetchAll()` | FETCH_UNIQUE \| FETCH_ASSOC — first column is the key, row is the value | supported |  |
| `PDOStatement::fetchAll()` | FETCH_UNIQUE \| FETCH_COLUMN | supported |  |

## Values and round-trips

| Member | Case | Status | Notes |
|---|---|---|---|
| `value round-trip` | ±2^31 and ±(2^53-1) — inside the JS-safe-integer band, no precision loss possible | supported |  |
| `value round-trip` | the documented supported path: SELECT CAST(v AS TEXT) for a wide integer written as an inline literal | supported |  |
| `value round-trip` | a stored >2^53-1 INTEGER read WITHOUT a CAST — workerd loses precision before Atoms code runs; refused (mvp-spec.md Appendix item 1) | refused | Reading a stored >2^53-1 INTEGER directly (no CAST) throws a typed int64_precision PDOException on our side: workerd hands ctx.storage.sql INTEGER columns to JS as doubles, so the exact value is already lost before any Atoms code runs (mvp-spec.md Appendix item 1). Real pdo_sqlite reads it exactly. Select the column as CAST(col AS TEXT) instead — see int.wide.cast_read and int.wide.write_exact, which both match. |
| `value round-trip` | the NEGATIVE end of the wide-int band, via CAST(v AS TEXT) — the documented supported path | supported |  |
| `value round-trip` | the NEGATIVE end of the wide-int band: a stored PHP_INT_MIN read WITHOUT a CAST — same int64 wall, opposite sign | refused | M1 review F-11: the NEGATIVE end of the wide-int band was previously unexercised. Reading a stored PHP_INT_MIN directly (no CAST) throws the SAME typed int64_precision PDOException as int.wide.direct_read, for the same reason and by the same mechanism, just below zero instead of above 2^53-1 (mvp-spec.md Appendix item 1). Real pdo_sqlite reads it exactly. Select the column as CAST(col AS TEXT) instead — see int.wide.negative_cast_read and int.wide.negative_write_exact, which both match. |
| `value round-trip` | PHP_INT_MIN written through a BOUND PARAMETER round-trips exactly via CAST | supported |  |
| `value round-trip` | a wide integer written through a BOUND PARAMETER (not an inline literal) round-trips exactly via CAST | supported |  |
| `value round-trip` | PARAM_BOOL true, bound explicitly, becomes integer 1 on both sides | supported |  |
| `value round-trip` | a non-integral float | supported |  |
| `value round-trip` | a REAL holding an integral value (2.0) — measured: real returns float(2), our wire loses the float-ness | differs | A REAL literal holding a whole number (2.0) round-trips as PHP int(2) on our side (typeof still correctly reports 'real' — the storage class is not lost, only the PHP-level type PDO hands back); real pdo_sqlite returns float(2). Root cause: JSON has no int/float distinction for a whole-number token, and our wire's encodeInt64Deep() emits an integral JS number as a plain JSON numeral indistinguishable from an int, so json_decode() on this side always resolves it to PHP int. There is no workaround short of a schema-aware encoder change; do not rely on a computed REAL that happens to be whole staying a PHP float. |
| `value round-trip` | an ordinary int | supported |  |
| `value round-trip` | NULL | supported |  |
| `value round-trip` | an ordinary string | supported |  |
| `value round-trip` | the empty string | supported |  |
| `value round-trip` | multi-byte UTF-8 text | supported |  |
| `value round-trip` | SQLite storage class of every seeded column type, read from a real row (not a bound literal) | supported |  |

## Errors

| Member | Case | Status | Notes |
|---|---|---|---|
| `PDO::errorCode()` | query() is a CONNECTION-level entry point — its own failure updates $pdo->errorCode() | supported |  |
| `PDO::errorCode()` | a clean beginTransaction()/commit() does NOT reset a stale connection error state | supported |  |
| `PDO::errorCode()` | prepare() resets a stale connection error state to clean | supported |  |
| `PDO::errorCode()` | quote() resets a stale connection error state to clean | supported |  |
| `PDO::errorCode()` | a successful STATEMENT execute() does NOT reset a stale CONNECTION error state | supported |  |
| `PDOStatement::errorCode()` | PDOStatement::errorCode() after its own failed execute() | supported |  |
| `PDO::errorInfo()` | a clean 00000/null/null triple after a successful operation | supported |  |
| `PDOStatement::execute()` | a NOT NULL violation | refused by both | A NOT NULL violation throws on both sides with the SAME SQLSTATE 23000 (measured), driver codes differ (1 vs 19) but that is not part of the strict comparison. A genuine refused_by_both, not a gap. |
| `PDO::errorCode()` | PDO::errorCode() on the CONNECTION after a STATEMENT failure | supported |  |
| `PDO::exec()` | a SQL syntax error | refused by both | A SQL syntax error throws on both sides with the SAME SQLSTATE HY000 and the SAME driver code 1 (measured — real pdo_sqlite's own SQLITE_ERROR code); messages differ (sqlite's own wording, never compared) but the SQLSTATE and driver code coincide exactly. A genuine refused_by_both, not a gap. |
| `PDOException::getCode()` | real PDO's getCode() IS the SQLSTATE string; ours defaults to int 0 | supported |  |
| `PDOStatement::execute()` | a UNIQUE violation | refused by both | A UNIQUE violation throws on both sides with the SAME SQLSTATE 23000 (measured); the driver error codes differ (ours reports 1, real reports 19 — SQLite's own constraint-specific error code, which our bridge does not carry across the wire) but driver code is not part of the strict comparison. A genuine refused_by_both, not a gap. |

## Duplicate columns

| Member | Case | Status | Notes |
|---|---|---|---|
| `PDOStatement::fetch()` | a duplicate-name join, aliased DISTINCTLY on each side | supported |  |
| `PDOStatement::fetch()` | FETCH_ASSOC over duplicate column names | supported |  |
| `PDOStatement::fetch()` | FETCH_BOTH over duplicate column names | refused | FETCH_BOTH over duplicate column names needs the full 4-value arity; Branch A's `columns` list (cursor.columnNames, design §2.7) lets us DETECT the duplicate precisely now, so instead of silently answering with the wrong arity (the pre-fill deviation) we refuse loudly — real pdo_sqlite answers with the true 6-entry BOTH shape (measured), which the wire's last-wins `{column: value}` collapse has already made unrecoverable on our side. Alias duplicate columns distinctly (see dup.aliased, which matches) rather than relying on FETCH_BOTH over ambiguous column names. |
| `PDOStatement::bindColumn() / FETCH_BOUND` | FETCH_BOUND binding a column BY INDEX over duplicate column names | refused | FETCH_BOUND reads a bound column's value from the row positionally, by the 0-based index bindColumn() resolved against the true (duplicate-preserving) column list; once a duplicate name has collapsed two columns into one wire entry, that index no longer lines up with the collapsed row (M1 review round 2, R1). Measured: real pdo_sqlite answers bindColumn(3, $out) as true/'b' (the true 3rd column's own value) from the full 4-column arity, which our wire's last-wins collapse has already discarded. Alias duplicate columns distinctly instead. |
| `PDOStatement::fetchAll()` | FETCH_CLASS \| FETCH_CLASSTYPE where the CLASSNAME column itself is duplicated | refused | FETCH_CLASS \| FETCH_CLASSTYPE reads the class name from the FIRST column positionally (M1 review round 2, R1); when that column's own name is duplicated, this shim now refuses rather than risk answering from a value the wire's last-wins collapse may have silently substituted. Measured: real pdo_sqlite genuinely hydrates an App\Pdo\Fixtures\Row instance from the first occurrence's value, with the object's own `classname` property left holding the SECOND occurrence's value ('bogus-classname') because property hydration collapses duplicate names the ordinary last-wins way, independent of the classtype resolution that reads column 0 positionally — refused_by_us records that ours declines to guess which occurrence a real driver would have honoured in general, rather than relying on this seed happening to name a resolvable class. |
| `PDOStatement::columnCount()` | columnCount() over a result set with duplicate column names | supported |  |
| `PDOStatement::fetchColumn()` | fetchColumn(1) over duplicate column names | refused | fetchColumn(1) over duplicate column names now throws (M1 review F-1, FIX: fetchColumn() reached the wire directly and skipped the same duplicate-column guard every other FETCH_COLUMN path uses, silently answering an unrecoverable collapsed value under duplicates). Real pdo_sqlite answers the SECOND COLUMN's (a.i) own original value — an ordinary int, 1 — from the true 4-value arity (measured); our wire's last-wins collapse has already discarded it before PHP could see it (M1 review round 2, R10: this note previously said 'second occurrence', which is imprecise — index 1 is unambiguously the second column regardless of naming). Alias duplicate columns distinctly (see dup.aliased, which matches) instead. |
| `PDOStatement::fetchColumn()` | fetchColumn() with the default index over duplicate column names | refused | fetchColumn() with the default index (0) over duplicate column names now throws (M1 review F-1, FIX: fetchColumn() reached the wire directly and skipped the same duplicate-column guard every other FETCH_COLUMN path uses, silently answering column 0's collapsed value under duplicates). Real pdo_sqlite answers the FIRST occurrence's original value from the true 4-value arity (measured); our wire's last-wins collapse has already discarded it before PHP could see it. Alias duplicate columns distinctly (see dup.aliased, which matches) instead. |
| `PDOStatement::fetchAll()` | FETCH_FUNC over duplicate column names | refused | FETCH_FUNC calls the given callable with EVERY column as a positional arg (`...array_values($row)`); under duplicate column names the collapsed row has fewer entries than the true arity, so the callable would receive too few (and wrong) values (M1 review round 2, R1). Measured: real pdo_sqlite calls the callback with all 4 columns' true values from the full arity, which our wire's last-wins collapse has already discarded before this fetch mode could see them. Alias duplicate columns distinctly instead. |
| `PDOStatement::fetchAll()` | FETCH_GROUP \| FETCH_ASSOC over duplicate column names | refused | FETCH_GROUP/FETCH_UNIQUE key every group by the FIRST column's value, taken positionally, for EVERY base mode they combine with — not only NUM/BOTH/COLUMN, so this refusal is now unconditional on any duplicate rather than scoped to those three (M1 review round 2, R1, a strengthening over the prior narrower guard). Measured: real pdo_sqlite keys the group by the first column's TRUE original value ('a'), which our wire's last-wins collapse has already discarded before this fetch mode could see it. Alias duplicate columns distinctly instead. |
| `PDOStatement::fetchAll()` | FETCH_KEY_PAIR over exactly two duplicate-named columns | refused | FETCH_KEY_PAIR pairs a row's first value with its second, positionally; two DISTINCT columns sharing a name still pass the 'exactly 2 columns' arity check, but the wire's last-wins collapse folds them into ONE entry, leaving nothing to pair (M1 review round 2, R1). Measured: real pdo_sqlite answers the true 2-value pair {'a': 'b'} (first column's value keyed to the second's) from the uncollapsed row, which our side can no longer recover. Alias duplicate columns distinctly instead. |
| `PDOStatement::fetch()` | FETCH_NAMED over duplicate column names | refused | FETCH_NAMED groups duplicate columns into an array on real pdo_sqlite (measured); the values that would need grouping are already gone from our wire (the `{column: value}` collapse is last-wins, before PHP ever sees the row), so Branch A's `columns` list (design §2.7) lets this be detected and refused precisely rather than answered wrong. FETCH_NAMED itself IS filled for the no-duplicate case (design §3 F-6) — see the unique-column cases in the Fetch modes group, which match. |
| `PDOStatement::fetch()` | FETCH_NUM over duplicate column names | refused | FETCH_NUM over duplicate column names needs the full 4-value arity; our wire already collapsed the row to a 2-key map before PHP saw it (last-column-wins). Branch A's `columns` list (cursor.columnNames, design §2.7) now lets AtomsStatement detect that precisely and refuse loudly instead of silently answering with the wrong arity (the pre-fill deviation) — real pdo_sqlite answers with all 4 original values (measured). Alias duplicate columns distinctly (see dup.aliased, which matches) rather than relying on positional FETCH_NUM over ambiguous column names. |
| `PDOStatement::fetch()` | FETCH_OBJ over duplicate column names | supported |  |

## Statement misc

| Member | Case | Status | Notes |
|---|---|---|---|
| `PDOStatement::closeCursor()` | fetch() after closeCursor() returns false | supported |  |
| `PDOStatement::debugDumpParams()` | debugDumpParams() with a bound named param and a bound positional param, exact captured-output byte comparison | supported |  |
| `PDOStatement::debugDumpParams()` | debugDumpParams() with nothing bound, exact captured-output byte comparison | supported |  |
| `PDOStatement::debugDumpParams()` | debugDumpParams() with two bound positional params, exact captured-output byte comparison | supported |  |
| `PDOStatement::debugDumpParams()` | debugDumpParams() after params supplied via execute() (named array), exact captured-output byte comparison | supported |  |
| `PDOStatement::debugDumpParams()` | debugDumpParams() after params supplied via execute() (positional array), exact captured-output byte comparison | supported |  |
| `PDOStatement::getAttribute()` | getAttribute(ATTR_CURSOR) on a prepared statement | refused by both | Both sides refuse PDOStatement::getAttribute() with the SAME SQLSTATE now (design §3 F-23: AtomsNotSupported's third constructor arg carries 'IM001', matching real pdo_sqlite's measured SQLSTATE[IM001] exactly — there is no driver-owned statement handle to read from on either side). A tightened implementation, not a loosened comparison — refused_by_both with a matching triple, not merely a matching family. |
| `PDOStatement::getColumnMeta()` | PDOStatement::getColumnMeta() | refused | getColumnMeta() throws unconditionally and PERMANENTLY: real pdo_sqlite answers name/native_type/pdo_type/flags/table/len/precision, and even under this baseline's wire we would only ever have the column NAME — the rest would have to be invented outright (design §3 F-19). |
| `PDOStatement::nextRowset()` | nextRowset() after a single-result-set query | refused by both | Both sides refuse nextRowset() with the SAME SQLSTATE now (design §3 F-23): real pdo_sqlite itself throws SQLSTATE[IM001] ("driver does not support multiple rowsets", measured) and ours throws AtomsNotSupported constructed with 'IM001' for the same reason (a single result set per statement) — a tightened implementation, refused_by_both with a matching triple. |
| `PDOStatement::columnCount()` | columnCount() on an intercepted PRAGMA result | supported |  |
| `PDOStatement::$queryString` | the SQL as prepared, :name placeholders intact (allowlist A1) | supported |  |
| `PDOStatement::$queryString` | post-construction EXTERNAL reassignment of $queryString, measured on this build: refused on BOTH sides (not a pinned deviation) | supported |  |
| `PDOStatement::setAttribute()` | setAttribute(ATTR_CURSOR, CURSOR_FWDONLY) on a prepared statement | refused by both | Both sides refuse PDOStatement::setAttribute() with the SAME SQLSTATE now, for the same reason as getAttribute(): real answers SQLSTATE[IM001] (measured) and ours throws AtomsNotSupported constructed with 'IM001' (design §3 F-23) — refused_by_both with a matching triple, not merely a matching family. |
| `PDO::sqliteCreateAggregate()` | same reasoning as sqlite_create_function | refused | Same permanent reasoning as stmt.sqlite_create_function — an aggregate callback is equally unreachable from the Durable Object's SQL engine (design §3 F-32). |
| `PDO::sqliteCreateCollation()` | same reasoning as sqlite_create_function | refused | Same permanent reasoning as stmt.sqlite_create_function — a collation callback is equally unreachable from the Durable Object's SQL engine (design §3 F-32). |
| `PDO::sqliteCreateFunction()` | a real sqlite_create_function extension the guest cannot register a callback with | refused | sqliteCreateFunction() throws unconditionally and PERMANENTLY: statements execute in the Durable Object, not in the guest, so a PHP callback registered here could never actually be invoked by the engine running the SQL (design §3 F-32). Real pdo_sqlite (same process as the SQL engine) supports it natively. |

## Weak-mode type boundaries

| Member | Case | Status | Notes |
|---|---|---|---|
| `PDOStatement::bindValue() / execute()` | bind and read back an ordinary value from a case file with no declare(strict_types=1) | supported |  |
| `PDO::quote()` | quote(true) under weak typing — bool coerces to a string on both sides | supported |  |
| `PDO::quote()` | quote(42) under weak typing — int coerces to a string on both sides | supported |  |
| `PDO::quote()` | quote(null) under WEAK typing (this file has no declare(strict_types=1)) | refused | Under WEAK typing (no declare(strict_types=1) — CasesWeak.php is the one case file without it, M1 review round 2, R5), quote(null) throws a \TypeError on our side: AtomsPDO::quote()'s own `string $string` parameter (M1 review round 1, F-5) is a USERLAND signature, and userland functions never got PHP's legacy internal-function leniency for passing null to a non-nullable scalar parameter. Real (internal/C) \PDO::quote() still coerces null to the empty string under weak typing (deprecated as of 8.1, but not an error) and returns "''". A userland-vs-internal-function typing gap, not a bridge limitation — pass an explicit '' instead of null. |
