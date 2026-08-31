# Corresponding source for the php-wasm binary

The PHP 8.3 interpreter is a prebuilt WebAssembly binary compiled by the
WordPress Playground project. It is fetched from
`@php-wasm/web-8-3@3.1.48`; it is not committed to this repository.
`../worker/scripts/prepare-runtime.mjs` stages it into the gitignored
`../worker/.php-wasm/` directory after verifying its SHA-256.

This directory is kept as **provenance**. It records exactly which
upstream artifact the build pins, how that pin was checked against
upstream rather than against itself, and how to obtain the matching
source. That is worth having on its own terms — and it is no longer a
claim maintained by hand: `prepare-runtime.mjs` enforces the same hashes
recorded below on every build and refuses to proceed on a mismatch, so a
silent swap of the interpreter underneath us fails the build rather than
quietly invalidating this file.

It is also groundwork for an owned php-wasm build, which has to know what
the current one contains and how upstream builds it; the survey below
records that evidence.

## Repository history

Written in the past tense as of 2026-08-08, because the hazard was real
and is now closed.

In the repository where this tree was developed, the generated binary was out
of the working tree but still reachable in history. This repository imported
the Cloudflare tree as files rather than commits, so the large generated
artifact is absent from both the current tree and its history.

Confirmed on a fresh clone: no blob over 5MB, and no blob whose content
SHA-256 matches the one recorded below.

The rule that follows is permanent and cheap: **never graft that
predecessor history onto this repository, and never commit the binary.** `../worker/.gitignore` and `../worker/scripts/prepare-runtime.mjs`
enforce the second half.

## Which binary this corresponds to

| | |
|---|---|
| Artifact | `asyncify/8_3_32/php_8_3.wasm`, staged as `../worker/.php-wasm/8_3_32/php_8_3.wasm` |
| SHA-256 | `eca478d2bad4cae984cd5b5ec39ce42311fcc4d31cf48fce7293e9a5034f1c98` |
| Bytes | 18,309,089 |
| Package it comes from | `@php-wasm/web-8-3@3.1.48` |
| Hash and size pinned in | `../worker/scripts/prepare-runtime.mjs` |
| Upstream repository | `github.com/WordPress/wordpress-playground` |
| Upstream commit | `53d5c3030702e1edb3f82e7dbc308fcac27c3d0a` |
| Commit subject | `v3.1.48` |
| Commit author date | Mon Aug 3 10:34:28 2026 +0000 |
| Tree hash | `0db020c5bd51a4d32c97929a64191a7de6356a28` |

The accompanying JavaScript glue is `asyncify/php_8_3.js` from the same
package — SHA-256
`719346ea1827cc48c8dd298974649503b7a227a7c0e5c12827db1c993dccf3ac`,
268,881 bytes, likewise pinned in `prepare-runtime.mjs`. It needs no
separate archive: it is already human-readable source, so its
corresponding source is the file itself, and it is a file anyone can
fetch from the same npm package.

Atoms patches one line into it while staging —
`Module['Asyncify'] = Asyncify;`. Under the old vendoring the glue
carried **two** modifications; the second was an import-path change,
needed because vendoring flattened upstream's directory layout. Staging
preserves that layout, keeping the `.wasm` in its nested `8_3_32/`
directory, so upstream's own `./8_3_32/php_8_3.wasm` import resolves
untouched and only the Asyncify line remains. `prepare-runtime.mjs`
documents the change at its patch site and fails loudly if the anchor
moves or upstream starts exposing `Asyncify` itself.

### The pin is verified from inside the archive, not merely asserted

Playground commits its build outputs, so the archive contains the very
file the build stages. Two independent checks confirm the commit above is
the right one:

| Path inside the archive | SHA-256 | Matches |
|---|---|---|
| `packages/php-wasm/web-builds/8-3/asyncify/8_3_32/php_8_3.wasm` | `eca478d2…4f1c98` | byte-identical to the staged `.wasm` |
| `packages/php-wasm/web-builds/8-3/asyncify/php_8_3.js` | `719346ea…ccf3ac` (268,881 bytes) | the upstream glue hash pinned in `prepare-runtime.mjs` |

So the npm package the build installs from is a repackaging of build
outputs committed at this commit, and the provenance recorded here holds
up when checked against upstream rather than against itself.

## Reproducing the archive

`git archive` is used rather than a tarball of a working directory
because its output is a pure function of the commit: no timestamps, no
file modes from the local filesystem, no `.git` directory. `gzip -n`
then omits the filename and mtime from the gzip header, which is what
makes the compressed file reproducible too.

```sh
git clone https://github.com/WordPress/wordpress-playground.git
cd wordpress-playground
git archive --format=tar \
    --prefix=wordpress-playground-53d5c30/ \
    53d5c3030702e1edb3f82e7dbc308fcac27c3d0a \
    > wordpress-playground-53d5c30.tar
gzip -n -9 < wordpress-playground-53d5c30.tar \
    > wordpress-playground-53d5c30.tar.gz
```

`fetch-corresponding-source.sh` in this directory does exactly that and
then verifies the result against `SHA256SUMS`, which is the part that
matters — a script that only downloads proves nothing.

| | |
|---|---|
| `wordpress-playground-53d5c30.tar` | 506,234,880 bytes, SHA-256 `91054fa3019a1c7b7df4d2b6c2350bdb91f11a08cc22ff64ce214f1531c6bb39` |
| `wordpress-playground-53d5c30.tar.gz` | 165,277,545 bytes (157.62 MiB), SHA-256 `a32e92763f0e888f6df801a844489dfee7ac148c3bdd72ab038ad6def2e76753` |
| Produced with | `git version 2.43.0` |

Both files were generated twice from the same commit and compared with
`cmp`; both were identical, so the procedure is reproducible on this git
version at least. The git version is recorded because `git archive`'s tar
framing is not contractually frozen across releases — if a future git
produces a different byte stream, the tree hash above is the durable
identity and the archive hash is only a convenience.

A shallow fetch is enough. The script uses
`git fetch --depth=1 origin <commit>`, which takes about a minute against
a full clone's much longer run, and produces an identical archive because
the archive depends only on that commit's tree.

The archive itself is not committed and `out/` is gitignored. At 157.62
MiB the `.tar.gz` is past GitHub's 100 MB hard limit for a tracked file,
so committing it was never available; recording the hashes and a script
that reproduces them byte-for-byte is.

## What this source actually covers, and where it stops

The answer is not the comfortable one, which is why it is written down.

The archive contains Playground's own source and its build scripts. It does
**not** contain the source of the code making up the bulk of the binary.
That matters for an owned build — it cannot start from "reproduce what
Playground does" if what Playground does is fetch most of it from elsewhere
at build time. Three separate things are going on.

### Playground's own contributions are present as source

The archive contains these Playground-authored inputs:

| In the archive | What it is |
|---|---|
| `packages/php-wasm/compile/php-post-message-to-js/` | C extension, `post_message_to_js.c` + `config.m4` |
| `packages/php-wasm/compile/php-wasm-dns-polyfill/` | C extension, `dns_polyfill.c` + `config.m4` |
| `packages/php-wasm/compile/php-wasm-memory-storage/` | C extension, `wasm_memory_storage.c` + `config.m4` |
| `packages/php-wasm/compile/php/php8.3.patch` | 39-line patch applied to php-src before configure |

`php/Dockerfile` copies the three extensions into `php-src/ext/` (lines
25–32) and applies the patch with `git apply --no-index
/root/php${PHP_PATCH_VERSION}*.patch -v` (line 294). Each extension
prints the banner that `../THIRD_PARTY_NOTICES.md` reports finding in the
artifact's strings — `post_message_to_js support`, `dns_polyfill
support`, `wasm_memory_storage support` — so the chain from source in the
archive to bytes in the binary is complete for these four items. The
patch itself is small and unsurprising: a missing `#include <unistd.h>`
for `getpid()`, and a guard against copying zero-length files that
crashed the JS runtime.

**Confirmed, both in the repository and compiled into the binary.**

### PHP itself is downloaded at build time, not vendored

`php/Dockerfile` lines 19–23:

```dockerfile
RUN PHP_REF="${PHP_REF:-php-$PHP_VERSION}" && \
	git clone https://github.com/php/php-src.git php-src \
		--branch $PHP_REF \
		--single-branch \
		--depth 1;
```

There is no PHP source in the archive. The build clones it from GitHub at
the tag `php-8.3.32`. A tag is a mutable pointer, not a content hash, and
nothing verifies what arrives — so the recipe pins an *intent* rather
than a *bit pattern*.

### The third-party libraries are not in source form at all

This is the sharpest edge. The libraries are not vendored as source, and
they are also not compiled from source during the PHP build. They are
committed into the repository as **prebuilt static archives**, and the
PHP build simply copies them in. `php/Dockerfile` lines 38–58:

```dockerfile
COPY ./compile/libiconv/ /root/builds/libiconv
COPY ./compile/libopenssl/ /root/builds/libopenssl
COPY ./compile/libsqlite3/ /root/builds/libsqlite3
COPY ./compile/libxml2/ /root/builds/libxml2
…
RUN if [ "$WITH_JSPI" = "yes" ]; then \
	find /root/builds -path '*/jspi/dist/root/lib' -type d -exec cp -r {}/. /root/lib \; ; \
else \
	find /root/builds -path '*/asyncify/dist/root/lib' -type d -exec cp -r {}/. /root/lib \; ; \
fi
```

Those `dist/root/lib` directories hold checked-in `ar` archives —
`libiconv/asyncify/dist/root/lib/lib/libiconv.a`,
`libsqlite3/asyncify/dist/root/lib/lib/libsqlite3.a`, and so on, roughly
100 MB of `.a` files across seventeen library directories. They are
produced out of band by the sibling `Makefile`, which builds each library
in its own Docker image and then lifts the result back into the working
tree with `docker cp`:

```make
libz/asyncify/dist/root/lib/lib/libz.a: base-image
	docker cp $$(docker create playground-php-wasm:libz):/root/lib/lib ./libz/asyncify/dist/root/lib
```

So the source for, say, the linked libiconv is neither in the archive nor
fetched by the PHP build. It is fetched only if someone
separately re-runs that library's own Dockerfile, which downloads it from
its upstream home at build time:

| Library | Fetched from | Pin |
|---|---|---|
| PHP | `github.com/php/php-src.git` | tag `php-$PHP_VERSION` (`php-8.3.32`) |
| libiconv | `ftp.gnu.org/pub/gnu/libiconv/libiconv-1.17.tar.gz` | 1.17, in the URL |
| SQLite | `sqlite.org/2025/sqlite-autoconf-3510000.tar.gz` | 3.51.0, in the URL, fetched with `--no-check-certificate` |
| libxml2 | `gitlab.gnome.org/GNOME/libxml2.git` | tag `v2.9.10`, cloned with `GIT_SSL_NO_VERIFY=true` |
| zlib | `zlib.net/fossils/zlib-1.2.13.tar.gz` | 1.2.13, in the URL |
| curl | `curl.haxx.se/download/$CURL_VERSION.tar.gz` | `ARG CURL_VERSION="curl-7.69.1"` |
| OpenSSL | `openssl.org/source/openssl-$OPENSSL_VERSION.tar.gz` | `ARG` with no default; `Makefile` passes `1.1.0h` and `1.1.1t` |
| oniguruma | `github.com/kkos/oniguruma.git` | **no ref at all — floating default branch** |
| libpng | `prdownloads.sourceforge.net/libpng/libpng-1.6.39.tar.gz` | 1.6.39, over plain `http://` |
| libjpeg-turbo | `github.com/libjpeg-turbo/libjpeg-turbo` | 3.0.3, in the URL |
| libwebp | `chromium.googlesource.com/webm/libwebp/+archive/845d5476….tar.gz` | commit hash — the only true content pin |
| libaom | `aomedia.googlesource.com/aom/+archive/v3.13.1.tar.gz` | v3.13.1 |
| libavif | `github.com/AOMediaCodec/libavif` | v1.3.0 |
| libzip | `libzip.org/download/libzip-$LIBZIP_VERSION.tar.gz` | `ARG`, fetched with `curl -k` |
| libgd | `github.com/libgd/libgd` | `ARG GD_VERSION="2.3.3"` |
| ImageMagick | `github.com/ImageMagick/ImageMagick` | 7.1.1-39 |
| libedit | `thrysoee.dk/editline/libedit-20221030-3.1.tar.gz` | exact |
| ncurses | `ftp.gnu.org/gnu/ncurses/ncurses-6.2.tar.gz` | 6.2 |
| Imagick ext | `github.com/Imagick/imagick.git` | **no ref** |

Not every row is linked into the artifact; `../THIRD_PARTY_NOTICES.md`
records which components are actually evidenced in the binary's strings.
The table describes the recipe's whole fetch surface, because that is
what determines whether the recipe is self-contained. It is not.

Two properties of that table matter more than any individual version.
**Nothing is verified.** A repository-wide search for `sha256sum`,
`md5sum`, `gpg --verify` or a `sha256:` field across every Dockerfile,
Makefile and build script under `packages/php-wasm/compile/` returns
nothing; three fetches actively disable TLS verification
(`--no-check-certificate`, `GIT_SSL_NO_VERIFY=true`, `curl -k`) and one
uses plain HTTP. **Two pins float outright.** oniguruma — which is
mbstring's regex engine and is present in the binary — is cloned with no
branch or tag, so the recipe describes "whatever `kkos/oniguruma` HEAD
was on the day the `.a` was built," which is unrecoverable after the
fact.

### Emscripten

`base-image/Dockerfile` pins the toolchain by version but not the tool
that installs it:

```dockerfile
git clone https://github.com/emscripten-core/emsdk.git
EMSDK_NOTTY=1 EMSDK_USE_CURL=1 ./emsdk/emsdk install --notty 4.0.19
EMSDK_NOTTY=1 /root/emsdk/emsdk activate --notty 4.0.19
```

4.0.19 is pinned, and the file carries a comment insisting every linked
library be produced by that same version. The `emsdk` repository itself
is cloned at HEAD, and the base image is `FROM ubuntu:noble`, a moving
tag. The runtime support code Emscripten emits into the staged files is
present in the human-readable glue fetched from npm.

### Two observations from reading the recipe

Recorded because they were surprising, and flagged as read rather than
executed — no build was attempted here, so these are properties of the
text, not of a failed run.

The OpenSSL actually linked into the artifact comes from
`libopenssl/asyncify/dist/root/`, which contains 1.1.1t. No `Makefile`
target populates that directory; the targets write to
`dist/1.1.0h/` and `dist/1.1.1/` and only `mkdir -p` the `dist/root` path.
So the committed OpenSSL that ends up in the binary is regenerated by no
target in the tree at this commit. Relatedly, `build.js` defaults
`WITH_OPENSSL_VERSION` to `1.1.0h`, which is not what is linked.

Separately, `php/Dockerfile` copies both
`libzip/asyncify/dist/1.2.0/root/lib` and `…/1.9.2/root/lib`, but only
`1.9.2` exists in the tree. Taken literally, that step has nothing to
copy for `1.2.0`.

### Provenance gaps

The archive contains Playground's own source and complete build scripts. It
does **not** contain PHP source or the source of the statically linked
libraries. Those are fetched from third-party hosts at build time, unverified,
and in two cases from unpinned refs; the libraries are produced by separate
out-of-band steps whose committed outputs the PHP build consumes. If any host
stops serving a version, the recipe stops being reconstructible from this
archive alone.

Closing the gap means pinning and hashing every fetched source — PHP 8.3.32
first, since it is the largest single body of code in the binary. That work
belongs with the owned build's generated SBOM. Note that `php.net` and
`gnu.org` were unreachable from the environment in which this directory
was prepared (the egress proxy returns 403 on CONNECT for both), so the
PHP and libiconv tarballs could not be mirrored here even as a first
step; that is an environment limitation, not an upstream one.

This directory is therefore an accurate account of *where* the current
runtime's source comes from, not a complete offline source mirror.
