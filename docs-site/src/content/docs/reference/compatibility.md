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
| Host PHP | `^8.3`; tested on PHP 8.3 and PHP 8.4 |
| Guest PHP | PHP 8.3 WebAssembly |
| Node.js | 22 |
| Wrangler | 4.118.0, pinned exactly in the Worker's `package.json` |

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
