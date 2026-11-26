#!/usr/bin/env python3
"""Collect the exact offline source inputs, notices and release recipe."""
import argparse
import gzip
import hashlib
import json
from pathlib import Path
import tarfile
from tempfile import TemporaryDirectory

from build import LOCK, RECIPE, locked_files
from libraries import library_lock


def digest(path):
    h = hashlib.sha256()
    with path.open("rb") as stream:
        while chunk := stream.read(1024 * 1024):
            h.update(chunk)
    return h.hexdigest()


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


def bundle(inputs, output):
    locked_files(inputs)
    libraries = library_lock()
    files = {}
    notices = [
        "Atoms PHP Worker interpreter\n\n"
        "The combined interpreter and matching JavaScript glue are offered under GPL version 3. "
        "The source includes the GPL-2.0-or-later Playground web SAPI, extensions and build patches. "
        "Atoms-authored build scripts and modifications are covered by the accompanying MIT grant. "
        "PHP and Zend are used under the successor terms published by the PHP Group. "
        "Upstream copyright notices and grants for individual parts follow. "
        "This software is based in part on the work of the Independent JPEG Group.",
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
    for name in ["build.py", "builder.Dockerfile", "worker-build.patch", "extension-abi.mjs",
                 "libraries.py", "build-libraries.sh", "compiler.lock.json", "libraries.lock.json",
                 "source-bundle.py", "pack.mjs", "README.md"]:
        files["php-wasm-build/" + name] = RECIPE / name

    inventory = {
        "builder": LOCK["builder"],
        "platform": LOCK["platform"],
        "compiler": {"emscripten": LOCK["emscripten"], "lock": digest(RECIPE / "compiler.lock.json")},
        "php": {"version": LOCK["php"], "commit": LOCK["phpCommit"], "license": "BSD-3-Clause",
                "bundled": ["Zend", "PCRE2", "libmbfl", "timelib", "libbcmath", "file/libmagic",
                            "xxHash", "libavifinfo"]},
        "playground": {"commit": LOCK["playgroundCommit"], "license": "GPL-2.0-or-later",
                       "components": ["web SAPI", "PHP patches", "opcache changes", "dns_polyfill",
                                      "post_message_to_js", "wasm_memory_storage", "JavaScript glue"]},
        "emscriptenRuntime": ["JavaScript support", "musl", "compiler-rt"],
        "libraries": libraries,
        "sourceFiles": {name: digest(path) for name, path in sorted(files.items())},
    }
    output.mkdir(parents=True, exist_ok=True)
    with TemporaryDirectory(dir=output) as scratch:
        tmp = Path(scratch)
        (tmp / "components.json").write_text(json.dumps(inventory, indent=2) + "\n")
        (tmp / "THIRD_PARTY_NOTICES.txt").write_text("\n\n".join(notices) + "\n")
        for name in ("components.json", "THIRD_PARTY_NOTICES.txt"):
            files[name] = tmp / name
        source = tmp / "corresponding-source.tar.gz"
        with source.open("wb") as raw, gzip.GzipFile(filename="", fileobj=raw, mode="wb", mtime=0,
                                                    compresslevel=1) as compressed:
            with tarfile.open(fileobj=compressed, mode="w|", format=tarfile.GNU_FORMAT) as archive:
                for name, path in sorted(files.items()):
                    info = tarfile.TarInfo(name)
                    info.size = path.stat().st_size
                    info.mode = 0o755 if path.suffix == ".sh" else 0o644
                    with path.open("rb") as data:
                        archive.addfile(info, data)
        for path in sorted(tmp.iterdir()):
            path.replace(output / path.name)


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("inputs", type=Path)
    parser.add_argument("output", type=Path)
    args = parser.parse_args()
    bundle(args.inputs.resolve(), args.output.resolve())
