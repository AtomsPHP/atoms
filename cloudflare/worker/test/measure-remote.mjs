#!/usr/bin/env node

/**
 * Remote latency measurement for the deployed Worker (spec §"Conformance
 * suite", remote-only additions): cold activation, warm turn, and
 * post-hibernation wake. Writes `test/results/remote.json`.
 *
 * Every number here is end-to-end wall time measured by this client, so it
 * includes the client's round trip to the Cloudflare edge. That is deliberate:
 * the guest clock does not advance inside a turn on deployed workerd (see the
 * spec appendix), so there is no in-guest timer to report instead, and the
 * client-observed latency is the number a caller actually experiences.
 *
 * Config via env:
 *   ATOMS_BASE_URL   (required)
 *   ATOMS_APP_KEY    (optional bearer token)
 *   ATOMS_COLD_SAMPLES     (default 5)  distinct fresh ids, first invoke each
 *   ATOMS_WARM_SAMPLES     (default 20) sequential invokes on one residency
 *   ATOMS_EVICTION_WAIT_MS (default 16000)
 *   ATOMS_RESULTS_PATH     (default test/results/remote.json)
 */

import { mkdir, writeFile } from 'node:fs/promises';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { execFile } from 'node:child_process';
import { promisify } from 'node:util';

const execFileAsync = promisify(execFile);
const here = dirname(fileURLToPath(import.meta.url));

const BASE_URL = process.env.ATOMS_BASE_URL;
const APP_KEY = process.env.ATOMS_APP_KEY;
const COLD_SAMPLES = Number(process.env.ATOMS_COLD_SAMPLES ?? '5');
const WARM_SAMPLES = Number(process.env.ATOMS_WARM_SAMPLES ?? '20');
const EVICTION_WAIT_MS = Number(process.env.ATOMS_EVICTION_WAIT_MS ?? '16000');
const RESULTS_PATH = resolve(
	here,
	'..',
	process.env.ATOMS_RESULTS_PATH ?? 'test/results/remote.json'
);

if (!BASE_URL) {
	console.error('Error: ATOMS_BASE_URL env var is required');
	process.exit(1);
}
const baseUrl = BASE_URL.replace(/\/$/, '');

const RUN = `m${Date.now().toString(36)}`;

/** @param {string} method @param {string} path @param {unknown} [body] */
async function request(method, path, body) {
	const opts = { method, headers: {} };
	if (APP_KEY) opts.headers.Authorization = `Bearer ${APP_KEY}`;
	if (body !== undefined) {
		opts.headers['Content-Type'] = 'application/json';
		opts.body = JSON.stringify(body);
	}
	const started = performance.now();
	const res = await fetch(new URL(path, baseUrl).toString(), opts);
	const text = await res.text();
	const ms = performance.now() - started;
	let data;
	try {
		data = JSON.parse(text);
	} catch {
		data = { _raw: text };
	}
	return { status: res.status, data, ms };
}

/** A timed invoke that refuses to record the latency of a failure. */
async function timedInvoke(type, id, method, args = []) {
	const r = await request('POST', `/invoke/${type}/${id}/${method}`, { args });
	if (r.status !== 200 || r.data?.error) {
		throw new Error(`${method} on ${id} failed: ${r.status} ${JSON.stringify(r.data)}`);
	}
	return r;
}

/** @param {number[]} xs */
function stats(xs) {
	const sorted = [...xs].sort((a, b) => a - b);
	const at = (q) => sorted[Math.min(sorted.length - 1, Math.floor(q * sorted.length))];
	const round = (n) => Math.round(n * 10) / 10;
	return {
		samples: sorted.length,
		min_ms: round(sorted[0]),
		median_ms: round(
			sorted.length % 2
				? sorted[(sorted.length - 1) / 2]
				: (sorted[sorted.length / 2 - 1] + sorted[sorted.length / 2]) / 2
		),
		p90_ms: round(at(0.9)),
		max_ms: round(sorted[sorted.length - 1]),
		all_ms: sorted.map(round),
	};
}

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

async function deployedVersionId() {
	try {
		const { stdout } = await execFileAsync(
			'npx',
			['wrangler', 'deployments', 'list', '--json'],
			{ cwd: resolve(here, '..'), maxBuffer: 16 * 1024 * 1024 }
		);
		const list = JSON.parse(stdout.slice(stdout.indexOf('[')));
		const latest = list[list.length - 1];
		const version = latest?.versions?.[0];
		return {
			deployment_id: latest?.id ?? null,
			version_id: version?.version_id ?? null,
			deployed_at: latest?.created_on ?? null,
		};
	} catch (e) {
		return { deployment_id: null, version_id: null, deployed_at: null, error: String(e).slice(0, 300) };
	}
}

async function main() {
	console.log(`Measuring ${baseUrl}`);

	// Cold activation: the first invoke on an id no residency has ever existed
	// for. It pays wasm boot, MEMFS install, migrations, construction and
	// onActivation() on top of the turn itself.
	const cold = [];
	for (let i = 0; i < COLD_SAMPLES; i++) {
		const id = `${RUN}-cold-${i}`;
		const r = await timedInvoke('Counter', id, 'increment', [1]);
		cold.push(r.ms);
		console.log(`  cold ${i + 1}/${COLD_SAMPLES}: ${r.ms.toFixed(1)}ms`);
	}

	// Warm turn: sequential invokes on one live residency. The first invoke
	// activates it and is excluded — it is a cold activation by definition.
	const warmId = `${RUN}-warm`;
	const warmActivation = await timedInvoke('Counter', warmId, 'increment', [1]);
	const warm = [];
	for (let i = 0; i < WARM_SAMPLES; i++) {
		const r = await timedInvoke('Counter', warmId, 'increment', [1]);
		warm.push(r.ms);
	}
	console.log(`  warm: ${WARM_SAMPLES} sequential turns, median ${stats(warm).median_ms}ms`);

	// Post-hibernation wake: the same Atom after enough idle time to be evicted,
	// so the wake re-boots PHP and re-runs onActivation() against durable state.
	console.log(`  idling ${EVICTION_WAIT_MS}ms for eviction...`);
	await sleep(EVICTION_WAIT_MS);
	const wake = await timedInvoke('Counter', warmId, 'increment', [1]);
	const info = await request('GET', `/debug/Counter/${warmId}/info`);
	const constructions = info.data?.info?.constructions ?? null;
	console.log(`  wake: ${wake.ms.toFixed(1)}ms (constructions=${constructions})`);
	if (constructions !== null && constructions < 2) {
		console.log(
			`  WARNING: constructions=${constructions} — the residency was never evicted, ` +
				'so the "wake" figure is a warm turn, not a wake.'
		);
	}

	const results = {
		measured_at: new Date().toISOString(),
		base_url: baseUrl,
		worker: 'atoms-mvp-conformance',
		deployment: await deployedVersionId(),
		method: {
			note:
				'client-observed end-to-end latency, including the client round trip to the ' +
				'Cloudflare edge; the guest clock does not advance inside a turn on deployed workerd',
			cold: 'first invoke on an id with no prior residency (wasm boot + migrations + onActivation + turn)',
			warm: `median of ${WARM_SAMPLES} sequential invokes on one live residency, activation excluded`,
			wake: `one invoke after ${EVICTION_WAIT_MS}ms idle on an Atom with durable state`,
			eviction_wait_ms: EVICTION_WAIT_MS,
		},
		cold_activation: stats(cold),
		warm_turn: stats(warm),
		post_hibernation_wake: {
			ms: Math.round(wake.ms * 10) / 10,
			constructions_after: constructions,
			warm_activation_ms: Math.round(warmActivation.ms * 10) / 10,
		},
	};

	await mkdir(dirname(RESULTS_PATH), { recursive: true });
	await writeFile(RESULTS_PATH, `${JSON.stringify(results, null, '\t')}\n`);
	console.log(`\nWrote ${RESULTS_PATH}`);
	console.log(
		`cold median ${results.cold_activation.median_ms}ms | ` +
			`warm median ${results.warm_turn.median_ms}ms | ` +
			`wake ${results.post_hibernation_wake.ms}ms`
	);
}

main().catch((e) => {
	console.error(`Fatal: ${e.message}`);
	process.exit(1);
});
