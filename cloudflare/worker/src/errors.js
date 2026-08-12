/**
 * Typed host errors and their HTTP mapping.
 *
 * Every failure the Worker can produce carries one of the codes below. Nothing
 * in this Worker fails silently: unimplemented surfaces throw `AtomsError` with
 * `not_supported`, never a no-op answer.
 *
 * The public envelope mirrors `atoms-framework/docs/platform/api-contract.md`:
 * `{"error":{"code","message","retryable"}}`. The turn-result codes defined by
 * the MVP spec (`atom_exception`, `method_not_found`, `atom_not_found`,
 * `internal`) are passed through verbatim so the client sees exactly what PHP
 * reported.
 */

/**
 * @typedef {(
 *   | 'invalid_request'
 *   | 'unauthenticated'
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
 *   | 'reserved_table'
 *   | 'int64_range'
 *   | 'int64_precision'
 *   | 'unsupported_value'
 *   | 'tx_state'
 *   | 'bad_host_message'
 *   | 'turn_deadline_exceeded'
 *   | 'internal'
 * )} AtomsErrorCode
 */

/** @type {Record<string, {status: number, retryable: boolean}>} */
const CODE_TABLE = {
	invalid_request: { status: 400, retryable: false },
	unauthenticated: { status: 401, retryable: false },
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
	reserved_table: { status: 400, retryable: false },
	int64_range: { status: 400, retryable: false },
	int64_precision: { status: 500, retryable: false },
	unsupported_value: { status: 500, retryable: false },
	tx_state: { status: 500, retryable: false },
	bad_host_message: { status: 500, retryable: false },
	// Matches the retired platform contract's table and what atoms/client
	// already expects (AtomsClient.php maps this to TurnDeadlineExceeded and
	// only retries when the call site opts in) — see design doc §9.4.
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
