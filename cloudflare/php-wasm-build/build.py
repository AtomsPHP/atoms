#!/usr/bin/env python3
"""Fetch verified inputs or build the selected Atoms PHP interpreter offline."""
import argparse
from dataclasses import dataclass
import hashlib
import json
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile

from libraries import fetch, library_lock, verify

RECIPE = Path(__file__).resolve().parent
COMPILER = json.loads((RECIPE / "compiler.lock.json").read_text())
PHP = json.loads((RECIPE / "rust/php-sources.json").read_text())
TOOLS = json.loads((RECIPE / "rust/tools.lock.json").read_text())
MINORS = tuple(PHP)
RECIPE_FILES = (
    "build.py", "builder.Dockerfile", "build-libraries.sh", "compiler.lock.json", "libraries.py",
    "libraries.lock.json", "rust/build.py", "rust/configure.sh", "rust/bindings.sh", "rust/link.sh",
    "rust/extensions.mk", "rust/bridge-imports.js", "rust/pdo-imports.js", "rust/php.ini",
    "rust/prepend.php", "rust/message.php", "rust/tools.lock.json", "rust/php-sources.json",
    "rust/timelib-long64.patch", "rust/opcache-file-cache.patch", "rust/opcache-file-cache-84.patch",
    "rust/bridge/Cargo.toml", "rust/bridge/Cargo.lock",
    *(p.relative_to(RECIPE).as_posix() for p in sorted((RECIPE / "rust/bridge/src").rglob("*.rs"))),
)


@dataclass(frozen=True)
class Target:
    minor: str
    version: str
    commit: str
    php_source: dict

    @property
    def stem(self):
        return "php_" + self.minor.replace(".", "_")

    @property
    def files(self):
        return self.stem + ".js", self.stem + ".wasm"


def target(minor="8.3"):
    php = PHP[minor]
    source = {"file": f"php-{php['version']}.tar.gz", "sha256": php["sha256"],
              "source": f"https://codeload.github.com/php/php-src/tar.gz/{php['commit']}"}
    return Target(minor, php["version"], php["commit"], source)


def source_inputs(selected):
    return [selected.php_source, *(s for s in COMPILER["downloads"] if not s["file"].startswith("debs/")),
            *(dict(lib, file="libraries/" + lib["file"]) for lib in library_lock())]


def tool_inputs():
    return [*TOOLS["downloads"], *(s for s in COMPILER["downloads"] if s["file"].startswith("debs/"))]


def locked_files(inputs, selected, download=False):
    for item in [*source_inputs(selected), *tool_inputs()]:
        path = inputs / item["file"]
        if download and not path.exists():
            path.parent.mkdir(parents=True, exist_ok=True)
            fetch(item, path)
        verify(path, item["sha256"])


def digest(path):
    with path.open("rb") as stream:
        return hashlib.file_digest(stream, "sha256").hexdigest()


def build(inputs, output, selected, jobs):
    if output.exists() and (not output.is_dir() or any(output.iterdir())):
        raise ValueError(f"Build output must be empty: {output}")
    output.parent.mkdir(parents=True, exist_ok=True)
    # Snapshot first, then verify the inputs and compile using the same locks
    # that will be recorded. An edit to the checkout cannot split this build.
    with tempfile.TemporaryDirectory(dir=output.parent) as temp:
        scratch = Path(temp)
        snapshot = scratch / "recipe"
        for name in RECIPE_FILES:
            dest = snapshot / name
            dest.parent.mkdir(parents=True, exist_ok=True)
            shutil.copy2(RECIPE / name, dest)
        subprocess.run([sys.executable, "-B", snapshot / "build.py", "_compile", "--php", selected.minor,
                        "--jobs", str(jobs), inputs, scratch / "raw"], check=True)
        raw = scratch / "raw"
        shipped = scratch / "shipped"
        shipped.mkdir()
        for name in selected.files:
            source = selected.stem + ".mjs" if name.endswith(".js") else name
            (raw / source).rename(shipped / name)
        metadata = {
            "schema": 1, "target": selected.minor, "php": selected.version,
            "recipe": {name: digest(snapshot / name) for name in RECIPE_FILES},
            "bindings": {name: digest(raw / name) for name in ("bindings.rs", "crypto-bindings.rs")},
            "zendBuildId": (raw / "build-id.txt").read_text().strip(),
            "outputs": {name: digest(shipped / name) for name in selected.files},
        }
        (shipped / "build-metadata.json").write_text(json.dumps(metadata, indent=2, sort_keys=True) + "\n")
        shipped.rename(output)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("operation", choices=["fetch", "verify", "build", "_compile"])
    parser.add_argument("--php", choices=MINORS, default="8.3")
    parser.add_argument("--jobs", type=int, default=4)
    parser.add_argument("inputs", type=Path)
    parser.add_argument("output", type=Path, nargs="?")
    args = parser.parse_args()
    inputs = args.inputs.resolve()
    selected = target(args.php)
    if args.jobs < 1:
        parser.error("--jobs must be positive")
    if (args.operation in ("build", "_compile")) != (args.output is not None):
        parser.error("build requires an output directory")
    if args.operation == "build":
        build(inputs, args.output.resolve(), selected, args.jobs)
    else:
        if args.operation == "fetch":
            subprocess.run(["docker", "pull", "--platform", COMPILER["platform"], COMPILER["builder"]], check=True)
        locked_files(inputs, selected, args.operation == "fetch")
        if args.operation == "_compile":
            from rust.build import build as compile_embed
            compile_embed(inputs, args.output.resolve(), None, args.jobs, selected.minor, selected.stem)


if __name__ == "__main__":
    main()
