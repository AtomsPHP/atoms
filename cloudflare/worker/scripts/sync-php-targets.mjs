import { readFileSync, writeFileSync } from 'node:fs';

const source = readFileSync(new URL('../../../packages/cli/resources/php-targets.json', import.meta.url));
const destination = new URL('./lib/php-targets.json', import.meta.url);
if (process.argv.includes('--check')) {
    if (!source.equals(readFileSync(destination))) throw new Error('PHP targets are stale; run node scripts/sync-php-targets.mjs');
} else {
    writeFileSync(destination, source);
}
