/**
 * The host side of the PHP<->JS protocol.
 *
 * `Bridge` implements the synchronous ('!') ops — `sql.exec`, `config.get`,
 * `meta.get`, `meta.set`, `log` — which run with the PHP stack still live above
 * the JS frame, so `ctx.storage.sql.exec()` answers without any Asyncify
 * unwind.
 *
 * `TransactionMachine` implements the park ('~') ops `tx.begin` / `tx.commit` /
 * `tx.rollback` on top of `ctx.storage.transactionSync()`: the guest is resumed
 * *inside* the transaction callback, runs its statements through the same
 * synchronous SQL door (read-your-own-writes on the same connection), and parks
 * again at commit/rollback so the callback can return (commit) or throw a
 * sentinel (rollback, discarding the write set). Ported from the spike's
 * `serviceParked()`.
 *
 * Nothing here throws out of the sync door: a throw would unwind through wasm.
 * Every failure becomes an `{"ok":false,"error":{...}}` reply, which the PHP
 * helpers turn into exceptions.
 */
import { LOG_LEVELS, META_TABLE, META_KEYS, RESERVED_TABLE_PREFIX } from './config.js';
import { AtomsError, normalizeError } from './errors.js';
import {
	decodeInt64Deep,
	encodeInt64Deep,
	inlineWideIntegers,
	toSqlBinding,
} from './int64.js';

/**
 * @typedef {object} ParkedCall
 * @property {{op?: string, [k: string]: unknown}} msg  decoded park message
 * @property {(reply: string) => void} reply            resumes the guest synchronously
 */

/**
 * @typedef {object} ParkHost
 * @property {() => ParkedCall|null} takePending  take and clear the pending park, if any
 * @property {(parked: ParkedCall) => void} restorePending
 *   put a park back so the caller's own loop services it (used when the guest
 *   reaches the turn boundary from inside a transaction)
 */

/** @param {Record<string, unknown>} extra */
function ok(extra = {}) {
	return JSON.stringify({ ok: true, ...extra });
}

/**
 * @param {string} code
 * @param {string} message
 * @param {Record<string, unknown>} [extra] merged into the error object
 */
function fail(code, message, extra = {}) {
	return JSON.stringify({ ok: false, error: { code, message, ...extra } });
}

export { ok as okReply, fail as errorReply };

/** Pragmas answered synthetically instead of being forwarded to the DO. */
const SYNTHETIC_PRAGMAS = new Set(['journal_mode', 'synchronous', 'busy_timeout']);

export class Bridge {
	/**
	 * @param {object} opts
	 * @param {any} opts.ctx      DurableObjectState
	 * @param {Record<string, unknown>} opts.env
	 * @param {import('./config.js').AtomsConfig} opts.config
	 * @param {() => ({type: string, id: string}|null)} opts.identityRef
	 */
	constructor({ ctx, env, config, identityRef }) {
		this.ctx = ctx;
		this.env = env;
		this.config = config;
		this.sql = ctx.storage.sql;
		this.identityRef = identityRef;
		this.sqlCalls = 0;
	}

	// ------------------------------------------------------------------ schema

	/** Create the host-owned metadata table. Idempotent, safe in a constructor. */
	ensureSchema() {
		this.sql.exec(
			`CREATE TABLE IF NOT EXISTS ${META_TABLE} (key TEXT PRIMARY KEY, value TEXT NOT NULL)`
		);
	}

	/**
	 * @param {string} key
	 * @returns {string|null}
	 */
	metaGet(key) {
		const rows = this.sql.exec(`SELECT value FROM ${META_TABLE} WHERE key = ?`, key).toArray();
		return rows.length ? String(rows[0].value) : null;
	}

	/**
	 * @param {string} key
	 * @param {string} value
	 */
	metaSet(key, value) {
		this.sql.exec(
			`INSERT INTO ${META_TABLE} (key, value) VALUES (?, ?) ` +
				'ON CONFLICT(key) DO UPDATE SET value = excluded.value',
			key,
			value
		);
	}

	/**
	 * Count this residency. Called from the DO constructor, so it is the
	 * observable for hibernation/eviction.
	 *
	 * @returns {number}
	 */
	bumpConstructions() {
		const prior = Number(this.metaGet(META_KEYS.constructions) ?? '0');
		const next = (Number.isFinite(prior) ? prior : 0) + 1;
		this.metaSet(META_KEYS.constructions, String(next));
		return next;
	}

	/**
	 * Encoder policy for values coming out of DO SQL. Durable Object SQL hands
	 * integers wider than 2^53-1 to JS as doubles, so the exact value is gone by
	 * the time we see it: refuse it by default, or emit it with a warning when
	 * ATOMS_SQL_UNSAFE_INTEGER=tag. Never silently.
	 *
	 * @returns {import('./int64.js').EncodeOptions}
	 */
	encodeOptions() {
		return {
			unsafeInteger: this.config.sqlUnsafeInteger,
			onLossy: (value) => {
				const identity = this.identityRef();
				console.log(
					JSON.stringify({
						ts: new Date().toISOString(),
						level: 'warning',
						source: 'host',
						msg: 'atoms.bridge.integer_precision_lost',
						atom: identity,
						value: String(value),
					})
				);
			},
		};
	}

	// -------------------------------------------------------------- sync door

	/**
	 * Dispatch one '!' message. Always returns a JSON reply string.
	 *
	 * @param {string} raw message with the tag byte already stripped
	 * @returns {string}
	 */
	handleSync(raw) {
		/** @type {any} */
		let msg;
		try {
			msg = JSON.parse(raw);
		} catch (e) {
			return fail('bad_host_message', `sync message is not JSON: ${String(e)}`);
		}
		if (typeof msg !== 'object' || msg === null || typeof msg.op !== 'string') {
			return fail('bad_host_message', 'sync message must be an object with an "op" string');
		}

		try {
			switch (msg.op) {
				case 'sql.exec':
					return this.opSqlExec(msg);
				case 'config.get':
					return this.opConfigGet(msg);
				case 'meta.get':
					return this.opMetaGet(msg);
				case 'meta.set':
					return this.opMetaSet(msg);
				case 'log':
					return this.opLog(msg);
				default:
					return fail('bad_host_message', `unknown sync op "${msg.op}"`);
			}
		} catch (e) {
			const n = normalizeError(e);
			if (n.code === 'internal') {
				console.log(
					JSON.stringify({
						ts: new Date().toISOString(),
						level: 'error',
						msg: 'atoms.bridge.sync_failed',
						op: msg.op,
						error: n.message,
					})
				);
			}
			return fail(n.code, n.message, n.detail);
		}
	}

	// ------------------------------------------------------------------- ops

	/**
	 * `{"op":"sql.exec","sql":string,"bindings":[...],"mode":"rows"|"run"}`
	 *
	 * @param {any} msg
	 * @returns {string}
	 */
	opSqlExec(msg) {
		const sql = msg.sql;
		if (typeof sql !== 'string' || sql.trim() === '') {
			throw new AtomsError('invalid_request', 'sql.exec requires a non-empty "sql" string');
		}
		const mode = msg.mode === undefined ? 'rows' : msg.mode;
		if (mode !== 'rows' && mode !== 'run') {
			throw new AtomsError('invalid_request', `sql.exec mode must be "rows" or "run", got ${JSON.stringify(mode)}`);
		}
		const rawBindings = msg.bindings === undefined || msg.bindings === null ? [] : msg.bindings;
		if (!Array.isArray(rawBindings)) {
			throw new AtomsError('invalid_request', 'sql.exec "bindings" must be an array (positional)');
		}
		if (rawBindings.length > this.config.sqlMaxBindings) {
			throw new AtomsError(
				'invalid_request',
				`sql.exec received ${rawBindings.length} bindings, limit is ${this.config.sqlMaxBindings}`
			);
		}

		// Checked on the WHOLE request text, before anything else looks at it:
		// `sql.exec` runs every statement in the string (the fixture's
		// 001_init.sql is three), and a pragma this host answers synthetically
		// must not become a way to smuggle a second statement past the guard.
		assertNoReservedTable(sql);

		const statement = normalizeStatement(sql);
		const pragma = matchPragma(statement);
		if (pragma) {
			const synthetic = this.handlePragma(pragma, mode);
			if (synthetic !== null) return synthetic;
			// else: fall through and let the DO answer it (e.g. foreign_keys).
		}

		// Workerd rejects bigint bindings, so integers wider than 2^53-1 are
		// folded into the statement text as validated decimal literals.
		const decoded = rawBindings.map((v) => toSqlBinding(decodeInt64Deep(v)));
		const prepared = inlineWideIntegers(sql, decoded);

		this.sqlCalls++;
		let cursor;
		try {
			cursor = prepared.bindings.length
				? this.sql.exec(prepared.sql, ...prepared.bindings)
				: this.sql.exec(prepared.sql);
		} catch (e) {
			throw sqlError(e);
		}

		/** @type {unknown[]} */
		const rows = [];
		try {
			if (mode === 'rows') {
				for (const row of cursor) {
					if (rows.length >= this.config.sqlMaxRows) {
						throw new AtomsError(
							'sql_error',
							`result set exceeds ATOMS_SQL_MAX_ROWS (${this.config.sqlMaxRows})`,
							{ detail: { sqlstate: 'HY000' } }
						);
					}
					rows.push(encodeInt64Deep(row, this.encodeOptions()));
				}
			} else {
				// Drain so the statement runs to completion; discard the rows.
				for (const _row of cursor) {
					/* discarded on purpose: mode "run" reports counters only */
				}
			}
		} catch (e) {
			if (e instanceof AtomsError) throw e;
			throw sqlError(e);
		}

		const rowsRead = Number(cursor.rowsRead ?? 0);
		const rowsWritten = Number(cursor.rowsWritten ?? 0);

		/** @type {Record<string, unknown>} */
		const reply = { rows_read: rowsRead, rows_written: rowsWritten };

		// `last_insert_rowid` is reported ONLY when this statement wrote, because
		// the guest caches whatever it is told (SqlBridge::exec) and PDO's
		// contract is that lastInsertId() survives every intervening read. Sending
		// a 0 for a plain SELECT would silently reset it, so a customer inserting
		// a parent row, reading something, then inserting children with
		// `parent_id = lastInsertId()` would write 0 for every child. The key is
		// absent instead, and the guest leaves its cached value alone.
		if (rowsWritten > 0) {
			try {
				const r = this.sql.exec('SELECT last_insert_rowid() AS id').toArray();
				reply.last_insert_rowid = encodeInt64Deep(r.length ? r[0].id : 0, this.encodeOptions());
			} catch (e) {
				throw sqlError(e);
			}
		}

		return mode === 'rows' ? ok({ rows, ...reply }) : ok(reply);
	}

	/**
	 * `PRAGMA` interception.
	 *
	 * - `user_version` (read and `= N` write) is mapped onto `__atoms_meta` so
	 *   the unmodified `Atoms\Migrations\Migrator` works against DO storage,
	 *   which has no user_version of its own.
	 * - `journal_mode` / `synchronous` / `busy_timeout` get synthetic no-op
	 *   answers: the DO owns durability, the guest does not.
	 * - everything else (including `foreign_keys`) returns `null` here and is
	 *   forwarded to `ctx.storage.sql` unchanged.
	 *
	 * @param {{name: string, value: string|null}} pragma
	 * @param {'rows'|'run'} mode
	 * @returns {string|null} the reply, or null to forward the statement
	 */
	handlePragma(pragma, mode) {
		// No `last_insert_rowid`: an intercepted pragma writes no row, and telling
		// the guest otherwise would reset its cached lastInsertId (see opSqlExec).
		const empty = { rows_read: 0, rows_written: 0 };

		if (pragma.name === 'user_version') {
			if (pragma.value === null) {
				const stored = Number(this.metaGet(META_KEYS.userVersion) ?? '0');
				const version = Number.isFinite(stored) ? Math.trunc(stored) : 0;
				return mode === 'rows'
					? ok({ rows: [{ user_version: version }], ...empty, rows_read: 1 })
					: ok({ ...empty, rows_read: 1 });
			}
			if (!/^-?\d+$/.test(pragma.value)) {
				throw new AtomsError('sql_error', `PRAGMA user_version = ${pragma.value} is not an integer`, {
					detail: { sqlstate: 'HY000' },
				});
			}
			this.metaSet(META_KEYS.userVersion, String(Math.trunc(Number(pragma.value))));
			return mode === 'rows' ? ok({ rows: [], ...empty }) : ok(empty);
		}

		if (!SYNTHETIC_PRAGMAS.has(pragma.name)) return null;

		/** @type {Record<string, string|number>} */
		let row;
		switch (pragma.name) {
			case 'journal_mode':
				// DO storage is not a file-backed SQLite the guest can reconfigure.
				row = { journal_mode: 'wal' };
				break;
			case 'synchronous':
				row = { synchronous: 1 };
				break;
			default:
				row = { timeout: pragma.value !== null && /^\d+$/.test(pragma.value) ? Number(pragma.value) : 0 };
				break;
		}
		return mode === 'rows' ? ok({ rows: [row], ...empty, rows_read: 1 }) : ok({ ...empty, rows_read: 1 });
	}

	/**
	 * `{"op":"config.get","key":string}` resolved from an allowlisted view of
	 * `env`: `foo.bar` -> `ATOMS_CONFIG_FOO_BAR` (prefix configurable), plus any
	 * exact env names listed in `ATOMS_CONFIG_ENV_KEYS`. Nothing else in `env`
	 * is reachable from the guest, and the deny list wins over both.
	 *
	 * @param {any} msg
	 * @returns {string}
	 */
	opConfigGet(msg) {
		const key = msg.key;
		if (typeof key !== 'string' || key === '') {
			throw new AtomsError('invalid_request', 'config.get requires a non-empty "key" string');
		}

		const deny = new Set(this.config.configEnvDenyKeys);
		/** @type {string[]} */
		const candidates = [];
		const normalized = this.config.configEnvPrefix + key.toUpperCase().replace(/[^A-Z0-9]+/g, '_');
		candidates.push(normalized);
		if (this.config.configEnvKeys.includes(key)) candidates.push(key);

		for (const name of candidates) {
			if (deny.has(name)) continue;
			const raw = this.env[name];
			if (typeof raw !== 'string') continue;
			return ok({ value: encodeInt64Deep(coerceConfigValue(raw), this.encodeOptions()) });
		}
		return ok({ value: null });
	}

	/**
	 * @param {any} msg
	 * @returns {string}
	 */
	opMetaGet(msg) {
		const key = requireMetaKey(msg.key);
		return ok({ value: this.metaGet(key) });
	}

	/**
	 * @param {any} msg
	 * @returns {string}
	 */
	opMetaSet(msg) {
		const key = requireMetaKey(msg.key);
		if (typeof msg.value !== 'string') {
			throw new AtomsError('invalid_request', 'meta.set requires a string "value"');
		}
		this.metaSet(key, msg.value);
		return ok({});
	}

	/**
	 * `{"op":"log","level":string,"fields":{...}}` — one structured JSON line.
	 *
	 * @param {any} msg
	 * @returns {string}
	 */
	opLog(msg) {
		const level = typeof msg.level === 'string' ? msg.level.toLowerCase() : 'info';
		const known = LOG_LEVELS.includes(level) ? level : 'info';
		if (LOG_LEVELS.indexOf(known) < LOG_LEVELS.indexOf(this.config.logLevel)) {
			return ok({ emitted: false });
		}

		const fields = typeof msg.fields === 'object' && msg.fields !== null ? msg.fields : {};
		/** @type {Record<string, unknown>} */
		const safe = {};
		for (const [k, v] of Object.entries(fields)) {
			safe[k] = truncate(v, this.config.logMaxFieldBytes);
		}
		const identity = this.identityRef();
		console.log(
			JSON.stringify({
				ts: new Date().toISOString(),
				level: known,
				source: 'php',
				atom: identity ? { type: identity.type, id: identity.id } : null,
				...safe,
			})
		);
		return ok({ emitted: true });
	}
}

/**
 * The tx.begin/commit/rollback park ops.
 *
 * @implements {object}
 */
export class TransactionMachine {
	/**
	 * @param {object} opts
	 * @param {any} opts.ctx
	 * @param {import('./config.js').AtomsConfig} opts.config
	 * @param {ParkHost} opts.host
	 */
	constructor({ ctx, config, host }) {
		this.ctx = ctx;
		this.config = config;
		this.host = host;
		/** @type {boolean} */
		this.open = false;
		/** Unique sentinel: thrown inside the callback to force a rollback. */
		this.SENTINEL = Symbol('atoms.tx.rollback');
	}

	/** Forget any transaction state (used when a residency is discarded). */
	reset() {
		this.open = false;
	}

	/**
	 * Service a `tx.begin` park.
	 *
	 * Returns once the guest has been resumed past commit/rollback and has
	 * parked again; the new park is left pending for the caller's loop.
	 *
	 * @param {ParkedCall} parked
	 */
	begin(parked) {
		if (this.open) {
			// PHP's Database::transaction() already guards nesting; this is
			// defense in depth, and it must not silently open a second one.
			parked.reply(fail('tx_state', 'a transaction is already open for this Atom'));
			return;
		}

		/** @type {ParkedCall|null} */
		let commitParked = null;
		/** @type {ParkedCall|null} */
		let rollbackParked = null;
		/** @type {ParkedCall|null} */
		let abandonedParked = null;

		this.open = true;
		try {
			this.ctx.storage.transactionSync(() => {
				// Resume the guest INSIDE the callback. Asyncify's rewind is a
				// synchronous call back into wasm, so every statement the guest
				// runs from here lands on this connection inside this transaction.
				parked.reply(ok({ opened: true }));
				const end = this.drain();
				if (end.kind === 'commit') {
					commitParked = end.parked;
					return;
				}
				if (end.kind === 'rollback') rollbackParked = end.parked;
				else abandonedParked = end.parked;
				throw this.SENTINEL;
			});
		} catch (e) {
			this.open = false;
			if (e === this.SENTINEL && rollbackParked) {
				// Cloudflare discarded the write set; tell the guest.
				/** @type {ParkedCall} */ (rollbackParked).reply(ok({ rolledBack: true }));
				return;
			}
			if (e === this.SENTINEL && abandonedParked) {
				this.finishAbandoned(/** @type {ParkedCall} */ (abandonedParked));
				return;
			}
			throw e instanceof AtomsError
				? e
				: new AtomsError('internal', `transactionSync failed: ${String(e)}`, { cause: e });
		}
		this.open = false;
		if (!commitParked) {
			throw new AtomsError('internal', 'transaction committed without a parked tx.commit');
		}
		/** @type {ParkedCall} */ (commitParked).reply(ok({ committed: true }));
	}

	/**
	 * The guest reached the turn boundary with a transaction still open, and the
	 * write set has just been discarded by the sentinel.
	 *
	 * The runtime prelude settles an abandoned transaction itself (`run_turn()`
	 * rolls it back and reports `atom_exception`), so this is the host's
	 * defence in depth for a guest that did not. It must not answer the park:
	 * `turn.await` is the turn boundary, so the park is handed back to
	 * `serviceParks()`, with its result replaced — the turn's writes are gone,
	 * and reporting the guest's `ok` result for them would be a silent lie.
	 *
	 * @param {ParkedCall} parked the pending `turn.await`
	 */
	finishAbandoned(parked) {
		parked.msg.result = {
			ok: false,
			error: {
				code: 'internal',
				message:
					'the turn ended with a database transaction still open; its writes were rolled back',
			},
		};
		this.host.restorePending(parked);
	}

	/**
	 * Pump parks until the guest reaches commit or rollback.
	 *
	 * Any other park op is rejected while a transaction is open (spec): the
	 * guest gets an error reply, which becomes a PHP exception, which its own
	 * `transaction()` wrapper turns into a `tx.rollback`.
	 *
	 * `turn.await` is the one exception. It is not a protocol violation to be
	 * answered but the end of the turn, so rejecting it would strand the guest:
	 * it would re-park at the same door with the rejection as its result and
	 * spin until `ATOMS_MAX_TX_PARK_STEPS`, poisoning the residency over what is
	 * only a customer forgetting `commit()`. It is reported as `abandoned`
	 * instead, which rolls the transaction back and hands the park on.
	 *
	 * @returns {{kind: 'commit'|'rollback'|'abandoned', parked: ParkedCall}}
	 */
	drain() {
		for (let steps = 0; steps < this.config.maxTxParkSteps; steps++) {
			const p = this.host.takePending();
			if (!p) {
				throw new AtomsError(
					'internal',
					'the PHP runtime left the transaction without parking (guest fatal?)'
				);
			}
			const op = typeof p.msg.op === 'string' ? p.msg.op : '(none)';
			if (op === 'tx.commit') return { kind: 'commit', parked: p };
			if (op === 'tx.rollback') return { kind: 'rollback', parked: p };
			if (op === 'turn.await') return { kind: 'abandoned', parked: p };
			p.reply(
				fail(
					'tx_state',
					`park op "${op}" is not allowed while a transaction is open; ` +
						'only tx.commit and tx.rollback are'
				)
			);
		}
		throw new AtomsError(
			'internal',
			`transaction exceeded ATOMS_MAX_TX_PARK_STEPS (${this.config.maxTxParkSteps}) park ops`
		);
	}
}

// ---------------------------------------------------------------- helpers

/**
 * Trim whitespace, a trailing semicolon and leading line comments so the
 * PRAGMA and reserved-table checks see the statement text.
 *
 * @param {string} sql
 * @returns {string}
 */
function normalizeStatement(sql) {
	return sql
		.replace(/^\s*(?:--[^\n]*\n|\/\*[\s\S]*?\*\/|\s)+/, '')
		.trim()
		.replace(/;\s*$/, '')
		.trim();
}

/**
 * `PRAGMA [schema.]name` or `PRAGMA [schema.]name = value`.
 *
 * Function-call form (`PRAGMA table_info(t)`) deliberately does not match: it
 * is forwarded to the DO unchanged.
 *
 * @param {string} statement
 * @returns {{name: string, value: string|null}|null}
 */
function matchPragma(statement) {
	// The value may not span lines: `PRAGMA journal_mode = wal\nUPDATE ...` must
	// NOT read as one pragma, or the trailing statement would be swallowed by a
	// synthetic answer and silently never run.
	const m = /^PRAGMA\s+(?:(?:main|temp)\s*\.\s*)?([A-Za-z_][A-Za-z0-9_]*)\s*(?:=\s*([^;\r\n]+?))?\s*$/i.exec(
		statement
	);
	if (!m) return null;
	const value = m[2] === undefined ? null : m[2].trim().replace(/^['"]|['"]$/g, '');
	return { name: m[1].toLowerCase(), value };
}

/**
 * Blank the parts of a statement that cannot name a table, so the reserved
 * prefix can be searched for in what is left.
 *
 * This walks the text the way SQLite's own tokenizer does rather than running
 * one regex over it: a `'` inside a `--` comment or inside a `"…"` identifier
 * does NOT open a string literal for SQLite, so a regex that pairs quotes
 * globally desynchronises from the parser and can be made to erase a real
 * statement from the checked text — `SELECT 1; -- it's fine` followed by
 * `UPDATE __atoms_meta …` was exactly that hole. Two rules keep the scanner and
 * SQLite in agreement:
 *
 *  - string literals (`'…'`, `''` escaping, including the `x'…'` blob form) are
 *    replaced by `''`, so a literal that merely mentions the prefix is fine;
 *  - comments (`--` to end of line, `/* … *\/` to the terminator or to EOF) are
 *    replaced by whitespace, so nothing can hide inside one — and neither can a
 *    statement hide *behind* one.
 *
 * Quoted and bracketed identifiers (`"…"`, `` `…` ``, `[…]`) are deliberately
 * kept verbatim: they are how a table is named, so `"__atoms_meta"` must remain
 * visible to the search.
 *
 * @param {string} sql
 * @returns {string}
 */
export function reservedTableScanResidue(sql) {
	let out = '';

	for (let i = 0; i < sql.length; i++) {
		const c = sql[i];

		if (c === "'") {
			i++;
			while (i < sql.length) {
				if (sql[i] === "'") {
					if (sql[i + 1] === "'") i++;
					else break;
				}
				i++;
			}
			// An unterminated literal consumes the rest of the text, exactly as
			// it does for SQLite (which then rejects the statement outright).
			out += "''";
			continue;
		}

		if (c === '"' || c === '`') {
			const quote = c;
			out += c;
			i++;
			while (i < sql.length) {
				out += sql[i];
				if (sql[i] === quote) {
					if (sql[i + 1] === quote) {
						i++;
						out += sql[i];
					} else break;
				}
				i++;
			}
			continue;
		}

		if (c === '[') {
			while (i < sql.length && sql[i] !== ']') {
				out += sql[i];
				i++;
			}
			out += i < sql.length ? sql[i] : '';
			continue;
		}

		if (c === '-' && sql[i + 1] === '-') {
			while (i < sql.length && sql[i] !== '\n') i++;
			out += '\n';
			continue;
		}

		if (c === '/' && sql[i + 1] === '*') {
			i += 2;
			while (i < sql.length && !(sql[i] === '*' && sql[i + 1] === '/')) i++;
			i++;
			out += ' ';
			continue;
		}

		out += c;
	}

	return out;
}

/**
 * Reject customer SQL that names a host-owned table.
 *
 * Best-effort by design (MVP, documented in the spec) in that it is a lexical
 * check and not a parse: it cannot tell a table name from a column alias that
 * happens to start with the prefix, and it errs towards refusing. What it is
 * NOT allowed to be is defeatable by ordinary SQL punctuation — see
 * {@link reservedTableScanResidue}.
 *
 * @param {string} sql the full request text, which may hold several statements
 */
function assertNoReservedTable(sql) {
	const residue = reservedTableScanResidue(sql);
	const re = new RegExp(RESERVED_TABLE_PREFIX.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'i');
	if (re.test(residue)) {
		throw new AtomsError(
			'reserved_table',
			`SQL referencing reserved "${RESERVED_TABLE_PREFIX}*" tables is not allowed`
		);
	}
}

/**
 * Turn a DO SQL failure into a typed `sql_error` with a PDO-shaped SQLSTATE.
 *
 * @param {unknown} e
 * @returns {AtomsError}
 */
function sqlError(e) {
	const message = e instanceof Error ? e.message : String(e);
	const sqlstate = /constraint failed|UNIQUE constraint|NOT NULL constraint|FOREIGN KEY constraint/i.test(
		message
	)
		? '23000'
		: 'HY000';
	return new AtomsError('sql_error', message, { cause: e, detail: { sqlstate } });
}

/**
 * @param {unknown} key
 * @returns {string}
 */
function requireMetaKey(key) {
	if (typeof key !== 'string' || key === '') {
		throw new AtomsError('invalid_request', 'meta ops require a non-empty "key" string');
	}
	return key;
}

/**
 * Config values arrive as env strings. JSON-decodable strings become their JSON
 * value (numbers, booleans, null, objects, arrays); everything else stays a
 * string.
 *
 * @param {string} raw
 * @returns {unknown}
 */
function coerceConfigValue(raw) {
	const t = raw.trim();
	if (t === '') return raw;
	if (!/^[-\d[{"tfn]/.test(t)) return raw;
	try {
		return JSON.parse(t);
	} catch {
		return raw;
	}
}

/**
 * @param {unknown} v
 * @param {number} maxBytes
 * @returns {unknown}
 */
function truncate(v, maxBytes) {
	if (typeof v !== 'string') return v;
	return v.length > maxBytes ? v.slice(0, maxBytes) + '…' : v;
}
