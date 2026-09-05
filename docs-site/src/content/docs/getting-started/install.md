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
- A Cloudflare Workers Paid account

## PHP

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
composer require --dev atoms/phpstan-rules:^0.5
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

The `init` command creates `atoms.json` and `atoms-composer.json`. It also prints a command that scaffolds the matching version of the Cloudflare Worker runtime:

```bash
npm exec --yes --package=@atomsphp/runtime-cloudflare@0.5.0 -- \
  atoms-runtime-cloudflare init atoms-worker
cd atoms-worker
npm ci
cd ..
```

Then fill in the generated `atoms.json` — at minimum an `endpoint` and a
`worker_name` for each environment you deploy to. See
[Configuration](/guides/configuration/) for every key, and for which settings
belong in `atoms-worker/wrangler.jsonc` instead.

## Next

Continue with [Laravel](/getting-started/laravel/), [Symfony](/getting-started/symfony/), or [plain PHP](/getting-started/plain-php/), then [deploy the Worker](/guides/deploy/).
