---
title: Install
description: Requirements, PHP packages, the Cloudflare runtime, and initial project setup.
---

:::caution[Pre-1.0]
Atoms is currently experimental and in early stages. The goal is to keep the current `atoms/core` runtime API frozen and only add to it.
:::

## Requirements

- PHP 8.3 or 8.4 on the application host
- Composer 2
- Node.js 22 for the Cloudflare Worker toolchain
- A Cloudflare Workers Paid account for deployment

Atoms 0.7 supports deployments on Workers Paid. This is an Atoms support policy;
Free-plan operation has not been qualified. Cloudflare offers SQLite-backed
Durable Objects on Free too, but that availability does not establish compatibility
with the full Atoms runtime. See [Cloudflare plan and platform limits](/reference/limits/#cloudflare-plan-and-platform-limits).

Host PHP runs your application and the Atoms CLI. The Worker uses a guest PHP
interpreter supplied by the runtime release, independently of the host PHP version.

## PHP

<!-- BEGIN generated Laravel compatibility -->
The Laravel adapter allows Laravel 11, 12 and 13 through its Illuminate dependencies. CI tests Laravel 12 and 13. Laravel 11 is allowed but is not currently tested in CI. See [Laravel and Illuminate compatibility](/reference/compatibility/#laravel-and-illuminate).
<!-- END generated Laravel compatibility -->

Choose the adapter for the application you already have:

```bash
# Laravel
composer require atoms/laravel

# Symfony
composer require atoms/symfony

# Vanilla PHP
composer require atoms/client
```

Install the CLI for development and deployment:

```bash
composer require --dev atoms/cli
```

To use Eloquent inside your Atom (with Atom-specific models only!), you need to install a package that runs _inside_ your Atom. See [Eloquent and the query builder](/guides/eloquent/).

## Static Analysis

Add the static rules that protect the PHP↔Worker boundary:

```bash
composer require --dev atoms/phpstan-rules:^0.7
```

Then include its configuration from your PHPStan config:

```text
includes:
    - vendor/atoms/phpstan-rules/rules.neon
```

## Initialize the project

```bash
vendor/bin/atoms init
```

The `init` command creates `atoms.json` and `atoms-composer.json`, and appends `/.atoms/` and `/.env.atoms.*` to your `.gitignore`. It also prints a command that scaffolds the matching version of the Cloudflare Worker runtime:

```bash
npm exec --yes --package=@atomsphp/runtime-cloudflare@0.7.0 -- \
  atoms-runtime-cloudflare init atoms-worker
cd atoms-worker
npm ci
cd ..
```

Then fill in the generated `atoms.json` — at minimum a non-empty
`worker_name` for each environment you deploy to. Declare a
`callback_url` on that environment when its Worker must call `app()` or `dispatch()`;
the deployed Worker URL belongs in the monolith's independent
`ATOMS_ENDPOINT`. See
[Configuration](/guides/configuration/) for every key, and for which settings
belong in `atoms-worker/wrangler.jsonc` instead.

Values that belong to your machine rather than to the repository — a Cloudflare
API token for local deploys, a callback URL pointing at your own tunnel — go in
[`.env.atoms.<environment>`](/guides/configuration/#envatomsenvironment) beside
`atoms.json`, which is the gitignored file `init` just made room for. It is
optional; nothing needs one to start.

## Next

Continue with [Laravel](/getting-started/laravel/), [Symfony](/getting-started/symfony/), or [plain PHP](/getting-started/plain-php/), then [deploy the Worker](/guides/deploy/).
