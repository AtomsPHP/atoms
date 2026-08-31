---
title: Compatibility
description: The coordinated versions and supported host toolchain for Atoms 0.1.
---

Atoms releases its PHP packages, Worker runtime, and deployment Action as one compatible line.

| Component | 0.1 support |
|---|---|
| `atoms/core` | `^0.1` frozen, additive ABI |
| `atoms/client`, adapters, testing, rules, CLI | `^0.1` |
| `@atomsphp/runtime-cloudflare` | `0.1.0`, co-versioned with the release |
| Deploy Action | immutable `AtomsPHP/atoms/action@v0.1.0` |
| Host PHP | `^8.3`; tested on PHP 8.3 and PHP 8.4 |
| Guest PHP | PHP 8.3 WebAssembly |
| Node.js | 22 |
| Wrangler | 4.118.0 (exact runtime-template pin) |

Use matching 0.1 release artifacts. The CLI stamps the core ABI version into the bundle manifest, and the Worker rejects an unsupported core/runtime pairing with [ATOMS-E043](/reference/errors/#atoms-e043) instead of attempting to run it.

The runtime scaffold command printed by `atoms init` and used by the deploy Action is generated from the same release manifest as the tag. It is not an independently moving “latest” dependency.

Pre-1.0 APIs outside `atoms/core` may change between minor versions. Package patch releases remain within the declared Composer constraints; use lockfiles for repeatable application and Worker installs.