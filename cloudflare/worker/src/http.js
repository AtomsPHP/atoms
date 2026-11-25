import { Buffer } from 'node:buffer';
import { turnDeadlineFailure } from './callbacks.js';

const methods = new Set(['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS']);
const reservedPrefixes = ['cf-', 'x-atoms-', 'proxy-', 'sec-', 'x-forwarded-', 'x-envoy-'];
const reservedNames = new Set([
	'host', 'content-length', 'connection', 'keep-alive', 'te', 'trailer', 'transfer-encoding',
	'upgrade', 'http2-settings', 'forwarded', 'via', 'cdn-loop', 'x-forwarded', 'x-real-ip',
	'true-client-ip', 'x-original-url', 'x-original-host', 'x-original-uri', 'x-rewrite-url',
	'x-http-method-override', 'x-http-method', 'x-method-override',
]);

class HttpFailure extends Error {}

// Validate the original authority before WHATWG normalization (including
// empty userinfo, backslashes and empty fragments).
function httpsUrl(raw, originOnly) {
	if (typeof raw !== 'string' || /[\u0000-\u0020\u007f#\\]/u.test(raw)) return null;
	const match = /^https:\/\/([a-z0-9.-]+)(?::[0-9]+)?(?=[/?]|$)/i.exec(raw);
	if (!match || (originOnly && !['', '/'].includes(raw.slice(match[0].length)))) return null;
	const host = match[1].toLowerCase();
	const labels = host.split('.');
	if (host.length > 253 || labels.length < 2 || labels.some(label =>
		!/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/.test(label))) return null;
	if (/(?:^|\.)(?:[0-9]+|0x[0-9a-f]*|local|localhost|internal|localdomain|home\.arpa)$/.test(host)) return null;
	try {
		return new URL(raw);
	} catch {
		return null;
	}
}

export function httpAllowlist(raw) {
	try {
		const entries = typeof raw === 'string' ? JSON.parse(raw) : raw;
		if (!Array.isArray(entries)) return null;
		const allowed = new Set();
		for (const entry of entries) {
			const url = httpsUrl(entry, true);
			if (!url) return null;
			allowed.add(url.origin);
		}
		return allowed;
	} catch {
		return null;
	}
}

function prepare(msg, url, config) {
	if (!config.httpAllowlist) throw new HttpFailure('ATOMS-E115');
	if (!url || !config.httpAllowlist.has(url.origin)) throw new HttpFailure('ATOMS-E116');
	if (!methods.has(msg.method) || !Array.isArray(msg.headers) ||
		(msg.body !== null && (typeof msg.body !== 'string' || ['GET', 'HEAD'].includes(msg.method)))) {
		throw new HttpFailure('ATOMS-E118');
	}

	let remaining = config.httpMaxRequestBytes - Buffer.byteLength(msg.url) - msg.method.length;
	const headers = new Headers();
	for (const pair of msg.headers) {
		if (!Array.isArray(pair) || pair.length !== 2) throw new HttpFailure('ATOMS-E118');
		const [name, value] = pair;
		if (typeof name !== 'string' || !name || /[^!#$%&'*+.^_`|~0-9a-z-]/i.test(name)
			|| typeof value !== 'string' || /[\p{Cc}\p{Cs}]/u.test(value)) throw new HttpFailure('ATOMS-E118');
		const lower = name.toLowerCase();
		if (reservedNames.has(lower) || reservedPrefixes.some(prefix => lower.startsWith(prefix))) {
			throw new HttpFailure('ATOMS-E117');
		}
		remaining -= name.length + Buffer.byteLength(value);
		if (remaining < 0) throw new HttpFailure('ATOMS-E121');
		headers.append(name, value);
	}
	if (remaining < 0) throw new HttpFailure('ATOMS-E121');
	let body = null;
	if (msg.body !== null) {
		if (msg.body.length > 4 * Math.ceil(remaining / 3)) throw new HttpFailure('ATOMS-E121');
		body = Buffer.from(msg.body, 'base64');
		if (body.length > remaining) throw new HttpFailure('ATOMS-E121');
		if (body.toString('base64') !== msg.body) throw new HttpFailure('ATOMS-E118');
	}
	return { method: msg.method, headers, body, redirect: 'manual' };
}

async function readResponse(response, limit, signal) {
	const reader = response.body?.getReader();
	const cancel = () => { reader?.cancel().catch(() => {}); };
	signal.addEventListener('abort', cancel, { once: true });
	try {
		signal.throwIfAborted();
		const headers = Object.create(null);
		let remaining = limit - String(response.status).length;
		const add = (name, value) => {
			remaining -= Buffer.byteLength(name) + Buffer.byteLength(value);
			if (remaining < 0) throw new HttpFailure('ATOMS-E121');
			(headers[name] ??= []).push(value);
		};
		for (const [name, value] of response.headers) {
			if (name !== 'set-cookie') add(name, value);
		}
		for (const cookie of response.headers.getAll('set-cookie')) add('set-cookie', cookie);
		if (remaining < 0) throw new HttpFailure('ATOMS-E121');

		const chunks = [];
		if (reader) {
			for (;;) {
				const { done, value } = await reader.read();
				signal.throwIfAborted();
				if (done) break;
				remaining -= value.byteLength;
				if (remaining < 0) throw new HttpFailure('ATOMS-E121');
				if (value.byteLength > 0) chunks.push(value);
			}
		}
		return { ok: true, status: response.status, headers, body: Buffer.concat(chunks).toString('base64') };
	} catch (error) {
		cancel();
		throw error;
	} finally {
		signal.removeEventListener('abort', cancel);
		reader?.releaseLock();
	}
}

// The timer races the complete exchange. The reader also observes abort in
// case the transport stops observing the fetch signal after headers arrive.
async function fetchWithinWindow(msg, url, budget, config, transport) {
	const init = prepare(msg, url, config);
	if (budget.httpRequests >= config.httpMaxRequestsPerTurn) throw new HttpFailure('ATOMS-E122');
	const started = Date.now();
	const remaining = budget.deadlineMs - (started - budget.startedAt);
	if (remaining <= 0) return turnDeadlineFailure(budget);

	const duration = Math.min(config.httpTimeoutMs, remaining);
	const controller = new AbortController();
	budget.httpController = controller;
	const interrupted = new Promise((_, reject) => {
		controller.signal.addEventListener('abort', () => reject(controller.signal.reason), { once: true });
	});
	const timer = setTimeout(() => controller.abort(new HttpFailure('ATOMS-E120')), duration);
	try {
		budget.httpRequests++;
		const exchange = transport(url, { ...init, signal: controller.signal })
			.then(response => readResponse(response, config.httpMaxResponseBytes, controller.signal));
		const response = await Promise.race([exchange, interrupted]);
		if (Date.now() - started >= duration) throw new HttpFailure('ATOMS-E120');
		return JSON.stringify(response);
	} finally {
		clearTimeout(timer);
		budget.httpController = null;
	}
}

/** Reply once to the still-parked generation, including on local refusal. */
export async function serviceHttp(park, budget, config, transport, generationIsLive) {
	const url = httpsUrl(park.msg.url, false);
	let result;
	try {
		result = turnDeadlineFailure(budget) ?? await fetchWithinWindow(park.msg, url, budget, config, transport);
	} catch (error) {
		const code = error instanceof HttpFailure ? error.message : 'ATOMS-E123';
		const reason = { code, message: code };
		if (url) reason.origin = url.origin;
		result = turnDeadlineFailure(budget) ?? JSON.stringify({ ok: false, error: reason });
	}
	if (generationIsLive()) park.reply(result);
}
