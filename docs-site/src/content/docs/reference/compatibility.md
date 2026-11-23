---
title: Compatibility
description: The versions that make up Atoms 0.10 and the toolchain it supports.
---

Atoms releases its PHP packages, Worker runtime, and deploy Action together,
under one version number.

| Component | 0.10 support |
|---|---|
| `atoms/core` | `^0.10`; a stable API that grows by addition |
| `atoms/client`, adapters, testing, rules, CLI | `^0.10` |
| `atoms/database-illuminate` | `^0.10`, installed inside the Atom through `atoms-composer.json` |
| `@atomsphp/runtime-cloudflare` | `0.10.0` |
| Deploy Action | `AtomsPHP/atoms/action@v0.10.0` (the tag never moves) |

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
| Node.js | `>=22.12.0` | Node.js 22 |
| Wrangler | `4.165.0` | Wrangler 4.165.0 |

Use the supported versions above. Dependency constraints may allow newer
versions that Atoms has not tested.

Host PHP runs your application and CLI. The Worker release supplies its own
PHP 8.3 interpreter compiled to WebAssembly.
Atom classes, Support code, shared code included in the bundle, migrations,
and bundled dependencies must use PHP 8.3-compatible syntax,
even when the application host uses a newer PHP version.

## Worker PHP extensions

The bundled PHP 8.3 WebAssembly runtime loads these PHP extensions.
`atoms validate` and `atoms build` check Atom code against this list
([ATOMS-E019](/reference/errors/#atoms-e019)), and `atoms build` supplies them
to Composer when resolving `atoms-composer.json` dependencies:

`bcmath`, `calendar`, `core`, `ctype`, `curl`, `date`, `dom`, `exif`, `fileinfo`, `filter`, `gd`, `hash`, `iconv`, `json`, `libxml`, `mbstring`, `openssl`, `pcre`, `pdo`, `pdo_sqlite`, `phar`, `random`, `reflection`, `session`, `simplexml`, `soap`, `spl`, `sqlite3`, `standard`, `tokenizer`, `xml`, `xmlreader`, `xmlwriter`, `zend opcache` (Composer: `ext-zend-opcache`), `zip`, `zlib`.

`core` and `standard` are provided as part of `php` in Composer rather than as
separate `ext-*` platform packages.

Some extensions compiled into upstream `php-wasm` behave differently inside the
Cloudflare Workers WebAssembly sandbox:

- **SQLite (`pdo`, `pdo_sqlite`, `sqlite3`)**: Use `$this->db()` or `$this->db()->pdo()`
  for persistent storage backed by Durable Object SQLite (see
  [PDO compatibility](/reference/pdo/)). Standalone `sqlite3` or `pdo_sqlite`
  connections write only to the ephemeral in-memory filesystem and do not
  survive eviction.
- **Networking (`curl`, `soap`)**: The WebAssembly guest has no direct outbound
  network sockets. Move outbound HTTP calls to a [Methods class](/guides/methods/)
  via `$this->app()` or an [AtomJob](/guides/jobs/) via `$this->dispatch()`.
- **Sessions (`session`)**: PHP session files live in ephemeral guest memory;
  store persistent Atom state in SQLite via `$this->db()`.

## Laravel and Illuminate

`atoms/laravel` accepts Laravel 11, 12 and 13. CI tests these combinations:

| Laravel | Orchestra Testbench constraint | Host PHP |
|---|---|---|
| Laravel 12 | `^10.0` | PHP 8.3, PHP 8.4 and PHP 8.5 |
| Laravel 13 | `^11.0` | PHP 8.3, PHP 8.4, PHP 8.5 and PHP 8.6 |

Laravel 11 has no current CI coverage.

The optional `atoms/database-illuminate` bridge runs inside an Atom and allows
Illuminate Database 12 and 13, independently of your application's Laravel version.
