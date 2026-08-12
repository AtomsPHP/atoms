/**
 * The timers seam: the host side of `timer.schedule` / `timer.cancel` /
 * `timer.get` (sync ops dispatched from `Bridge.handleSync`), the
 * `__atoms_timers` table (DDL in `bridge.js`'s `ensureSchema()`, alongside
 * `__atoms_meta`), and the Durable Object alarm re-arm rule (M2 wave 3).
 *
 * Every op below runs its SQL through `this.sql` — literally the same
 * `ctx.storage.sql` reference `Bridge.opSqlExec` uses — so a schedule/cancel
 * issued while a database transaction is open (the guest is parked inside
 * `ctx.storage.transactionSync()`) lands inside that same transaction and is
 * rolled back with it, exactly like an ordinary `sql.exec` write.
 *
 * The host never calls `ctx.storage.setAlarm()`/`deleteAlarm()` from inside a
 * transaction: `rearm()`/`rearmIfTouched()` only ever run from the post-turn
 * hook in `atom-do.js`, after `runTurn()` has returned and any transaction
 * the turn opened has already committed or rolled back.
 */
import { TIMERS_TABLE } from './config.js';
import { AtomsError, normalizeError } from './errors.js';

const encoder = new TextEncoder();

/** @param {Record<string, unknown>} extra */
function ok(extra = {}) {
	return JSON.stringify({ ok: true, ...extra });
}

/**
 * @param {string} code
 * @param {string} message
 * @param {Record<string, unknown>} [extra]
 */
function fail(code, message, extra = {}) {
	return JSON.stringify({ ok: false, error: { code, message, ...extra } });
}

/**
 * `timer.schedule` requires a non-empty name within the byte cap. The code
 * `timer_invalid_name` is what {@see CfTimers} on the guest maps onto
 * ATOMS-E085 (`InvalidTimerName`).
 *
 * @param {unknown} raw
 * @param {number} maxBytes
 * @returns {string}
 */
function requireTimerName(raw, maxBytes) {
	if (typeof raw !== 'string' || raw === '') {
		throw new AtomsError('timer_invalid_name', 'timer name must be a non-empty string');
	}
	const bytes = encoder.encode(raw).length;
	if (bytes > maxBytes) {
		throw new AtomsError(
			'timer_invalid_name',
			`timer name is ${bytes} bytes, over ATOMS_TIMER_NAME_MAX_BYTES (${maxBytes})`
		);
	}
	return raw;
}

/**
 * `timer.cancel`/`timer.get` accept any string name, including one that was
 * never scheduled: cancelling or looking up an unknown name is a defined
 * no-op success, not an error (`Atoms\Timers\Timers`'s contract) — so this
 * only guards against a malformed (non-string) protocol message, never
 * against an empty or unknown one.
 *
 * @param {unknown} raw
 * @param {string} op
 * @returns {string}
 */
function requireNameString(raw, op) {
	if (typeof raw !== 'string') {
		throw new AtomsError('invalid_request', `${op} requires a string "name"`);
	}
	return raw;
}

/**
 * @param {unknown} raw
 * @returns {number}
 */
function requireDueAtMs(raw) {
	if (typeof raw !== 'number' || !Number.isFinite(raw)) {
		throw new AtomsError('invalid_request', 'timer.schedule requires a numeric "due_at_ms"');
	}
	return Math.trunc(raw);
}

/**
 * One instance per DO residency, alongside `Bridge`/`CallbackChannel`/
 * `WebSocketHost` in `atom-do.js`. Owns the `__atoms_timers` table access and
 * the residency-local "timers touched this turn" flag the re-arm rule reads.
 */
export class TimersHost {
	/**
	 * @param {object} opts
	 * @param {any} opts.ctx DurableObjectState
	 * @param {import('./config.js').AtomsConfig} opts.config
	 * @param {(level: string, fields: Record<string, unknown>) => void} opts.log
	 */
	constructor({ ctx, config, log }) {
		this.ctx = ctx;
		this.config = config;
		this.log = log;
		this.sql = ctx.storage.sql;

		// Set by any timer.* op this turn. Read-and-cleared by
		// rearmIfTouched() at the end of the turn that set it (atom-do.js's
		// settlePostTurn()) — never persisted, purely an optimisation so an
		// ordinary turn that never touches a timer never pays for a
		// MIN(due_at_ms) query or a setAlarm/deleteAlarm round trip.
		this.touched = false;

		// Debug-endpoint observable, monotonic per residency: how many timer
		// turns this residency's alarm() has fired.
		this.firedThisResidency = 0;
	}

	// -------------------------------------------------------------- sync ops

	/**
	 * `{"op":"timer.schedule","name":string,"due_at_ms":int}` — INSERT OR
	 * REPLACE by primary key: at most one outstanding timer per name
	 * (`Atoms\Timers\Timers`'s contract).
	 *
	 * @param {any} msg
	 * @returns {string}
	 */
	opSchedule(msg) {
		const name = requireTimerName(msg.name, this.config.timerNameMaxBytes);
		const dueAtMs = requireDueAtMs(msg.due_at_ms);

		// The cap is per-Atom, not per-call: replacing an existing timer's due
		// time must never be refused by it, so the count only matters for a
		// name that is not already scheduled.
		const existing = this.sql.exec(`SELECT 1 FROM ${TIMERS_TABLE} WHERE name = ?`, name).toArray();
		if (existing.length === 0) {
			const counted = this.sql.exec(`SELECT COUNT(*) AS n FROM ${TIMERS_TABLE}`).toArray();
			const count = Number(counted[0]?.n ?? 0);
			if (count >= this.config.timersMax) {
				throw new AtomsError(
					'timer_limit',
					`this Atom already has ${count} scheduled timers, at ATOMS_TIMERS_MAX (${this.config.timersMax})`,
					{ detail: { count } }
				);
			}
		}

		this.sql.exec(
			`INSERT INTO ${TIMERS_TABLE} (name, due_at_ms) VALUES (?, ?) ` +
				'ON CONFLICT(name) DO UPDATE SET due_at_ms = excluded.due_at_ms',
			name,
			dueAtMs
		);
		this.touched = true;

		return ok({});
	}

	/**
	 * `{"op":"timer.cancel","name":string}` — idempotent: cancelling a name
	 * with no pending timer is a silent success.
	 *
	 * @param {any} msg
	 * @returns {string}
	 */
	opCancel(msg) {
		const name = requireNameString(msg.name, 'timer.cancel');
		this.sql.exec(`DELETE FROM ${TIMERS_TABLE} WHERE name = ?`, name);
		this.touched = true;
		return ok({});
	}

	/**
	 * `{"op":"timer.get","name":string}` -> `{"ok":true,"due_at_ms":int|null}`.
	 * `due_at_ms` is a plain JSON number, never int64-tagged: it is epoch
	 * milliseconds, always far inside 2^53 for any timer a customer could
	 * plausibly schedule, so the wire carries it as an ordinary JSON integer
	 * (the int64 rule only taxes values that actually need tagging).
	 *
	 * @param {any} msg
	 * @returns {string}
	 */
	opGet(msg) {
		const name = requireNameString(msg.name, 'timer.get');
		const rows = this.sql.exec(`SELECT due_at_ms FROM ${TIMERS_TABLE} WHERE name = ?`, name).toArray();
		return ok({ due_at_ms: rows.length ? Number(rows[0].due_at_ms) : null });
	}

	/**
	 * Dispatch one timer.* sync op. Mirrors `Bridge.handleSync`'s contract:
	 * always returns a JSON reply string, never throws out of the sync door.
	 *
	 * @param {string} op
	 * @param {any} msg
	 * @returns {string}
	 */
	handleSync(op, msg) {
		try {
			switch (op) {
				case 'timer.schedule':
					return this.opSchedule(msg);
				case 'timer.cancel':
					return this.opCancel(msg);
				case 'timer.get':
					return this.opGet(msg);
				default:
					return fail('bad_host_message', `unknown timer sync op "${op}"`);
			}
		} catch (e) {
			const n = normalizeError(e);
			return fail(n.code, n.message, n.detail);
		}
	}

	// ---------------------------------------------------------- alarm plumbing

	/**
	 * Due rows, host-side, WITHOUT booting PHP — `atom-do.js`'s `alarm()`
	 * calls this before deciding whether there is anything to dispatch at
	 * all. Bounded by `limit` (`ATOMS_TIMERS_MAX_PER_ALARM`) and ordered so a
	 * residency with more due timers than one `alarm()` run will process
	 * always fires the OLDEST ones first.
	 *
	 * @param {number} nowMs
	 * @param {number} limit
	 * @returns {{name: string, due_at_ms: number}[]}
	 */
	dueRows(nowMs, limit) {
		return /** @type {{name: string, due_at_ms: number}[]} */ (
			this.sql
				.exec(
					`SELECT name, due_at_ms FROM ${TIMERS_TABLE} WHERE due_at_ms <= ? ORDER BY due_at_ms ASC, name ASC LIMIT ?`,
					nowMs,
					limit
				)
				.toArray()
		);
	}

	/**
	 * At-most-once firing: `atom-do.js`'s alarm loop calls this BEFORE
	 * dispatching the timer turn, never after, so a throwing (or
	 * residency-poisoning) `onTimer` still consumes the timer rather than
	 * being retried on a later alarm.
	 *
	 * @param {string} timerName
	 */
	deleteRow(timerName) {
		this.sql.exec(`DELETE FROM ${TIMERS_TABLE} WHERE name = ?`, timerName);
	}

	/** Record one alarm-driven timer dispatch, for the debug endpoint. */
	noteFired() {
		this.firedThisResidency++;
	}

	/**
	 * Unconditional re-arm: `SELECT MIN(due_at_ms)`, then `setAlarm()` (a past
	 * value is fine — the platform fires immediately) or `deleteAlarm()` when
	 * nothing is scheduled. Never called from inside a transaction: every
	 * caller runs after a turn's `runTurn()` has returned, by which point any
	 * transaction it opened has already committed or rolled back.
	 */
	async rearm() {
		const rows = this.sql.exec(`SELECT MIN(due_at_ms) AS due FROM ${TIMERS_TABLE}`).toArray();
		const due = rows.length ? rows[0].due : null;
		if (due === null || due === undefined) {
			await this.ctx.storage.deleteAlarm();
		} else {
			await this.ctx.storage.setAlarm(Number(due));
		}
	}

	/**
	 * The re-arm rule's turn-boundary half: only pay for `rearm()` when THIS
	 * turn actually touched a timer. Resets the flag whether or not anything
	 * was found, so the next turn starts clean.
	 */
	async rearmIfTouched() {
		if (!this.touched) return;
		this.touched = false;
		await this.rearm();
	}

	// ------------------------------------------------------------- debug info

	/**
	 * The `"timers"` block of `AtomDurableObject.info()`. Reads only
	 * `__atoms_timers` through `this.sql` — never boots PHP, so it stays
	 * usable on an evicted residency (same rule as `WebSocketHost.debugBlock`).
	 *
	 * @returns {{scheduled: number, next_due_at_ms: number|null, fired_this_residency: number}}
	 */
	debugBlock() {
		const count = this.sql.exec(`SELECT COUNT(*) AS n FROM ${TIMERS_TABLE}`).toArray();
		const next = this.sql.exec(`SELECT MIN(due_at_ms) AS due FROM ${TIMERS_TABLE}`).toArray();
		const dueVal = next.length ? next[0].due : null;

		return {
			scheduled: Number(count[0]?.n ?? 0),
			next_due_at_ms: dueVal === null || dueVal === undefined ? null : Number(dueVal),
			fired_this_residency: this.firedThisResidency,
		};
	}
}
