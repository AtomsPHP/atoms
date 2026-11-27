#!/usr/bin/env python3
"""Build a pinned PHP Worker target with a private, offline compiler cache."""
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

MINORS = ("8.3", "8.4", "8.5", "8.6")


@dataclass(frozen=True)
class Target:
    minor: str
    lock: dict
    worker_patches: tuple
    crypto_patch: str
    libraries_lock: str
    recipe_files: tuple

    @property
    def files(self):
        prefix = "php_" + self.minor.replace(".", "_")
        return (prefix + ".js", prefix + ".wasm")


def target(minor="8.3"):
    if minor not in MINORS:
        raise ValueError(f"Unsupported PHP Worker target: {minor}")
    if minor == "8.3":
        return Target(minor, LOCK, ("worker-build.patch",), "openssl-legacy-provider.patch",
                      "libraries.lock.json", BUILD_RECIPE_FILES)
    name = f"targets/{minor}/compiler.json"
    config = json.loads((RECIPE / name).read_text())
    commit = config["phpCommit"]
    lock = {**LOCK, **{k: config[k] for k in ("php", "phpCommit", "playgroundCommit", "playgroundFiles")}}
    lock["downloads"] = [
        {"file": "php.tar.gz", "source": f"https://codeload.github.com/php/php-src/tar.gz/{commit}",
         "sha256": config["phpSourceSha256"]} if source["file"] == "php.tar.gz" else source
        for source in LOCK["downloads"]
    ]
    patches = tuple(config["workerPatches"])
    crypto = config["cryptoPatch"]
    libraries = config["librariesLock"]
    files = ("build.py", "builder.Dockerfile", "compiler.lock.json", name, "extension-abi.mjs",
             "build-libraries.sh", "libraries.py", libraries, *patches, crypto, "targets/openssl-shake.h")
    return Target(minor, lock, patches, crypto, libraries, files)


def run(*args, **kwargs):
    subprocess.run(args, check=True, **kwargs)


def fetch_playground(inputs, selected):
    # Use a source-only archive. The optional intl side module is read once for
    # its ABI, matching the upstream link step. Its machine code is discarded.
    with tempfile.TemporaryDirectory(dir=inputs.parent) as temp:
        checkout = Path(temp)
        commit = selected.lock["playgroundCommit"]
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
                f"{commit}:packages/php-wasm/web-builds/{selected.minor.replace('.', '-')}/asyncify/extensions/intl/intl.so",
                stdout=stream)
        run("node", RECIPE / "extension-abi.mjs", module, inputs)


def locked_files(inputs, download=False, *, target=target()):
    lock = target.lock
    libs = library_lock(RECIPE / target.libraries_lock)
    for source in [*lock["downloads"], *(dict(c, file="libraries/" + c["file"]) for c in libs)]:
        path = inputs / source["file"]
        if download and not path.exists():
            path.parent.mkdir(parents=True, exist_ok=True)
            fetch(source, path)
        verify(path, source["sha256"])
    if download and any(not (inputs / f).is_file() for f in lock["playgroundFiles"]):
        fetch_playground(inputs, target)
    for file, sha in lock["playgroundFiles"].items():
        verify(inputs / file, sha)


def build(inputs, output, selected):
    # A long compile may outlive edits in the maintainer checkout. Build from
    # a private snapshot so every hash describes the code and locks executed.
    with tempfile.TemporaryDirectory(dir=inputs.parent) as temp:
        snapshot = Path(temp)
        for file in selected.recipe_files:
            (snapshot / file).parent.mkdir(parents=True, exist_ok=True)
            shutil.copyfile(RECIPE / file, snapshot / file)
        run(sys.executable, "-B", snapshot / "build.py", "--compile-snapshot", selected.minor, inputs, output)


def digest(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()


def compile_snapshot(inputs, output, selected):
    locked_files(inputs, target=selected)
    lock = selected.lock
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
        for file in ["build-libraries.sh", "libraries.py"]:
            shutil.copyfile(RECIPE / file, context / "recipe" / file)
        shutil.copyfile(RECIPE / selected.libraries_lock, context / "recipe/libraries.lock.json")
        shutil.copyfile(RECIPE / selected.crypto_patch, context / "recipe/openssl-legacy-provider.patch")
        files = ["php.tar.gz", "js-exports.txt", "wasm-exports.txt"]
        files.extend(c["file"] for c in lock["downloads"] if c["file"].startswith("debs/"))
        files.extend("libraries/" + c["file"] for c in library_lock(RECIPE / selected.libraries_lock))
        for file in files:
            shutil.copyfile(inputs / file, context / file)
        for file in selected.worker_patches:
            run("patch", "--batch", "--fuzz=0", "-p0", "-i", RECIPE / file, cwd=context / "compile")
        builder = (RECIPE / "builder.Dockerfile").read_bytes()
        if selected.minor != "8.3":
            shutil.copyfile(RECIPE / "targets/openssl-shake.h", context / "recipe/atoms-shake.h")
            builder += b"COPY recipe/atoms-shake.h /root/php-src/ext/openssl/atoms-shake.h\n"
        (context / "Dockerfile").write_bytes(builder + (context / "compile/php/Dockerfile").read_bytes())
        enabled = ("CLI_SAPI", "LIBZIP", "SQLITE", "CURL", "FILEINFO", "ICONV", "LIBXML", "SOAP",
                   "EXIF", "GD", "MBSTRING", "MBREGEX", "OPENSSL", "WS_NETWORKING_PROXY", "OPCACHE")
        args = [f"--build-arg=WITH_{name}=yes" for name in enabled]
        run("docker", "buildx", "build", "--no-cache", "--network=none", "--progress=plain",
            "--platform", lock["platform"], "--build-arg", f"SDK_IMAGE={lock['builder']}",
            "--build-arg", f"SOURCE_DATE_EPOCH={lock['sourceDateEpoch']}",
            "--build-arg", f"PHP_VERSION={lock['php']}", "--build-arg", "STACK_SIZE=1MB",
            "--build-arg", "WITH_JSPI=no", "--build-arg", "WITH_MYSQL=no", "--build-arg", "WITH_IMAGICK=no",
            "--build-arg", "WITH_SOURCEMAPS=no", "--build-arg", "WITH_DEBUG=no",
            "--build-arg", "OUTPUT_DIR_ON_HOST=/build/output", "--build-arg", "DEBUG_DWARF_COMPILATION_DIR=/root",
            *args, "--output", f"type=local,dest={shipped}", context)
        metadata = {
            "schema": 1,
            "target": selected.minor,
            "php": lock["php"],
            "recipe": {file: digest(RECIPE / file) for file in selected.recipe_files},
            "dockerfileSha256": digest(context / "Dockerfile"),
            "outputs": {file: digest(shipped / file) for file in selected.files},
        }
        (shipped / "build-metadata.json").write_text(json.dumps(metadata, sort_keys=True, indent=2) + "\n")
        shipped.rename(output)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    cmd = parser.add_subparsers(dest="operation", required=True)
    for name in ("fetch", "verify", "build"):
        p = cmd.add_parser(name)
        p.add_argument("--php", choices=MINORS, default="8.3")
        p.add_argument("inputs", type=Path)
        if name == "build":
            p.add_argument("output", type=Path)
    args = parser.parse_args()
    inputs = args.inputs.resolve()
    selected = target(args.php)
    if args.operation == "build":
        build(inputs, args.output.resolve(), selected)
    else:
        if args.operation == "fetch":
            inputs.mkdir(parents=True, exist_ok=True)
            run("docker", "pull", "--platform", selected.lock["platform"], selected.lock["builder"])
        locked_files(inputs, download=args.operation == "fetch", target=selected)


if __name__ == "__main__":
    if len(sys.argv) == 5 and sys.argv[1] == "--compile-snapshot":
        compile_snapshot(Path(sys.argv[3]), Path(sys.argv[4]), target(sys.argv[2]))
    else:
        main()
