#!/usr/bin/env python3
"""Build the single PHP 8.3 Worker target with a private, offline compiler cache."""
import argparse
import hashlib
import json
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile

from libraries import fetch, library_lock, verify

RECIPE = Path(__file__).resolve().parent
LOCK = json.loads((RECIPE / "compiler.lock.json").read_text())
# These are the complete Atoms-authored inputs to the two delivered files.
BUILD_RECIPE_FILES = (
    "build.py", "builder.Dockerfile", "compiler.lock.json", "worker-build.patch",
    "openssl-legacy-provider.patch", "extension-abi.mjs", "build-libraries.sh",
    "libraries.py", "libraries.lock.json",
)
COMPILE = "packages/php-wasm/compile"
PLAYGROUND_SOURCE = [
    "LICENSE", *(f"{COMPILE}/{path}" for path in (
        "base-image", "build.js", "opcache", "php", "php-post-message-to-js",
        "php-wasm-dns-polyfill", "php-wasm-memory-storage", "shared/intl/exported-functions.list",
    )),
]


def run(*args, **kwargs):
    subprocess.run(args, check=True, **kwargs)


def fetch_playground(inputs):
    # Use a source-only archive. The optional intl side module is read once for
    # its ABI, matching the upstream link step. Its machine code is discarded.
    with tempfile.TemporaryDirectory(dir=inputs.parent) as temp:
        checkout = Path(temp)
        commit = LOCK["playgroundCommit"]
        run("git", "init", "--quiet", checkout)
        run("git", "-C", checkout, "remote", "add", "origin", "https://github.com/WordPress/wordpress-playground.git")
        run("git", "-C", checkout, "config", "remote.origin.promisor", "true")
        run("git", "-C", checkout, "config", "remote.origin.partialclonefilter", "blob:none")
        run("git", "-C", checkout, "fetch", "--quiet", "--filter=blob:none", "--depth=1", "origin", commit)
        run("git", "-C", checkout, "archive", "--format=tar", f"--output={inputs / 'playground.tar'}",
            commit, *PLAYGROUND_SOURCE)
        module = checkout / "reference-intl.so"
        with module.open("wb") as stream:
            run("git", "-C", checkout, "show",
                f"{commit}:packages/php-wasm/web-builds/8-3/asyncify/extensions/intl/intl.so", stdout=stream)
        run("node", RECIPE / "extension-abi.mjs", module, inputs)


def locked_files(inputs, download=False):
    for source in [*LOCK["downloads"], *(dict(c, file="libraries/" + c["file"]) for c in library_lock())]:
        path = inputs / source["file"]
        if download and not path.exists():
            path.parent.mkdir(parents=True, exist_ok=True)
            fetch(source, path)
        verify(path, source["sha256"])
    if download and any(not (inputs / f).is_file() for f in LOCK["playgroundFiles"]):
        fetch_playground(inputs)
    for file, sha in LOCK["playgroundFiles"].items():
        verify(inputs / file, sha)


def build(inputs, output):
    # A long compile may outlive edits in the maintainer checkout. Build from
    # a private snapshot so every hash describes the code and locks executed.
    with tempfile.TemporaryDirectory(dir=inputs.parent) as temp:
        snapshot = Path(temp)
        for file in BUILD_RECIPE_FILES:
            shutil.copyfile(RECIPE / file, snapshot / file)
        run(sys.executable, "-B", snapshot / "build.py", "--compile-snapshot", inputs, output)


def digest(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()


def compile_snapshot(inputs, output):
    locked_files(inputs)
    output.parent.mkdir(parents=True, exist_ok=True)
    if output.exists() and (not output.is_dir() or any(output.iterdir())):
        raise ValueError(f"Build output must be empty: {output}")
    with tempfile.TemporaryDirectory(dir=inputs.parent) as temp, tempfile.TemporaryDirectory(dir=output.parent) as staging:
        context = Path(temp)
        shipped = Path(staging) / "shipped"
        # Stage only named files. Passing the containing checkout as a Docker
        # context would include unrelated project configuration and artifacts.
        run("tar", "-xf", inputs / "playground.tar", "--strip-components=2", "-C", context, COMPILE)
        for path in ["recipe", "libraries", "debs"]:
            (context / path).mkdir()
        for file in ["build-libraries.sh", "libraries.py", "libraries.lock.json", "openssl-legacy-provider.patch"]:
            shutil.copyfile(RECIPE / file, context / "recipe" / file)
        files = ["php.tar.gz", "js-exports.txt", "wasm-exports.txt"]
        files.extend(c["file"] for c in LOCK["downloads"] if c["file"].startswith("debs/"))
        files.extend("libraries/" + c["file"] for c in library_lock())
        for file in files:
            shutil.copyfile(inputs / file, context / file)
        run("patch", "--batch", "--fuzz=0", "-p0", "-i", RECIPE / "worker-build.patch", cwd=context / "compile")
        (context / "Dockerfile").write_bytes(
            (RECIPE / "builder.Dockerfile").read_bytes() + (context / "compile/php/Dockerfile").read_bytes())
        enabled = ("CLI_SAPI", "LIBZIP", "SQLITE", "CURL", "FILEINFO", "ICONV", "LIBXML", "SOAP",
                   "EXIF", "GD", "MBSTRING", "MBREGEX", "OPENSSL", "WS_NETWORKING_PROXY", "OPCACHE")
        args = [f"--build-arg=WITH_{name}=yes" for name in enabled]
        run("docker", "buildx", "build", "--no-cache", "--network=none", "--progress=plain",
            "--platform", LOCK["platform"], "--build-arg", f"SDK_IMAGE={LOCK['builder']}",
            "--build-arg", f"SOURCE_DATE_EPOCH={LOCK['sourceDateEpoch']}",
            "--build-arg", f"PHP_VERSION={LOCK['php']}", "--build-arg", "STACK_SIZE=1MB",
            "--build-arg", "WITH_JSPI=no", "--build-arg", "WITH_MYSQL=no", "--build-arg", "WITH_IMAGICK=no",
            "--build-arg", "WITH_SOURCEMAPS=no", "--build-arg", "WITH_DEBUG=no",
            "--build-arg", "OUTPUT_DIR_ON_HOST=/build/output", "--build-arg", "DEBUG_DWARF_COMPILATION_DIR=/root",
            *args, "--output", f"type=local,dest={shipped}", context)
        metadata = {
            "schema": 1,
            "recipe": {file: digest(RECIPE / file) for file in BUILD_RECIPE_FILES},
            "dockerfileSha256": digest(context / "Dockerfile"),
            "outputs": {file: digest(shipped / file) for file in ("php_8_3.js", "php_8_3.wasm")},
        }
        (shipped / "build-metadata.json").write_text(json.dumps(metadata, sort_keys=True, indent=2) + "\n")
        shipped.rename(output)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    cmd = parser.add_subparsers(dest="operation", required=True)
    for name in ("fetch", "verify", "build"):
        p = cmd.add_parser(name)
        p.add_argument("inputs", type=Path)
        if name == "build":
            p.add_argument("output", type=Path)
    args = parser.parse_args()
    inputs = args.inputs.resolve()
    if args.operation == "build":
        build(inputs, args.output.resolve())
    else:
        if args.operation == "fetch":
            inputs.mkdir(parents=True, exist_ok=True)
            run("docker", "pull", "--platform", LOCK["platform"], LOCK["builder"])
        locked_files(inputs, download=args.operation == "fetch")


if __name__ == "__main__":
    if len(sys.argv) == 4 and sys.argv[1] == "--compile-snapshot":
        compile_snapshot(Path(sys.argv[2]), Path(sys.argv[3]))
    else:
        main()
