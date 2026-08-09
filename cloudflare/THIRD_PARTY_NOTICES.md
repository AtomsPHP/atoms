# Third-party notices for the Atoms Cloudflare Worker

This file is hand-authored. It covers what a Worker built from this directory
contains, with the evidence for each component, and it says where the evidence
runs out. It is deliberately not presented as a complete software bill of
materials: a generated, audited SBOM is deferred work, planned as an output of
a future owned build rather than as archaeology over this one, and pretending
an incomplete inventory is a complete one would be worse than admitting the
gap.

**Atoms does not distribute the runtime.** The interpreter is fetched from
Playground's npm package at install time and staged into a gitignored
directory, so the notice-reproduction conditions these licences carry do not
bind Atoms. This file is not compliance paperwork. It is here because knowing
what is inside the interpreter you run is worth knowing on its own, and
because it is what the Tier 2 SBOM and M5's scoping build on.

An earlier draft argued that gaps in this inventory were harmless, because
the whole Worker goes out under GPL-2.0-or-later — the most restrictive term
in play — so an incomplete notice for a permissive component could only
understate what a recipient may do, never overstate it. **That argument is
wrong and has been withdrawn.** It confuses a recipient's permissions with a
distributor's obligations, which are separate things. It is recorded rather
than deleted because it is the kind of reasoning that feels sound and is not,
and because chasing it down is what turned up a component whose licence may
not be compatible with the GPL at all. See "Unresolved: OpenSSL 1.1.1t".

Licence texts are not shipped here. Each entry names its licence by SPDX
identifier and links the authoritative text, which is enough to look anything
up and avoids implying an obligation that does not apply.

## The short version

`cloudflare/worker/` builds against a prebuilt PHP 8.3 WebAssembly interpreter
compiled by the WordPress Playground project. Playground compiles its own C
extensions and its own patch to PHP's source *into* that binary, so
Playground-authored, GPL-licensed expression is genuinely present in the
artifact — this is not the "a GPL compiler does not taint its output" case,
which is true in general and does not apply here. The binary is therefore a
GPL-2.0-or-later combined executable that contains BSD-licensed PHP and Zend
portions, LGPL-licensed libiconv and mbstring portions, and a long tail of
components under their own compatible licences.

Atoms obtains it from npm rather than shipping it. The distinction runs
through this whole file: everything below describes what is *in* the runtime,
not what this repository hands to anyone.

## Components

### WordPress Playground php-wasm — GPL-2.0-or-later

The PHP interpreter staged into `worker/.php-wasm/` at build time, and the
`@php-wasm/*` npm packages the Worker imports at runtime. Neither is committed
to this repository; both arrive from npm.

| | |
|---|---|
| Copyright | WordPress Playground contributors |
| Licence | `GPL-2.0-or-later` — [text](https://www.gnu.org/licenses/old-licenses/gpl-2.0.txt) |
| Upstream | `WordPress/wordpress-playground`, commit `53d5c3030702e1edb3f82e7dbc308fcac27c3d0a` (`v3.1.48`) |
| Package | `@php-wasm/web-8-3@3.1.48` |

The staged `.wasm` is that package's `asyncify/8_3_32/php_8_3.wasm`,
unmodified — SHA-256
`eca478d2bad4cae984cd5b5ec39ce42311fcc4d31cf48fce7293e9a5034f1c98`,
18,309,089 bytes, checked on every build by
`worker/scripts/prepare-runtime.mjs`, which refuses to proceed on a mismatch.

The staged glue is that package's `asyncify/php_8_3.js` with exactly one Atoms
modification, applied by the same script: `Module['Asyncify'] = Asyncify;`,
which exposes Emscripten's otherwise module-private Asyncify object so the
host can own the `wakeUp` callback and resume the guest from a chosen JS stack
frame. The script documents the change at its patch site and fails loudly if
the anchor moves or upstream starts exposing Asyncify itself.

GPLv2 §2(a) requires a modified file to carry a prominent, dated notice of the
change. That obligation attaches to distributing the modified file, and the
patched glue exists only in a gitignored build directory on the machine that
built it — so the notice is not owed. The script is nonetheless the honest
record of what is changed and why, and it is the file a reader should look at.

At runtime the Worker also imports `@php-wasm/universal`, which pulls in
`@php-wasm/logger`, `@php-wasm/util` and `@php-wasm/stream-compression`. All
of these declare `GPL-2.0-or-later` in their package metadata except `util`,
which omits the `license` field but ships the same full GPLv2 text as its
`LICENSE`. That omission is ambiguity rather than evidence of a permissive
grant, so `util` is treated here as GPL-2.0-or-later, which is also the
conservative reading.

Complete corresponding source for the binary: see `corresponding-source/`.

### Playground's own C extensions and PHP patch — GPL-2.0-or-later

These are the reason the previous entry is not merely a build-tool question.
Playground copies C extensions of its own authorship into `php-src/ext` and
applies its own patch to PHP's source before compiling, so the resulting
binary incorporates Playground expression that has no separate permissive
grant.

Their presence in our exact artifact is not inferred from the build recipe —
it is visible in the binary. The strings dump of
the PHP/WebAssembly runtime contains the module banners
`post_message_to_js support`, `dns_polyfill support` and
`wasm_memory_storage support`, which are the three extensions named in
Playground's compile tree. The patch is `php8.3.patch` in the same tree.

| | |
|---|---|
| Copyright | WordPress Playground contributors |
| Licence | `GPL-2.0-or-later` — [text](https://www.gnu.org/licenses/old-licenses/gpl-2.0.txt) |

### PHP 8.3.32 and the Zend Engine — elected to BSD-3-Clause

The interpreter itself, and the engine under it. Confirmed in the binary:
`X-Powered-By: PHP/8.3.32` and
`Zend Engine v4.3.32, Copyright (c) Zend Technologies`.

PHP 8.3.32 shipped under PHP License 3.01 and Zend Engine License 2.00, and
those are the licences in its source tree. Both contain a successor-version
clause — PHP License 3.01 §5 and Zend Engine License 2.00 §4 — under which a
user of already-published covered code "may also choose to use such covered
code under the terms of any subsequent version of the license published by"
the PHP Group and Zend Technologies respectively.

**Atoms elects those successor versions.** The PHP 8.3.32 portions are used
under the PHP License, version 4, and the Zend Engine portions under the Zend
Engine License, version 3.0. Both remove the name, acknowledgment and
advertising clauses that made the older licences GPL-incompatible, and both
are the Modified BSD Licence (`BSD-3-Clause`) in substance. PHP's own licence
page states the election for earlier releases in terms, and records that Zend
invoked its clause 4 at the same time.

This election is what makes the combined GPL binary coherent. Without it,
BSD-incompatible clauses would sit inside a work distributed under the GPL.

| | |
|---|---|
| Copyright | The PHP Group and Contributors; Zend Technologies Ltd., a subsidiary of Perforce Software, Inc. |
| Elected licence | PHP License v4 / Zend Engine License v3.0 — [text](https://www.php.net/license/4_0.txt) |
| Original licences | [PHP License 3.01](https://www.php.net/license/3_01.txt); Zend Engine License 2.00, in php-src at tag `php-8.3.32` as `Zend/LICENSE` |
| SPDX | `BSD-3-Clause` |

One wrinkle worth stating rather than smoothing over: Zend does not publish
version 3.0 as a standalone text. php.net records that the `Zend/` directory
is no longer separately licensed and that its terms are now the consolidated
text carrying both the PHP Group's and Zend Technologies' copyright lines —
which php.net serves as `/license/4_0.txt`. So the Zend election is made against a text
that exists and is published, but not against a document titled "Zend Engine
License, version 3.0". A reader checking our work should expect to find the
statement of the change on php.net's licence page rather than a separate
licence file.

### SQLite 3.51.0 — public domain

PHP's `sqlite3` and `pdo_sqlite` extensions are compiled in, with SQLite
amalgamated into the binary. The version string `3.51.0` appears in the
artifact.

SQLite's authors have dedicated the code to the public domain. There is no
licence to reproduce and no attribution condition to satisfy; this entry
exists because a component inventory that silently omits a component is worse
than one that records "nothing is required here".

Note that the Worker does not actually execute this SQLite at runtime for
application data — `Atoms\Cf\BridgeDatabase` routes every query to the Durable
Object's own SQL storage. The compiled-in copy is redistributed nonetheless,
so it is listed.

### GNU libiconv — LGPL-2.1-or-later

Statically linked into the Playground build, which enables iconv by default.
Present in the artifact (`libiconv`, `iconv support`, `ICONV_VERSION`); no
version banner is embedded, so the exact version is not established here and
is one of the things the Tier 2 audit has to pin down.

This is the entry that falsifies the once-held assumption that everything
linked into the binary besides Playground's own code is permissive. LGPL-2.1
is compatible with a GPL-2.0-or-later distribution, so nothing about the
combined licence changes — but the component has to appear in the inventory,
its licence text has to ship, and its source has to be part of the
corresponding source.

| | |
|---|---|
| Copyright | Free Software Foundation, Inc. |
| Licence | `LGPL-2.1-or-later` — [text](https://www.gnu.org/licenses/old-licenses/lgpl-2.1.txt) |

### mbstring's streamable kanji code filter — LGPL-2.1

A second LGPL component, and one that is easy to miss because it is inside a
PHP extension rather than an obviously separate library. The artifact carries
the notice verbatim:

> mbstring extension makes use of "streamable kanji code filter and
> converter", which is distributed under the GNU Lesser General Public
> License version 2.1.

| | |
|---|---|
| Licence | `LGPL-2.1` — [text](https://www.gnu.org/licenses/old-licenses/lgpl-2.1.txt) |

### Emscripten runtime — MIT or NCSA

Emscripten compiled the WebAssembly and emitted runtime support code into
both the `.wasm` and the JavaScript glue. Emscripten is offered under the MIT
Licence or the University of Illinois/NCSA Open Source Licence, at the
recipient's option, and that generated runtime bulk does not become GPL merely
because a compiler emitted it — it is the majority of `php_8_3.asyncify.js` by
volume.

The compiler itself is a build tool and is not corresponding source. The
runtime code it *copied into* our distributed files is a different matter and
does belong in the source release.

| | |
|---|---|
| Copyright | Emscripten authors; University of Illinois at Urbana-Champaign |
| Licence | `MIT` or `NCSA`, at your option — [Emscripten licensing](https://emscripten.org/docs/introducing_emscripten/emscripten_license.html) |

### Unresolved: OpenSSL 1.1.1t — a possible GPL incompatibility

**This is an open question that blocks publication, and it is recorded here
rather than resolved, because resolving it is a legal judgement and not a
documentation task.**

The artifact contains OpenSSL 1.1.1t. This is not inferred from the build
recipe: the strings dump carries the banner `OpenSSL 1.1.1t  7 Feb 2023`
alongside `OPENSSL_VERSION_TEXT`, and PHP's `openssl` extension is compiled in
and live — `openssl_pkey_new`, `openssl_encrypt`, `openssl.cafile` and
`OpenSSLAsymmetricKey` are all present.

OpenSSL 1.1.1 predates the project's move to Apache-2.0. It is under the dual
OpenSSL License and original SSLeay License, and **both of which contain an
advertising clause**. They are quoted here rather than linked, because they are
the whole of the finding:

> 3. All advertising materials mentioning features or use of this
>    software must display the following acknowledgment:
>    "This product includes software developed by the OpenSSL Project
>    for use in the OpenSSL Toolkit. (http://www.openssl.org/)"

> 3. All advertising materials mentioning features or use of this software
>    must display the following acknowledgement:
>    "This product includes cryptographic software written by
>     Eric Young (eay@cryptsoft.com)"

That pair of clauses is the long-standing reason the FSF treats the OpenSSL
licence as GPL-incompatible.

If that reading holds, the problem is not a missing notice. It is that a
GPL-2.0-or-later combined work would contain a component under terms the GPL
does not permit to be combined with it — an internal contradiction that no
amount of notice-writing fixes. Distributing under `-or-later` does not help:
the advertising clause is incompatible with GPLv3 as well.

Arguments that might dissolve it, none of which have been evaluated here:

- GPLv2 §3's system-library exception. It looks unlikely to apply — the
  library is statically linked into the same artifact rather than being a
  component of the operating system — but the boundary is worth an opinion
  rather than an assumption.
- An OpenSSL linking exception granted by the relevant copyright holders.
  PHP has historically dealt with this problem for its own licence; whether
  anything reaching Playground's GPL grant exists is unknown.
- Reading the WebAssembly build's actual configuration, which may or may not
  link the pieces of OpenSSL the clause attaches to.

The practical fix, if the incompatibility is real, is upstream: build without
OpenSSL, or against a version under Apache-2.0. Both are on the table for a
future owned php-wasm build, which is planned work. That is a build change,
not a licensing change.

This entry is also the reason the withdrawn argument at the top of this file
mattered. Treating the notice gap as harmless is precisely what would have let
this sit undiscovered until someone else found it.

| | |
|---|---|
| Copyright | The OpenSSL Project; Eric Young; Tim Hudson |
| Licence | `OpenSSL` (the dual OpenSSL AND SSLeay licence) — [text as of 1.1.1t](https://github.com/openssl/openssl/blob/OpenSSL_1_1_1t/LICENSE) |
| Version | 1.1.1t, 7 Feb 2023 |

### Further libraries statically linked into the artifact

Identified by scanning the artifact's embedded strings, so presence is
evidenced but licences and exact versions are not audited file by file. This
is the list the Tier 2 SBOM has to turn into something authoritative.

| Component | Version evidence in the artifact | Licence (unaudited) |
|---|---|---|
| PCRE2 | `10.42 2022-12-12` | BSD-3-Clause |
| libpng | `1.6.39` | libpng (zlib-like) |
| libjpeg-turbo | `libjpeg-turbo version 3.0.3 (build 20250321)` | IJG / BSD-3-Clause / zlib |
| libaom | v3.13.1 — see the note below | BSD-2-Clause |
| zlib | banner only (`ZLib Support`) | zlib |
| libxml2 | banner only (`libxml2 Version`) | MIT |
| libcurl | banner only (`cURL support`) | curl (MIT-like) |
| libzip | banner only (`Libzip library version`) | BSD-3-Clause |
| oniguruma | banner only (`Multibyte regex (oniguruma)`) | BSD-2-Clause |
| FreeType | banner only (`FreeType Support`) | FTL or GPL-2.0-or-later |
| libwebp | banner only (`WebP Support`) | BSD-3-Clause |
| libsodium | banner only (`Sodium`) | ISC |
| libavif | banner only (`AVIF Support`) | BSD-2-Clause |

ICU was searched for and is **not** present.

The libaom row is a worked example of why the column above is a starting
point rather than a finding. The artifact's strings contain
`Detected libaom v3.6.0 bug with large images…`, which reads exactly like a
version banner and is not one — it is the text of a workaround for a bug in
that version, and it says nothing about which version is linked. Playground's
build recipe pins v3.13.1, and the prebuilt archive committed in its tree is
v3.13.1. Anyone continuing this inventory should assume every remaining
"banner only" row can fail the same way.

The "licence (unaudited)" column is each project's usual licence and is
recorded as a starting point for the audit, not as a finding. Do not cite it
as one. Where a project has changed licence across versions — FreeType is the
obvious case, and OpenSSL was the one that has already bitten — the version
actually linked decides, and for most of these rows that has not been checked.
OpenSSL is the worked example of what "unaudited" is hiding: it began as one
more row in this table and turned out to be a possible blocker.

## What is *not* third-party

`worker/php/atoms-core/` is not covered by this file. Those are verbatim
copies of the `atoms/core` package from `packages/core`, which is
Atoms' own MIT-licensed code; see `worker/php/atoms-core/VENDORED-FROM.md`.
They are carried into the guest as interpreted data and are not linked into
the WebAssembly binary.

End-user PHP application code is likewise outside this file entirely. See the
licensing section of `README.md`.
