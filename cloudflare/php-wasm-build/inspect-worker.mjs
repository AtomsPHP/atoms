// conformance.py stages this entry point alongside the installed scaffold.
import { DurableObject } from 'cloudflare:workers';
import { bootPHP } from './src/php-host.js';
import code from './scripts/inspect-guest.php';

// Use the class exported by the conformance Wrangler configuration.
export class AtomDurableObject extends DurableObject {
	async fetch() {
		const php = await bootPHP({ onSync() {}, onPark() {} });
		try {
			const result = await php.run({ code });
			if (result.exitCode !== 0 || result.errors) {
				throw new Error(result.errors || 'Guest inspection failed.');
			}
			return new Response(result.text, { headers: { 'Content-Type': 'application/json' } });
		} finally {
			php.exit();
		}
	}
}

export default {
	fetch(request, env) {
		if (new URL(request.url).pathname === '/healthz') return new Response('OK');
		return env.ATOMS.getByName('release-inventory').fetch(request);
	},
};
