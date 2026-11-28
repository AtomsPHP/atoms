#!/usr/bin/env python3
"""Run the private guest in local workerd with a fresh SQLite database."""
import argparse
import json
from pathlib import Path
import subprocess
import sys
import tempfile

RECIPE = Path(__file__).resolve().parent
parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('build', type=Path)
parser.add_argument('--workerd', type=Path, default=RECIPE.parents[1] / 'worker/node_modules/.bin/workerd')
mode = parser.add_mutually_exclusive_group()
mode.add_argument('--turns', action='store_true', help='verify inline resume, rollback and engine recovery')
mode.add_argument('--timestamps', dest='turns', action='store_false',
                  help='run the ABI, timestamp and extension checks (default)')
parser.set_defaults(turns=False)
mode.add_argument('--inventory', type=Path, metavar='JSON', help='write the full PHP release inventory')
args = parser.parse_args()
with tempfile.TemporaryDirectory() as temp:
    root = Path(temp)
    for name in ['php.mjs', 'php.wasm']:
        (root / name).symlink_to((args.build / name).resolve(strict=True))
    worker = 'turn' if args.turns else 'abi'
    guest = RECIPE / ('test/turn.php' if args.turns else 'abi.php')
    if args.inventory:
        worker = 'inventory'
        guest = RECIPE.parent / 'inspect-guest.php'
    (root / 'worker.js').symlink_to(RECIPE / f'test/{worker}.js')
    for name in ['bridge.js', 'int64.js', 'config.js', 'errors.js']:
        (root / name).symlink_to(RECIPE.parents[1] / 'worker/src' / name)
    (root / 'loader.js').symlink_to(RECIPE / 'loader.js')
    (root / 'guest.php').symlink_to(guest)
    (root / 'timestamps.php').symlink_to(RECIPE / 'test/timestamps.php')
    (root / 'parity.php').symlink_to(RECIPE / 'test/parity.php')
    (root / 'inspect-guest.php').symlink_to(RECIPE.parent / 'inspect-guest.php')
    (root / 'proof.capnp').write_text((RECIPE / 'test/proof.capnp').read_text())
    (root / 'sqlite').mkdir()
    command = [args.workerd.resolve(), 'test', '-d', f'disk={root / "sqlite"}', root / 'proof.capnp']
    if args.inventory:
        result = subprocess.run(command, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True)
        reports = []
        for line in result.stdout.splitlines():
            if line.startswith('ATOMS_INVENTORY='):
                reports.append(json.loads(line.removeprefix('ATOMS_INVENTORY=')))
            else:
                print(line, file=sys.stderr)
        result.check_returncode()
        if len(reports) != 1:
            raise ValueError('Expected one guest inventory')
        args.inventory.write_text(json.dumps(reports[0], indent=2, ensure_ascii=False) + '\n')
    else:
        subprocess.run(command, check=True)
