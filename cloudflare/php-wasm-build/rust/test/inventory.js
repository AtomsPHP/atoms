import createModule from './php.mjs';
import wasm from './php.wasm';
import source from './guest.php';
import {bootPHP} from './loader.js';

export class Probe {
  async fetch() {
    const output = [];
    const errors = [];
    const unexpectedMessage = () => { throw Error('Inventory attempted to send an Atom message'); };
    const php = await bootPHP(createModule, wasm, {
      onSync: unexpectedMessage, onPark: unexpectedMessage,
      print: line => output.push(line), printErr: line => errors.push(line),
    });
    const result = await php.run({code: source});
    if (result.exitCode || errors.length) throw Error([...errors, ...output].join('\n'));
    return Response.json(JSON.parse(output.join('\n')));
  }
}

export default {
  async test(_controller, env) {
    const room = env.PROBE.get(env.PROBE.idFromName('inventory'));
    const response = await room.fetch('http://probe/inventory');
    if (!response.ok) throw Error(await response.text());
    console.log('ATOMS_INVENTORY=' + await response.text());
  },
};
