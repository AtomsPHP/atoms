import createModule from './php.mjs';
import wasm from './php.wasm';
import program from './guest.php';
import runtimeFiles from './pdo-runtime.js';
import {bootPHP} from './loader.js';
import {Bridge, TransactionMachine} from './bridge.js';
import {loadConfig} from './config.js';

function requireValue(condition, message) {
  if (!condition) throw Error(message);
}

export class Probe {
  constructor(ctx) {
    this.ctx = ctx;
    const sql = ctx.storage.sql;
    sql.exec('CREATE TABLE IF NOT EXISTS entries(k TEXT PRIMARY KEY)');
    sql.exec('CREATE TABLE IF NOT EXISTS constructions(n INTEGER)');
    sql.exec('INSERT INTO constructions VALUES (1)');
    this.constructions = sql.exec('SELECT count(*) AS n FROM constructions').one().n;
    this.config = loadConfig({});
    this.bridge = new Bridge({ctx, env: {}, config: this.config, inTransactionRef: () => this.inCallback});
    this.generation = 0;
  }

  takePending() {
    const park = this.pending;
    this.pending = null;
    return park;
  }

  async boot() {
    const factory = async options => {
      const module = await createModule(options);
      const malloc = module._malloc;
      // Force growth in the allocation that publishes the tx.begin reply.
      module._malloc = size => {
        if (!this.grow) return malloc(size);
        this.grow = false;
        const before = module.HEAPU8;
        const initial = before.byteLength;
        const ptr = malloc(initial + 65 * 1024 * 1024);
        const delta = module.HEAPU8.byteLength - initial;
        requireValue(this.inCallback && before.byteLength === 0 && delta > 64 * 1024 * 1024,
          'Expected a detached view and more than 64 MiB of guest memory growth inside the callback');
        this.trace.push('memoryGrowthBytes=' + delta);
        return ptr;
      };
      return module;
    };
    const log = line => { if (line) this.trace.push(line); };
    const php = await bootPHP(factory, wasm, {
      print: log, printErr: log,
      onSync: raw => {
        const msg = JSON.parse(raw);
        if (msg.op === 'fixture.largeReply') return ' '.repeat(9 * 1024 * 1024);
        this.trace.push((this.inCallback ? 'inside ' : 'outside ') + msg.sql);
        return this.bridge.handleSync(raw);
      },
      onPark: (raw, reply) => {
        requireValue(!this.pending, 'Guest parked twice');
        const msg = JSON.parse(raw);
        this.trace.push(msg.op);
        this.pending = {msg, reply};
      },
    });
    this.php = php;
    this.generation++;
    const ctx = {storage: {transactionSync: callback => this.ctx.storage.transactionSync(() => {
      this.trace.push('enter');
      this.inCallback = true;
      try {
        return callback();
      } finally {
        this.inCallback = false;
        this.trace.push('leave');
      }
    })}};
    this.tx = new TransactionMachine({ctx, config: this.config, host: this});
    php.mkdir('/fixture');
    for (const [name, source] of Object.entries(runtimeFiles)) php.writeFile('/fixture/' + name, source);
    php.writeFile('/fixture/compile.php', '<?php final class ParentType {} class ChildType extends ParentType {}');
    requireValue(php.fileExists('/fixture/compile.php'), 'Missing compile fixture');
    this.run = php.run({code: program});
    if (!this.pending) {
      const result = await this.run;
      throw Error('Bootstrap exited before first park: ' + JSON.stringify({result, trace: this.trace}));
    }
  }

  async fetch(request) {
    this.trace = [];
    if (!this.php) await this.boot();
    const [mode, key] = new URL(request.url).pathname.slice(1).split('/');
    let status = 200;
    let result;
    const parked = this.takePending();
    requireValue(parked?.msg.op === 'turn.await', 'Expected turn.await');
    try {
      parked.reply(JSON.stringify({mode, key}));
      if (this.pending?.msg.op === 'tx.begin') {
        this.grow = mode === 'grow';
        this.tx.begin(this.takePending());
      }
      requireValue(this.pending?.msg.op === 'turn.await', 'Guest failed to park for the next turn');
      result = this.pending.msg.result;
    } catch (error) {
      this.php.exit();
      this.php = null;
      this.pending = null;
      requireValue((await this.run).exitCode === 255, 'Fatal guest reported success');
      status = 500;
      result = {code: error.code, message: error.message};
    }
    let wakeError;
    try { parked.reply('{}'); } catch (error) { wakeError = error.message; }
    requireValue(wakeError === (status === 500 ? 'PHP instance has closed' : 'PHP wake already consumed'),
      'Old wake remained usable');
    return Response.json({result, id: this.ctx.id.toString(), constructions: this.constructions,
      generation: this.generation, trace: this.trace}, {status});
  }
}

export default {
  async test(_controller, env) {
    const stub = env.PROBE.get(env.PROBE.idFromName('turn-proof'));
    async function run(mode, key) {
      const res = await stub.fetch('http://probe/' + mode + '/' + key);
      const body = await res.json();
      console.log(mode, res.status, JSON.stringify(body));
      return {...body, status: res.status};
    }
    const rows = keys => [...keys].sort().map(k => ({k}));
    const equal = (a, b) => JSON.stringify(a) === JSON.stringify(b);
    const keys = ['seed'];
    const seed = await run('write', 'seed');
    requireValue(seed.status === 200 && equal(seed.result, rows(keys)), 'Seed write failed');
    let generation = seed.generation;
    for (const mode of ['rollback', 'grow', 'compile', 'oom', 'catch']) {
      const r = await run(mode, mode);
      const fatal = mode === 'compile' || mode === 'oom';
      requireValue(r.generation === generation && r.id === seed.id &&
        r.constructions === seed.constructions, 'Turn used a different interpreter or DO');
      if (mode !== 'catch') {
        const from = r.trace.indexOf('enter'), to = r.trace.indexOf('leave');
        const inside = r.trace.slice(from + 1, to);
        requireValue(from >= 0 && to > from && inside.includes('inside INSERT INTO entries VALUES (?)')
          && inside.includes('inside SELECT k FROM entries WHERE k = ?'), 'Missing inline guest write/read');
        if (fatal) {
          const expected = mode === 'oom' ? /Allowed memory size of 8388608 bytes exhausted.*message\.php/ :
            /cannot extend final class ParentType.*compile\.php/i;
          requireValue(inside.some(v => expected.test(v)) && r.status === 500 && r.result.code === 'internal',
            'Expected PHP fatal inside the failed transaction');
          generation++;
        } else {
          requireValue(r.status === 200 && inside.includes('tx.rollback') && equal(r.result, rows([mode])),
            'Expected explicit rollback');
          if (mode === 'grow') requireValue(inside.some(v => v.startsWith('memoryGrowthBytes=')), 'No memory growth');
        }
      } else {
        requireValue(r.status === 200 && r.result === 'caught', 'Catchable exception discarded its guest');
      }
      const next = await run('read', 'next');
      requireValue(next.status === 200 && next.id === seed.id && next.constructions === seed.constructions
        && next.generation === generation && equal(next.result, rows(keys)), 'Incorrect next-turn state');
      const key = mode + '-next';
      const write = await run('write', key);
      requireValue(write.status === 200 && write.generation === generation && equal(write.result, rows([key])),
        'Recovered interpreter failed write/read');
      keys.push(key);
    }
  },
};
