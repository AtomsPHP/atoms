#!/usr/bin/env python3
"""Fetch verified inputs, or cross-build the selected PHP embed offline."""
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
LIBRARIES = json.loads((RECIPE.parent / 'libraries.lock.json').read_text())
PHP = json.loads((RECIPE / 'php-sources.json').read_text())
COMMON_INPUTS = [item for item in COMPILER['downloads'] if item['file'].startswith('debs/')]
COMMON_INPUTS += TOOLS['downloads']
COMMON_INPUTS += [dict(item, file='libraries/' + item['file']) for item in LIBRARIES]


def php_input(minor):
    target = PHP[minor]
    return {'file': f'php-{target["version"]}.tar.gz',
            'source': f'https://codeload.github.com/php/php-src/tar.gz/{target["commit"]}',
            'sha256': target['sha256']}


def verify(path, sha):
    with path.open('rb') as stream:
        if hashlib.file_digest(stream, 'sha256').hexdigest() != sha:
            raise ValueError(f'SHA-256 mismatch for {path}')


def fetch(cache, minor):
    for item in [php_input(minor), *COMMON_INPUTS]:
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


def build(cache, work, libclang, jobs, minor):
    source = php_input(minor)
    for item in [source, *COMMON_INPUTS]:
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
    (context / 'libraries').mkdir()
    (context / 'recipe').mkdir()
    for name in ['build-libraries.sh', 'libraries.py', 'libraries.lock.json']:
        shutil.copy2(RECIPE.parent / name, context / 'recipe' / name)
    for item in COMMON_INPUTS:
        if item['file'].startswith(('debs/', 'libraries/')):
            shutil.copyfile(cache / item['file'], context / item['file'])
    image_id = work / 'image-id'
    run('docker', 'build', '--network=none', '--platform', COMPILER['platform'],
        '--iidfile', image_id, '--target', 'libraries',
        '--build-arg', f'LIBRARY_JOBS={jobs}', '--build-arg', f'SDK_IMAGE={COMPILER["builder"]}',
        '--build-arg', f'SOURCE_DATE_EPOCH={COMPILER["sourceDateEpoch"]}',
        '-f', RECIPE.parent / 'builder.Dockerfile', context)
    container = ['docker', 'run', '--rm', '--network=none', '--cap-drop=ALL',
                 '--security-opt=no-new-privileges', '--user', f'{os.getuid()}:{os.getgid()}',
                 '-v', f'{work}:/build', '-v', f'{RECIPE}:/recipe:ro', '-w', '/build/php',
                 '-e', f'BUILD_JOBS={jobs}', '-e', f'PHP_SERIES={minor}', image_id.read_text().strip()]
    unpack(cache / source['file'], work / 'php')
    run(*container, 'bash', '/recipe/configure.sh')
    version = PHP[minor]['version']
    header = (work / 'php/main/php_version.h').read_text().splitlines()
    if f'#define PHP_VERSION "{version}"' not in header:
        raise ValueError(f'Configured headers do not match the selected PHP {version}')
    run(*container, 'bash', '-ec', 'mkdir /build/headers; '
        'cp -a /libs/include /build/headers/libs; '
        'cp -a /emsdk/upstream/emscripten/cache/sysroot/include /build/headers/sysroot; '
        'cp -a /emsdk/upstream/lib/clang/*/include /build/headers/builtin')

    env = dict(os.environ, LIBCLANG_PATH=str(libclang), ATOMS_PHP_BINDINGS=str(work / 'bindings.rs'),
               ATOMS_CRYPTO_BINDINGS=str(work / 'crypto-bindings.rs'),
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
    features = [] if minor == '8.3' else ['--features', 'zend-bool']
    run('cargo', 'build', '--release', '--locked', '--offline', '--target', 'wasm32-unknown-emscripten',
        *features, '--manifest-path', RECIPE / 'bridge/Cargo.toml', env=env)
    run(*container, 'bash', '/recipe/link.sh', '/build/php/libs/libphp.a',
        '/build/target/wasm32-unknown-emscripten/release/libatoms_php_bridge.a',
        '/build/php/.libs/libatoms-opcache.a', '/build/php.mjs')


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    cmds = parser.add_subparsers(dest='op', required=True)
    fetch_cmd = cmds.add_parser('fetch')
    fetch_cmd.add_argument('cache', type=Path)
    compile_cmd = cmds.add_parser('build')
    compile_cmd.add_argument('cache', type=Path)
    compile_cmd.add_argument('work', type=Path, help='a new directory for this build')
    compile_cmd.add_argument('--libclang', type=lambda value: Path(value).resolve(),
                             help='use a local copy of the same verified libclang')
    compile_cmd.add_argument('--jobs', type=int, default=1)
    for command in [fetch_cmd, compile_cmd]:
        command.add_argument('--php', choices=PHP, default='8.3', help='guest PHP series (default: 8.3)')
    args = parser.parse_args()
    if args.op == 'fetch':
        fetch(args.cache, args.php)
    else:
        build(args.cache.resolve(), args.work.resolve(), args.libclang, args.jobs, args.php)
