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

import path from 'node:path';
import fs from 'node:fs';
import { fileURLToPath } from 'node:url';

import { runtimeFiles, sortedFiles, walkDir, writeBundleModule } from './lib/bundle-module.mjs';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const PROJECT_ROOT = path.resolve(__dirname, '..');

const fixtureDir = path.resolve(PROJECT_ROOT, process.argv[2] || 'fixtures/counter');
const outputFile = path.resolve(PROJECT_ROOT, process.argv[3] || 'src/bundle.generated.js');

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

    // 2 & 3. The runtime half — php/runtime/* as /atoms/runtime/... and the
    // vendored php/atoms-core/* under /atoms/core/. Fail-loud on a missing
    // tree: both are committed repository source (see runtimeFiles()).
    Object.assign(allFiles, runtimeFiles(PROJECT_ROOT));

    // Sort keys for deterministic output
    const sorted = sortedFiles(allFiles);

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

    writeBundleModule('build-bundle.mjs', outputManifest, sorted, outputFile);
    console.error(`Bundle generated successfully`);
    console.error(`Files included: ${Object.keys(sorted).length}`);
    console.error(`Atoms defined: ${Object.keys(rewrittenAtoms).length}`);
}

buildBundle().catch(err => {
    console.error(`Error: ${err.message}`);
    process.exit(1);
});
