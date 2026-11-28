#!/usr/bin/env python3
"""Collect the exact offline source inputs, notices and release recipe."""
import argparse
import gzip
import hashlib
import json
from pathlib import Path
import tarfile
from tempfile import TemporaryDirectory

from build import COMPILER, MINORS, RECIPE, RECIPE_FILES, TOOLS, locked_files, source_inputs, target
from libraries import library_lock

SOURCE_RELEASE_FILES = ("source-bundle.py", "pack.mjs", "README.md")


def digest_stream(stream):
    h = hashlib.sha256()
    while chunk := stream.read(1024 * 1024):
        h.update(chunk)
    return h.hexdigest()


def digest(path):
    with path.open("rb") as stream:
        return digest_stream(stream)


def read_notices(archive, names, strip_root=True):
    """Load the pinned upstream notices in one sequential archive pass."""
    result = {}
    with tarfile.open(archive, "r|*") as source:
        for entry in source:
            name = entry.name.partition("/")[2] if strip_root else entry.name
            if name not in names or not entry.isfile():
                continue
            raw = source.extractfile(entry).read().decode("utf-8", "replace")
            if name.endswith((".c", ".h")):
                # These projects keep the license in the source header.
                marker = "*/"
                if name.endswith("pcre2_compile.c"):
                    start = raw.index("/* PCRE")
                    raw = raw[start:]
                if name.endswith("crypt_freesec.c"):
                    start = raw.index("/*\n * FreeSec: libcrypt")
                    raw = raw[start:]
                raw = raw[:raw.index(marker) + len(marker)]
            result[name] = raw
    if missing := set(names) - set(result):
        raise FileNotFoundError(f"Missing notices in {archive.name}: {sorted(missing)}")
    return "\n".join(f"\n{archive.name} / {name}\n\n{result[name]}" for name in names)


def add_file(archive, name, path):
    # Hash the same bytes that tar receives. A concurrent recipe edit cannot
    # leave components.json describing an older copy of a packaged file.
    sha = hashlib.sha256()
    with path.open("rb") as stream:
        class Reader:
            def read(self, size):
                data = stream.read(size)
                sha.update(data)
                return data

        info = tarfile.TarInfo(name)
        info.size = path.stat().st_size
        info.mode = 0o755 if path.suffix == ".sh" else 0o644
        archive.addfile(info, Reader())
    return sha.hexdigest()


def build_record(output, requested_minor=None):
    metadata_path = output / "build-metadata.json"
    metadata = json.loads(metadata_path.read_bytes())
    selected = target(metadata["target"])
    if requested_minor is not None and requested_minor != selected.minor:
        raise ValueError(f"Requested PHP {requested_minor}, found {selected.minor}")
    if (metadata.get("php") != selected.version or metadata.get("schema") != 1
            or set(metadata.get("recipe", {})) != set(RECIPE_FILES)
            or set(metadata.get("outputs", {})) != set(selected.files)
            or set(metadata.get("bindings", {})) != {"bindings.rs", "crypto-bindings.rs"}
            or not metadata.get("zendBuildId")):
        raise ValueError("Incomplete interpreter build record")
    for name, sha in metadata["outputs"].items():
        if digest(output / name) != sha:
            raise ValueError(f"Output differs from the compiled file: {name}")
    for name, sha in metadata["recipe"].items():
        if digest(RECIPE / name) != sha:
            raise ValueError(f"Recipe changed after compilation: {name}")
    return selected, metadata


def expected_sources(selected, metadata):
    pins = {"inputs/" + s["file"]: s["sha256"] for s in source_inputs(selected)}
    pins.update({"php-wasm-build/" + file: sha for file, sha in metadata["recipe"].items()})
    pins.update({"php-wasm-build/" + file: digest(RECIPE / file) for file in SOURCE_RELEASE_FILES})
    pins["LICENSE-MIT"] = digest(RECIPE.parent / "LICENSE-MIT")
    return pins


def verify_bundle(output, minor=None):
    selected, metadata = build_record(output, minor)
    inventory = json.loads((output / "components.json").read_bytes())
    if (inventory["php"]["version"] != selected.version or inventory["php"]["commit"] != selected.commit
            or inventory["libraries"] != library_lock() or inventory["build"] != metadata
            or inventory["rustTools"] != TOOLS or inventory["builder"] != COMPILER["builder"]):
        raise ValueError("Component inventory differs from the selected compiler or source locks")
    pins = expected_sources(selected, metadata)
    pins["build-metadata.json"] = digest(output / "build-metadata.json")
    if inventory["sourceFiles"] != pins:
        raise ValueError("Source inventory differs from the verified inputs")
    expected = dict(pins)
    for name in ("components.json", "THIRD_PARTY_NOTICES.txt"):
        expected[name] = digest(output / name)

    with gzip.open(output / "corresponding-source.tar.gz", "rb") as compressed:
        with tarfile.open(fileobj=compressed, mode="r|") as source:
            for member in source:
                if not member.isfile() or member.name not in expected:
                    raise ValueError(f"Unexpected or repeated source archive member: {member.name}")
                if digest_stream(source.extractfile(member)) != expected.pop(member.name):
                    raise ValueError(f"Source archive contents differ for {member.name}")
        # tar ends before gzip's CRC. Require the complete gzip trailer, too.
        while compressed.read(1024 * 1024):
            pass
    if expected:
        raise ValueError(f"Missing source archive files: {sorted(expected)}")


def php_components(minor):
    names = [
        "LICENSE", "Zend/LICENSE", "TSRM/LICENSE", "ext/bcmath/libbcmath/LICENSE",
        "ext/date/lib/LICENSE.rst", "ext/mbstring/libmbfl/LICENSE", "ext/fileinfo/libmagic/LICENSE",
        "ext/pcre/pcre2lib/pcre2_compile.c", "ext/hash/xxhash/xxhash.h",
        "ext/standard/libavifinfo/LICENSE", "ext/standard/libavifinfo/PATENTS",
        "Zend/zend_strtod.c", "ext/calendar/dow.c", "ext/hash/murmur/PMurHash.c",
        "ext/hash/sha3/generic32lc/KeccakHash.c", "ext/hash/sha3/generic32lc/brg_endian.h",
        "ext/standard/crypt_freesec.c", "ext/standard/crypt_blowfish.c",
        "ext/standard/crypt_sha256.c", "ext/standard/crypt_sha512.c", "ext/standard/strnatcmp.c",
    ]
    bundled = ["Zend", "PCRE2", "libmbfl", "timelib", "libbcmath", "file/libmagic",
               "xxHash", "libavifinfo", "Lucent strtod", "Scott E. Lee calendar",
               "MurmurHash3", "Keccak Code Package", "FreeSec", "crypt_blowfish", "SHA-crypt", "strnatcmp"]
    if minor != "8.3":
        lexbor = "ext/dom/lexbor" if minor == "8.4" else "ext/lexbor"
        names.extend([f"{lexbor}/LICENSE", f"{lexbor}/NOTICE"])
        bundled.append("Lexbor")
    if minor == "8.5":
        names.extend(["ext/uri/uriparser/COPYING.BSD-3-Clause", "ext/uri/uriparser/include/uriparser/COPYING",
                      "ext/uri/uriparser/src/COPYING"])
        bundled.append("uriparser")
    return names, bundled


def bundle(inputs, output, minor=None):
    selected, metadata = build_record(output, minor)
    locked_files(inputs, selected)
    metadata_path = output / "build-metadata.json"
    files = {"build-metadata.json": metadata_path, "LICENSE-MIT": RECIPE.parent / "LICENSE-MIT"}
    php_notices, bundled = php_components(selected.minor)
    rust_source = next(s["file"] for s in source_inputs(selected) if s["file"].startswith("rust-src-"))
    notices = [
        "Atoms PHP Worker interpreter\n\n"
        "Atoms-authored source is licensed under the MIT grant below. The upstream PHP embed SAPI, "
        "Rust runtime, Emscripten runtime and linked libraries retain their accompanying grants. "
        "This software is based in part on the work of the Independent JPEG Group.",
        (RECIPE.parent / "LICENSE-MIT").read_text(),
        "PHP and Zend are distributed under the recorded successor Modified BSD terms. "
        "The original grants and the PHP License 4.0 text follow.",
        (inputs / "notices/PHP-LICENSE-4.0").read_text(),
        read_notices(inputs / selected.php_source["file"], php_notices),
        read_notices(inputs / "emscripten.tar.gz", ["LICENSE", "system/lib/libc/musl/COPYRIGHT",
            "system/lib/compiler-rt/LICENSE.TXT", "system/lib/libcxx/LICENSE.TXT",
            "system/lib/libcxxabi/LICENSE.TXT", "system/lib/libunwind/LICENSE.TXT"]),
        read_notices(inputs / rust_source, ["COPYRIGHT", "LICENSE-MIT", "LICENSE-APACHE",
            "rust-src/lib/rustlib/src/rust/library/compiler-builtins/LICENSE.txt"]),
    ]
    for component in library_lock():
        notices.append(read_notices(inputs / "libraries" / component["file"], component["notices"]))
    for item in source_inputs(selected):
        files["inputs/" + item["file"]] = inputs / item["file"]
    for name in [*RECIPE_FILES, *SOURCE_RELEASE_FILES]:
        files["php-wasm-build/" + name] = RECIPE / name

    inventory = {
        "builder": COMPILER["builder"], "platform": COMPILER["platform"],
        "compiler": {"emscripten": COMPILER["emscripten"], "lock": digest(RECIPE / "compiler.lock.json")},
        "rustTools": TOOLS,
        "php": {"version": selected.version, "commit": selected.commit, "license": "BSD-3-Clause",
                "bundled": bundled, "sapi": "embed",
                "patches": ["rust/timelib-long64.patch"] + {
                    "8.3": ["rust/opcache-file-cache.patch"],
                    "8.4": ["rust/opcache-file-cache-84.patch"], "8.5": [],
                }[selected.minor]},
        "emscriptenRuntimeInputs": ["Asyncify", "MEMFS", "musl", "dlmalloc", "compiler-rt", "libc++", "libc++abi"],
        "libraries": library_lock(), "build": metadata, "sourceFiles": {},
    }
    output.mkdir(parents=True, exist_ok=True)
    with TemporaryDirectory(dir=output) as scratch:
        tmp = Path(scratch)
        (tmp / "THIRD_PARTY_NOTICES.txt").write_text("\n\n".join(notices) + "\n")
        expected = expected_sources(selected, metadata)
        expected["build-metadata.json"] = digest(metadata_path)
        source = tmp / "corresponding-source.tar.gz"
        with source.open("wb") as raw, gzip.GzipFile(filename="", fileobj=raw, mode="wb", mtime=0,
                                                    compresslevel=1) as compressed:
            with tarfile.open(fileobj=compressed, mode="w|", format=tarfile.GNU_FORMAT) as archive:
                for name, path in sorted(files.items()):
                    sha = add_file(archive, name, path)
                    if name in expected and sha != expected[name]:
                        raise ValueError(f"Source changed during packaging: {name}")
                    inventory["sourceFiles"][name] = sha
                (tmp / "components.json").write_text(json.dumps(inventory, indent=2) + "\n")
                for name in ("components.json", "THIRD_PARTY_NOTICES.txt"):
                    add_file(archive, name, tmp / name)
        for path in sorted(tmp.iterdir()):
            path.replace(output / path.name)


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--php", choices=MINORS, help="require this PHP series")
    parser.add_argument("inputs_or_verify", help="locked input directory, or 'verify' to check an existing source bundle")
    parser.add_argument("output", type=Path)
    args = parser.parse_args()
    if args.inputs_or_verify == "verify":
        verify_bundle(args.output.resolve(), args.php)
    else:
        bundle(Path(args.inputs_or_verify).resolve(), args.output.resolve(), args.php)
