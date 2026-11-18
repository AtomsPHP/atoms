import { readFileSync } from 'node:fs';

// Generated copy of packages/cli/resources/php-targets.json, checked by tests.
export const PHP_TARGETS = JSON.parse(readFileSync(new URL('./php-targets.json', import.meta.url), 'utf8'));

export function phpTarget(compiler) {
    if (!Object.hasOwn(PHP_TARGETS, compiler)) throw new Error(`Unknown compiler ${compiler}; use php-wasm or typephp.`);
    return PHP_TARGETS[compiler];
}

export function targetManifest(manifest, compiler, source = manifest.abi) {
    const target = phpTarget(compiler);
    if (source?.php !== target.php || (source.php_version !== undefined && source.php_version !== target.php_version)) {
        throw new Error(`${compiler} targets PHP ${target.php_version}; the source manifest targets ${JSON.stringify(source)}. Rebuild for PHP ${target.php}.`);
    }
    return { ...manifest, abi: { ...manifest.abi, php: target.php, php_version: target.php_version } };
}
