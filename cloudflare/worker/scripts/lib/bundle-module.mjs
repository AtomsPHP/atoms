/**
 * lib/bundle-module.mjs — shared machinery for emitting `src/bundle.generated.js`.
 *
 * Two scripts produce that file: `build-bundle.mjs` (the conformance fixture)
 * and `bundle-from-cli.mjs` (the translation of an `atoms build` artifact).
 * They must emit the same module shape from the same runtime inputs, so the
 * directory walk, the runtime-half assembly and the writer live here rather
 * than drifting apart again — the two inline copies this replaces had already
 * diverged on missing-directory behavior (warn vs throw). Reconciled to
 * fail-loud throughout: a bundle silently missing `php/runtime/` or
 * `atoms/core` is worse than no bundle.
 *
 * In the monorepo, `atoms/core` lives solely in `packages/core/`. When
 * `pack-runtime.mjs` stages `@atomsphp/runtime-cloudflare`, it copies the
 * filtered guest subset into `template/php/atoms-core/` so customer Worker
 * directories are self-contained. `runtimeFiles()` reads `php/atoms-core/`
 * when present and falls back to `../../packages/core/` inside the monorepo.
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { phpRuntime } from './php-runtimes.mjs';

/**
 * Paths relative to `packages/core/src/` deliberately omitted from the
 * Cloudflare Worker guest and from the published `@atomsphp/runtime-cloudflare`
 * template.
 *
 * `Sqlite/SqliteDatabase.php` opens a real `PDO('sqlite:…')` against a
 * filesystem path, which is what the Durable Object bridge replaces
 * (`Atoms\Cf\BridgeDatabase` implements `Atoms\Database` in the guest).
 */
export const CORE_GUEST_OMISSIONS = new Set([
    'Sqlite/SqliteDatabase.php',
]);

/**
 * Recursively collect regular files under `dir` as `{rel, sourcePath}` with
 * POSIX relative paths, sorted in localeCompare order for deterministic output.
 *
 * @param {string} dir
 * @param {string} prefix
 * @returns {Array<{ rel: string, sourcePath: string }>}
 */
function walkEntries(dir, prefix = '') {
    const out = [];
    if (!fs.existsSync(dir)) {
        return out;
    }

    const entries = fs.readdirSync(dir, { withFileTypes: true });
    const sorted = entries.sort((a, b) => a.name.localeCompare(b.name));

    for (const entry of sorted) {
        const fullPath = path.join(dir, entry.name);
        const rel = prefix === '' ? entry.name : path.posix.join(prefix, entry.name);

        if (entry.isDirectory()) {
            out.push(...walkEntries(fullPath, rel));
        } else if (entry.isFile()) {
            out.push({ rel, sourcePath: fullPath });
        }
    }

    return out;
}

/**
 * Enumerate the `atoms/core` files shipped into the Worker guest from a
 * `packages/core` directory: every `.php` file under `src/` except
 * `CORE_GUEST_OMISSIONS`, plus `resources/errors.json`.
 *
 * Returned `rel` paths are POSIX paths relative to `php/atoms-core/`
 * (e.g. `Atom.php`, `Errors/ErrorCode.php`, `resources/errors.json`), so
 * `runtimeFiles()` and `pack-runtime.mjs` share one file list and omission rule.
 *
 * @param {string} corePackageDir - path to `packages/core`
 * @returns {Array<{ rel: string, sourcePath: string }>}
 */
export function coreSourceEntries(corePackageDir) {
    const srcDir = path.join(corePackageDir, 'src');
    if (!fs.existsSync(srcDir) || !fs.statSync(srcDir).isDirectory()) {
        throw new Error(`packages/core/src/ not found at ${srcDir}`);
    }

    const errorsJson = path.join(corePackageDir, 'resources', 'errors.json');
    if (!fs.existsSync(errorsJson) || !fs.statSync(errorsJson).isFile()) {
        throw new Error(`packages/core/resources/errors.json not found at ${errorsJson}`);
    }

    const entries = [];
    for (const entry of walkEntries(srcDir)) {
        if (!entry.rel.endsWith('.php')) continue;
        if (CORE_GUEST_OMISSIONS.has(entry.rel)) continue;
        entries.push(entry);
    }
    if (!entries.some((entry) => entry.rel === 'Atom.php')) {
        throw new Error(`packages/core/src/ at ${srcDir} is incomplete: missing Atom.php`);
    }
    entries.push({ rel: 'resources/errors.json', sourcePath: errorsJson });

    return entries.sort((a, b) => (a.rel < b.rel ? -1 : a.rel > b.rel ? 1 : 0));
}

/**
 * Recursively read a directory into {guestPath: contents}, entries in
 * localeCompare order for deterministic output.
 *
 * @param {string} dir
 * @param {string} prefix
 * @returns {Record<string, string>}
 */
export function walkDir(dir, prefix = '') {
    const files = {};
    if (!fs.existsSync(dir)) {
        return files;
    }

    const entries = fs.readdirSync(dir, { withFileTypes: true });
    const sorted = entries.sort((a, b) => a.name.localeCompare(b.name));

    for (const entry of sorted) {
        const fullPath = path.join(dir, entry.name);
        const guestPath = path.posix.join(prefix, entry.name);

        if (entry.isDirectory()) {
            Object.assign(files, walkDir(fullPath, guestPath));
        } else if (entry.isFile()) {
            const content = fs.readFileSync(fullPath, 'utf-8');
            files[guestPath] = content;
        }
    }

    return files;
}

/**
 * The runtime half every bundle carries: the verbatim `php/runtime/*` sources
 * as `/atoms/runtime/...`, plus `atoms/core` — PHP sources at
 * `/atoms/core/src/...` and `resources/errors.json` at
 * `/atoms/core/resources/errors.json`. That split is load-bearing:
 * `Atoms\Errors\ErrorCatalog` resolves its catalog as
 * `__DIR__ . '/../../resources/errors.json'`, i.e. from `/atoms/core/src/Errors`
 * up to `/atoms/core/resources`. See php/README.md §1.
 *
 * Prefers `php/atoms-core/` when present (a scaffolded
 * `@atomsphp/runtime-cloudflare` directory) and falls back to
 * `../../packages/core/` inside the monorepo. Throws when `php/runtime/` or
 * both core locations are missing, or when a core tree is incomplete.
 *
 * @param {string} projectRoot - the worker project root holding `php/`
 * @returns {Record<string, string>}
 */
export function runtimeFiles(projectRoot) {
    const files = {};

    const runtimeDir = path.join(projectRoot, 'php', 'runtime');
    if (!fs.existsSync(runtimeDir)) {
        throw new Error(`php/runtime/ not found at ${runtimeDir}`);
    }
    Object.assign(files, walkDir(runtimeDir, '/atoms/runtime'));

    const stagedCoreDir = path.join(projectRoot, 'php', 'atoms-core');
    if (fs.existsSync(stagedCoreDir)) {
        for (const [rel, contents] of Object.entries(walkDir(stagedCoreDir, '/'))) {
            if (!/\.(php|json)$/.test(rel)) continue;
            const stripped = rel.replace(/^\//, '');
            if (CORE_GUEST_OMISSIONS.has(stripped)) continue;
            const guestPath = stripped.startsWith('resources/')
                ? `/atoms/core/${stripped}`
                : `/atoms/core/src/${stripped}`;
            files[guestPath] = contents;
        }
        if (!('/atoms/core/src/Atom.php' in files) || !('/atoms/core/resources/errors.json' in files)) {
            throw new Error(
                `php/atoms-core/ at ${stagedCoreDir} is incomplete: expected Atom.php and resources/errors.json`,
            );
        }
        return files;
    }

    const monorepoCoreDir = path.resolve(projectRoot, '..', '..', 'packages', 'core');
    if (!fs.existsSync(monorepoCoreDir)) {
        throw new Error(
            `atoms/core not found: checked ${stagedCoreDir} and monorepo fallback ${monorepoCoreDir}`,
        );
    }
    for (const { rel, sourcePath } of coreSourceEntries(monorepoCoreDir)) {
        const guestPath = rel.startsWith('resources/')
            ? `/atoms/core/${rel}`
            : `/atoms/core/src/${rel}`;
        files[guestPath] = fs.readFileSync(sourcePath, 'utf-8');
    }

    return files;
}

/**
 * Sort {guestPath: contents} by key for deterministic output.
 *
 * @param {Record<string, string>} files
 * @returns {Record<string, string>}
 */
export function sortedFiles(files) {
    const sorted = {};
    for (const key of Object.keys(files).sort()) {
        sorted[key] = files[key];
    }
    return sorted;
}

/**
 * Emit the deploy module the Worker loads:
 * The static loader import selects exactly one interpreter for Wrangler.
 *
 * @param {string} generatorName - script name for the banner, e.g. 'build-bundle.mjs'
 * @param {any} manifest - the host manifest to embed
 * @param {Record<string, string>} files - guest path => contents, pre-sorted
 * @param {string} outputFile - absolute destination path
 */
export function writeBundleModule(generatorName, manifest, files, outputFile) {
    const runtime = phpRuntime(manifest.abi.php);
    const loader = fileURLToPath(new URL(`../../.php-wasm/${runtime.glue}`, import.meta.url));
    const relative = path.relative(path.dirname(outputFile), loader).split(path.sep).map(encodeURIComponent).join('/');
    const specifier = relative.startsWith('../') ? relative : './' + relative;
    const output = `/**
 * Auto-generated bundle by ${generatorName}
 * Do not edit manually.
 */

import * as asyncifyLoader from ${JSON.stringify(specifier)};

export default {
  asyncifyLoader,
  manifest: ${JSON.stringify(manifest, null, 2)},
  files: ${JSON.stringify(files, null, 2)},
};
`;

    fs.mkdirSync(path.dirname(outputFile), { recursive: true });
    fs.writeFileSync(outputFile, output, 'utf-8');
}
