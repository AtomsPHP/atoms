#!/usr/bin/env python3
"""Collect the exact offline source inputs, notices and release recipe."""
import argparse
import gzip
import hashlib
import json
from pathlib import Path
import tarfile
from tempfile import TemporaryDirectory

from build import MINORS, RECIPE, locked_files, target
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
    # The original qualified 8.3 receipt predates explicit target fields.
    minor = metadata.get("target", "8.3")
    selected = target(minor)
    if requested_minor is not None and requested_minor != minor:
        raise ValueError(f"Requested PHP {requested_minor}, but the build record specifies {minor}")
    if ("target" in metadata or "php" in metadata) and metadata.get("php") != selected.lock["php"]:
        raise ValueError("PHP patch version differs from the selected compiler lock")
    expected_outputs = set(selected.files)
    if (metadata.get("schema") != 1 or set(metadata.get("recipe", {})) != set(selected.recipe_files)
            or set(metadata.get("outputs", {})) != expected_outputs):
        raise ValueError("Incomplete compiler build-metadata.json")
    for name in expected_outputs:
        if digest(output / name) != metadata["outputs"][name]:
            raise ValueError(f"Output differs from the compiled file: {name}")
    return selected, metadata


def expected_sources(lock, libraries, metadata):
    pins = {f"inputs/{s['file']}": s["sha256"] for s in lock["downloads"]
            if not s["file"].startswith("debs/")}
    pins.update({f"inputs/{name}": sha for name, sha in lock["playgroundFiles"].items()})
    pins.update({f"inputs/libraries/{c['file']}": c["sha256"] for c in libraries})
    pins.update({f"php-wasm-build/{name}": sha for name, sha in metadata["recipe"].items()})
    return pins


def verify_bundle(output, minor=None):
    selected, metadata = build_record(output, minor)
    lock = selected.lock
    libraries = library_lock(RECIPE / selected.libraries_lock)
    inventory = json.loads((output / "components.json").read_bytes())
    if (inventory["php"]["version"] != lock["php"] or inventory["php"]["commit"] != lock["phpCommit"]
            or inventory["playground"]["commit"] != lock["playgroundCommit"] or inventory["libraries"] != libraries):
        raise ValueError("Component inventory differs from the selected target source locks")
    if inventory["build"] != metadata:
        raise ValueError("Component inventory differs from build-metadata.json")
    expected = inventory["sourceFiles"].copy()
    pins = expected_sources(lock, libraries, metadata)
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
    lock = selected.lock
    locked_files(inputs, target=selected)
    libraries = library_lock(RECIPE / selected.libraries_lock)
    metadata_path = output / "build-metadata.json"
    files = {"build-metadata.json": metadata_path}
    php_notices, bundled = php_components(selected.minor)
    php_grant = ("Section 5 of PHP License 3.01 and section 4 of Zend Engine License 2.00 "
                     "permit election of the successor terms, the consolidated Modified BSD grant.")
    playground_package = f"packages/php-wasm/web-builds/{selected.minor.replace('.', '-')}/package.json"
    openssl = next(c for c in libraries if c["name"] == "openssl")
    notices = [
        "Atoms PHP Worker interpreter\n\n"
        "The combined interpreter and matching JavaScript glue are offered under GPL version 3. "
        "The source includes the GPL-2.0-or-later Playground web SAPI, extensions and build patches. "
        "Atoms-authored build scripts and modifications are covered by the accompanying MIT grant. "
        "Upstream copyright notices and grants for individual parts follow. "
        "This software is based in part on the work of the Independent JPEG Group.",
        "WordPress Playground declares GPL-2.0-or-later for its web interpreter. "
        f"Atoms elects version 3 for the combined work. The release links OpenSSL {openssl['version']} "
        f"from the {openssl['license']} source identified by {selected.libraries_lock}. OpenSSL records "
        "the change in grant for 3.x, and the Apache Software Foundation documents "
        "inclusion of Apache-2.0 code in GPLv3 projects.\n"
        f"https://github.com/WordPress/wordpress-playground/blob/{lock['playgroundCommit']}/{playground_package}\n"
        "https://openssl-library.org/source/license/\n"
        "https://www.apache.org/licenses/GPL-compatibility.html",
        php_grant + " Other file-specific grants are retained.\nhttps://www.php.net/license/\n"
        "https://www.php.net/license/4_0.txt",
        (RECIPE.parent / "LICENSE-MIT").read_text(),
        (inputs / "notices/PHP-LICENSE-4.0").read_text(),
        read_notices(inputs / "playground.tar", ["LICENSE"], False),
        read_notices(inputs / "php.tar.gz", php_notices),
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

    for name in [s["file"] for s in lock["downloads"] if not s["file"].startswith("debs/")]:
        files["inputs/" + name] = inputs / name
    for name in lock["playgroundFiles"]:
        files["inputs/" + name] = inputs / name

    files["LICENSE-MIT"] = RECIPE.parent / "LICENSE-MIT"
    # Only build source and maintainer instructions enter the source release.
    for name in [*selected.recipe_files, *SOURCE_RELEASE_FILES]:
        files["php-wasm-build/" + name] = RECIPE / name

    inventory = {
        "builder": lock["builder"],
        "platform": lock["platform"],
        "compiler": {"emscripten": lock["emscripten"], "lock": digest(RECIPE / "compiler.lock.json")},
        "php": {"version": lock["php"], "commit": lock["phpCommit"], "license": "BSD-3-Clause",
                "patches": ["Playground source patches in playground.tar", *selected.worker_patches,
                            *([selected.crypto_patch] if selected.crypto_patch else [])],
                "acceptedCompatibilityChanges": [
                    "The interpreter follows OpenSSL 3 for the removed SSLv23 RSA padding mode. "
                    "PHP omits OPENSSL_SSLV23_PADDING and rejects numeric mode 2. "
                    "https://github.com/openssl/openssl/issues/14283"
                ],
                "bundled": bundled},
        "playground": {"commit": lock["playgroundCommit"], "license": "GPL-2.0-or-later",
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
        expected = expected_sources(lock, libraries, metadata)
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
