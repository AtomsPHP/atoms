/**
 * Typed host errors and their HTTP mapping.
 *
 * Every failure the Worker can produce carries one of the codes below. Nothing
 * in this Worker fails silently: unimplemented surfaces throw `AtomsError` with
 * `not_supported`, never a no-op answer.
 *
 * The public envelope is `{"error":{"code","message","retryable"}}`. The
 * turn-result codes defined by the MVP spec (`atom_exception`, `method_not_found`, `atom_not_found`,
 * `internal`) are passed through verbatim so the client sees exactly what PHP
 * reported.
 */

/**
 * @typedef {(
 *   | 'invalid_request'
 *   | 'unauthenticated'
 *   | 'misconfigured'
 *   | 'not_found'
 *   | 'method_not_allowed'
 *   | 'unknown_atom_type'
 *   | 'atom_not_found'
 *   | 'method_not_found'
 *   | 'atom_exception'
 *   | 'identity_conflict'
 *   | 'payload_too_large'
 *   | 'not_supported'
 *   | 'sql_error'
 *   | 'sql_result_too_large'
 *   | 'sql_columns_unavailable'
 *   | 'reserved_table'
 *   | 'int64_range'
 *   | 'int64_precision'
 *   | 'unsupported_value'
 *   | 'tx_state'
 *   | 'bad_host_message'
 *   | 'turn_deadline_exceeded'
 *   | 'ws_conn_gone'
 *   | 'ws_fanout_limit'
 *   | 'timer_invalid_name'
 *   | 'timer_limit'
 *   | 'ticket_invalid'
 *   | 'ticket_expired'
 *   | 'internal'
 * )} AtomsErrorCode
 */

/** @type {Record<string, {status: number, retryable: boolean}>} */
const CODE_TABLE = {
	invalid_request: { status: 400, retryable: false },
	unauthenticated: { status: 401, retryable: false },
	// The Worker's own configuration is unusable: ATOMS_SHARED_SECRET is absent
	// or does not decode to exactly 32 bytes of base64 (or the optional
	// ATOMS_SHARED_SECRET_PREVIOUS overlap is set and malformed). Every route
	// except GET /healthz refuses with this code, so a misconfigured Worker is
	// loudly broken rather than silently open. 500 because the fault is the
	// deployment's, not the caller's, and not retryable because re-sending the
	// same request cannot fix a secret — the message names the variable and the
	// rule, and the operator-facing catalog entry is ATOMS-E105.
	misconfigured: { status: 500, retryable: false },
	not_found: { status: 404, retryable: false },
	method_not_allowed: { status: 405, retryable: false },
	unknown_atom_type: { status: 404, retryable: false },
	atom_not_found: { status: 404, retryable: false },
	method_not_found: { status: 404, retryable: false },
	atom_exception: { status: 500, retryable: false },
	identity_conflict: { status: 409, retryable: false },
	payload_too_large: { status: 413, retryable: false },
	not_supported: { status: 501, retryable: false },
	sql_error: { status: 500, retryable: false },
	// A result set hit ATOMS_SQL_MAX_ROWS or ATOMS_SQL_MAX_RESULT_BYTES
	// (design §4.3). Deliberately distinct from sql_error: "your query was
	// wrong" and "your query returned too much" call for opposite client
	// responses, and detail.cap ('rows'|'bytes') says which cap fired.
	sql_result_too_large: { status: 500, retryable: false },
	// rows-mode's `cursor.columnNames` is what lets
	// AtomsStatement detect duplicate result-set column names and refuse the
	// fetch modes that would otherwise silently answer wrong under them
	// (Branch A, design §2.7). Measured present on every workerd build this
	// milestone has exercised, so no local conformance run can trigger this
	// — it exists to fail loudly, not silently degrade to `columns: []`, on
	// a future deployed build where the platform capability regresses.
	sql_columns_unavailable: { status: 500, retryable: false },
	reserved_table: { status: 400, retryable: false },
	int64_range: { status: 400, retryable: false },
	int64_precision: { status: 500, retryable: false },
	unsupported_value: { status: 500, retryable: false },
	tx_state: { status: 500, retryable: false },
	bad_host_message: { status: 500, retryable: false },
	// The id resolved to no socket: a connection that already closed,
	// or a broadcast fan-out that would exceed ATOMS_WS_MAX_BROADCAST_SOCKETS.
	// Neither is the caller's fault in the retry sense — the recipient is gone
	// or the cap needs raising — so both are non-retryable.
	ws_conn_gone: { status: 500, retryable: false },
	ws_fanout_limit: { status: 500, retryable: false },
	// Timer refusals (§Timers). Both are raised on the sync door and become
	// ATOMS-E085/E086 in the guest, so neither should ever reach an HTTP
	// envelope — they are tabulated anyway so that if one ever escapes through
	// `normalizeError()` it maps to a truthful status instead of being silently
	// reclassified as a retryable `internal`. A bad timer name is not retryable.
	timer_invalid_name: { status: 500, retryable: false },
	timer_limit: { status: 500, retryable: false },
	// Connection-ticket refusals (spec §Routing and auth). Both are
	// credential failures on the /ws upgrade, so 401 like `unauthenticated` —
	// but distinct slugs for logs, server-side probes and non-browser
	// clients: ticket_invalid (malformed, forged, mis-scoped, or signed under
	// a secret this deployment does not hold) and ticket_expired both mean
	// "mint a fresh ticket". Neither is retryable: re-sending the same request re-presents
	// the same ticket, which can never succeed. Both are raised at the edge
	// before any DO is addressed.
	ticket_invalid: { status: 401, retryable: false },
	ticket_expired: { status: 401, retryable: false },
	// Matches what atoms/client already expects (AtomsClient.php maps this
	// to TurnDeadlineExceeded and only retries when the call site opts in).
	turn_deadline_exceeded: { status: 504, retryable: true },
	internal: { status: 500, retryable: true },
};

export class AtomsError extends Error {
	/**
	 * @param {AtomsErrorCode|string} code
	 * @param {string} message
	 * @param {{cause?: unknown, detail?: Record<string, unknown>}} [opts]
	 */
	constructor(code, message, opts = {}) {
		super(message, opts.cause !== undefined ? { cause: opts.cause } : undefined);
		this.name = 'AtomsError';
		/** @type {string} */
		this.code = code;
		/** @type {Record<string, unknown>} */
		this.detail = opts.detail ?? {};
	}
}

/**
 * HTTP status for an error code. Unknown codes are treated as `internal`.
 *
 * @param {string} code
 * @returns {number}
 */
export function statusFor(code) {
	return (CODE_TABLE[code] ?? CODE_TABLE.internal).status;
}

/**
 * Whether the SDK may retry, per the platform contract's `retryable` column.
 *
 * @param {string} code
 * @returns {boolean}
 */
export function retryableFor(code) {
	return (CODE_TABLE[code] ?? CODE_TABLE.internal).retryable;
}

/**
 * Normalize anything thrown into `{code, message, detail}`.
 *
 * Non-`AtomsError` throwables are runtime bugs and become `internal`; their
 * message is preserved for the server-side log but callers decide what to
 * expose.
 *
 * @param {unknown} err
 * @returns {{code: string, message: string, detail: Record<string, unknown>}}
 */
export function normalizeError(err) {
	if (err instanceof AtomsError) {
		return { code: err.code, message: err.message, detail: err.detail };
	}
	if (err instanceof Error) {
		return { code: 'internal', message: `${err.name}: ${err.message}`, detail: {} };
	}
	return { code: 'internal', message: String(err), detail: {} };
}
