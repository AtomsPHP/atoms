#!/usr/bin/env python3
"""Collect the exact offline source inputs, notices and release recipe."""
import argparse
import gzip
import hashlib
import json
from pathlib import Path
import tarfile
import sys
from tempfile import TemporaryDirectory

from build import BUILD_RECIPE_FILES, LOCK, RECIPE, locked_files
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


def build_record(output):
    metadata_path = output / "build-metadata.json"
    metadata = json.loads(metadata_path.read_bytes())
    expected_outputs = {"php_8_3.js", "php_8_3.wasm"}
    if (metadata.get("schema") != 1 or set(metadata.get("recipe", {})) != set(BUILD_RECIPE_FILES)
            or set(metadata.get("outputs", {})) != expected_outputs):
        raise ValueError("Incomplete compiler build-metadata.json")
    for name in expected_outputs:
        if digest(output / name) != metadata["outputs"][name]:
            raise ValueError(f"Output differs from the compiled file: {name}")
    return metadata


def expected_sources(libraries, metadata):
    pins = {f"inputs/{s['file']}": s["sha256"] for s in LOCK["downloads"]
            if not s["file"].startswith("debs/")}
    pins.update({f"inputs/{name}": sha for name, sha in LOCK["playgroundFiles"].items()})
    pins.update({f"inputs/libraries/{c['file']}": c["sha256"] for c in libraries})
    pins.update({f"php-wasm-build/{name}": sha for name, sha in metadata["recipe"].items()})
    return pins


def verify_bundle(output):
    metadata = build_record(output)
    inventory = json.loads((output / "components.json").read_bytes())
    if inventory["build"] != metadata:
        raise ValueError("Component inventory differs from build-metadata.json")
    expected = inventory["sourceFiles"].copy()
    pins = expected_sources(library_lock(), metadata)
    pins["build-metadata.json"] = digest(output / "build-metadata.json")
    allowed = set(pins)
    allowed.add("LICENSE-MIT")
    allowed.update(f"php-wasm-build/{name}" for name in SOURCE_RELEASE_FILES)
    if set(expected) != allowed:
        raise ValueError("Source inventory does not match the expected release file list")
    for name, sha in pins.items():
        if expected.get(name) != sha:
            raise ValueError(f"Component inventory differs from the release pins for {name}")
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


def bundle(inputs, output):
    locked_files(inputs)
    libraries = library_lock()
    metadata = build_record(output)
    metadata_path = output / "build-metadata.json"
    files = {"build-metadata.json": metadata_path}
    notices = [
        "Atoms PHP Worker interpreter\n\n"
        "The combined interpreter and matching JavaScript glue are offered under GPL version 3. "
        "The source includes the GPL-2.0-or-later Playground web SAPI, extensions and build patches. "
        "Atoms-authored build scripts and modifications are covered by the accompanying MIT grant. "
        "PHP and Zend are used under the successor terms published by the PHP Group. "
        "Upstream copyright notices and grants for individual parts follow. "
        "This software is based in part on the work of the Independent JPEG Group.",
        "WordPress Playground v3.1.48 declares GPL-2.0-or-later for its web interpreter. "
        "Atoms elects version 3 for the combined work. The release links OpenSSL 3.5.9 "
        "from the Apache-2.0 source identified by libraries.lock.json. OpenSSL records "
        "the change in grant for 3.x, and the Apache Software Foundation documents "
        "inclusion of Apache-2.0 code in GPLv3 projects.\n"
        "https://github.com/WordPress/wordpress-playground/blob/53d5c3030702e1edb3f82e7dbc308fcac27c3d0a/packages/php-wasm/web-builds/8-3/package.json\n"
        "https://openssl-library.org/source/license/\n"
        "https://www.apache.org/licenses/GPL-compatibility.html",
        "Section 5 of PHP License 3.01 permits election of PHP License 4 for earlier covered "
        "sources. Section 4 of Zend Engine License 2.00 permits election of Zend Engine "
        "License 3.0. The PHP Group records both elections and publishes the consolidated "
        "Modified BSD text with both copyright holders. Other upstream file-specific grants "
        "are retained in the included sources.\nhttps://www.php.net/license/\nhttps://www.php.net/license/4_0.txt",
        (RECIPE.parent / "LICENSE-MIT").read_text(),
        (inputs / "notices/PHP-LICENSE-4.0").read_text(),
        read_notices(inputs / "playground.tar", ["LICENSE"], False),
        read_notices(inputs / "php.tar.gz", [
            "LICENSE", "Zend/LICENSE", "TSRM/LICENSE", "ext/bcmath/libbcmath/LICENSE",
            "ext/date/lib/LICENSE.rst", "ext/mbstring/libmbfl/LICENSE", "ext/fileinfo/libmagic/LICENSE",
            "ext/pcre/pcre2lib/pcre2_compile.c", "ext/hash/xxhash/xxhash.h",
            "ext/standard/libavifinfo/LICENSE", "ext/standard/libavifinfo/PATENTS",
        ]),
        read_notices(inputs / "emscripten.tar.gz", ["LICENSE", "system/lib/libc/musl/COPYRIGHT",
            "system/lib/compiler-rt/LICENSE.TXT", "system/lib/libcxx/LICENSE.TXT",
            "system/lib/libcxxabi/LICENSE.TXT", "system/lib/libunwind/LICENSE.TXT"]),
    ]
    for component in libraries:
        name = "libraries/" + component["file"]
        files["inputs/" + name] = inputs / name
        names = component["notices"]
        if component["name"] == "libiconv":
            names = [*names, "COPYING"]  # Verbatim GPLv3 text for the combined artifact.
        notices.append(read_notices(inputs / name, names))

    for name in [s["file"] for s in LOCK["downloads"] if not s["file"].startswith("debs/")]:
        files["inputs/" + name] = inputs / name
    for name in LOCK["playgroundFiles"]:
        files["inputs/" + name] = inputs / name

    files["LICENSE-MIT"] = RECIPE.parent / "LICENSE-MIT"
    # Only build source and maintainer instructions enter the source release.
    for name in [*BUILD_RECIPE_FILES, *SOURCE_RELEASE_FILES]:
        files["php-wasm-build/" + name] = RECIPE / name

    inventory = {
        "builder": LOCK["builder"],
        "platform": LOCK["platform"],
        "compiler": {"emscripten": LOCK["emscripten"], "lock": digest(RECIPE / "compiler.lock.json")},
        "php": {"version": LOCK["php"], "commit": LOCK["phpCommit"], "license": "BSD-3-Clause",
                "patches": ["Playground patches in playground.tar",
                            "openssl-legacy-provider.patch loads the static OpenSSL legacy provider at MINIT"],
                "bundled": ["Zend", "PCRE2", "libmbfl", "timelib", "libbcmath", "file/libmagic",
                            "xxHash", "libavifinfo"]},
        "playground": {"commit": LOCK["playgroundCommit"], "license": "GPL-2.0-or-later",
                       "components": ["web SAPI", "PHP patches", "opcache changes", "dns_polyfill",
                                      "post_message_to_js", "wasm_memory_storage", "JavaScript glue"]},
        "emscriptenRuntimeInputs": ["JavaScript support", "GL/getprocaddr", "OpenAL", "HTML5",
                                    "system stubs", "musl", "dlmalloc", "compiler-rt",
                                    "libc++", "libc++abi", "Emscripten sockets"],
        "libraries": libraries,
        "build": metadata,
        "sourceFiles": {},
    }
    output.mkdir(parents=True, exist_ok=True)
    with TemporaryDirectory(dir=output) as scratch:
        tmp = Path(scratch)
        (tmp / "THIRD_PARTY_NOTICES.txt").write_text("\n\n".join(notices) + "\n")
        expected = expected_sources(libraries, metadata)
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
    if sys.argv[1:2] == ["verify"]:
        parser.add_argument("operation", choices=["verify"])
        parser.add_argument("output", type=Path)
        verify_bundle(parser.parse_args().output.resolve())
    else:
        parser.add_argument("inputs", type=Path)
        parser.add_argument("output", type=Path)
        args = parser.parse_args()
        bundle(args.inputs.resolve(), args.output.resolve())
