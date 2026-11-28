export default {
	async test(_controller, env) {
		const response = await env.WORKER.fetch('http://atom/invoke/VersionRoom/offline/state', {
			method: 'POST',
			headers: { 'content-type': 'application/json' },
			body: JSON.stringify({ args: [] }),
		});
		if (!response.ok) throw new Error(`Deployed Worker returned ${response.status}: ${await response.text()}`);
		const { result } = await response.json();
		if (result.version !== env.PHP || result.intBytes !== 8 || result.rows.length !== 0) {
			throw new Error('The deployed Wasm failed to boot the expected PHP guest and fresh SQLite room');
		}
	},
};
