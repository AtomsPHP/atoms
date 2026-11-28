import { registerHooks } from 'node:module';

// Node qualification reads local Wasm as the compiled module supplied by Wrangler.
registerHooks({
	load(url, context, next) {
		if (!url.endsWith('.wasm')) return next(url, context);
		return {
			format: 'module',
			shortCircuit: true,
			source: "import { readFileSync } from 'node:fs'; export default new WebAssembly.Module(readFileSync(new URL(import.meta.url)));",
		};
	},
});

export async function runGuestProbe(bootPHP, loader, code) {
	const output = [];
	const errors = [];
	const unexpected = () => { throw new Error('The PHP inventory probe sent a host message'); };
	const php = await bootPHP(loader, {
		onSync: unexpected, onPark: unexpected,
		print: line => output.push(line), printErr: line => errors.push(line),
	});
	try {
		const { exitCode } = await php.run({ code });
		if (exitCode || errors.length) throw new Error(errors.join('\n') || `PHP exited with ${exitCode}`);
		return JSON.parse(output.join('\n'));
	} finally {
		php.exit();
	}
}
