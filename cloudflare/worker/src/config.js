/**
 * Env-derived settings, resolved in exactly one place.
 *
 * Workspace rule: no capacity constants in code. Every TTL, cap, deadline,
 * limit and poll interval below comes from an environment variable with a
 * default resolved here; nothing else in `src/` may hardcode one.
 *
 * Everything is a string in `env` (Wrangler `vars` and secrets are strings),
 * so each getter coerces and falls back to the documented default when the
 * variable is absent or unparseable.
 */

import { AtomsError } from './errors.js';

/** @typedef {Record<string, unknown>} Env */

/**
 * @typedef {object} AtomsConfig
 * @property {string}   appKey                Bearer key; empty string = auth disabled.
 * @property {boolean}  debugEndpoints        Enable `GET /debug/:type/:id/info`.
 * @property {number}   maxRequestBytes       Reject invoke bodies larger than this.
 * @property {number}   maxAtomIdBytes        Reject atom ids longer than this.
 * @property {number}   maxJsonDepth          Reject invoke args nested deeper than this (the guest's json_decode has a depth limit of its own).
 * @property {number}   activationTimeoutMs   Wall-clock budget for the activation gate.
 * @property {number}   activationPollMs      Sleep between polls while waiting for the first park.
 * @property {number}   activationMaxPolls    Hard spin cap for the same wait.
 * @property {number}   parkWaitTimeoutMs     Budget for waiting on a park that has not arrived synchronously.
 * @property {number}   maxParkStepsPerTurn   Park ops serviced in one turn before the turn is declared runaway.
 * @property {number}   maxTxParkSteps        Park ops serviced inside one open transaction before it is declared runaway.
 * @property {'error'|'tag'|'float'} sqlUnsafeInteger What to do when DO SQL returns an integral double wider than 2^53-1, which may be a lossy INTEGER or an exact REAL.
 * @property {number}   sqlMaxRows            Row cap for a single `sql.exec` in `rows` mode.
 * @property {number}   sqlMaxResultBytes     Byte cap for the rows of a single `sql.exec` in `rows` mode.
 * @property {number}   sqlMaxBindings        Binding cap for a single `sql.exec`.
 * @property {number}   logMaxFieldBytes      Truncation cap for a single logged field.
 * @property {string}   logLevel              Minimum level emitted by the `log` op.
 * @property {string}   configEnvPrefix       Env prefix that forms the `config.get` allowlist.
 * @property {string[]} configEnvKeys         Extra exact env names readable through `config.get`.
 * @property {string[]} configEnvDenyKeys     Names never readable through `config.get`, whatever else says. Always includes the built-in defaults; `ATOMS_CONFIG_ENV_DENY_KEYS` is additive to them, never a replacement.
 * @property {string}   bootstrapPath         Guest path of the PHP bootstrap script.
 * @property {string}   bootPayloadPath       Guest path the boot payload JSON is written to.
 * @property {string}   runtimeDir            Guest directory holding the `Atoms\\Cf` prelude.
 * @property {string}   coreDir               Guest directory holding the verbatim atoms/core sources.
 * @property {string[]} guestDirs             Directories created in MEMFS before files are written.
 * @property {number}   bundleFormat          Bundle format version this host understands.
 * @property {string}   callbackUrl           The monolith's callback endpoint. '' = unconfigured.
 * @property {string}   callbackSigningKey    Base64 of the 32-byte Ed25519 seed used to sign callbacks.
 * @property {number}   turnDeadlineMs        Aggregate budget for one turn's time spent awaiting the callback channel.
 * @property {number}   callbackTimeoutMs     Per-POST abort bound for one callback.
 * @property {number}   callbackMaxRequestBytes  Reject an app()/dispatch() request body larger than this.
 * @property {number}   callbackMaxResponseBytes Reject an app() response body larger than this (it is copied into guest memory).
 * @property {number}   maxDispatchesPerTurn  dispatch() calls allowed in one turn before `dispatch_limit`.
 * @property {'configured'|'unconfigured'|'misconfigured'} callbackState  Derived from callbackUrl/callbackSigningKey.
 * @property {string|null} callbackConfigError Human-readable reason when callbackState is 'misconfigured'.
 * @property {number}   wsMaxTagsPerConnection  Platform hard limit on tags per hibernatable socket (measured: 10).
 * @property {number}   wsMaxTagBytes           Platform hard limit on one tag's byte length (measured: 256).
 * @property {number}   wsMaxChannels           Channels a single connection may join at connect time.
 * @property {number}   wsMaxChannelNameBytes   Per-channel-name cap, before the "ch:" prefix.
 * @property {string}   wsChannelTagPrefix      Tag prefix for channel membership. Disjoint from wsConnTagPrefix by construction (asserted below).
 * @property {string}   wsConnTagPrefix         Tag prefix for the one per-connection identity tag.
 * @property {number}   wsMaxParams             Query parameters delivered to onConnect.
 * @property {number}   wsMaxParamBytes         Total decoded bytes of all onConnect params.
 * @property {number}   wsMaxAttachmentBytes    Host-side ceiling on the serialized {v,id,ch} attachment.
 * @property {number}   wsMaxMessageBytes       Inbound frame cap, decoded bytes.
 * @property {number}   wsMaxSendBytes          Outbound cap for one Connection::send() or one broadcast frame.
 * @property {number}   wsMaxBroadcastSockets   Fan-out cap for one broadcast() call.
 * @property {number}   wsDebugMaxConnections   Connection rows the debug endpoint will list.
 * @property {number}   wsTicketTtlMs           Mint-time lifetime stamped into a connection ticket's `exp`.
 * @property {number}   wsTicketSkewMs          Verification-side clock allowance added to `exp` (0 is legal; tests use it).
 * @property {number}   wsTicketMaxClaims       Claim entries allowed in one mint request.
 * @property {number}   wsTicketMaxClaimBytes   Total UTF-8 bytes of claim keys plus values in one mint request.
 * @property {number}   wsTicketMaxBytes        Longest ticket string `/ws` will even look at.
 * @property {number}   timersMax             Per-Atom cap on scheduled timers (ATOMS_TIMERS_MAX).
 * @property {number}   timerNameMaxBytes     Byte-length cap on a timer name (ATOMS_TIMER_NAME_MAX_BYTES).
 * @property {number}   timersMaxPerAlarm     Due timers processed by one alarm() invocation before it re-arms and returns, rather than looping unbounded (ATOMS_TIMERS_MAX_PER_ALARM).
 */

/** Levels understood by the `log` sync op, lowest first. */
export const LOG_LEVELS = ['debug', 'info', 'notice', 'warning', 'error'];

/** Table that holds host-owned runtime metadata inside the DO's SQLite. */
export const META_TABLE = '__atoms_meta';

/** Prefix reserved for host-owned tables; customer SQL touching it is rejected. */
export const RESERVED_TABLE_PREFIX = '__atoms_';

/** Table that holds this Atom's scheduled timers inside the DO's SQLite. */
export const TIMERS_TABLE = '__atoms_timers';

/** Table that records claimed (single-use) WebSocket connection-ticket jtis. */
export const WS_TICKETS_TABLE = '__atoms_ws_tickets';

/** Meta keys the host itself owns. */
export const META_KEYS = {
	type: 'atom_type',
	id: 'atom_id',
	userVersion: 'user_version',
	constructions: 'constructions',
	bundleFormat: 'bundle_format',
	abiPhp: 'abi_php',
	createdAt: 'created_at',
};

/**
 * @param {Env} env
 * @param {string} name
 * @param {string} dflt
 * @returns {string}
 */
function str(env, name, dflt) {
	const v = env[name];
	return typeof v === 'string' && v.length > 0 ? v : dflt;
}

/**
 * @param {Env} env
 * @param {string} name
 * @param {number} dflt
 * @returns {number}
 */
function int(env, name, dflt) {
	const v = env[name];
	if (typeof v === 'number' && Number.isFinite(v)) return Math.trunc(v);
	if (typeof v !== 'string' || v.trim() === '') return dflt;
	const n = Number(v);
	return Number.isFinite(n) ? Math.trunc(n) : dflt;
}

/**
 * `int()`, plus the "int, default, **>0 else default**" rule for the
 * values where zero or negative is not a tighter setting but a broken one: a
 * deadline of 0 would make every `app()` fail before it started, a dispatch
 * cap of 0 would refuse every `dispatch()`, and a size cap of 0 would refuse
 * every body — none of which is a configuration anyone means. An operator
 * typo lands on the documented default instead, and says so in the log.
 *
 * @param {Env} env
 * @param {string} name
 * @param {number} dflt must itself be > 0
 * @returns {number}
 */
function posInt(env, name, dflt) {
	const n = int(env, name, dflt);
	if (n > 0) return n;
	console.log(
		JSON.stringify({
			ts: new Date().toISOString(),
			level: 'warning',
			source: 'host',
			msg: 'atoms.config.non_positive',
			var: name,
			value: n,
			using: dflt,
		})
	);
	return dflt;
}

/**
 * @param {Env} env
 * @param {string} name
 * @param {boolean} dflt
 * @returns {boolean}
 */
function bool(env, name, dflt) {
	const v = env[name];
	if (typeof v === 'boolean') return v;
	if (typeof v !== 'string' || v.trim() === '') return dflt;
	const s = v.trim().toLowerCase();
	if (s === '1' || s === 'true' || s === 'yes' || s === 'on') return true;
	if (s === '0' || s === 'false' || s === 'no' || s === 'off') return false;
	return dflt;
}

/**
 * @param {Env} env
 * @param {string} name
 * @param {string[]} dflt
 * @returns {string[]}
 */
function list(env, name, dflt) {
	const v = env[name];
	if (typeof v !== 'string' || v.trim() === '') return dflt;
	return v
		.split(',')
		.map((s) => s.trim())
		.filter((s) => s.length > 0);
}

/**
 * `ATOMS_SQL_UNSAFE_INTEGER`, normalized. Anything unrecognised falls back to
 * the refusing default rather than to a permissive one.
 *
 * @param {string} raw
 * @returns {'error'|'tag'|'float'}
 */
function unsafeIntegerPolicy(raw) {
	const v = raw.trim().toLowerCase();
	return v === 'tag' || v === 'float' ? v : 'error';
}

/**
 * Decode base64 into raw bytes, without throwing: an operator's typo in a
 * secret must classify as `'misconfigured'`, never crash `loadConfig()` (which
 * must stay total — `/healthz` has to answer on a broken Worker).
 *
 * @param {string} b64
 * @returns {Uint8Array|null}
 */
export function base64ToBytes(b64) {
	try {
		const binary = atob(b64);
		const bytes = new Uint8Array(binary.length);
		for (let i = 0; i < binary.length; i++) bytes[i] = binary.charCodeAt(i);
		return bytes;
	} catch {
		return null;
	}
}

/**
 * `ATOMS_CALLBACK_URL` validation: absolute, `https:`, or
 * `http:` only when the host is a loopback address. Plain `http` to a public
 * host would send customer arguments in the clear — the Ed25519 signature
 * protects integrity and authenticity, never confidentiality. The loopback
 * exemption is what keeps the conformance harness and `atoms dev` legal.
 *
 * @param {string} raw
 * @returns {string|null} a human-readable reason, or null when valid
 */
function validateCallbackUrl(raw) {
	/** @type {URL} */
	let url;
	try {
		url = new URL(raw);
	} catch {
		return `ATOMS_CALLBACK_URL ${JSON.stringify(raw)} is not a valid absolute URL`;
	}
	if (url.protocol === 'https:') return null;
	const loopback = url.hostname === '127.0.0.1' || url.hostname === 'localhost' || url.hostname === '[::1]';
	if (url.protocol === 'http:' && loopback) return null;
	return (
		`ATOMS_CALLBACK_URL must use "https:", or "http:" only for a loopback host ` +
		`(127.0.0.1, localhost, [::1]); got "${url.protocol}//${url.hostname}"`
	);
}

/**
 * Classify the callback channel from the two raw env values into one of
 * three states: configured, unconfigured, or misconfigured. Never throws:
 * `loadConfig()` stays total so `/healthz` answers on a misconfigured
 * Worker; the typed failure is raised only when `app()`/`dispatch()` is
 * actually used.
 *
 * @param {string} callbackUrl
 * @param {string} callbackSigningKey
 * @returns {{state: 'configured'|'unconfigured'|'misconfigured', error: string|null}}
 */
function classifyCallbackChannel(callbackUrl, callbackSigningKey) {
	if (callbackUrl === '') {
		return { state: 'unconfigured', error: null };
	}

	const urlError = validateCallbackUrl(callbackUrl);
	if (urlError) {
		return { state: 'misconfigured', error: urlError };
	}

	if (callbackSigningKey === '') {
		return { state: 'misconfigured', error: 'ATOMS_CALLBACK_SIGNING_KEY is not set' };
	}
	const seed = base64ToBytes(callbackSigningKey);
	if (!seed || seed.length !== 32) {
		return {
			state: 'misconfigured',
			error: 'ATOMS_CALLBACK_SIGNING_KEY does not decode to exactly 32 bytes of base64',
		};
	}

	return { state: 'configured', error: null };
}

/**
 * Resolve the whole configuration for one Worker/DO invocation.
 *
 * @param {Env} env
 * @returns {AtomsConfig}
 */
export function loadConfig(env) {
	const level = str(env, 'ATOMS_LOG_LEVEL', 'info').toLowerCase();
	const callbackUrl = str(env, 'ATOMS_CALLBACK_URL', '');
	const callbackSigningKey = str(env, 'ATOMS_CALLBACK_SIGNING_KEY', '');
	const callback = classifyCallbackChannel(callbackUrl, callbackSigningKey);

	const config = {
		appKey: str(env, 'ATOMS_APP_KEY', ''),
		debugEndpoints: bool(env, 'ATOMS_DEBUG_ENDPOINTS', false),

		maxRequestBytes: int(env, 'ATOMS_MAX_REQUEST_BYTES', 1024 * 1024),
		maxAtomIdBytes: int(env, 'ATOMS_MAX_ATOM_ID_BYTES', 256),
		maxJsonDepth: int(env, 'ATOMS_MAX_JSON_DEPTH', 64),

		activationTimeoutMs: int(env, 'ATOMS_ACTIVATION_TIMEOUT_MS', 20000),
		activationPollMs: int(env, 'ATOMS_ACTIVATION_POLL_MS', 0),
		activationMaxPolls: int(env, 'ATOMS_ACTIVATION_MAX_POLLS', 200000),

		parkWaitTimeoutMs: int(env, 'ATOMS_PARK_WAIT_TIMEOUT_MS', 20000),
		maxParkStepsPerTurn: int(env, 'ATOMS_MAX_PARK_STEPS_PER_TURN', 100000),
		maxTxParkSteps: int(env, 'ATOMS_MAX_TX_PARK_STEPS', 1000),

		sqlUnsafeInteger: unsafeIntegerPolicy(str(env, 'ATOMS_SQL_UNSAFE_INTEGER', 'error')),
		sqlMaxRows: int(env, 'ATOMS_SQL_MAX_ROWS', 100000),
		// Eight times ATOMS_MAX_REQUEST_BYTES's default, and a small fraction of
		// the isolate's memory envelope — high enough that no legitimate page of
		// rows trips it, low enough that a runaway SELECT * fails as a typed
		// error instead of an OOM that kills the residency (M1 design §4.1).
		sqlMaxResultBytes: posInt(env, 'ATOMS_SQL_MAX_RESULT_BYTES', 8388608),
		sqlMaxBindings: int(env, 'ATOMS_SQL_MAX_BINDINGS', 1000),

		logMaxFieldBytes: int(env, 'ATOMS_LOG_MAX_FIELD_BYTES', 4096),
		logLevel: LOG_LEVELS.includes(level) ? level : 'info',

		configEnvPrefix: str(env, 'ATOMS_CONFIG_ENV_PREFIX', 'ATOMS_CONFIG_'),
		configEnvKeys: list(env, 'ATOMS_CONFIG_ENV_KEYS', []),
		// MERGED, never replaced: the built-in entries below are the two
		// credentials the Worker holds (the bearer key and the callback signing
		// seed) plus the two lists that decide the allowlist itself. An operator
		// who sets ATOMS_CONFIG_ENV_DENY_KEYS is adding names, not choosing a
		// new set — a replacement would let a single well-meant "deny my own
		// secret" setting hand the signing seed to `config.get()`.
		configEnvDenyKeys: mergeDenyKeys(list(env, 'ATOMS_CONFIG_ENV_DENY_KEYS', [])),

		bootstrapPath: str(env, 'ATOMS_BOOTSTRAP_PATH', '/atoms/runtime/bootstrap.php'),
		bootPayloadPath: str(env, 'ATOMS_BOOT_PAYLOAD_PATH', '/atoms/boot.json'),
		runtimeDir: str(env, 'ATOMS_RUNTIME_DIR', '/atoms/runtime'),
		coreDir: str(env, 'ATOMS_CORE_DIR', '/atoms/core/src'),
		guestDirs: list(env, 'ATOMS_GUEST_DIRS', [
			'/atoms',
			'/atoms/runtime',
			'/atoms/core',
			'/atoms/core/src',
			'/atoms/core/resources',
			'/app',
		]),

		bundleFormat: int(env, 'ATOMS_BUNDLE_FORMAT', 0),

		callbackUrl,
		callbackSigningKey,
		turnDeadlineMs: posInt(env, 'ATOMS_TURN_DEADLINE_MS', 30000),
		callbackTimeoutMs: posInt(env, 'ATOMS_CALLBACK_TIMEOUT_MS', 10000),
		callbackMaxRequestBytes: posInt(env, 'ATOMS_CALLBACK_MAX_REQUEST_BYTES', 1024 * 1024),
		callbackMaxResponseBytes: posInt(env, 'ATOMS_CALLBACK_MAX_RESPONSE_BYTES', 1024 * 1024),
		maxDispatchesPerTurn: posInt(env, 'ATOMS_MAX_DISPATCHES_PER_TURN', 100),
		callbackState: callback.state,
		callbackConfigError: callback.error,

		wsMaxTagsPerConnection: int(env, 'ATOMS_WS_MAX_TAGS_PER_CONNECTION', 10),
		wsMaxTagBytes: int(env, 'ATOMS_WS_MAX_TAG_BYTES', 256),
		wsMaxChannels: int(env, 'ATOMS_WS_MAX_CHANNELS', 8),
		wsMaxChannelNameBytes: int(env, 'ATOMS_WS_MAX_CHANNEL_NAME_BYTES', 64),
		wsChannelTagPrefix: str(env, 'ATOMS_WS_CHANNEL_TAG_PREFIX', 'ch:'),
		wsConnTagPrefix: str(env, 'ATOMS_WS_CONN_TAG_PREFIX', 'c:'),
		wsMaxParams: int(env, 'ATOMS_WS_MAX_PARAMS', 32),
		wsMaxParamBytes: int(env, 'ATOMS_WS_MAX_PARAM_BYTES', 4096),
		wsMaxAttachmentBytes: int(env, 'ATOMS_WS_MAX_ATTACHMENT_BYTES', 512),
		wsMaxMessageBytes: int(env, 'ATOMS_WS_MAX_MESSAGE_BYTES', 131072),
		wsMaxSendBytes: int(env, 'ATOMS_WS_MAX_SEND_BYTES', 131072),
		wsMaxBroadcastSockets: int(env, 'ATOMS_WS_MAX_BROADCAST_SOCKETS', 1000),
		wsDebugMaxConnections: int(env, 'ATOMS_WS_DEBUG_MAX_CONNECTIONS', 100),
		wsTicketTtlMs: posInt(env, 'ATOMS_WS_TICKET_TTL_MS', 60000),
		// Plain int, not posInt: a skew of 0 is a legal (tighter) setting, and
		// the conformance suite's expiry check runs with exactly that.
		wsTicketSkewMs: int(env, 'ATOMS_WS_TICKET_SKEW_MS', 5000),
		wsTicketMaxClaims: int(env, 'ATOMS_WS_TICKET_MAX_CLAIMS', 16),
		wsTicketMaxClaimBytes: int(env, 'ATOMS_WS_TICKET_MAX_CLAIM_BYTES', 2048),
		wsTicketMaxBytes: posInt(env, 'ATOMS_WS_TICKET_MAX_BYTES', 8192),

		timersMax: int(env, 'ATOMS_TIMERS_MAX', 10000),
		timerNameMaxBytes: int(env, 'ATOMS_TIMER_NAME_MAX_BYTES', 256),
		timersMaxPerAlarm: int(env, 'ATOMS_TIMERS_MAX_PER_ALARM', 100),
	};

	assertWsPrefixesDisjoint(config.wsChannelTagPrefix, config.wsConnTagPrefix);

	return config;
}

/**
 * Names `config.get()` must never resolve, whatever the environment says.
 * Kept beside `loadConfig()` rather than inline so the guarantee has a name:
 * the operator list is additive to this one, never a replacement for it.
 */
const BUILT_IN_CONFIG_DENY_KEYS = [
	'ATOMS_APP_KEY',
	'ATOMS_CALLBACK_SIGNING_KEY',
	'ATOMS_CONFIG_ENV_KEYS',
	'ATOMS_CONFIG_ENV_DENY_KEYS',
];

/**
 * @param {string[]} operatorKeys
 * @returns {string[]} the built-in deny list plus the operator's, de-duplicated
 */
function mergeDenyKeys(operatorKeys) {
	return [...new Set([...BUILT_IN_CONFIG_DENY_KEYS, ...operatorKeys])];
}

/**
 * The channel-tag prefix and the connection-tag prefix must never let a
 * `ATOMS_WS_CHANNEL_TAG_PREFIX + <any channel name>` tag collide with a
 * `ATOMS_WS_CONN_TAG_PREFIX + <any connection id>` tag. That is
 * guaranteed for EVERY possible name/id, whatever charset they use, as long
 * as neither prefix is a prefix of the other: a shared prefix is the only way
 * two differently-sourced strings could ever compare equal. Checked once at
 * config-resolution time so a misconfigured environment fails loudly at boot
 * rather than mixing up connections and channels under load.
 *
 * @param {string} channelPrefix
 * @param {string} connPrefix
 */
function assertWsPrefixesDisjoint(channelPrefix, connPrefix) {
	const [shorter, longer] =
		channelPrefix.length <= connPrefix.length ? [channelPrefix, connPrefix] : [connPrefix, channelPrefix];
	if (shorter !== '' && longer.startsWith(shorter)) {
		throw new AtomsError(
			'internal',
			`ATOMS_WS_CHANNEL_TAG_PREFIX (${JSON.stringify(channelPrefix)}) and ATOMS_WS_CONN_TAG_PREFIX ` +
				`(${JSON.stringify(connPrefix)}) are not disjoint: one is a prefix of the other, which would let a ` +
				'channel tag collide with a connection tag'
		);
	}
}
