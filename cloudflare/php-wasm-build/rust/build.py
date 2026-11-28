#!/usr/bin/env python3
"""Fetch verified inputs, or cross-build the private PHP 8.3 embed probe offline."""
import argparse
import ast
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
from urllib.request import urlopen

RECIPE = Path(__file__).resolve().parent
COMPILER = json.loads((RECIPE.parent / 'compiler.lock.json').read_text())
TOOLS = json.loads((RECIPE / 'tools.lock.json').read_text())
INPUTS = [entry for entry in COMPILER['downloads']
          if entry['file'] == 'php.tar.gz' or entry['file'].startswith('debs/')] + TOOLS['downloads']


def verify(path, sha):
    with path.open('rb') as stream:
        if hashlib.file_digest(stream, 'sha256').hexdigest() != sha:
            raise ValueError(f'SHA-256 mismatch for {path}')


def fetch(cache):
    for item in INPUTS:
        path = cache / item['file']
        path.parent.mkdir(parents=True, exist_ok=True)
        if not path.exists():
            with tempfile.NamedTemporaryFile(dir=path.parent, delete=False) as temp:
                try:
                    with urlopen(item['source'], timeout=120) as response:
                        shutil.copyfileobj(response, temp)
                    temp.flush()
                    verify(Path(temp.name), item['sha256'])
                    os.replace(temp.name, path)
                finally:
                    Path(temp.name).unlink(missing_ok=True)
        verify(path, item['sha256'])


def run(*args, **kwargs):
    subprocess.run(args, check=True, **kwargs)


def unpack(path, dest):
    dest.mkdir()
    run('tar', '-xf', path, '--no-same-owner', '--strip-components=1', '-C', dest)


def build(cache, work, libclang, jobs):
    for item in INPUTS:
        verify(cache / item['file'], item['sha256'])
    work.mkdir(parents=True, exist_ok=False)
    tools = work / 'tools'
    tools.mkdir()
    for item in TOOLS['downloads']:
        path = cache / item['file']
        if path.suffix == '.deb':
            run('dpkg-deb', '-x', path, tools / 'bindgen')
        else:
            src = tools / path.name.removesuffix('.tar.xz')
            unpack(path, src)
            run(src / 'install.sh', f'--prefix={tools / "rust"}', '--disable-ldconfig')

    if libclang is None:
        libclang = tools / 'bindgen/usr/lib/x86_64-linux-gnu/libclang-21.so.1'
    verify(libclang, TOOLS['libclang']['sha256'])

    context = work / 'compiler-context'
    (context / 'debs').mkdir(parents=True)
    for item in INPUTS:
        if item['file'].startswith('debs/'):
            shutil.copyfile(cache / item['file'], context / item['file'])
    image_id = work / 'image-id'
    run('docker', 'build', '--network=none', '--platform', COMPILER['platform'],
        '--iidfile', image_id, '--target', 'tools', '--build-arg', f'SDK_IMAGE={COMPILER["builder"]}',
        '--build-arg', f'SOURCE_DATE_EPOCH={COMPILER["sourceDateEpoch"]}',
        '-f', RECIPE.parent / 'builder.Dockerfile', context)
    container = ['docker', 'run', '--rm', '--network=none', '--cap-drop=ALL',
                 '--security-opt=no-new-privileges', '--user', f'{os.getuid()}:{os.getgid()}',
                 '-v', f'{work}:/build', '-v', f'{RECIPE}:/recipe:ro', '-w', '/build/php',
                 '-e', f'BUILD_JOBS={jobs}', image_id.read_text().strip()]
    unpack(cache / 'php.tar.gz', work / 'php')
    run(*container, 'bash', '/recipe/configure.sh')
    run(*container, 'bash', '-ec', 'mkdir /build/headers; '
        'cp -a "$(em-config CACHE)/sysroot/include" /build/headers/sysroot; '
        'cp -a /emsdk/upstream/lib/clang/*/include /build/headers/builtin')

    env = dict(os.environ, LIBCLANG_PATH=str(libclang), ATOMS_PHP_BINDINGS=str(work / 'bindings.rs'),
               CARGO_HOME=str(tools / 'cargo'), CARGO_TARGET_DIR=str(work / 'target'),
               PATH=f'{tools}/rust/bin:{tools}/bindgen/usr/bin:{os.environ["PATH"]}')
    run(RECIPE / 'bindings.sh', work / 'php', work / 'headers/builtin', work / 'headers/sysroot',
        env['ATOMS_PHP_BINDINGS'], env=env)
    # The pinned embed is a release NTS build. Ask its own preprocessor for
    # the concatenated build ID that Zend places in the static module table.
    command = ['emcc', '-E', '-P', '-x', 'c', '-include', 'sapi/embed/php_embed.h',
               '-I.', '-Imain', '-IZend', '-ITSRM', '-DZEND_ENABLE_ZVAL_LONG64=1', '-']
    expanded = subprocess.check_output(container[:2] + ['-i'] + container[2:] + command,
                                       input='ZEND_MODULE_BUILD_ID\n', text=True)
    env['ATOMS_PHP_BUILD_ID'] = ast.literal_eval(expanded.strip().splitlines()[-1])
    run('cargo', 'build', '--release', '--locked', '--offline', '--target', 'wasm32-unknown-emscripten',
        '--manifest-path', RECIPE / 'bridge/Cargo.toml', env=env)
    run(*container, 'bash', '/recipe/link.sh', '/build/php/libs/libphp.a',
        '/build/target/wasm32-unknown-emscripten/release/libatoms_php_bridge.a', '/build/php.mjs')


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    cmds = parser.add_subparsers(dest='op', required=True)
    cmds.add_parser('fetch').add_argument('cache', type=Path)
    compile_cmd = cmds.add_parser('build')
    compile_cmd.add_argument('cache', type=Path)
    compile_cmd.add_argument('work', type=Path, help='a new directory for this build')
    compile_cmd.add_argument('--libclang', type=lambda value: Path(value).resolve(),
                             help='use a local copy of the same verified libclang')
    compile_cmd.add_argument('--jobs', type=int, default=1)
    args = parser.parse_args()
    if args.op == 'fetch':
        fetch(args.cache)
    else:
        build(args.cache.resolve(), args.work.resolve(), args.libclang, args.jobs)
