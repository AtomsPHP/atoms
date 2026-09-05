---
title: Configuration
description: The files that configure an Atoms project, what an environment is, and every atoms.json key.
---

Four files configure an Atoms project, and the most common mistake is putting a
setting in the wrong one:

| File | Committed | Configures |
|---|---|---|
| `atoms.json` | yes | The project itself: where your Atom code lives, and one entry per environment you deploy to |
| `atoms-composer.json` | yes | The Composer packages that ship *inside* the Atom |
| `atoms-worker/wrangler.jsonc` | yes | Cloudflare's view of the Worker: routes, custom domains, logging, runtime vars |
| Your application's own config | — | How your app *calls* a deployed Worker — see [Laravel](/getting-started/laravel/) or [Symfony](/getting-started/symfony/) |

`vendor/bin/atoms init` writes the first two. The Worker scaffold command it
prints writes the third.

## Environments

An environment is a named entry under `environments` in `atoms.json`. You choose
the names; `atoms init` scaffolds `production` and `staging`:

```jsonc
{
    "project": "my-app",
    "paths": {
        "atoms": "app/Atoms",
        // DTOs or other shared code used on the Atom side and the App side:
        "shared": "app/Atoms/Shared"
    },
    "php": "8.3",
    "environments": {
        "production": {
            // A Cloudflare workers.dev subdomain or a custom domain
            "endpoint": "https://my-app.<your-subdomain>.workers.dev",
            // The Cloudflare Worker this environment deploys to
            "worker_name": "my-app",
            // Your Cloudflare account id
            "account_id": "",
            "debug_endpoints": false
        },
        "staging": {
            "endpoint": "https://my-app-staging.<your-subdomain>.workers.dev",
            "worker_name": "my-app-staging",
            "account_id": "",
            "debug_endpoints": true
        }
    },
    "callback_url": {
        // where your app mounts the callback route
        "production": "https://example.com/atoms/callback",
        "staging": "http://127.0.0.1:8000/atoms/callback"
    }
}
```

Every Cloudflare-facing command takes `--env <name>` — `deploy`, `status`,
`rollback`, `secrets:set`, `secrets:list`, `shared-secret:set` and
`shared-secret:unset` require it; `dev` and `token` default to `staging`. The
flag is a lookup of that key, and the entry it finds supplies the whole run.

Against the file above, `vendor/bin/atoms deploy --env production` deploys to
the Worker `my-app` with the `/debug` routes off, while `--env staging` deploys
the same code to the separate Worker `my-app-staging` with them on.

:::caution
Give each environment its own `worker_name`. It defaults to the top-level
`project`, so two environments that both leave it out point at one Worker and
deploy over each other.
:::

The entry's `endpoint` is not used to deploy — it is what `deploy` and `status`
report back, and the value you configure as `ATOMS_ENDPOINT` in the application
that calls this environment's Atoms.

### These are not Wrangler environments

`atoms deploy` always selects the Worker with `wrangler deploy --name`, and
never passes Wrangler's own `-e`/`--env`. Wrangler's `env.<name>` sections in
`wrangler.jsonc` therefore do not apply to anything Atoms deploys.

Put routes, custom domains, logging and runtime settings at the **top level** of
`atoms-worker/wrangler.jsonc`. That one file serves every environment, which is
why the setting that must differ between them — `debug_endpoints` — lives in
`atoms.json` instead, and is forwarded to Wrangler as a `--var` at deploy time.

## `atoms.json` keys

| Key | Required | Default or fallback |
|---|---|---|
| `project` | yes | — |
| `paths.atoms` | yes | — |
| `paths.shared` | no | `<paths.atoms>/Shared` |
| `php` | no | `8.3` |
| `environments.<name>.endpoint` | yes, within an entry | — (a trailing `/` is stripped) |
| `environments.<name>.worker_name` | no | the top-level `project` |
| `environments.<name>.account_id` | no | `CLOUDFLARE_ACCOUNT_ID`, then whichever account your Wrangler login can reach |
| `environments.<name>.debug_endpoints` | no | `false` |
| `callback_url.<name>` | no | unset — see [Callback URL](/guides/callbacks/#callback-url) |

Structural problems in this file are reported as
[ATOMS-E070](/reference/errors/#atoms-e070).

`debug_endpoints` takes a JSON boolean and nothing else: `"debug_endpoints":
"false"` is refused rather than read as `true`.

`environments.<name>.region` is accepted so older files still load, and is
ignored — Cloudflare places a Durable Object itself.

## Precedence

Two settings resolve from more than one place, and they resolve in opposite
directions. The committed file wins for the account id; the command line wins
for the callback URL.

| Setting | Order |
|---|---|
| Account id | `account_id` in `atoms.json` → `CLOUDFLARE_ACCOUNT_ID` → Wrangler's own choice |
| Callback URL | `--callback-url` → `ATOMS_CALLBACK_URL` in the environment → `callback_url.<env>` |
| Worker name | `worker_name` → `project` |
| Worker directory | `--worker-dir` → `atoms-worker/` beside `atoms.json` |

A callback URL that differs per machine — a tunnel host, a local port — is
better left out of the committed file and supplied by the environment or the
flag.

The Worker directory is a convention rather than a setting: `atoms.json` has no
key for it, because one directory serves every environment so that every
environment deploys the same runtime.

## `atoms-composer.json`

The Composer packages that ship inside the Atom, separate from your
application's own `composer.json`:

```json
{
    "require": {
        "atoms/database-illuminate": "^0.6"
    }
}
```

Only `require` and `repositories` are accepted, and required packages must be on
the Atoms allowlist; anything else is
[ATOMS-E071](/reference/errors/#atoms-e071). `atoms build` resolves the file and
writes `atoms-composer.lock` beside it — **commit that lock file**, since it is
what makes a build reproducible.

See [Deploy](/guides/deploy/) for how the vendor stage resolves, caches and
ships those packages.
