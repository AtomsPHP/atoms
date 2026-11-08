import { phpTarget } from '../scripts/lib/php-target.mjs';

/** Select expectations explicitly. Never infer them from observed output. */
export function phpContract(backend = 'php-wasm') {
    const target = phpTarget(backend);
    const suffix = backend === 'php-wasm' ? '' : '-php85';
    return { backend, ...target, pins: `pdo-expected${suffix}.json`,
        report: `pdo-matrix${suffix}.json`, doc: `pdo-compatibility${suffix}.md` };
}
