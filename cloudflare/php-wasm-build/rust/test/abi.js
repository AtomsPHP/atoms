import init from './php.mjs';
import wasm from './php.wasm';
import {bootPHP} from './loader.js';
import script from './guest.php';
import expectedVersion from './expected-version.txt';
import syntax from './syntax.php';
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
    const factory = options => init({
      ...options,
      atomsRoundtripI64(value) {
        if (value !== 9223372036854775807n) throw Error('Expected INT64_MAX at the Wasm import');
        hostCalls++;
        return value;
      },
    });
    const php = await bootPHP(factory, wasm, {
      print: line => output.push(line),
      printErr: line => errors.push(line),
    });
    php.writeFile('/atoms/syntax.php', syntax);
    php.writeFile('/atoms/timestamps.php', timestamps);
    php.writeFile('/atoms/parity.php', parity);
    php.writeFile('/atoms/inspect-guest.php', inspectGuest);
    try {
      const {exitCode} = await php.run({code: script});
      return Response.json({ok: exitCode === 0, output, errors, hostCalls});
    } finally {
      php.exit();
      console.log([...output, ...errors].join('\n'));
    }
  }
}

export default {
  async test(_controller, env) {
    const res = await env.PROBE.get(env.PROBE.idFromName('abi')).fetch('http://probe/abi');
    const {ok, output, errors, hostCalls} = await res.json();
    if (!ok || errors.length || hostCalls !== 1 || output.at(-1) !== expectedVersion + ' wasm 9223372036854775807') {
      throw Error('PHP ABI probe failed');
    }
  },
};
