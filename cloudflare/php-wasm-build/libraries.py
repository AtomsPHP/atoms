"""Fetch or verify the source archives linked into the Worker PHP target."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import subprocess
import tempfile


def library_lock(path=None):
    path = Path(path) if path is not None else Path(__file__).with_name("libraries.lock.json")
    return json.loads(path.read_text())


def verify(path, expected):
    with path.open("rb") as stream:
        digest = hashlib.sha256()
        while chunk := stream.read(1024 * 1024):
            digest.update(chunk)
    if digest.hexdigest() != expected:
        raise ValueError(f"SHA-256 mismatch for {path}: {digest.hexdigest()}")


def fetch(component, dest):
    with tempfile.NamedTemporaryFile(dir=dest.parent, delete=False) as target:
        temp_path = Path(target.name)
    try:
        source = component["source"]
        command = ["curl", "--proto", "=https", "--proto-redir", "=https", "--fail",
                   "--location", "--connect-timeout", "10", "--output", str(temp_path)]
        gnu = "https://ftp.gnu.org/gnu/"
        if source.startswith(gnu):
            fetched = subprocess.run([*command, source])
            if fetched.returncode:
                mirror = "https://mirror.csclub.uwaterloo.ca/gnu/" + source.removeprefix(gnu)
                subprocess.run([*command, "--retry", "3", mirror], check=True)
        else:
            subprocess.run([*command, "--retry", "3", source], check=True)
        verify(temp_path, component["sha256"])
        os.replace(temp_path, dest)
    finally:
        temp_path.unlink(missing_ok=True)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("operation", choices=["fetch", "verify"])
    parser.add_argument("archive_dir", type=Path)
    args = parser.parse_args()
    if args.operation == "fetch":
        args.archive_dir.mkdir(parents=True, exist_ok=True)
    for component in library_lock():
        path = args.archive_dir / component["file"]
        if args.operation == "fetch" and not path.exists():
            fetch(component, path)
        verify(path, component["sha256"])


if __name__ == "__main__":
    main()
