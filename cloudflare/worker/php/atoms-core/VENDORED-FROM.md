# Vendored: the `atoms/core` package

These files are **verbatim copies** from the customer SDK monorepo
`AtomsPHP/atoms-framework`, and are the reason the Cloudflare MVP can claim the
real ABI runs inside the guest.

| | |
|---|---|
| Upstream | `atoms-framework/packages/core` |
| Source of `*.php` | `packages/core/src/…` (same relative tree) |
| Source of `resources/errors.json` | `packages/core/resources/errors.json` |
| Vendored on | 2026-08-04 |
| Licence | MIT — Atoms' own code, same as the rest of `atoms-framework` |

These files carry no SPDX headers, and must not be given any: a header is an
edit, and the rule below is that they are never edited. Their licence is the
`atoms/core` package's own, declared in that package's `composer.json`. They
are the one tree under `cloudflare/` that is neither Atoms-authored-here nor
third-party — and they are worth distinguishing from `../../vendor/`, which is
GPL and is a genuinely foreign artifact. Nothing here is linked into the
WebAssembly binary; it is PHP source carried into the guest and interpreted.

## Never edit in place

If one of these files cannot work unmodified inside the php-wasm guest, that is
a finding about the ABI, not a patch to apply here. Raise it upstream; a fork
that silently diverges would make the MVP's central claim false.

Re-vendor by re-copying from upstream, then re-check the digests below.

## What is here, and why

Required by the runtime: `Atom.php`, `AtomJob.php`, `AtomMethods.php`,
`Database.php`, `Runtime/{AtomContext,LifecycleInvoker}.php`, `Migrations/*`,
`Serialization/*`, `Websocket/{Connection,Message}.php`, and the `Errors/*`
closure that `AtomsError` → `ErrorCatalog` → `CatalogEntry` pulls in (plus its
`resources/errors.json` data file).

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
d9211327e890f2ebe551d40b2460dce095b57c0ba5e320203fffb683bfe494a2  Atom.php
e1cf8cea48ad7525422c43e4c7422c250ed66028b9d4cf3bf97f5fce5fe9e624  AtomJob.php
4a4da51d856e552242a045b68d04e7e5754e48b1825c708d0736a0b8fadc87e9  AtomMethods.php
efda00eec6a42bfdd40ed60e432a5d279c6701a99fde7a400b020249d68cce53  Attributes/MethodsFor.php
5239bdac78cdf7d4d9b6af62cd2827f807cd7b6892cf2d20c377de736b677124  Attributes/SharedWithAtoms.php
0f446033d4006ba86c48368dcce6e0dfd7e477e02f3483e540f944601d70cb62  Database.php
7c996f6c31cff9bf210040f311bb534a140e79ae3c62b721c6266ad6d78353e2  Errors/AtomsError.php
0b4bfcf9ea74ed277614139157b03696f4eae42dc85f420120f96663cf654283  Errors/CatalogEntry.php
ee937355bb4a22b02287215525faabf25d2fd0b9427f160352953a65eda13b34  Errors/ErrorCatalog.php
49aa5038e66c740f288143b63ee5366f70c6f52b8415c56a0153679e4d8841c4  Errors/ErrorCode.php
e230d8cf59d4d9c773be3f46fb4b49db948dd52279ffe08a5488d7b35718987f  Migrations/Migration.php
f433e85e2449339b31bf806c4c8dde1afbd9e06dda005a2dc5a3df62fcd1252e  Migrations/MigrationEntry.php
addfe71f9472e7f2e76422227ef06586818b546e09842cdcba8e8a97a1dcd690  Migrations/MigrationSet.php
50af83b416b9dec8f9c16e4b0fa635ef4fb5be8e38134571f66d910bded18b9d  Migrations/Migrator.php
df2fe7aa993514464f9ad5b32dedf3e3ae86da8c90b1e14112d5101a8df01382  resources/errors.json
a79559d5f3c3f2c6e50817daca583d21222aa13e10e90da317bd9cf12b4aa102  Runtime/AtomContext.php
2f7038814942c735af5d501ad51a59520237d55f848dfb6c3603ff7370fddcf4  Runtime/LifecycleInvoker.php
b765f073ca2b9e9c62834a2316a78ffe4a19bf5a2c97a6528449f13442584629  Serialization/Payload.php
1486ab89bf416b88929159b6014b2a268b081f2efd1d46a314fe4d86948d8bc8  Serialization/SerializationException.php
0b3224c2173e0fcde24b433a9373a5a1268f72a7a94e232f214e1c6bb15ec1d4  Serialization/Serializer.php
9976931fc24b29337ddddbb686432d537e45524f388cfbc60b37554af8db46f5  Websocket/Connection.php
c0a739e750b0b2558133cb5c33e6ac119ed1f1526be41587a4f2bf21b5ba63d9  Websocket/Message.php
```

Verify with, from this directory:

```sh
find . -type f -name '*.php' -o -name '*.json' | sort | xargs shasum -a 256
```
