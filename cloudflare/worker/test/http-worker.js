// Only the conformance runner imports this entry. Every accepted request
// still passes through the production policy, timeout and response reader.
export { default } from '../src/index.js';
import { AtomDurableObject as ProductionAtom } from '../src/atom-do.js';

export class AtomDurableObject extends ProductionAtom {
	constructor(ctx, env) {
		super(ctx, env);
		this.httpTransport = (url, init) => {
			if (url.origin !== 'https://api.example.com') throw new Error('Unexpected test origin');
			const local = new URL('http://127.0.0.1');
			local.port = env.RECORDER_PORT;
			local.pathname = url.pathname;
			local.search = url.search;
			return fetch(local, init);
		};
	}
}
