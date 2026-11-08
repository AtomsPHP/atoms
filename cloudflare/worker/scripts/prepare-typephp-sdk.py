#!/usr/bin/env python3
"""Prepare the measured PHPX 2.9.4 WASI SDK from verified local inputs.

Run manually during toolchain installation; Atoms builds never invoke this.
Requires Python 3.12+, WASI SDK 33, and the archives/source in docs/typephp.md.
"""

import argparse
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import tarfile
import tempfile


RELEASE_SHA256 = "a0358df19d725a703498c22da32066b973095a0c9e1e613dc0d40b6be06c843d"
SOURCE_COMMIT = "f6a2555e14694e8888d2f40ff2838ffb22bc04f2"
# SHA256 of compact JSON [[relative POSIX path, file SHA256], ...], sorted by
# path, for every file under include/, src/misc/, and src/typephp/ at the commit.
SOURCE_SHA256 = "917af5a3de128225efebfb1b5725533d5c790d9049b8557b112061418421daca"
HEADERS = ["phpx_exception_policy.h", "phpx_cast_policy.h", "typephp_sapi.h",
           "typephp_opcode_table.h", "phpx_ext.h"]
SOURCES = ["src/typephp/typephp_attribute.cc", "src/typephp/typephp_call.cc",
           "src/misc/typephp_runtime.cc", "src/typephp/typephp_property.cc",
           "src/typephp/typephp_trait.cc"]


def sha256(file):
    return hashlib.sha256(file.read_bytes()).hexdigest()


def run(command):
    return subprocess.run([str(part) for part in command], check=True,
                          capture_output=True, text=True,
                          env={**os.environ, "SOURCE_DATE_EPOCH": "0"}).stdout.strip()


def verify_source(source):
    rows = []
    for relative in ["include", "src/misc", "src/typephp"]:
        directory = source / relative
        if directory.is_symlink() or not directory.is_dir():
            raise ValueError(f"Missing plain source directory: {directory}")
        for file in directory.rglob("*"):
            if file.is_symlink() or not (file.is_dir() or file.is_file()):
                raise ValueError(f"Non-regular source entry: {file}")
            if file.is_file():
                rows.append([file.relative_to(source).as_posix(), sha256(file)])
    digest = hashlib.sha256(json.dumps(sorted(rows), separators=(",", ":")).encode()).hexdigest()
    if digest != SOURCE_SHA256:
        raise ValueError(f"PHPX source differs from {SOURCE_COMMIT}: {digest}")


def prepare(args):
    source = args.phpx_source.resolve()
    archive = args.release_archive.resolve()
    tools = args.wasi_sdk.resolve() / "bin"
    output = args.output.absolute()
    if os.path.lexists(output):
        raise ValueError(f"Output already exists; choose a new SDK directory: {output}")
    if sha256(archive) != RELEASE_SHA256:
        raise ValueError("PHPX SDK release archive SHA256 mismatch; no files changed")
    verify_source(source)
    versions = {}
    for name in ["wasm32-wasip2-clang++", "llvm-ar", "llvm-nm"]:
        versions[name] = run([tools / name, "--version"])
        if "22.1.0" not in versions[name]:
            raise ValueError(f"WASI SDK 33 (LLVM 22.1.0) required: {name}")
    if run([tools / "wasm32-wasip2-clang++", "-dumpmachine"]) != "wasm32-unknown-wasip2":
        raise ValueError("WASI compiler target must be wasm32-unknown-wasip2")
    output.parent.mkdir(parents=True, exist_ok=True)
    with tempfile.TemporaryDirectory(prefix=".atoms-typephp-sdk-", dir=output.parent) as temporary:
        staging = Path(temporary)
        with tarfile.open(archive) as package:
            package.extractall(staging, filter="data")
        sdk = staging / "phpx-wasi-sdk_v2.9.4_php8.5.9_wasm32-wasip2"
        if (sdk / ".typephp-wasi-sdk-abi").read_text().strip() != "typephp-wasip2-sdk-abi-v4":
            raise ValueError("Unexpected PHPX SDK ABI marker")
        for header in HEADERS:
            shutil.copyfile(source / "include" / header, sdk / "include/phpx" / header)
        includes = [sdk / "include" / name for name in
                    ["php", "php/main", "php/Zend", "php/TSRM", "php/ext/date/lib", "phpx", ""]]
        includes.append(source / "src/misc")
        flags = ["-std=c++17", "-O2", "-fwasm-exceptions", "-mllvm", "-wasm-enable-sjlj",
                 "-mllvm", "-wasm-use-legacy-eh=false", "-Wno-deprecated-literal-operator"]
        flags.extend(f"-I{directory}" for directory in includes)
        objects = []
        for relative in SOURCES:
            obj = staging / (Path(relative).stem + ".o")
            run([tools / "wasm32-wasip2-clang++", *flags, "-c", source / relative, "-o", obj])
            objects.append(obj)
        library = sdk / "lib/libphpx.a"
        run([tools / "llvm-ar", "rD", library, *objects])
        symbols = run([tools / "llvm-nm", "--defined-only", "--demangle", library])
        for symbol in ["typephp_install_reflection_attribute_handlers()", "typephp_runtime_start",
                       "typephp_read_property_cached(", "typephp_write_property_scoped("]:
            if not any(" T " + symbol in line for line in symbols.splitlines()):
                raise ValueError(f"Repaired library is missing {symbol}")
        record = {"phpxCommit": SOURCE_COMMIT, "releaseSha256": RELEASE_SHA256,
                  "sourceSha256": SOURCE_SHA256, "tools": versions,
                  "addedHeaders": HEADERS, "compiledSources": SOURCES,
                  "libphpxSha256": sha256(library)}
        (sdk / "atoms-sdk-repair.json").write_text(json.dumps(record, indent=2) + "\n")
        # Never replace an existing installation, even if another setup finished
        # while compilation was running. An exclusive reservation handles races.
        output.mkdir()
        try:
            os.rename(sdk, output)
        except BaseException:
            output.rmdir()
            raise
    print(f"Prepared PHPX WASI SDK: {output}")


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--phpx-source", required=True, type=Path)
    parser.add_argument("--release-archive", required=True, type=Path)
    parser.add_argument("--wasi-sdk", required=True, type=Path)
    parser.add_argument("--output", required=True, type=Path)
    try:
        prepare(parser.parse_args())
    except (OSError, ValueError, subprocess.CalledProcessError, tarfile.TarError) as error:
        catalog = Path(__file__).resolve().parent.parent / "php/atoms-core/resources/errors.json"
        entry = next(item for item in json.loads(catalog.read_text())["errors"] if item["code"] == "ATOMS-E113")
        reason = f"SDK preparation failed: {error} {getattr(error, 'stderr', '') or ''}".strip()
        parser.exit(1, f"ATOMS-E113: {entry['message'].replace('{reason}', reason)} Fix: {entry['fix']}\n")
