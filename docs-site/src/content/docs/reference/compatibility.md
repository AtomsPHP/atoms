---
title: Compatibility
description: The versions that make up Atoms 0.9 and the toolchain it supports.
---

Atoms releases its PHP packages, Worker runtime, and deploy Action together,
under one version number.

| Component | 0.9 support |
|---|---|
| `atoms/core` | `^0.9`; a stable API that grows by addition |
| `atoms/client`, adapters, testing, rules, CLI | `^0.9` |
| `atoms/database-illuminate` | `^0.9`, installed inside the Atom through `atoms-composer.json` |
| `@atomsphp/runtime-cloudflare` | `0.9.1` |
| Deploy Action | `AtomsPHP/atoms/action@v0.9.1` (the tag never moves) |

Keep every component on the same release. If your `atoms/core` version is
outside the range the runtime supports, the Worker rejects the bundle with
[ATOMS-E043](/reference/errors/#atoms-e043).

`atoms-worker/atoms-runtime.json` records the runtime release your Worker
directory came from, and it must match the CLI's release. `atoms dev` and
`atoms deploy` check it before building and report
[ATOMS-E108](/reference/errors/#atoms-e108) if it is missing or different.
After updating the PHP packages, follow
[Upgrade the runtime](/guides/deploy/#upgrade-the-runtime).

Before 1.0, APIs outside `atoms/core` may change between minor versions. Patch
releases stay within the declared Composer constraints. Commit your lockfiles
so application and Worker installs are repeatable.

## Host and toolchain support

| Component | Dependency constraint | Supported versions, tested in CI |
|---|---|---|
| Host PHP | `^8.3` | PHP 8.3, PHP 8.4, PHP 8.5 and PHP 8.6 |
| Node.js | `>=22` | Node.js 22 |
| Wrangler | `4.118.0` | Wrangler 4.118.0 |

Use the supported versions above. Dependency constraints may allow newer
versions that Atoms has not tested.

Host PHP runs your application and CLI. The Worker release supplies its own
PHP 8.3 interpreter compiled to WebAssembly.
All bundled PHP code must remain compatible with that interpreter.

## Laravel and Illuminate

`atoms/laravel` accepts Laravel 11, 12 and 13. CI tests these combinations:

| Laravel | Orchestra Testbench constraint | Host PHP |
|---|---|---|
| Laravel 12 | `^10.0` | PHP 8.3, PHP 8.4 and PHP 8.5 |
| Laravel 13 | `^11.0` | PHP 8.3, PHP 8.4, PHP 8.5 and PHP 8.6 |

Laravel 11 has no current CI coverage.

The optional `atoms/database-illuminate` bridge runs inside an Atom and allows
Illuminate Database 12 and 13, independently of your application's Laravel version.
