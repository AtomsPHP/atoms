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

`atoms validate` checks built-in function and class names against these Worker
extensions. `atoms build` uses their versions when Composer resolves dependencies
in `atoms-composer.json`.

`bcmath`, `calendar`, `core`, `ctype`, `curl`, `date`, `dom`, `exif`, `fileinfo`, `filter`, `gd`, `hash`, `iconv`, `json`, `libxml`, `mbstring`, `openssl`, `pcre`, `pdo`, `pdo_sqlite`, `phar`, `random`, `reflection`, `session`, `simplexml`, `soap`, `spl`, `sqlite3`, `standard`, `tokenizer`, `xml`, `xmlreader`, `xmlwriter`, `zend opcache`, `zip`, `zlib`.

Composer includes `core` and `standard` in the `php` package. To require
`zend opcache`, use `ext-zend-opcache`.

Some extensions compiled into upstream `php-wasm` are restricted by the
Cloudflare Workers WebAssembly sandbox:

- **SQLite (`pdo`, `pdo_sqlite`, `sqlite3`)**: For persistent SQLite data, use
  `$this->db()` or its [PDO connection](/reference/pdo/). A connection you open
  directly with `pdo_sqlite` or `sqlite3` uses guest memory and loses its data
  when the Worker evicts the Atom.
- **Networking (`curl`, `soap`)**: The WebAssembly guest has no direct outbound
  network sockets. Move outbound HTTP or SOAP requests into a
  [Methods class](/guides/methods/) via `$this->app()` or an
  [AtomJob](/guides/jobs/) via `$this->dispatch()`.
- **Sessions (`session`)**: PHP session storage lives in ephemeral guest memory
  and is lost on eviction; store persistent Atom state in SQLite via `$this->db()`.

## Laravel and Illuminate

`atoms/laravel` accepts Laravel 11, 12 and 13. CI tests these combinations:

| Laravel | Orchestra Testbench constraint | Host PHP |
|---|---|---|
| Laravel 12 | `^10.0` | PHP 8.3, PHP 8.4 and PHP 8.5 |
| Laravel 13 | `^11.0` | PHP 8.3, PHP 8.4, PHP 8.5 and PHP 8.6 |

Laravel 11 has no current CI coverage.

The optional `atoms/database-illuminate` bridge runs inside an Atom and allows
Illuminate Database 12 and 13, independently of your application's Laravel version.
