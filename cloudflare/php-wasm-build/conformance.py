#!/usr/bin/env python3
"""Run the repository's Worker conformance postures on one installed scaffold."""

import argparse
import base64
import contextlib
import http.client
import os
from pathlib import Path
import re
import secrets
import shutil
import signal
import socket
import subprocess
import sys
import tempfile
import time

import yaml


ROOT = Path(__file__).resolve().parents[2]
HARNESS = ROOT / 'cloudflare/worker'


def prepare(scaffold, work, env):
    """Use the installed Worker's host, guest, dependencies and interpreter pin."""
    worker = work / 'worker'
    for directory in ('src', 'php', 'scripts', 'release'):
        shutil.copytree(scaffold / directory, worker / directory)
    shutil.copyfile(scaffold / 'package.json', worker / 'package.json')
    (worker / 'node_modules').symlink_to((scaffold / 'node_modules').resolve(strict=True))
    # The customer config may contain real bindings. Use CI's local DO binding.
    shutil.copyfile(HARNESS / 'wrangler.jsonc', worker / 'wrangler.jsonc')
    (worker / 'test').mkdir()
    for name in ('scripts/build-bundle.mjs', 'scripts/dev-with-callback.mjs',
                 'scripts/gen-pdo-matrix.mjs', 'test/conformance.mjs',
                 'test/required-capabilities.mjs', 'test/pdo-expected.json'):
        shutil.copyfile(HARNESS / name, worker / name)
    (work / 'docs').mkdir()
    shutil.copyfile(ROOT / 'cloudflare/docs/pdo-compatibility.md', work / 'docs/pdo-compatibility.md')
    subprocess.run(['node', 'scripts/prepare-runtime.mjs'], check=True, cwd=worker, env=env)
    subprocess.run(['node', 'scripts/build-bundle.mjs', HARNESS / 'fixtures/counter'], check=True, cwd=worker, env=env)
    return worker


def expand(value, variables):
    return re.sub(r'\$\{\{\s*env\.(\w+)\s*\}\}', lambda m: variables[m[1]], value)


def entries(block, variables):
    return dict(line.split('=', 1) for line in expand(block, variables).splitlines() if line)


def postures(job, env, variables):
    steps = job['steps']
    full = next(step for step in steps if step.get('run') == 'npm test')
    start = next(step for step in steps if step.get('name') == 'Start wrangler dev')
    yield ('full', ['node', 'scripts/dev-with-callback.mjs', '--port', env['WORKER_PORT']],
           {k: expand(v, variables) for k, v in start['env'].items()},
           {k: expand(v, variables) for k, v in full['env'].items()})
    for step in steps:
        if step.get('uses') != './.github/actions/conformance-worker':
            continue
        args = step['with']
        cmd = ['node_modules/.bin/wrangler', 'dev', '--ip', '127.0.0.1', '--port', env['WORKER_PORT']]
        for name, value in entries(args.get('vars', ''), variables).items():
            if value != '-':
                cmd.extend(['--var', f'{name}:{value}'])
        suite = entries(args['suite-env'], variables)
        suite.update(ATOMS_ONLY=args['checks'], ATOMS_BASE_URL=variables['ATOMS_BASE_URL'])
        yield args['name'], cmd, {}, suite


@contextlib.contextmanager
def process(cmd, cwd, env, log):
    with log.open('wb') as stream:
        child = subprocess.Popen(cmd, cwd=cwd, env=env, stdout=stream,
                                 stderr=subprocess.STDOUT, start_new_session=True)
        try:
            yield child
        finally:
            # Wrangler starts a separate workerd; stop this run's process group.
            with contextlib.suppress(ProcessLookupError):
                os.killpg(child.pid, signal.SIGTERM)
            try:
                child.wait(timeout=10)
            except subprocess.TimeoutExpired:
                with contextlib.suppress(ProcessLookupError):
                    os.killpg(child.pid, signal.SIGKILL)
                child.wait()
            # The parent may exit before a remaining grandchild processes TERM.
            with contextlib.suppress(ProcessLookupError):
                os.killpg(child.pid, signal.SIGKILL)


def execute(worker, results, env, variables, spec):
    name, command, server_env, suite_env = spec
    print(f'Running {name}', flush=True)
    for port in ('WORKER_PORT', 'ATOMS_CALLBACK_PORT'):
        with socket.socket() as probe:
            if probe.connect_ex(('127.0.0.1', int(env[port]))) == 0:
                raise RuntimeError(f'Loopback port {env[port]} is already serving')
    logs = [worker / f'{name}.worker.log', worker / f'{name}.log']
    try:
        with process(command, worker, env | server_env, logs[0]) as server:
            deadline = time.monotonic() + 120
            while time.monotonic() < deadline:
                if server.poll() is not None:
                    raise RuntimeError(f'{name} Worker exited before serving /healthz')
                with contextlib.suppress(OSError, http.client.HTTPException):
                    with contextlib.closing(http.client.HTTPConnection('127.0.0.1', env['WORKER_PORT'], timeout=1)) as conn:
                        conn.request('GET', '/healthz')
                        if conn.getresponse().status == 200:
                            break
                time.sleep(.5)
            else:
                raise RuntimeError(f'{name} Worker readiness timed out')
            with process(['node', 'test/conformance.mjs'], worker, env | suite_env, logs[1]) as suite:
                return suite.wait(timeout=720) == 0
    finally:
        (worker / 'test/.dev-secret.json').unlink(missing_ok=True)
        for log in logs:
            if log.exists():
                data = log.read_bytes()
                for key in ('RUN_SECRET', 'RUN_SECRET_NEXT'):
                    data = data.replace(variables[key].encode(), b'[redacted]')
                (results / log.name).write_bytes(data)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('scaffold', type=Path, help='scaffold directory after npm ci')
    parser.add_argument('results', type=Path, help='new directory for the four suite and Worker logs')
    args = parser.parse_args()
    if not (args.scaffold / 'php/atoms-core').is_dir():
        parser.error('expected an installed runtime scaffold containing php/atoms-core')
    args.results.mkdir(parents=True)
    job = yaml.safe_load((ROOT / '.github/workflows/ci.yml').read_text())['jobs']['worker']
    with tempfile.TemporaryDirectory(prefix='atoms-conformance-') as temp:
        work = Path(temp)
        env = {k: v for k, v in os.environ.items()
               if not k.startswith(('ATOMS_', 'CLOUDFLARE_', 'CF_', 'WRANGLER_'))
               and k not in ('RUN_SECRET', 'RUN_SECRET_NEXT')}
        env.update(job['env'], XDG_CONFIG_HOME=str(work / 'config'))
        with socket.socket() as a, socket.socket() as b:
            a.bind(('127.0.0.1', 0))
            b.bind(('127.0.0.1', 0))
            env.update(WORKER_PORT=str(a.getsockname()[1]), ATOMS_CALLBACK_PORT=str(b.getsockname()[1]))
        variables = env | {'ATOMS_BASE_URL': 'http://127.0.0.1:' + env['WORKER_PORT']}
        for key in ('RUN_SECRET', 'RUN_SECRET_NEXT'):
            variables[key] = base64.b64encode(secrets.token_bytes(32)).decode()
        worker = prepare(args.scaffold, work, env)
        shutil.copyfile(worker / 'release/interpreter.json', args.results / 'interpreter.json')
        passed = True
        for spec in postures(job, env, variables):
            try:
                ok = execute(worker, args.results, env, variables, spec)
            except (OSError, RuntimeError, subprocess.TimeoutExpired) as error:
                print(f'{spec[0]}: {error}', file=sys.stderr)
                ok = False
            print(f'{spec[0]} {"passed" if ok else "failed"}', flush=True)
            passed = passed and ok
        return 0 if passed else 1


if __name__ == '__main__':
    # Unwind active process groups and scratch files on cancellation.
    def stop(signum, frame):
        raise KeyboardInterrupt
    signal.signal(signal.SIGTERM, stop)
    sys.exit(main())
