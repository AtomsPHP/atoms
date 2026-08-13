# `cloudflare/` — Atoms on Cloudflare Workers

An Atoms runtime that hosts a persistent PHP interpreter inside a SQLite-backed
Durable Object: one generic `AtomDurableObject`, one parked PHP loop per active
Atom, the real `atoms/core` ABI running unmodified inside the guest.

| Path | What it is |
|---|---|
| [`docs/mvp-spec.md`](docs/mvp-spec.md) | The binding specification, including the appendix of measured platform deviations |
| [`docs/mvp-summary.md`](docs/mvp-summary.md) | What was built, in brief |
| [`worker/`](worker/) | The Worker itself: host JavaScript, the `Atoms\Cf\` guest prelude, fixtures, and the conformance suite |
| [`LICENSE-MIT`](LICENSE-MIT) | The MIT grant for everything Atoms wrote here |
| [`THIRD_PARTY_NOTICES.md`](THIRD_PARTY_NOTICES.md) | What is inside the PHP/WebAssembly runtime, with the evidence |
| [`corresponding-source/`](corresponding-source/) | Provenance of that runtime: which upstream artifact is pinned, and how to get its source |

The PHP interpreter is **not** in this repository. `npm ci` fetches it from
Playground's own npm package and `scripts/prepare-runtime.mjs` stages it into
a gitignored `worker/.php-wasm/`, verifying its hash first. That is a
licensing decision as much as a size one — see below.

This is the Cloudflare track, and it is the only runtime Atoms targets. An
earlier platform preceded it; that work is frozen and is not in this
repository.

## Running it

```sh
cd worker
npm ci
npm run bundle
npx wrangler dev --port 8799
ATOMS_BASE_URL=http://127.0.0.1:8799 ATOMS_DEBUG_ENDPOINTS=1 node test/conformance.mjs
```

30 checks, all of which must pass (13–17, the callback channel, and 29, the
result-set size guard, skip rather than fail when their prerequisite is not
configured for the run — set `ATOMS_REQUIRE_CALLBACK_CHECKS=1`/
`ATOMS_REQUIRE_SQL_CAP_CHECKS=1`, as CI does, to make those skips failures;
see `worker/test/README.md`). Checks 12, 21, 24 and 25 each wait out
a real eviction, so the run takes a few minutes. No Cloudflare account is
needed for any of this.

## Licensing

Atoms is a mixed-license project. There is no single repository-wide licence,
and the mixture is deliberate rather than accidental — so it is worth reading
this section rather than looking for a top-level `LICENSE` file and stopping.

**This repository does not distribute the GPL runtime.** That is the first
thing to know, because it decides most of what follows. The PHP interpreter is
compiled by the WordPress Playground project and is GPL-2.0-or-later —
Playground compiles C extensions of its own authorship, and its own patch to
PHP's source, *into* that binary, so Playground-authored protected material is
genuinely present. The familiar argument that a GPL build tool does not
encumber its output is correct in general and does not reach this case.

We used to vendor that binary. We no longer do. `npm ci` fetches it from
Playground's own npm package, and `scripts/prepare-runtime.mjs` stages it into
a gitignored `worker/.php-wasm/` after verifying its hash. GPL obligations
attach to *distribution*, and we do not distribute it — the copy on your
machine came to you from Playground.

**The Worker you assemble is GPL-2.0-or-later.** Once the runtime is staged
and bundled, the result is a combined work: the host JavaScript calls that
code in-process and ships in one bundle with it. That applies to the artifact,
not to this repository. `worker/LICENSE` carries the text and explains the
distinction. You deploy that artifact to your own Cloudflare account, which
is not distribution, so the obligations never come due.

**Atoms' own source files remain MIT.** Everything Atoms wrote under
`cloudflare/` — the host JavaScript, the guest PHP prelude, the build and test
scripts, the fixture app, these documents — is MIT, granted by `LICENSE-MIT`
in this directory. Anyone taking those files separately, the int64 codec or
the door protocol or the PDO shim, has them under MIT. The GPL governs the
combination, not their independent availability.

There are no per-file SPDX headers, deliberately. `LICENSE-MIT` and
`worker/LICENSE` between them say what is MIT, what is GPL, and why both are
true at once; repeating that in a two-line banner on thirty files would add
noise without adding information. One file does carry a licence declaration:
`worker/package.json` says `GPL-2.0-or-later`, because a package manifest
describes the package as assembled, and that is the GPL Worker.

The seven PHP framework packages under `packages/` are MIT for the
same reason, and the copies of the `atoms/core` package under
`worker/php/atoms-core/` are MIT too — under that package's own grant, since
they are verbatim and are never edited here.

**Your PHP application code is not relicensed. Not by any of this.** This is
the point most likely to be misread, so it is stated flatly. An Atom you write
is loaded into the guest's in-memory filesystem and interpreted; it is data
that PHP reads, not code linked into the runtime. It targets the MIT-licensed
`atoms/core` ABI, not a Playground-specific API. Neither the interpreter's
licence nor the Worker's reaches it.

One honest qualification, because it is better stated than discovered. The
MVP's bundler emits your PHP sources as JavaScript string literals into
`worker/src/bundle.generated.js`, so they are physically inside the same file
as the runtime even though they are semantically data. That weakens the
factual clarity of the separation without changing the analysis — the mapping
of paths to file contents is still much better characterised as application
data than as linked program code, and no application text is turned into
executable JavaScript statements. Moving the bundle to a Worker asset binding
would make the separation obvious on inspection rather than on argument, and
that change is planned. It is an ergonomics and bundle-size change that
happens to sharpen the licensing story, not a fix the licensing story needs.

The practical consequence: you may assemble, modify, deploy and run this
Worker in your own Cloudflare account without publishing anything. Deploying
to infrastructure you control is not distribution, and GPLv2 is not the AGPL,
so operating a Worker over a network triggers no source offer.

**PHP and the Zend Engine are used under their 2026 successor licences.** PHP
8.3.32 shipped under PHP License 3.01 and Zend Engine License 2.00, both of
which are GPL-incompatible and both of which contain a successor-version
clause. Atoms elects those successors: the PHP portions are used under the PHP
License, version 4, and the Zend Engine portions under the Zend Engine
License, version 3.0, each equivalent to `BSD-3-Clause`. The election is made
under PHP License 3.01 §5 and Zend Engine License 2.00 §4, and it is what lets
those portions sit inside a GPL work without conflict.

**Other components keep their own terms.** The WebAssembly binary also
contains GNU libiconv and mbstring's streamable kanji code filter
(LGPL-2.1-or-later and LGPL-2.1), SQLite 3.51.0 (public domain), Emscripten
runtime code (MIT or NCSA), and a tail of further linked libraries.
`THIRD_PARTY_NOTICES.md` lists them with the evidence for each, and is honest
about where the evidence stops: a machine-generated, audited SBOM is
outstanding work, not a claim being made here.

**Provenance.** `corresponding-source/` records which upstream artifact the
build pins, how that pin was checked against upstream rather than against
itself, and how to fetch and verify the matching source. `prepare-runtime.mjs`
enforces the same hashes on every build, so a silent swap of the interpreter
fails the build rather than going unnoticed. Licence texts are not shipped
here; `THIRD_PARTY_NOTICES.md` names each licence by SPDX identifier and links
the authoritative text.

### The history hazard, and how it was resolved

Recorded because it was the last thing standing between this tree and
publication, and because the way it was avoided is not visible from the files.

In the repository this tree was developed in, the binary was gone from the
working tree but **still reachable in that repository's history** — committed
before it was removed. `git clone` delivers history, not the tip, so publishing
that repository would have distributed the binary and re-attached every
obligation described above. Deleting a file does not unpublish it.

**That did not happen, and cannot now.** On 2026-08-08 this tree was moved here
as files rather than as commits — a `git archive` of the source tip, extracted
into a repository that never had the old one as a git remote. None of that
history exists here to be reached. Verified on a fresh clone: no blob over 5MB,
and no blob whose content SHA-256 is `eca478d2…f1c98`.

The constraint that produced this is worth keeping rather than retiring,
because it is cheap to honour and silent to violate: **never graft that
predecessor history onto this repository**, and never commit the runtime
binary. The second half is enforced — `worker/.gitignore` covers
`.php-wasm/`, and `scripts/prepare-runtime.mjs` stages the artifact there
rather than anywhere tracked.

### Open questions about what the runtime contains

None of these bind Atoms. They are recorded because two of them shape what M5
should build, and because knowing what you are running is worth the page.

- **OpenSSL 1.1.1t may be GPL-incompatible.** The runtime links it, and its
  dual OpenSSL/SSLeay licence carries an advertising clause of the kind long
  read as incompatible with the GPL. If that reading holds, a distributed
  GPL-2.0-or-later artifact containing it is internally contradictory, and the
  fix is a different build rather than different wording — which is M5's job.
  `THIRD_PARTY_NOTICES.md` §"Unresolved: OpenSSL 1.1.1t" sets out the question
  and the arguments that might dissolve it. This is Playground's exposure
  today, not ours: they publish the artifact.
- **The upstream source is not self-contained.** Playground's build fetches
  PHP and every linked library from third-party hosts at build time, mostly
  unpinned and never hash-verified. That is a real constraint on M5, which
  cannot simply reproduce what Playground does. See `corresponding-source/`.
- **The component inventory is unaudited.** The list below is evidence from
  the artifact's strings, not an audit. §3's Tier 2 SBOM closes it, and M5
  emits one as a build output rather than as archaeology.

*None of the above is legal advice. It is the project's own account of what it
builds and runs, written so a reader can check the reasoning rather than take
it on trust.*
