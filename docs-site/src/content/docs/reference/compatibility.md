---
title: Compatibility
description: The versions that make up Atoms 0.7 and the toolchain it supports.
---

Atoms releases its PHP packages, Worker runtime, and deploy Action together,
under one version number.

| Component | 0.7 support |
|---|---|
| `atoms/core` | `^0.7`; a stable API that grows by addition |
| `atoms/client`, adapters, testing, rules, CLI | `^0.7` |
| `atoms/database-illuminate` | `^0.7`, installed inside the Atom through `atoms-composer.json` |
| `@atomsphp/runtime-cloudflare` | `0.7.0` |
| Deploy Action | `AtomsPHP/atoms/action@v0.7.0` (the tag never moves) |

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

| Component | Dependency constraint | Current qualified toolchain | CI coverage |
|---|---|---|---|
| Host PHP | `^8.3` | PHP 8.3 and PHP 8.4 | PHP 8.3 and PHP 8.4 |
| Node.js | `>=22` | Node.js 22 | Node.js 22 |
| Wrangler | `4.118.0` (exact pin) | Wrangler 4.118.0 | Wrangler 4.118.0 |

The listed versions are the current qualified host and toolchain baseline for
the documented path. A successful install on a version allowed by a broader
dependency constraint does not qualify that version: its compatibility is not
established by current CI and support is not automatic. Use the listed baseline.
CI coverage identifies version lines, not every patch release or dependency
combination. A smoke test on a later version does not establish complete framework
support; the framework-major matrix below is separate.

Host PHP runs the application, Composer, Artisan, the Atoms CLI, and tests. The
Worker uses a PHP 8.3 interpreter compiled to WebAssembly,
supplied by the runtime release. Selecting host PHP does not select or recompile
that guest interpreter.

## Laravel and Illuminate

### Application-side dependency compatibility

`atoms/laravel` allows Laravel 11, 12 and 13 through its Illuminate dependencies. These are dependency constraints;
they do not imply that every version is covered by CI.

### CI combinations

The test suite runs each Laravel version below with each listed host PHP version.

| Laravel | Orchestra Testbench constraint | Host PHP |
|---|---|---|
| Laravel 12 | `^10.0` | PHP 8.3 and PHP 8.4 |
| Laravel 13 | `^11.0` | PHP 8.3 and PHP 8.4 |

Laravel 11 is allowed by the adapter's dependencies but has no current CI coverage.

### Atom-side dependencies

The optional `atoms/database-illuminate` bridge runs inside an Atom and allows
Illuminate Database 12 and 13. Its dependencies are separate from the
application-side Laravel adapter.
