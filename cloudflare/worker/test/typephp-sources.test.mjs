import assert from 'node:assert/strict';
import { mkdtempSync, readFileSync, existsSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import test from 'node:test';
import { typePhpSources } from '../scripts/lib/typephp-sources.mjs';

const bootstrap = readFileSync(new URL('../php/runtime/bootstrap.php', import.meta.url), 'utf8');
const atom = readFileSync(new URL('../php/atoms-core/Atom.php', import.meta.url), 'utf8');

function fixture(source = '<?php namespace App; use Atoms\\Atom; final class Counter extends Atom {}') {
	return {
		manifest: { atoms: { Counter: { class: 'App\\Counter', file: '/app/Counter.php' } } },
		files: {
			'/app/Counter.php': source,
			'/app/Counter/Support/Helper.php': '<?php namespace App; final class Helper {}',
			'/app/migrations/001_init.php': '<?php throw new \\RuntimeException("not a build input");',
			'/app/vendor/autoload.php': '<?php throw new \\RuntimeException("not a build input");',
			'/atoms/core/src/Atom.php': atom,
			'/atoms/core/resources/errors.json': '{}',
			'/atoms/runtime/bootstrap.php': bootstrap,
			'/atoms/runtime/host.php': '<?php namespace Atoms\\Cf; function host_call() {}',
		},
	};
}

test('only primary Atom files and runtime declarations are compiled; support PHP remains available for runtime loading', () => {
	const input = fixture();
	const before = structuredClone(input);
	const result = typePhpSources(input);
	assert.deepEqual(result.compiledFiles, [
		'/app/Counter.php', '/atoms/core/src/Atom.php', '/atoms/runtime/bootstrap.php', '/atoms/runtime/host.php',
	]);
	assert.deepEqual(Object.keys(result.sources).sort(), [...result.compiledFiles, '/atoms/typephp/main.php'].sort());
	assert.deepEqual(input, before);
	assert.match(result.sources['/atoms/runtime/bootstrap.php'], /require_once \$index\[\$key\];/);
	assert.match(result.sources['/atoms/runtime/bootstrap.php'], /require_once \$vendorAutoload;/);
	assert.doesNotMatch(result.sources['/atoms/runtime/bootstrap.php'], /require_once \$entry\['file'\];/);
	assert.doesNotMatch(result.sources['/atoms/runtime/bootstrap.php'], /require_once \$path;/);
	assert.match(result.sources['/atoms/runtime/bootstrap.php'], /function typephp_entry\(array \$CFG\): void/);
	assert.match(result.sources['/atoms/typephp/main.php'], /fgets\(STDIN\)/);
	assert.match(result.sources['/atoms/typephp/main.php'], /"\\x1eATOMS:" \. strlen\(\$message\) \. "\\n"/);
});

test('magic constants become guest paths without touching strings/comments or executing PHP', () => {
	const temporary = mkdtempSync(join(tmpdir(), 'atoms-typephp-source-'));
	try {
		const sentinel = join(temporary, 'executed');
		const source = `<?php
namespace App;
use Atoms\\Atom;
file_put_contents(${JSON.stringify(sentinel)}, 'executed');
// __DIR__ and __FILE__ in comments are not code.
final class Counter extends Atom {
    public function paths(): array { return [__DIR__, __FILE__, '__DIR__', '__FILE__']; }
}
`;
		const output = typePhpSources(fixture(source)).sources['/app/Counter.php'];
		assert.ok(!existsSync(sentinel));
		assert.match(output, /return \['\/app', '\/app\/Counter\.php', '__DIR__', '__FILE__'\]/);
		assert.match(output, /\/\/ __DIR__ and __FILE__ in comments are not code\./);
	} finally {
		rmSync(temporary, { recursive: true, force: true });
	}
});

test('ordinary, fully qualified, aliased and grouped direct-parent imports are accepted', () => {
	for (const source of [
		'<?php namespace App; class Counter extends \\Atoms\\Atom {}',
		'<?php namespace App; use Atoms\\Atom as Base; class Counter extends Base {}',
		'<?php namespace App; use Atoms as Runtime; class Counter extends Runtime\\Atom {}',
		'<?php namespace App { use Atoms\\{Database, Atom as Base}; class Counter extends Base {} }',
		'<?php namespace App; use Atoms\\{function helper, Atom}; class Counter extends Atom {}',
		'<?php namespace App; use Atoms\\Atom; class Counter extends Atom { public function f($x) { return "{$x}"; } }',
	]) {
		assert.doesNotThrow(() => typePhpSources(fixture(source)), source);
	}
});

test('intermediate parents and primary traits fail before compiler invocation', () => {
	for (const source of [
		'<?php namespace App; class Counter extends ParentAtom {}',
		'<?php namespace App; use Other\\Atom; class Counter extends Atom {}',
		'<?php namespace App; use function Other\\foo, Atoms\\Atom; class Counter extends Atom {}',
	]) {
		assert.throws(() => typePhpSources(fixture(source)), /must directly extend Atoms\\Atom/);
	}
	assert.throws(() => typePhpSources(fixture('<?php namespace App; class Counter extends \\Atoms\\Atom { use SharedBehavior; }')), /uses a trait/);
	assert.throws(() => typePhpSources(fixture('<?php namespace App; if (true) { class Counter extends \\Atoms\\Atom {} }')), /not found at namespace scope/);
	assert.throws(() => typePhpSources(fixture('<?php namespace App; class Other extends \\Atoms\\Atom {}')), /primary class App\\Counter was not found/);
});

test('a closure capture inside an Atom method is not treated as a trait', () => {
	assert.doesNotThrow(() => typePhpSources(fixture(`<?php
namespace App;
class Counter extends \\Atoms\\Atom {
    public function capture(int $value): int {
        $closure = function () use ($value) { return $value; };
        return $closure();
    }
}
`)));
});

test('source ordering is deterministic', () => {
	const input = fixture();
	const reverse = { ...input, files: Object.fromEntries(Object.entries(input.files).reverse()) };
	assert.equal(JSON.stringify(typePhpSources(input)), JSON.stringify(typePhpSources(reverse)));
});

test('missing sources, unsafe paths and runtime adapter drift fail explicitly', () => {
	const missing = fixture();
	delete missing.files['/app/Counter.php'];
	assert.throws(() => typePhpSources(missing), /source is missing/);
	for (const bad of ['/app/../outside.php', '/app\\outside.php', '/app/nul\0.php', 'relative.php']) {
		const input = fixture();
		input.files[bad] = '<?php';
		assert.throws(() => typePhpSources(input), /Invalid TypePHP guest file/);
	}
	const drift = fixture();
	drift.files['/atoms/runtime/bootstrap.php'] = bootstrap.replace("require_once $entry['file'];", '');
	assert.throws(() => typePhpSources(drift), /bootstrap no longer contains exactly one/);
	const marker = fixture();
	marker.files['/atoms/runtime/bootstrap.php'] = '<?php';
	assert.throws(() => typePhpSources(marker), /entry marker is missing/);
});
