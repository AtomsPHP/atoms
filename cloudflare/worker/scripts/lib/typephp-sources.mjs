import path from 'node:path';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const MAGIC_HELPER = fileURLToPath(new URL('./typephp-magic.php', import.meta.url));
const BOOTSTRAP = '/atoms/runtime/bootstrap.php';
const ENTRY_MARKER = '// ---------------------------------------------------------------------------\n// Entry.';
const MAIN_PATH = '/atoms/typephp/main.php';

function replaceOnce(source, original, replacement) {
	if (source.split(original).length !== 2) {
		throw new Error(`TypePHP bootstrap no longer contains exactly one ${JSON.stringify(original)}; update its source adapter.`);
	}
	return source.replace(original, replacement);
}

/** Compile runtime declarations and preserve the autoloader for supporting PHP. */
function bootstrapSource(source) {
	const marker = source.indexOf(ENTRY_MARKER);
	if (marker < 0 || source.indexOf(ENTRY_MARKER, marker + 1) !== -1) {
		throw new Error('TypePHP bootstrap entry marker is missing or ambiguous; update its source adapter.');
	}
	const entry = source.indexOf('$__atoms_runtime_dir =', marker);
	if (entry < 0) throw new Error('TypePHP bootstrap entry assignment is missing; update its source adapter.');
	let output = source.slice(0, marker) + '\nfunction typephp_entry(array $CFG): void\n{\n' + source.slice(entry) + '\n}\n';
	for (const statement of [
		'require_once $path;',
		"require_once $entry['file'];",
		"require_once rtrim($__atoms_runtime_dir, '/') . '/host.php';",
		"require_once rtrim($__atoms_runtime_dir, '/') . '/int64.php';",
	]) {
		output = replaceOnce(output, statement, '// This file was compiled into the TypePHP component.');
	}
	return output;
}

const MAIN = `<?php

/** Standard streams carry byte-counted tagged host calls. */
function post_message_to_js(string $message): string
{
    $frame = "\\x1eATOMS:" . strlen($message) . "\\n" . $message;
    $written = 0;
    while ($written < strlen($frame)) {
        $count = fwrite(STDOUT, substr($frame, $written));
        if ($count === false || $count === 0) {
            throw new \\RuntimeException('Atoms: the TypePHP host output stream closed.');
        }
        $written += $count;
    }
    fflush(STDOUT);
    $reply = fgets(STDIN);
    if ($reply === false) {
        throw new \\RuntimeException('Atoms: the TypePHP host input stream closed.');
    }

    return rtrim($reply, "\\r\\n");
}

function main(): void
{
    $line = fgets(STDIN);
    if ($line === false) {
        throw new \\RuntimeException('Atoms: the TypePHP boot payload is missing.');
    }
    $config = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($config)) {
        throw new \\RuntimeException('Atoms: the TypePHP boot payload must be an object.');
    }
    \\Atoms\\Cf\\typephp_entry($config);
}
`;

function guestPath(value) {
	return typeof value === 'string' && value.startsWith('/') && !value.includes('\\') && !value.includes('\0')
		&& path.posix.normalize(value) === value && !value.endsWith('/');
}

/**
 * Prepare deterministic compiler inputs without executing customer PHP.
 * Compile primary Atom files and the runtime ahead of time. PHP loads supporting
 * classes, migrations, and vendor function files at runtime.
 */
export function typePhpSources({ manifest, files }, { php = process.env.ATOMS_TYPEPHP_PHP || 'php' } = {}) {
	if (!manifest?.atoms || typeof manifest.atoms !== 'object' || Array.isArray(manifest.atoms)) {
		throw new Error('TypePHP requires a Worker manifest with an atoms map.');
	}
	if (!files || typeof files !== 'object' || Array.isArray(files)) throw new Error('TypePHP requires a guest file map.');
	for (const [name, source] of Object.entries(files)) {
		if (!guestPath(name) || typeof source !== 'string') throw new Error(`Invalid TypePHP guest file: ${JSON.stringify(name)}.`);
	}
	const selected = new Set(Object.keys(files).filter((name) => name.endsWith('.php')
		&& (name.startsWith('/atoms/core/src/') || name.startsWith('/atoms/runtime/'))));
	const atoms = [];
	for (const [type, atom] of Object.entries(manifest.atoms)) {
		if (!atom || typeof atom.class !== 'string' || !atom.class || !guestPath(atom.file) || !atom.file.startsWith('/app/') || !atom.file.endsWith('.php')) {
			throw new Error(`TypePHP Atom ${type} must declare its class and an /app/*.php source path.`);
		}
		if (!Object.hasOwn(files, atom.file)) throw new Error(`TypePHP Atom ${type} source is missing: ${atom.file}.`);
		selected.add(atom.file);
		atoms.push({ class: atom.class, file: atom.file });
	}
	if (!selected.has(BOOTSTRAP) || !selected.has('/atoms/core/src/Atom.php')) {
		throw new Error('TypePHP requires the complete Atoms runtime and vendored core sources.');
	}
	if (Object.hasOwn(files, MAIN_PATH)) throw new Error(`The TypePHP entry path is reserved: ${MAIN_PATH}.`);
	const compiledFiles = [...selected].sort();
	const sources = Object.fromEntries(compiledFiles.map((name) => [name, name === BOOTSTRAP ? bootstrapSource(files[name]) : files[name]]));
	sources[MAIN_PATH] = MAIN;

	// This helper only tokenizes source. It neither requires the files nor loads
	// Composer, so declarations and top-level expressions cannot run during a build.
	const input = JSON.stringify({ sources, atoms }) + '\n';
	const result = spawnSync(php, [MAGIC_HELPER], {
		input, encoding: 'utf8', shell: false,
		env: Object.fromEntries(Object.entries(process.env).filter(([name]) => !/^(CLOUDFLARE_|CF_API_|ATOMS_SHARED_SECRET)/.test(name))),
		// A magic constant can expand to a guest path. Size the response buffer
		// from the input size and the longest guest path.
		maxBuffer: Buffer.byteLength(input) * (1 + Math.max(...Object.keys(sources).map((name) => name.length))),
	});
	if (result.error || result.status !== 0) {
		throw new Error(`TypePHP source preparation failed: ${result.error?.message || result.stderr.trim() || `exit ${result.status}`}`);
	}
	let transformed;
	try { transformed = JSON.parse(result.stdout); } catch { throw new Error('TypePHP source preparation returned invalid JSON.'); }
	if (!transformed || Object.keys(transformed).sort().join('\0') !== Object.keys(sources).sort().join('\0')
		|| Object.values(transformed).some((source) => typeof source !== 'string')) {
		throw new Error('TypePHP source preparation changed the selected source files.');
	}
	return { sources: transformed, compiledFiles };
}
