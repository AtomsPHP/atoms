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
parser.add_argument('--timestamps', action='store_true', help='also require the post-2038 integer ABI')
args = parser.parse_args()
with tempfile.TemporaryDirectory() as temp:
    root = Path(temp)
    for name in ['php.mjs', 'php.wasm']:
        (root / name).symlink_to((args.build / name).resolve(strict=True))
    (root / 'worker.js').symlink_to(RECIPE / 'test/abi.js')
    script = (RECIPE / 'abi.php').read_text()
    if args.timestamps:
        timestamps = (RECIPE / 'test/timestamps.php').read_text().removeprefix('<?php\n')
        script = script.replace('declare(strict_types=1);', timestamps, 1)
    (root / 'guest.php').write_text(script)
    (root / 'proof.capnp').write_text((RECIPE / 'test/proof.capnp').read_text())
    (root / 'sqlite').mkdir()
    subprocess.run([args.workerd.resolve(), 'test', '-d', f'disk={root / "sqlite"}',
                    root / 'proof.capnp'], check=True)
