# `cloudflare/` — Atoms on Cloudflare Workers

An Atoms runtime that hosts a persistent PHP interpreter inside a SQLite-backed
Durable Object: one generic `AtomDurableObject`, one parked PHP loop per active
Atom, the real `atoms/core` ABI running unmodified inside the guest.

| Path | What it is |
|---|---|
| [`docs/mvp-spec.md`](docs/mvp-spec.md) | The binding specification, including the appendix of measured platform deviations |
| [`worker/`](worker/) | The Worker itself: host JavaScript, the `Atoms\Cf\` guest prelude, fixtures, and the conformance suite |
| [`LICENSE-MIT`](LICENSE-MIT) | The MIT grant for Atoms-authored source in this tree |
| [`THIRD_PARTY_NOTICES.md`](THIRD_PARTY_NOTICES.md) | Components in the PHP/WebAssembly runtime and the evidence for each |
| [`corresponding-source/`](corresponding-source/) | The pinned upstream artifact and source provenance |

The PHP interpreter is not committed to this repository. `npm ci` fetches it
from WordPress Playground's npm package, and
`worker/scripts/prepare-runtime.mjs` stages it into the gitignored
`worker/.php-wasm/` directory after verifying its size and SHA-256.

This is the only runtime Atoms targets.

## Running it

```sh
cd worker
npm ci
npm run bundle
npx wrangler dev --port 8799
ATOMS_BASE_URL=http://127.0.0.1:8799 ATOMS_DEBUG_ENDPOINTS=1 node test/conformance.mjs
```

Every enabled check must pass. The callback-channel and result-cap sections
skip rather than fail when their prerequisite is not configured for the run.
Set `ATOMS_REQUIRE_CALLBACK_CHECKS=1` and
`ATOMS_REQUIRE_SQL_CAP_CHECKS=1`, as CI does, to make those skips failures;
see `worker/test/README.md`. Several checks wait out a real eviction, so the
run takes a few minutes. No Cloudflare account is needed.

## Source licenses and provenance

Atoms-authored source in this tree is MIT under [`LICENSE-MIT`](LICENSE-MIT).
The fetched runtime and its transitive components retain their upstream
licenses. [`THIRD_PARTY_NOTICES.md`](THIRD_PARTY_NOTICES.md) records the
component inventory. [`corresponding-source/`](corresponding-source/) records
the exact upstream commit, package, hashes, and source retrieval procedure.

The runtime binary remains generated and gitignored. Never commit `.php-wasm/`
or graft the predecessor repository history that contained an older copy of
that binary. The current repository was assembled from source files without
that history, and the preparation script verifies the upstream artifact on
every install.
