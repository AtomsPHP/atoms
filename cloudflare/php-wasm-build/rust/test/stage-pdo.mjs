import { copyFileSync, mkdirSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';

// Stage a private build only inside the PDO suite's temporary Worker. The
// ordinary installer continues to select and verify the three release pairs.
export function stagePdoBuild(root, pin, build) {
    const pair = join(root, '.php-wasm');
    mkdirSync(pair);
    const wasm = join(pair, 'php.wasm');
    const glue = join(pair, 'php.mjs');
    copyFileSync(join(build, 'php.wasm'), wasm);
    copyFileSync(join(build, 'php.mjs'), glue);
    writeFileSync(join(pair, pin.glue.path), "export {default as factory} from './php.mjs';\nexport {default as wasm} from './php.wasm';\n");
    copyFileSync(fileURLToPath(new URL('../loader.js', import.meta.url)), join(root, 'src/embed-loader.js'));
    copyFileSync(fileURLToPath(new URL('../../../worker/src/guest-files.js', import.meta.url)), join(root, 'src/guest-files.js'));
    copyFileSync(fileURLToPath(new URL('./pdo-host.js', import.meta.url)), join(root, 'src/php-host.js'));
    return {wasm, glue};
}
