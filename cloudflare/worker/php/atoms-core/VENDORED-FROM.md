# Vendored: the `atoms/core` package

These files are **verbatim copies** of the `atoms/core` package, and are the
reason the Cloudflare MVP can claim the real ABI runs inside the guest.

| | |
|---|---|
| Upstream | `packages/core` — **in this repository**, since 2026-08-08 |
| Source of `*.php` | `packages/core/src/…` (same relative tree) |
| Source of `resources/errors.json` | `packages/core/resources/errors.json` |
| Vendored on | 2026-08-04 (from the then-separate framework repository) |
| Re-verified | 2026-08-09, on the M3 Cloudflare toolchain change — all 22 files byte-identical to `packages/core`. `Errors/ErrorCode.php` and `resources/errors.json` were re-vendored (ATOMS-E073–E077 added, E072 reworded); the other 20 digests are unchanged |
| Re-verified | 2026-08-12, on M2 wave 0 (timers ABI) — `Atom.php`, `Errors/ErrorCode.php`, `Runtime/AtomContext.php`, `Runtime/LifecycleInvoker.php` and `resources/errors.json` re-vendored (`AtomContext::timers()`, `Atom::timers()`/`onTimer()`, `LifecycleInvoker::timer()`, ATOMS-E080–E086 added); new file `Timers/Timers.php` added, 23 files total; the other 17 digests are unchanged |
| Re-verified | 2026-08-13, on M4 T1 (adapter discipline error codes) — `Errors/ErrorCode.php` and `resources/errors.json` re-vendored (ATOMS-E100–E103 added: layering violation, sleep-in-Atom, elapsed-time wait loop, no queue bridge configured); still 23 files total, the other 21 digests are unchanged |
| Re-verified | 2026-08-13, on M7 documentation publication — `Errors/ErrorCatalog.php` re-vendored so stable error links use `docs.atomsphp.dev`; still 23 files total, the other 22 digests are unchanged |
| Re-verified | 2026-08-14, on the by-name dispatch fix — `Atom.php`, `Runtime/AtomContext.php`, `Errors/ErrorCode.php` and `resources/errors.json` re-vendored. `dispatch()` changed in place from `dispatch(AtomJob $job)` to `dispatch(string $job, array $args = [])`: the instance form needed the job class loaded in the guest, which a bundle never carries. A pre-1.0 signature change, taken deliberately — see `docs/conventions.md` §The `atoms/core` ABI. ATOMS-E104 added; E032/E061/E082/E084/E101/E102 reworded. Still 23 files total, the other 19 digests are unchanged |
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
that silently diverges would make the MVP's central claim false.

Re-vendor by re-copying from upstream, then re-check the digests below.

## What is here, and why

Required by the runtime: `Atom.php`, `AtomJob.php`, `AtomMethods.php`,
`Database.php`, `Runtime/{AtomContext,LifecycleInvoker}.php`, `Timers/Timers.php`,
`Migrations/*`, `Serialization/*`, `Websocket/{Connection,Message}.php`, and the
`Errors/*` closure that `AtomsError` → `ErrorCatalog` → `CatalogEntry` pulls in
(plus its `resources/errors.json` data file). `Timers/Timers.php` is required
because `Runtime/AtomContext.php` now declares `timers(): Timers\Timers` —
`CfAtomContext` (`worker/php/runtime/CfAtomContext.php`) implements this via
`CfTimers` in `worker/php/runtime/`, which landed in M2.

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
83362e39978897bb466cbc05e9d2c915d9576520efacde8aa88deee4fedb18d1  Errors/ErrorCode.php
e230d8cf59d4d9c773be3f46fb4b49db948dd52279ffe08a5488d7b35718987f  Migrations/Migration.php
f433e85e2449339b31bf806c4c8dde1afbd9e06dda005a2dc5a3df62fcd1252e  Migrations/MigrationEntry.php
addfe71f9472e7f2e76422227ef06586818b546e09842cdcba8e8a97a1dcd690  Migrations/MigrationSet.php
50af83b416b9dec8f9c16e4b0fa635ef4fb5be8e38134571f66d910bded18b9d  Migrations/Migrator.php
e0f9f876e5365af6f73e37c07957b202f1f6aef138e2b7ab3fd3acb98b261e7f  Runtime/AtomContext.php
bad340c4631a86b8b0d33df013854e5494f7e639917c71e103ce0635cb47dc41  Runtime/LifecycleInvoker.php
b765f073ca2b9e9c62834a2316a78ffe4a19bf5a2c97a6528449f13442584629  Serialization/Payload.php
1486ab89bf416b88929159b6014b2a268b081f2efd1d46a314fe4d86948d8bc8  Serialization/SerializationException.php
0b3224c2173e0fcde24b433a9373a5a1268f72a7a94e232f214e1c6bb15ec1d4  Serialization/Serializer.php
1fde1d8fa58f1bf741d746d8f82fd3dc847fb0ae44b04baa4c2eccd033df3295  Timers/Timers.php
9976931fc24b29337ddddbb686432d537e45524f388cfbc60b37554af8db46f5  Websocket/Connection.php
c0a739e750b0b2558133cb5c33e6ac119ed1f1526be41587a4f2bf21b5ba63d9  Websocket/Message.php
eefa01d36900c3b68c5f0bfee308856709ca8bc4f652799adc88be1cf254cf70  resources/errors.json
```

Verify with, from this directory:

```sh
find . -type f -name '*.php' -o -name '*.json' | sort | xargs shasum -a 256
```
