/**
 * Lossless int64 codec for the PHP<->JS boundary.
 *
 * JSON numbers are exact only through 2^53-1, but PHP ints and SQLite INTEGERs
 * are 64-bit. Any integer whose absolute value exceeds 2^53-1 therefore crosses
 * the boundary tagged:
 *
 *     {"$atoms_int64": "<decimal string>"}
 *
 * in bindings, result rows, `last_insert_rowid`, method args and method
 * results. JS works in `BigInt` internally; PHP decodes to a native int (this
 * build is 64-bit). A tagged value outside int64 range is an error, never a
 * truncation.
 *
 * The codec is applied recursively and is the only place that decides when a
 * number is "big".
 *
 * MEASURED WORKERD BEHAVIOUR (wrangler 4.118 / `wrangler dev`, 2026-08-04):
 * `ctx.storage.sql` does NOT speak BigInt in either direction.
 *
 *   - binding a `bigint` throws `TypeError: Cannot convert a BigInt value to a
 *     number`, so wide integers must be inlined into the statement text as
 *     decimal literals (`inlineWideIntegers` below);
 *   - reading an INTEGER wider than 2^53-1 yields a lossy JS `number`
 *     (`SELECT 9223372036854775807` came back as 9223372036854775808), so a
 *     value read back that way cannot be trusted. `encodeInt64Deep` refuses it
 *     by default rather than returning a quietly wrong integer.
 *
 * REAL columns arrive as `number` too, indistinguishable from a widened
 * INTEGER, so the refusal above is bounded as tightly as the platform allows:
 * it applies only where an INTEGER could actually have produced the value
 * (magnitude <= 2^63, see `INT64_MAX_DOUBLE`). Anything larger is provably a
 * REAL and crosses unchanged.
 */
import { AtomsError } from './errors.js';

/** The wire tag. Must match the PHP-side codec exactly. */
export const INT64_TAG = '$atoms_int64';

export const INT64_MIN = -(2n ** 63n);
export const INT64_MAX = 2n ** 63n - 1n;

const SAFE_MAX = BigInt(Number.MAX_SAFE_INTEGER);
const SAFE_MIN = BigInt(Number.MIN_SAFE_INTEGER);

/**
 * 2^63 as an exact double — the largest magnitude any SQLite INTEGER can have
 * *after* being widened to a double.
 *
 * INT64_MAX (2^63-1) is not representable as a double and rounds up to exactly
 * 2^63; INT64_MIN is exactly -2^63. So an integral double whose magnitude is
 * strictly greater than this cannot have come from an INTEGER column at all:
 * it is a REAL, its double value is exact, and it crosses the boundary as a
 * plain JSON number. Below (and at) this bound an INTEGER and a REAL are
 * indistinguishable once workerd has handed both to JS as a `number`, which is
 * what `EncodeOptions.unsafeInteger` arbitrates.
 */
const INT64_MAX_DOUBLE = 9223372036854775808;

/**
 * Is this a tagged int64 object (and nothing else)?
 *
 * @param {unknown} v
 * @returns {boolean}
 */
export function isTaggedInt64(v) {
	return (
		typeof v === 'object' &&
		v !== null &&
		!Array.isArray(v) &&
		Object.prototype.hasOwnProperty.call(v, INT64_TAG)
	);
}

/**
 * Build the wire form for a bigint, checking int64 range.
 *
 * @param {bigint} v
 * @returns {{[INT64_TAG]: string}}
 */
export function tagInt64(v) {
	assertInt64Range(v);
	return { [INT64_TAG]: v.toString(10) };
}

/**
 * @param {bigint} v
 * @returns {bigint}
 */
function assertInt64Range(v) {
	if (v < INT64_MIN || v > INT64_MAX) {
		throw new AtomsError(
			'int64_range',
			`integer ${v.toString(10)} is outside the signed 64-bit range`
		);
	}
	return v;
}

/**
 * Encode one integral value: a plain JSON number when it is exactly
 * representable, a tagged object otherwise.
 *
 * @param {bigint|number} v
 * @returns {number|{[INT64_TAG]: string}}
 */
export function encodeInteger(v) {
	const b = typeof v === 'bigint' ? v : BigInt(v);
	assertInt64Range(b);
	if (b >= SAFE_MIN && b <= SAFE_MAX) return Number(b);
	return { [INT64_TAG]: b.toString(10) };
}

/**
 * @typedef {object} EncodeOptions
 * @property {'error'|'tag'|'float'} [unsafeInteger]
 *   What to do with an integral JS number in the ambiguous band — magnitude
 *   above 2^53-1 but no greater than 2^63. Workerd hands INTEGER and REAL
 *   columns to JS identically, so such a value is *either* a wide INTEGER whose
 *   low bits are already gone *or* an exactly-representable REAL:
 *   `error` (default) refuses it with `int64_precision`; `tag` reads it as an
 *   integer and emits the tagged (possibly rounded) value; `float` reads it as
 *   the floating-point number it is and emits it verbatim. `tag` and `float`
 *   both call `onLossy` so the host can log the fact. There is no silent mode.
 * @property {(value: number) => void} [onLossy]
 */

/**
 * Recursively encode a JS value into its JSON-safe wire form.
 *
 * Accepts what `ctx.storage.sql` can return (number, bigint, string, null,
 * ArrayBuffer) plus arrays and plain objects. Binary column values are not
 * supported by the wire format and throw rather than being silently
 * mangled by `JSON.stringify`.
 *
 * @param {unknown} value
 * @param {EncodeOptions} [opts]
 * @returns {unknown}
 */
export function encodeInt64Deep(value, opts = {}) {
	if (value === null || value === undefined) return null;

	switch (typeof value) {
		case 'bigint':
			return encodeInteger(value);
		case 'number':
			if (!Number.isFinite(value)) {
				throw new AtomsError(
					'unsupported_value',
					`non-finite number (${String(value)}) cannot cross the PHP boundary`
				);
			}
			if (Number.isInteger(value) && (value > Number.MAX_SAFE_INTEGER || value < Number.MIN_SAFE_INTEGER)) {
				// Provably not a widened INTEGER (see INT64_MAX_DOUBLE): a REAL,
				// exact as a double, so it crosses as the number it is. Refusing it
				// would make large floating-point columns writable but unreadable.
				if (Math.abs(value) > INT64_MAX_DOUBLE) return value;

				const policy = opts.unsafeInteger ?? 'error';
				if (policy === 'error') {
					throw new AtomsError(
						'int64_precision',
						`the value ${value.toString()} reached JS as a double, so the host cannot tell ` +
							'a wide INTEGER (whose exact value is already lost — Durable Object SQL ' +
							'returns INTEGERs as JS numbers) from an exact REAL; select integers wider ' +
							'than 2^53-1 as TEXT (CAST(col AS TEXT)), or set ATOMS_SQL_UNSAFE_INTEGER=tag ' +
							'to accept the rounded integer / =float to accept the value as a float'
					);
				}
				opts.onLossy?.(value);
				return policy === 'float' ? value : encodeInteger(BigInt(value));
			}
			return value;
		case 'string':
		case 'boolean':
			return value;
		case 'object':
			break;
		default:
			throw new AtomsError(
				'unsupported_value',
				`value of type ${typeof value} cannot cross the PHP boundary`
			);
	}

	if (Array.isArray(value)) return value.map((v) => encodeInt64Deep(v, opts));

	if (value instanceof ArrayBuffer || ArrayBuffer.isView(value)) {
		throw new AtomsError(
			'unsupported_value',
			'binary (BLOB) values are not supported by the PHP<->JS wire format'
		);
	}

	if (isTaggedInt64(value)) {
		// Already tagged (e.g. re-encoding a decoded payload): re-validate.
		return tagInt64(parseTaggedInt64(value));
	}

	const proto = Object.getPrototypeOf(value);
	if (proto !== Object.prototype && proto !== null) {
		throw new AtomsError(
			'unsupported_value',
			`value of class ${value.constructor?.name ?? 'unknown'} cannot cross the PHP boundary`
		);
	}

	/** @type {Record<string, unknown>} */
	const out = {};
	for (const [k, v] of Object.entries(/** @type {Record<string, unknown>} */ (value))) {
		out[k] = encodeInt64Deep(v, opts);
	}
	return out;
}

/**
 * Read a tagged object back into a bigint, validating the decimal string.
 *
 * @param {Record<string, unknown>} v
 * @returns {bigint}
 */
export function parseTaggedInt64(v) {
	const raw = v[INT64_TAG];
	if (typeof raw !== 'string' || !/^-?\d+$/.test(raw)) {
		throw new AtomsError(
			'int64_range',
			`${INT64_TAG} must be a decimal integer string, got ${JSON.stringify(raw)}`
		);
	}
	return assertInt64Range(BigInt(raw));
}

/**
 * Recursively decode wire values: tagged objects become `BigInt`, everything
 * else is left alone.
 *
 * @param {unknown} value
 * @returns {unknown}
 */
export function decodeInt64Deep(value) {
	if (value === null || typeof value !== 'object') return value;
	if (Array.isArray(value)) return value.map((v) => decodeInt64Deep(v));
	if (isTaggedInt64(value)) return parseTaggedInt64(/** @type {Record<string, unknown>} */ (value));

	/** @type {Record<string, unknown>} */
	const out = {};
	for (const [k, v] of Object.entries(/** @type {Record<string, unknown>} */ (value))) {
		out[k] = decodeInt64Deep(v);
	}
	return out;
}

/**
 * Coerce a decoded binding into something `ctx.storage.sql.exec` accepts.
 *
 * `SqlStorageValue` is `ArrayBuffer | string | number | null` — workerd rejects
 * `bigint` outright — so a `bigint` here always goes on to `inlineWideIntegers()`
 * to fold into the statement text, regardless of its magnitude.
 *
 * MEASURED (temp instrumentation): binding a
 * plain JS `number` — including an integral one, e.g. `42` — to
 * `ctx.storage.sql`, `typeof(?)` on the bound param reports `'real'`, never
 * `'integer'`. Workerd's binder has no way to tell "this JS number is an
 * integer" from "this JS number is a double that happens to be integral";
 * every `number` binds with SQLite storage class REAL. A validated decimal
 * literal folded into the statement text, by contrast, is parsed by SQLite
 * itself and gets the storage class its own literal grammar assigns — an
 * integer literal is INTEGER. So this used to collapse a small `bigint`
 * (produced only for a value the PHP side tagged `$atoms_int64`, i.e. every
 * genuine PHP `int` — see `Atoms\Cf\SqlBridge::tagIntBindings()`) back into a
 * plain `number` when it fit a double exactly, which silently re-introduced
 * the REAL-storage-class bug for ordinary small ints. Every genuinely-int
 * PHP value is tagged now, not only the ones outside JSON's safe range, so
 * every one of them takes the literal-inlining path instead of a parameter
 * bind, and SQLite reports the correct INTEGER storage class. Genuine PHP
 * floats (including an integral one, e.g. `2.0`) are never tagged and stay
 * plain `number`s bound as parameters — REAL storage class, which is what
 * they should have.
 *
 * @param {unknown} v
 * @returns {string|number|bigint|null|ArrayBuffer}
 */
export function toSqlBinding(v) {
	if (v === null || v === undefined) return null;
	if (typeof v === 'string' || typeof v === 'number') return v;
	if (typeof v === 'boolean') return v ? 1 : 0;
	if (typeof v === 'bigint') {
		assertInt64Range(v);
		return v;
	}
	if (v instanceof ArrayBuffer) return v;
	throw new AtomsError(
		'unsupported_value',
		`binding of type ${Array.isArray(v) ? 'array' : typeof v} is not a supported SQL value`
	);
}

/**
 * Fold bindings that workerd cannot carry as a parameter into the statement
 * text — originally just the ones too wide for a double (`bigint` outside
 * the JS-safe range), now every `bigint` binding regardless of magnitude
 * (see `toSqlBinding()`), because a plain bound `number` always gets SQLite
 * storage class REAL and a genuine PHP int must not.
 *
 * Durable Object SQL rejects `bigint` bindings outright in any case, so this
 * was already the only way a wide integer could reach SQLite. The
 * substitution is safe by construction: the value is a validated decimal
 * integer, never customer text. Every other binding keeps its position and
 * stays a real bound parameter.
 *
 * @param {string} sql
 * @param {(string|number|bigint|null|ArrayBuffer)[]} bindings
 * @returns {{sql: string, bindings: (string|number|null|ArrayBuffer)[]}}
 */
export function inlineWideIntegers(sql, bindings) {
	if (!bindings.some((b) => typeof b === 'bigint')) {
		return { sql, bindings: /** @type {(string|number|null|ArrayBuffer)[]} */ (bindings) };
	}

	const positions = findPlaceholders(sql);
	if (positions.length !== bindings.length) {
		// This path is reached whenever the
		// BINDINGS ARRAY happens to contain at least one wide integer — not
		// only when the wide integer itself is what's mismatched. A plain
		// arity mistake (wrong number of bindings for unrelated reasons)
		// that merely CO-OCCURS with a bigint binding used to be reported
		// with "cannot bind an integer wider than 2^53-1" framing that had
		// nothing to do with the actual problem. Neutral wording covers
		// both cases honestly.
		throw new AtomsError(
			'sql_error',
			`statement has ${positions.length} positional placeholders but ${bindings.length} bindings were supplied`
		);
	}

	let out = '';
	let last = 0;
	/** @type {(string|number|null|ArrayBuffer)[]} */
	const rest = [];
	positions.forEach((pos, i) => {
		const b = bindings[i];
		if (typeof b === 'bigint') {
			out += sql.slice(last, pos) + b.toString(10);
			last = pos + 1;
		} else {
			rest.push(/** @type {string|number|null|ArrayBuffer} */ (b));
		}
	});
	out += sql.slice(last);
	return { sql: out, bindings: rest };
}

/**
 * Indexes of the `?` placeholders in a statement, skipping string literals,
 * quoted identifiers and comments.
 *
 * @param {string} sql
 * @returns {number[]}
 */
export function findPlaceholders(sql) {
	/** @type {number[]} */
	const out = [];
	for (let i = 0; i < sql.length; i++) {
		const c = sql[i];
		if (c === "'" || c === '"' || c === '`') {
			const quote = c;
			i++;
			while (i < sql.length) {
				if (sql[i] === quote) {
					if (sql[i + 1] === quote) i++;
					else break;
				}
				i++;
			}
			continue;
		}
		if (c === '[') {
			while (i < sql.length && sql[i] !== ']') i++;
			continue;
		}
		if (c === '-' && sql[i + 1] === '-') {
			while (i < sql.length && sql[i] !== '\n') i++;
			continue;
		}
		if (c === '/' && sql[i + 1] === '*') {
			i += 2;
			while (i < sql.length && !(sql[i] === '*' && sql[i + 1] === '/')) i++;
			i++;
			continue;
		}
		if (c === '?') {
			if (/[0-9]/.test(sql[i + 1] ?? '')) {
				throw new AtomsError(
					'sql_error',
					'numbered parameters (?NNN) are not supported; use plain positional "?" placeholders'
				);
			}
			out.push(i);
		}
	}
	return out;
}
