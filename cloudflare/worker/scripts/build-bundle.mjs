#!/usr/bin/env node

/**
 * build-bundle.mjs — assemble a fixture app into a bundle.
 *
 * Walks a fixture directory and emits src/bundle.generated.js as
 * export default {manifest, files} where files maps guest paths to contents.
 *
 * Usage: node build-bundle.mjs <fixture-dir> <output-file>
 * Default: node build-bundle.mjs fixtures/counter src/bundle.generated.js
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const PROJECT_ROOT = path.resolve(__dirname, '..');

const fixtureDir = path.resolve(PROJECT_ROOT, process.argv[2] || 'fixtures/counter');
const outputFile = path.resolve(PROJECT_ROOT, process.argv[3] || 'src/bundle.generated.js');

/** Recursively read all files in a directory. */
function walkDir(dir, prefix = '') {
    const files = {};
    if (!fs.existsSync(dir)) {
        return files;
    }

    const entries = fs.readdirSync(dir, { withFileTypes: true });
    const sorted = entries.sort((a, b) => a.name.localeCompare(b.name));

    for (const entry of sorted) {
        const fullPath = path.join(dir, entry.name);
        const guestPath = path.join(prefix, entry.name);

        if (entry.isDirectory()) {
            Object.assign(files, walkDir(fullPath, guestPath));
        } else if (entry.isFile()) {
            const content = fs.readFileSync(fullPath, 'utf-8');
            files[guestPath] = content;
        }
    }

    return files;
}

/** Read and parse manifest.json from fixture. */
function readManifest(dir) {
    const manifestPath = path.join(dir, 'manifest.json');
    if (!fs.existsSync(manifestPath)) {
        throw new Error(`manifest.json not found in ${dir}`);
    }
    return JSON.parse(fs.readFileSync(manifestPath, 'utf-8'));
}

/** Build the bundle. */
async function buildBundle() {
    console.error(`Reading fixture from: ${fixtureDir}`);
    console.error(`Writing bundle to: ${outputFile}`);

    const manifest = readManifest(fixtureDir);

    // Collect all files with deterministic ordering
    const allFiles = {};

    // 1. Walk app/ directory (fixture PHP and migrations)
    const appDir = path.join(fixtureDir, 'app');
    if (fs.existsSync(appDir)) {
        Object.assign(allFiles, walkDir(appDir, '/app'));
    }

    // 2. Include php/runtime/* as /atoms/runtime/...
    const runtimeDir = path.join(PROJECT_ROOT, 'php', 'runtime');
    if (fs.existsSync(runtimeDir)) {
        Object.assign(allFiles, walkDir(runtimeDir, '/atoms/runtime'));
    } else {
        console.warn(`php/runtime/ not found; will be missing from bundle`);
    }

    // 3. Include php/atoms-core/* as /atoms/core/src/... — except resources/,
    //    which must land at /atoms/core/resources/. That layout is load-bearing:
    //    Atoms\Errors\ErrorCatalog resolves its catalog as
    //    __DIR__ . '/../../resources/errors.json', i.e. from /atoms/core/src/Errors
    //    up to /atoms/core/resources. See php/README.md §1.
    const atomsCoreDir = path.join(PROJECT_ROOT, 'php', 'atoms-core');
    if (fs.existsSync(atomsCoreDir)) {
        const core = walkDir(atomsCoreDir, '/');
        for (const [rel, contents] of Object.entries(core)) {
            if (!/\.(php|json)$/.test(rel)) continue; // VENDORED-FROM.md et al.
            const stripped = rel.replace(/^\//, '');
            const guestPath = stripped.startsWith('resources/')
                ? `/atoms/core/${stripped}`
                : `/atoms/core/src/${stripped}`;
            allFiles[guestPath] = contents;
        }
    } else {
        console.warn(`php/atoms-core/ not found; will be missing from bundle`);
    }

    // Sort keys for deterministic output
    const sortedFiles = {};
    for (const key of Object.keys(allFiles).sort()) {
        sortedFiles[key] = allFiles[key];
    }

    // Rewrite file paths in manifest to be guest paths
    const rewrittenAtoms = {};
    for (const [atomType, atomDef] of Object.entries(manifest.atoms || {})) {
        const file = atomDef.file;
        const migrations = atomDef.migrations || [];

        rewrittenAtoms[atomType] = {
            ...atomDef,
            file: file.startsWith('/') ? file : `/app/${file}`,
            migrations: migrations.map(m => m.startsWith('/') ? m : `/app/${m}`),
        };
    }

    const outputManifest = {
        ...manifest,
        atoms: rewrittenAtoms,
    };

    // Generate the output file
    const output = `/**
 * Auto-generated bundle by build-bundle.mjs
 * Do not edit manually.
 */

export default {
  manifest: ${JSON.stringify(outputManifest, null, 2)},
  files: ${JSON.stringify(sortedFiles, null, 2)},
};
`;

    // Ensure output directory exists
    const outputDir = path.dirname(outputFile);
    if (!fs.existsSync(outputDir)) {
        fs.mkdirSync(outputDir, { recursive: true });
    }

    fs.writeFileSync(outputFile, output, 'utf-8');
    console.error(`Bundle generated successfully`);
    console.error(`Files included: ${Object.keys(sortedFiles).length}`);
    console.error(`Atoms defined: ${Object.keys(rewrittenAtoms).length}`);
}

buildBundle().catch(err => {
    console.error(`Error: ${err.message}`);
    process.exit(1);
});
