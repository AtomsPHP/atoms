#!/usr/bin/env python3
"""Run the private guest in local workerd with a fresh SQLite database."""
import argparse
from pathlib import Path
import subprocess
import tempfile

RECIPE = Path(__file__).resolve().parent
parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('build', type=Path)
parser.add_argument('--workerd', type=Path, default=RECIPE.parents[1] / 'worker/node_modules/.bin/workerd')
mode = parser.add_mutually_exclusive_group()
mode.add_argument('--turns', action='store_true', help='verify inline resume, rollback and engine recovery')
mode.add_argument('--timestamps', dest='turns', action='store_false',
                  help='run the ABI and timestamp checks (default)')
parser.set_defaults(turns=False)
args = parser.parse_args()
with tempfile.TemporaryDirectory() as temp:
    root = Path(temp)
    for name in ['php.mjs', 'php.wasm']:
        (root / name).symlink_to((args.build / name).resolve(strict=True))
    (root / 'worker.js').symlink_to(RECIPE / ('test/turn.js' if args.turns else 'test/abi.js'))
    for name in ['bridge.js', 'int64.js', 'config.js', 'errors.js']:
        (root / name).symlink_to(RECIPE.parents[1] / 'worker/src' / name)
    (root / 'loader.js').symlink_to(RECIPE / 'loader.js')
    (root / 'guest.php').symlink_to(RECIPE / ('test/turn.php' if args.turns else 'abi.php'))
    (root / 'timestamps.php').symlink_to(RECIPE / 'test/timestamps.php')
    (root / 'proof.capnp').write_text((RECIPE / 'test/proof.capnp').read_text())
    (root / 'sqlite').mkdir()
    subprocess.run([args.workerd.resolve(), 'test', '-d', f'disk={root / "sqlite"}',
                    root / 'proof.capnp'], check=True)
