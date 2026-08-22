/**
 * lib/bundle-module.mjs — shared machinery for emitting `src/bundle.generated.js`.
 *
 * Two scripts produce that file: `build-bundle.mjs` (the conformance fixture)
 * and `bundle-from-cli.mjs` (the translation of an `atoms build` artifact).
 * They must emit the same module shape from the same runtime inputs, so the
 * directory walk, the runtime-half assembly and the writer live here rather
 * than drifting apart again — the two inline copies this replaces had already
 * diverged on missing-directory behavior (warn vs throw). Reconciled to
 * fail-loud throughout: `php/runtime/` and `php/atoms-core/` are committed
 * repository source, so their absence means a broken tree, and a bundle
 * silently missing the runtime or the vendored core is worse than no bundle.
 */

import fs from 'node:fs';
import path from 'node:path';

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
 * as `/atoms/runtime/...`, plus `php/atoms-core/*` — except `resources/`,
 * which must land at `/atoms/core/resources/`. That split is load-bearing:
 * `Atoms\Errors\ErrorCatalog` resolves its catalog as
 * `__DIR__ . '/../../resources/errors.json'`, i.e. from `/atoms/core/src/Errors`
 * up to `/atoms/core/resources`. See php/README.md §1.
 *
 * Throws when either input tree is missing: both are committed repository
 * source, so their absence is a broken tree, not a shippable partial bundle.
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

    const coreDir = path.join(projectRoot, 'php', 'atoms-core');
    if (!fs.existsSync(coreDir)) {
        throw new Error(`php/atoms-core/ not found at ${coreDir}`);
    }
    for (const [rel, contents] of Object.entries(walkDir(coreDir, '/'))) {
        if (!/\.(php|json)$/.test(rel)) continue; // VENDORED-FROM.md et al.
        const stripped = rel.replace(/^\//, '');
        const guestPath = stripped.startsWith('resources/')
            ? `/atoms/core/${stripped}`
            : `/atoms/core/src/${stripped}`;
        files[guestPath] = contents;
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
 * `export default {manifest, files}` with a do-not-edit banner naming the
 * script that produced it.
 *
 * @param {string} generatorName - script name for the banner, e.g. 'build-bundle.mjs'
 * @param {any} manifest - the host manifest to embed
 * @param {Record<string, string>} files - guest path => contents, pre-sorted
 * @param {string} outputFile - absolute destination path
 */
export function writeBundleModule(generatorName, manifest, files, outputFile) {
    const output = `/**
 * Auto-generated bundle by ${generatorName}
 * Do not edit manually.
 */

export default {
  manifest: ${JSON.stringify(manifest, null, 2)},
  files: ${JSON.stringify(files, null, 2)},
};
`;

    fs.mkdirSync(path.dirname(outputFile), { recursive: true });
    fs.writeFileSync(outputFile, output, 'utf-8');
}
