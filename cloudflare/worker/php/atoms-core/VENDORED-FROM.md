# Vendored: the `atoms/core` package

These files are **verbatim copies** of the `atoms/core` package, and are the
reason the Cloudflare runtime can claim the real ABI runs inside the guest.

| | |
|---|---|
| Upstream | `packages/core` — **in this repository**, since 2026-08-08 |
| Source of `*.php` | `packages/core/src/…` (same relative tree) |
| Source of `resources/errors.json` | `packages/core/resources/errors.json` |
| Vendored on | 2026-08-04 (from the then-separate framework repository) |
| Re-verified | 2026-08-09, on the Cloudflare toolchain change — all 22 files byte-identical to `packages/core`. `Errors/ErrorCode.php` and `resources/errors.json` were re-vendored (ATOMS-E073–E077 added, E072 reworded); the other 20 digests are unchanged |
| Re-verified | 2026-08-12, on the timers ABI change — `Atom.php`, `Errors/ErrorCode.php`, `Runtime/AtomContext.php`, `Runtime/LifecycleInvoker.php` and `resources/errors.json` re-vendored (`AtomContext::timers()`, `Atom::timers()`/`onTimer()`, `LifecycleInvoker::timer()`, ATOMS-E080–E086 added); new file `Timers/Timers.php` added, 23 files total; the other 17 digests are unchanged |
| Re-verified | 2026-08-13, on the adapter discipline error codes — `Errors/ErrorCode.php` and `resources/errors.json` re-vendored (ATOMS-E100–E103 added: layering violation, sleep-in-Atom, elapsed-time wait loop, no queue bridge configured); still 23 files total, the other 21 digests are unchanged |
| Re-verified | 2026-08-13, on documentation publication — `Errors/ErrorCatalog.php` re-vendored so stable error links use `docs.atomsphp.dev`; still 23 files total, the other 22 digests are unchanged |
| Re-verified | 2026-08-14, on the by-name dispatch fix — `Atom.php`, `Runtime/AtomContext.php`, `Errors/ErrorCode.php` and `resources/errors.json` re-vendored. `dispatch()` changed in place from `dispatch(AtomJob $job)` to `dispatch(string $job, array $args = [])`: the instance form needed the job class loaded in the guest, which a bundle never carries. A pre-1.0 signature change, taken deliberately — see `docs/conventions.md` §The `atoms/core` ABI. ATOMS-E104 added; E032/E061/E082/E084/E101/E102 reworded. Still 23 files total, the other 19 digests are unchanged |
| Re-verified | 2026-08-14, on connection tickets — `Errors/ErrorCode.php` and `resources/errors.json` re-vendored (ATOMS-E067 added: WebSocket ticket acquisition failed; its fix line reworded when the single-use design was dropped pre-merge); still 23 files total, the other 21 digests are unchanged |
| Re-verified | 2026-08-15, on the shared-secret change (`docs/shared-secret.md`) — `Errors/ErrorCode.php` and `resources/errors.json` re-vendored (ATOMS-E105 added: shared secret missing or malformed; E064/E067/E080/E081 reworded from the two-secret design to `ATOMS_SHARED_SECRET`); still 23 files total, the other 21 digests are unchanged |
| Re-verified | 2026-08-16, on local WebSocket ticket issuance — `Errors/ErrorCode.php` and `resources/errors.json` re-vendored (ATOMS-E068 added: WebSocket ticket claims invalid, raised by the application's own `TicketIssuer`; E067 reworded to record that it is retired, the Worker having stopped minting tickets over HTTP). Still 23 files total, the other 21 digests are unchanged |
| Re-verified | 2026-08-16, on structured WebSocket frames — `Websocket/Connection.php` and `Websocket/Message.php` re-vendored (`sendJson(array $payload): void` and `json(): array` added to the two interfaces), and **new file** `Websocket/JsonFrame.php`, the single encoder both they and `CfAtomContext::broadcast()` pass through. **24 files total**, up from 23; the other 21 digests are unchanged |
| Re-verified | 2026-08-16, comment-only trim of `Websocket/{Connection,Message,JsonFrame}.php` (prose docblocks reduced to load-bearing constraints; no behavioural change). All three re-vendored; still 24 files total, the other 21 digests are unchanged |
| Re-verified | 2026-08-16, comment-pattern cleanup of `Websocket/{JsonFrame,Message}.php` — `JsonFrame::decode()`'s docblock reworded from constraint-as-identity phrasing ("is a frame") to a stated rule ("must decode to"), and from a "frame" reused mid-sentence for both the WebSocket-protocol sense and this library's structured-payload sense; `Message::json()`'s docblock, which duplicated that same explanation in full, now cross-references `{@see JsonFrame::decode()}` instead. Both re-vendored; still 24 files total, the other 22 digests are unchanged |
| Re-verified | 2026-08-16, on the duplicate-FQCN discovery fix — `Errors/ErrorCode.php` and `resources/errors.json` re-vendored (ATOMS-E002 added: two files declaring the same class under the Atoms path, raised by the CLI's build-time discovery). Nothing in the guest raises it; the copy carries it because the copy is verbatim. Still 24 files total, the other 22 digests are unchanged |
| Re-verified | 2026-08-17, on the Wrangler credential handoff (`docs/cloudflare-toolchain.md` §Credentials) — `resources/errors.json` re-vendored (E072 and E075 reworded: the CLI no longer pre-empts a missing `CLOUDFLARE_API_TOKEN` or a missing account id, so both codes now name the inlets and are raised from Wrangler's own failure output). No code added or removed; still 24 files total, the other 23 digests are unchanged |
| Re-verified | 2026-08-16, on the named-argument hydration primitive — `Serialization/Serializer.php` re-vendored (`denormalizeNamedArguments()` added: the one owner of the "bind wire args to a constructor by name" algebra the dispatched-job envelope and Payload hydration share; `denormalizePayload()` rewired onto it). Additive to the ABI, no existing signature changed. Still 24 files total, the other 23 digests are unchanged |
| Re-verified | 2026-08-17, comment-only note of the migration-payload invariant — `Migrations/MigrationEntry.php` gains a constructor docblock stating that exactly one of `$sql`/`$phpFile` must be set (unenforced: the constructor is public ABI), and `Migrations/Migrator.php` a comment on why its `?->` is reachable only for an entry violating that. No behavioural change. Both re-vendored; still 24 files total, the other 22 digests are unchanged |
| Re-verified | 2026-08-17, comment-only note of the migration-payload invariant — `Migrations/MigrationEntry.php` gains a constructor docblock stating that exactly one of `$sql`/`$phpFile` must be set (unenforced: the constructor is public ABI), and `Migrations/Migrator.php` a comment on why its `?->` is reachable only for an entry violating that. No behavioural change. Both re-vendored; still 24 files total, the other 22 digests are unchanged |
| Re-verified | 2026-08-30, on the database-illuminate bridge and vendor-shipping build — `Errors/ErrorCode.php` and `resources/errors.json` re-vendored (ATOMS-E079 added: vendor dependency resolution failed, raised by the CLI's build vendor stage; ATOMS-E106 added: schema builder not available on the Atom database connection, raised by the atoms/database-illuminate bridge). Nothing in the guest raises either; the copy carries them because the copy is verbatim. Still 24 files total, the other 22 digests are unchanged |
| Re-verified | 2026-08-30, on the fast-build refusal — `Errors/ErrorCode.php` and `resources/errors.json` re-vendored (ATOMS-E107 added: fast build cannot ship declared dependencies, raised by the CLI's Builder when `--fast` meets a non-empty atoms-composer.json). Nothing in the guest raises it; the copy carries it because the copy is verbatim. Still 24 files total, the other 22 digests are unchanged |
| Re-verified | 2026-08-31, on the support-class classification — `resources/errors.json` re-vendored (E001 and E012 reworded: both now name the Atom's `support/` directory, the sanctioned home for World-A helper classes shipping with an Atom). No code added or removed; still 24 files total, the other 23 digests are unchanged |
| Licence | MIT — Atoms' own code, same as `packages/core` itself |

Upstream used to be a different repository, which is why this copy exists at
all: the guest needed the ABI and could not `composer require` it. Since the
2026-08-08 monorepo migration, upstream is a sibling directory. That does not
make the copy redundant — the guest still loads PHP source from MEMFS, not from
a composer install — but it does mean drift is now a plain in-repo diff:

```sh
# From the repository root. Silence is a pass. Driven from the vendored side
# because `packages/core/src` legitimately holds more than the guest needs —
# see "One deliberate omission" below.
for f in $(cd cloudflare/worker/php/atoms-core && find . -name '*.php' | sed 's|^\./||'); do
    cmp "packages/core/src/$f" "cloudflare/worker/php/atoms-core/$f" || echo "DRIFT: $f"
done
cmp packages/core/resources/errors.json \
    cloudflare/worker/php/atoms-core/resources/errors.json
```

These files carry no SPDX headers, and must not be given any: a header is an
edit, and the rule below is that they are never edited. Their licence is the
`atoms/core` package's own, declared in that package's `composer.json`. They
are the one tree under `cloudflare/` that is neither Atoms-authored-here nor
third-party — and they are worth distinguishing from the upstream php-wasm
runtime, which is not carried in the repository: `npm ci` fetches it and
`scripts/prepare-runtime.mjs` stages it into a gitignored
`worker/.php-wasm/`). Nothing here is linked into the
WebAssembly binary; it is PHP source carried into the guest and interpreted.

## Never edit in place

If one of these files cannot work unmodified inside the php-wasm guest, that is
a finding about the ABI, not a patch to apply here. Raise it upstream; a fork
that silently diverges would make the runtime's central claim false.

Re-vendor by re-copying from upstream, then re-check the digests below,
then run `npm run bundle` from `cloudflare/worker` and commit
`src/bundle.generated.js` — this copy is embedded in it, and CI diffs a
fresh regeneration against the committed file. `npm run check:fresh` is
that gate, runnable locally. Comment-only edits count: the bundle embeds
file bytes, not behaviour.

## What is here, and why

Required by the runtime: `Atom.php`, `AtomJob.php`, `AtomMethods.php`,
`Database.php`, `Runtime/{AtomContext,LifecycleInvoker}.php`, `Timers/Timers.php`,
`Migrations/*`, `Serialization/*`, `Websocket/{Connection,Message,JsonFrame}.php`, and the
`Errors/*` closure that `AtomsError` → `ErrorCatalog` → `CatalogEntry` pulls in
(plus its `resources/errors.json` data file). `Timers/Timers.php` is required
because `Runtime/AtomContext.php` now declares `timers(): Timers\Timers` —
`CfAtomContext` (`worker/php/runtime/CfAtomContext.php`) implements this via
`CfTimers` in `worker/php/runtime/`.
`Websocket/JsonFrame.php` is required because `Websocket/Connection.php` now
declares `sendJson(array $payload): void` and `Websocket/Message.php` declares
`json(): array` — `CfConnection`, `CfMessage` and `CfAtomContext::broadcast()`
all encode through it, which is what keeps a structured frame's rules identical
whichever call produced it.

Two deliberate additions beyond the strict transitive closure:
`Attributes/MethodsFor.php` and `Attributes/SharedWithAtoms.php`. Nothing in
the runtime path references them, but a customer bundle may annotate a shared
DTO with `#[SharedWithAtoms]`, and a missing attribute class is a hard fatal at
class-declaration time. They are two behaviourless declarations.

One deliberate omission: `Sqlite/SqliteDatabase.php`. It opens a real
`PDO('sqlite:…')` against a filesystem path, which is precisely what the
Durable Object bridge replaces; `Atoms\Cf\BridgeDatabase` is the implementation
of `Atoms\Database` in this runtime. It is still the reference for
`transaction()`'s nesting guard.

## Digests (sha256)

```
dd7ddc039b676ae6e5ff02d9e307e5c1bcca9028b326715b779009f1356bc346  Atom.php
e1cf8cea48ad7525422c43e4c7422c250ed66028b9d4cf3bf97f5fce5fe9e624  AtomJob.php
4a4da51d856e552242a045b68d04e7e5754e48b1825c708d0736a0b8fadc87e9  AtomMethods.php
efda00eec6a42bfdd40ed60e432a5d279c6701a99fde7a400b020249d68cce53  Attributes/MethodsFor.php
5239bdac78cdf7d4d9b6af62cd2827f807cd7b6892cf2d20c377de736b677124  Attributes/SharedWithAtoms.php
0f446033d4006ba86c48368dcce6e0dfd7e477e02f3483e540f944601d70cb62  Database.php
7c996f6c31cff9bf210040f311bb534a140e79ae3c62b721c6266ad6d78353e2  Errors/AtomsError.php
0b4bfcf9ea74ed277614139157b03696f4eae42dc85f420120f96663cf654283  Errors/CatalogEntry.php
3d1a122b24f6e3dd88104816b2b3b96b846690a9acd9316cd142d16afb71c411  Errors/ErrorCatalog.php
825b602772a7bf2142352f79f477d3037ee4b24e4b38b4b48b243805e7e6200c  Errors/ErrorCode.php
e230d8cf59d4d9c773be3f46fb4b49db948dd52279ffe08a5488d7b35718987f  Migrations/Migration.php
cc9106ddfbb2c70523c404a83855fbb90c49f453e8d2e2314c4af94085202cde  Migrations/MigrationEntry.php
addfe71f9472e7f2e76422227ef06586818b546e09842cdcba8e8a97a1dcd690  Migrations/MigrationSet.php
dfd45d3a2afe9cd208bff409f5947f73ea715491ae91191444cd5b346b8f5055  Migrations/Migrator.php
e0f9f876e5365af6f73e37c07957b202f1f6aef138e2b7ab3fd3acb98b261e7f  Runtime/AtomContext.php
bad340c4631a86b8b0d33df013854e5494f7e639917c71e103ce0635cb47dc41  Runtime/LifecycleInvoker.php
b765f073ca2b9e9c62834a2316a78ffe4a19bf5a2c97a6528449f13442584629  Serialization/Payload.php
1486ab89bf416b88929159b6014b2a268b081f2efd1d46a314fe4d86948d8bc8  Serialization/SerializationException.php
7e293792487fcd78e1d36ef6ea5c85665e39882313ddee58d610d390698c4655  Serialization/Serializer.php
1fde1d8fa58f1bf741d746d8f82fd3dc847fb0ae44b04baa4c2eccd033df3295  Timers/Timers.php
7a95c9a1ba00a17fe37787b7fece3fb8ec9bdb82460d664f1808d6a430cc6bb0  Websocket/Connection.php
a0ff473e1d8f326269f0e67f2406dc9923151cf5915e7e06bb11bf205aa84bea  Websocket/JsonFrame.php
b98dace805bbbce5d06072c80f4153c5ed2d9a7847dadcf098642a7a70174880  Websocket/Message.php
485fc2c05376570b89b3fea49c1c517ca86984a95206774589aef53b7c40a7da  resources/errors.json
```

Verify with, from this directory:

```sh
find . -type f -name '*.php' -o -name '*.json' | sort | xargs shasum -a 256
```
