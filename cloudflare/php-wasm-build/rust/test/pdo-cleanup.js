import init from './php.mjs';
import wasm from './php.wasm';
import guest from './guest.php';
import {bootPHP} from './loader.js';

export class Probe {
  async fetch(request) {
    const mode = new URL(request.url).pathname.slice(1);
    const parks = [], lines = [], errors = [];
    let wake;
    const php = await bootPHP(init, wasm, {
      print: line => lines.push(line), printErr: line => errors.push(line),
      onSync: () => ' '.repeat(9 * 1024 * 1024),
      onPark(message, resume) { parks.push(message); wake = resume; },
    });
    php.mkdir('/fixture');
    php.writeFile('/fixture/mode', mode);
    php.writeFile('/fixture/compile.php', '<?php final class ParentType {} class ChildType extends ParentType {}');
    const run = php.run({code: guest});
    while (wake) {
      if (parks.length > 2) throw Error('Unexpected PDO destructor park: ' + parks.join(','));
      const next = wake;
      wake = null;
      next('ok');
    }
    return Response.json({result: await run, parks, lines, errors});
  }
}
export default {
  async test(_controller, env) {
    const stub = env.PROBE.get(env.PROBE.idFromName('cleanup'));
    for (const mode of ['normal', 'compile', 'oom']) {
      const response = await stub.fetch('http://probe/' + mode);
      const {result, parks, lines, errors} = await response.json();
      const normal = mode === 'normal';
      const expected = mode === 'compile' ? /cannot extend final class ParentType/i : /Allowed memory size of 8388608 bytes exhausted/;
      if (!lines.includes('resumed in destructor') || lines.includes('returned to caller') !== normal ||
          parks.join(',') !== (normal ? 'begin,rollback' : 'begin') || result.exitCode !== (normal ? 0 : 255) ||
          (normal ? (!lines.includes('Invalid affected row count from the Atom database.') || errors.length) : !errors.some(line => expected.test(line)))) {
        throw Error(JSON.stringify({mode, result, parks, lines, errors}));
      }
      console.log(mode, JSON.stringify({result, parks, errors}));
    }
  },
};
