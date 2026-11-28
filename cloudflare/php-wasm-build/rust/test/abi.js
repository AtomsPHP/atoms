import init from './php.mjs';
import wasm from './php.wasm';
import {precompiledModule} from './loader.js';
import script from './guest.php';
import timestamps from './timestamps.php';
import parity from './parity.php';
import inspectGuest from './inspect-guest.php';

export class Probe {
  constructor(ctx) {
    this.storage = ctx.storage;
  }

  async fetch() {
    this.storage.sql.exec('SELECT 1').one();
    const output = [];
    const errors = [];
    let hostCalls = 0;
    const php = await init({
      ...precompiledModule(wasm),
      atomsRoundtripI64(value) {
        if (value !== 9223372036854775807n) throw Error('Expected INT64_MAX at the Wasm import');
        hostCalls++;
        return value;
      },
      print: line => output.push(line),
      printErr: line => errors.push(line),
    });
    php.FS.writeFile('/guest.php', script);
    php.FS.writeFile('/timestamps.php', timestamps);
    php.FS.writeFile('/parity.php', parity);
    php.FS.writeFile('/inspect-guest.php', inspectGuest);
    php.atomsBridge = php._atoms_prepare();
    if (php._php_embed_init(0, 0) !== 0) throw Error('php_embed_init failed');
    const path = new TextEncoder().encode('/guest.php\0');
    const ptr = php._malloc(path.length);
    if (!ptr) throw Error('Could not allocate script path');
    php.HEAPU8.set(path, ptr);
    const file = php._atoms_script_handle();
    if (!file) throw Error('Zend did not initialize its Wasm stack bounds');
    php._zend_stream_init_filename(file, ptr);
    php._free(ptr);
    const ok = php._php_execute_script(file);
    php._zend_destroy_file_handle(file);
    php._php_embed_shutdown();
    console.log([...output, ...errors].join('\n'));
    return Response.json({ok, output, errors, hostCalls});
  }
}

export default {
  async test(_controller, env) {
    const res = await env.PROBE.get(env.PROBE.idFromName('abi')).fetch('http://probe/abi');
    const {ok, output, errors, hostCalls} = await res.json();
    if (!ok || errors.length || hostCalls !== 1 || output.at(-1) !== '8.3.32 wasm 9223372036854775807') {
      throw Error('PHP ABI probe failed');
    }
  },
};
