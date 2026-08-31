---
title: Install
description: Requirements, PHP packages, the Cloudflare runtime, and initial project setup.
---

:::caution[Pre-1.0]
Atoms is released on the `0.1` line. The `atoms/core` runtime ABI is frozen and additive; other pre-1.0 APIs may still change between minor releases.
:::

## Requirements

- PHP 8.3 or 8.4 on the application host.
- Composer 2.
- Node.js 22 for the Cloudflare Worker toolchain.
- A Cloudflare Workers Paid account. Free-plan support is outside the 0.1 support boundary.

## Install the PHP side

Choose the adapter for the application you already have:

```bash
# Laravel
composer require atoms/laravel:^0.1

# Symfony
composer require atoms/symfony:^0.1

# Framework-free
composer require atoms/client:^0.1
```

Install the CLI for development and deployment:

```bash
composer require --dev atoms/cli:^0.1
```

Add the static rules that protect the PHP↔Worker boundary:

```bash
composer require --dev atoms/phpstan-rules:^0.1
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

The command creates `atoms.json` and `atoms-composer.json`. It does not download a deployment toolchain. Scaffold the exact co-versioned Worker runtime printed by `atoms init`:

```bash
npm exec --yes --package=@atomsphp/runtime-cloudflare@0.1.0 -- \
  atoms-runtime-cloudflare init .atoms/worker
cd .atoms/worker
npm ci
```

The template includes its lockfile. `npm ci` fetches the pinned PHP/WebAssembly dependency, verifies its hashes, and stages it in a gitignored directory.

## Choose the source paths

Keep Atom code separate from framework-only code. A typical Laravel project uses:

```json
{
  "project": "my-app",
  "paths": {
    "atoms": "app/Atoms",
    "shared": "app/Atoms/Shared"
  },
  "environments": {
    "production": {
      "worker_dir": ".atoms/worker",
      "account_id": "your-cloudflare-account-id"
    }
  }
}
```

Treat the generated files as configuration, not credentials. Cloudflare tokens stay in environment variables and are passed directly to Wrangler.

## Next

Continue with [Laravel](/getting-started/laravel/), [Symfony](/getting-started/symfony/), or [plain PHP](/getting-started/plain-php/), then [deploy the Worker](/guides/deploy/).
