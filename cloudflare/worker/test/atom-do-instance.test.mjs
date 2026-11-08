#!/usr/bin/env node

/**
 * atom-do-instance.test.mjs — a discarded PHP instance cannot report onto a live one.
 *
 * `php.run()` is started once per activation and never awaited: it ends only
 * when the guest exits. Discarding an instance does not settle that promise, so
 * a run belonging to an instance that was poisoned and thrown away can end at
 * any later moment — including after the Durable Object has booted a fresh
 * instance into the same residency. When the settle handlers wrote
 * `this.runSettled` / `this.runError`, that late ending landed on whatever was
 * resident at the time: `waitForPark()` then saw a settled run and `activate()`
 * threw the dead instance's cause of death at a healthy new session.
 *
 * The conformance suite cannot schedule this. It drives a real Worker over
 * HTTP and has no way to hold one instance's run promise open across another
 * instance's boot, which is the entire interleaving. So this runs the real
 * `watchRun()`/`settleRun()` against promises whose settle moment the test
 * chooses.
 *
 *   node --test test/atom-do-instance.test.mjs
 *
 * `src/atom-do.js` imports `cloudflare:workers` and, through `php-host.js`, a
 * `.wasm` module — neither of which plain Node resolves. Both are stubbed by a
 * loader hook below rather than by importing a copy of the code under test.
 * Requires Node >= 22.15 for `module.registerHooks`.
 */

import assert from 'node:assert/strict';
import { registerHooks } from 'node:module';

const STUBS = {
	'atoms-test:cloudflare-workers': 'export class DurableObject {}\n',
	// Nothing here is called: the test never activates. They exist so the
	// module graph resolves.
	'atoms-test:php-host': [
		'export function bootPHP() { throw new Error("bootPHP is not exercised by this test"); }',
		'export function composeBootCode() { return ""; }',
		'export function guestMemoryBytes() { return 0; }',
		'export function mkdirp() {}',
		'export function writeGuestFile() {}',
		'',
	].join('\n'),
};

registerHooks({
	resolve(specifier, context, nextResolve) {
		if (specifier === 'cloudflare:workers') {
			return { url: 'atoms-test:cloudflare-workers', shortCircuit: true };
		}
		if (specifier.endsWith('php-host.js')) {
			return { url: 'atoms-test:php-host', shortCircuit: true };
		}
		return nextResolve(specifier, context);
	},
	load(url, context, nextLoad) {
		if (Object.prototype.hasOwnProperty.call(STUBS, url)) {
			return { format: 'module', source: STUBS[url], shortCircuit: true };
		}
		return nextLoad(url, context);
	},
});

const { AtomDurableObject } = await import('../src/atom-do.js');

/**
 * A Durable Object stood up far enough to own a residency record: the real
 * prototype, no activation. `log` is captured rather than printed.
 */
function residency() {
	const host = Object.create(AtomDurableObject.prototype);
	host.instance = null;
	host.logged = [];
	host.log = (/** @type {string} */ level, /** @type {any} */ fields) => host.logged.push({ level, ...fields });
	return host;
}

/** @param {number} gen */
function bootInstance(/** @type {any} */ host, gen) {
	/** @type {{resolve: (v: any) => void, reject: (e: any) => void}} */
	const control = /** @type {any} */ ({});
	const run = new Promise((resolve, reject) => {
		control.resolve = resolve;
		control.reject = reject;
	});
	const instance = { php: { exit() {} }, gen, settled: false, error: null };
	host.instance = instance;
	host.watchRun(instance, run);
	return { instance, control };
}

/** Let every already-settled promise's handlers run. */
const drain = () => new Promise((r) => setTimeout(r, 0));

let failures = 0;
/**
 * @param {string} name
 * @param {() => Promise<void>} fn
 */
async function check(name, fn) {
	try {
		await fn();
		console.log(`ok   ${name}`);
	} catch (e) {
		failures++;
		console.error(`FAIL ${name}`);
		console.error(String(e?.stack ?? e));
	}
}

await check('a discarded instance settling late leaves the resident one untouched', async () => {
	const host = residency();

	const a = bootInstance(host, 7);

	// The residency is poisoned: A is discarded, and a request re-activates
	// into a fresh instance. A's run promise is still open the whole time.
	host.instance = null;
	const b = bootInstance(host, 8);

	// Only now does the dead instance's interpreter finally exit.
	a.control.reject(new Error('wasm module exited'));
	await drain();

	assert.equal(a.instance.settled, true, "the dead instance records its own run's end");
	assert.match(String(a.instance.error), /wasm module exited/);

	assert.equal(b.instance.settled, false, 'the resident instance is still running');
	assert.equal(b.instance.error, null, "a dead instance must not file its error against a live one");
	assert.equal(host.instance, b.instance);
	assert.equal(host.instance.error, null);

	const stale = host.logged.filter((/** @type {any} */ l) => l.msg === 'atoms.do.stale_run_settled');
	assert.equal(stale.length, 1, 'the late settle is visible in the log');
	assert.equal(stale[0].gen, 7, 'and names the generation it belonged to');
});

await check('the resident instance still records its own run ending', async () => {
	const host = residency();
	const a = bootInstance(host, 1);

	a.control.reject(Object.assign(new Error('out of memory'), { name: 'RangeError' }));
	await drain();

	assert.equal(a.instance.settled, true);
	assert.equal(a.instance.error, 'the PHP bootstrap threw: RangeError: out of memory');
	assert.deepEqual(
		host.logged.filter((/** @type {any} */ l) => l.msg === 'atoms.do.stale_run_settled'),
		[],
		'settling onto the resident instance is not stale'
	);
});

await check('a run that returns is recorded with its exit status', async () => {
	const host = residency();
	const a = bootInstance(host, 2);

	a.control.resolve({ exitCode: 255, errors: 'PHP Fatal error: uncaught' });
	await drain();

	assert.equal(a.instance.settled, true);
	assert.equal(a.instance.error, 'the PHP bootstrap returned (exit 255): PHP Fatal error: uncaught');
});

await check('discardPhp detaches the instance before anything can settle onto it', async () => {
	const host = residency();
	host.pending = null;
	host.parkedTurn = null;
	host.activationPromise = null;
	host.turnBudget = null;
	host.phpGeneration = 3;
	host.callbacks = { endWindow() {} };
	host.tx = { reset() {} };
	host.ws = { clearMemo() {} };

	const a = bootInstance(host, 3);
	let exited = false;
	a.instance.php.exit = () => {
		exited = true;
	};

	host.discardPhp();

	assert.equal(host.instance, null, 'the residency has no instance after a discard');
	assert.equal(exited, true, 'the discarded interpreter is still told to exit');
	assert.equal(host.phpGeneration, 4, 'the generation advances so in-flight callbacks are invalidated');

	a.control.reject(new Error('exited'));
	await drain();
	assert.equal(host.instance, null, 'and the late settle does not resurrect one');
});

await check('TypePHP shutdown lets the guest consume its reply before disposal', async () => {
	const { default: bundle } = await import('../src/bundle.generated.js');
	const compiler = bundle.manifest.compiler;
	bundle.manifest.compiler = { name: 'typephp' };
	try {
		const host = residency();
		host.turnChain = Promise.resolve();
		host.config = { parkWaitTimeoutMs: 100 };
		const { instance, control } = bootInstance(host, 1);
		let consumed = false;
		host.parkedTurn = { reply(raw) {
			assert.equal(JSON.parse(raw).kind, 'shutdown');
			queueMicrotask(() => { consumed = true; control.resolve({ exitCode: 0 }); });
		} };
		host.discardPhp = () => {
			assert.equal(consumed, true, 'shutdown must reach the suspended guest');
			assert.equal(instance.settled, true);
			host.instance = null;
		};
		assert.deepEqual(await host.shutdown(), { ok: true, shutdown: 'sent' });
		assert.equal(host.instance, null);
	} finally {
		if (compiler === undefined) delete bundle.manifest.compiler;
		else bundle.manifest.compiler = compiler;
	}
});

await check('TypePHP shutdown disposes a non-terminating guest within its configured wait', async () => {
	const { default: bundle } = await import('../src/bundle.generated.js');
	const compiler = bundle.manifest.compiler;
	bundle.manifest.compiler = { name: 'typephp' };
	try {
		const host = residency();
		host.turnChain = Promise.resolve();
		host.config = { parkWaitTimeoutMs: 1 };
		const { instance } = bootInstance(host, 1);
		host.parkedTurn = { reply() {} };
		host.discardPhp = () => { host.instance = null; };
		assert.deepEqual(await host.shutdown(), { ok: true, shutdown: 'sent' });
		assert.equal(instance.settled, false, 'the timeout does not pretend the guest exited');
		assert.equal(host.instance, null);
	} finally {
		if (compiler === undefined) delete bundle.manifest.compiler;
		else bundle.manifest.compiler = compiler;
	}
});

await check('the default backend retains immediate shutdown disposal', async () => {
	const host = residency();
	host.turnChain = Promise.resolve();
	bootInstance(host, 1);
	host.parkedTurn = { reply() {} };
	host.discardPhp = () => { host.instance = null; };
	assert.deepEqual(await host.shutdown(), { ok: true, shutdown: 'sent' });
	assert.equal(host.instance, null);
});

await check('TypePHP parks and guest exits wake the host without a polling timer', async () => {
	const { default: bundle } = await import('../src/bundle.generated.js');
	const compiler = bundle.manifest.compiler;
	const realSetTimeout = globalThis.setTimeout;
	bundle.manifest.compiler = { name: 'typephp' };
	// Model a storage transaction whose input gate prevents timers from running.
	globalThis.setTimeout = () => undefined;
	try {
		for (const outcome of ['park', 'exit']) {
			const host = residency();
			const { control } = bootInstance(host, 1);
			host.config = { activationMaxPolls: 1, activationPollMs: 1 };
			const waiting = host.waitForPark(100);
			if (outcome === 'park') host.handlePark('{"op":"tx.commit"}', () => {});
			else control.resolve({ exitCode: 0 });
			const result = await Promise.race([
				waiting,
				new Promise((_, reject) => setImmediate(() => reject(new Error('waitForPark depended on a blocked timer')))),
			]);
			if (outcome === 'park') assert.equal(result.msg.op, 'tx.commit');
			else assert.equal(result, null);
			assert.equal(host.parkWaiters.size, 0, 'completed waits release their listeners');
		}
	} finally {
		globalThis.setTimeout = realSetTimeout;
		if (compiler === undefined) delete bundle.manifest.compiler;
		else bundle.manifest.compiler = compiler;
	}
});

process.exit(failures === 0 ? 0 : 1);
