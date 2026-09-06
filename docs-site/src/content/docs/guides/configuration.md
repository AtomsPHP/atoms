---
title: Configuration
description: The files that configure an Atoms project, what an environment is, and every atoms.json key.
---

Three committed files configure an Atoms project:

| File | Configures |
|---|---|
| `atoms.json` | The project itself: where your Atom code lives, and one entry per environment you deploy to |
| `atoms-composer.json` | The Composer packages that ship *inside* the Atom |
| `atoms-worker/wrangler.jsonc` | Cloudflare's view of the Worker: routes, custom domains, logging, runtime vars |

`vendor/bin/atoms init` writes the first two. The Worker scaffold command it
prints writes the third.

How your application calls a deployed Worker is configured in the application
itself, not in `atoms.json`. Set `ATOMS_ENDPOINT` in the monolith to the
Worker's URL. `ATOMS_ENVIRONMENT` is an application-side label for logging and
does not select the CLI environment or override any Worker setting. See
[Laravel](/getting-started/laravel/) or [Symfony](/getting-started/symfony/).

## Environments

An environment is a named entry under `environments` in `atoms.json`. You
choose the names; `atoms init` scaffolds `production` and `staging`:

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
            "worker_name": "my-app",
            // Optional when the credentials reach one account; required when
            // they reach more than one.
            "account_id": "",
            "debug_endpoints": false
        },
        "staging": {
            "worker_name": "my-app-staging",
            "account_id": "",
            "debug_endpoints": true
        }
    },
    "callback_url": {
        "production": "https://example.com/atoms/callback",
        "staging": "https://staging.example.com/atoms/callback"
    }
}
```

`atoms init` writes this shape with `callback_url` **empty** — an empty value
means no callback is declared, so `deploy` warns and `$this->app()` and
`$this->dispatch()` fail with
[ATOMS-E080](/reference/errors/#atoms-e080) until you fill it in. The URLs above
show a completed file.

Every Cloudflare-facing command takes `--env <name>` — `deploy`, `status`,
`rollback`, `secrets:set`, `secrets:list`, `shared-secret:set` and
`shared-secret:unset` require it; `dev` and `token` default to `staging`. The
flag selects one entry for that invocation. A build is project-wide and does
not select or resolve an environment, callback URL, or account; it does
validate the required shape of every configured environment, including its
`worker_name`.

For the file above, `vendor/bin/atoms deploy --env production` deploys to
`my-app` with `/debug` routes off. `--env staging` selects the other Worker and
its callback configuration.

`worker_name` is mandatory and must be non-empty in every configured
environment. It is the exact name passed to Wrangler; there is no fallback to
the top-level `project`.

`callback_url.<name>` is optional. An empty literal means unset. A non-empty
literal is passed to the Worker, and a whole-value expansion such as
`"${PRODUCTION_CALLBACK_URL}"` must resolve to a non-empty value or the CLI
raises [ATOMS-E070](/reference/errors/#atoms-e070). For example:

```json
{ "callback_url": { "production": "${ATOMS_CALLBACK_URL}" } }
```

The Worker requires HTTPS, except that HTTP loopback URLs such as
`http://127.0.0.1:8000/...`, `http://localhost:8000/...`, and
`http://[::1]:8000/...` are valid for local development. This URL is where the
Worker sends `app()` and `dispatch()` callbacks; it is independent of the
monolith's `ATOMS_ENDPOINT`, which points the application at the Worker for
ordinary RPC. If a deploy has no callback entry, it warns that `app()` and
`dispatch()` are unavailable and forwards no callback variable.

`account_id` may be empty. When it is empty, the CLI uses
`CLOUDFLARE_ACCOUNT_ID`; when both are non-empty they must agree, or the
command fails with [ATOMS-E070](/reference/errors/#atoms-e070). If neither is
set, Wrangler may still resolve a single reachable account. An ambiguous
Wrangler login remains an [ATOMS-E075](/reference/errors/#atoms-e075) failure.

`endpoint` is no longer part of the parsed configuration. Older files may keep
the key; it is tolerated and ignored indefinitely. The CLI never invents
or reports a Worker URL from it. Wrangler's deploy output is passed through as
Wrangler printed it, and `atoms status` reports Worker version data only.

### These are not Wrangler environments

`atoms deploy` always selects the Worker with `wrangler deploy --name`, and
never passes Wrangler's own `-e`/`--env`. Wrangler's `env.<name>` sections in
`wrangler.jsonc` therefore do not apply to anything Atoms deploys.

Put routes, custom domains, logging and runtime settings at the **top level**
of `atoms-worker/wrangler.jsonc`. That one file serves every environment,
which is why per-environment settings such as `debug_endpoints` and
`callback_url.<name>` live in `atoms.json` and are forwarded for the selected
target.

## Configuration mental model

The project file is the authority for deployment identity. The application
owns the URL it uses to reach the Worker. Wrangler owns the final deployment
output and any values configured directly in its Worker project.

| Reader | When it reads configuration | What it reads | What it does not do |
|---|---|---|---|
| `atoms build` | Build | Project paths, PHP version, Atom dependencies; validates environment shape | It does not select an environment or resolve callback/account values |
| `atoms dev` | Before starting local Wrangler | Selected `worker_name`, `account_id`, `debug_endpoints`, and callback sources | It does not require the deploy callback source to match a local override |
| `atoms deploy` | Before staging the selected target | Selected `worker_name`, `account_id`, optional file callback, and runtime vars | It does not accept `--callback-url` or fall back to an ambient callback URL |
| `atoms status` / `rollback` / secrets | Before invoking Wrangler | Selected `worker_name` and account target | Status does not claim an endpoint URL |
| Wrangler | `dev` or deploy invocation | Its own Worker project, command-line vars, credentials, and config | It does not choose the Atoms environment |
| Deployed Worker | Request and callback handling | Deployed vars/secrets, including `ATOMS_CALLBACK_URL`, and the bundle manifest | It does not read `atoms.json` or the monolith's environment |
| Laravel, Symfony, or plain PHP | Application startup and callback requests | `ATOMS_ENDPOINT`, shared secret, `ATOMS_ENVIRONMENT` for logging, and the callback route | It does not read `atoms.json` to choose its Worker |

The lifecycle is therefore: write the project file, build the manifest
artifact while validating every environment's shape, select one environment
with the invocation flag, resolve target facts from the file, and let Wrangler
deploy the bundle and its manifest. The deployed Worker then serves that
artifact and calls the monolith's callback route when an Atom uses `app()` or
`dispatch()`. The entity rule is simple: the deploy target is a file fact, the
machine and principal are environment facts, and the invocation selects one
environment with `--env`. A callback URL is resolved when `dev` or `deploy`
runs, never while a bundle is built.

## `atoms.json` keys

| Key | Required | Default or fallback |
|---|---|---|
| `project` | yes | — |
| `paths.atoms` | yes | — |
| `paths.shared` | no | `<paths.atoms>/Shared` |
| `php` | no | `8.3` |
| `environments.<name>.worker_name` | yes | — |
| `environments.<name>.account_id` | no | `CLOUDFLARE_ACCOUNT_ID` when the file value is empty |
| `callback_url.<name>` | no | empty/unset means callbacks are unavailable; literal or whole-value `${ENV_VAR}` |
| `environments.<name>.debug_endpoints` | no | `false` |

Structural problems in this file are reported as
[ATOMS-E070](/reference/errors/#atoms-e070). A legacy `endpoint` key is
accepted for migration and ignored.

`debug_endpoints` takes a JSON boolean and nothing else:
`"debug_endpoints": "false"` is refused rather than read as `true`.

`environments.<name>.region` is accepted so older files still load, and is
ignored — Cloudflare places a Durable Object itself.

## Precedence and agreement

These sources are deliberately command-specific. They are not a general
override ladder:

| Setting | Deploy | Dev |
|---|---|---|
| Callback URL | `callback_url.<name>` is authoritative when present. If `ATOMS_CALLBACK_URL` is set, it must match; it is never a fallback. With no file entry, an ambient value is undeclared and errors. | `--callback-url` and `ATOMS_CALLBACK_URL` are local sources and must match when both are present; otherwise `callback_url.<name>` is the fallback. |
| Account id | Non-empty file `account_id`; otherwise `CLOUDFLARE_ACCOUNT_ID`. If both are non-empty, they must match. | Same |
| Worker name | `worker_name` in the selected environment | `worker_name` in the selected environment |
| Worker directory | `--worker-dir` → `atoms-worker/` beside `atoms.json` | `--worker-dir` → `atoms-worker/` beside `atoms.json` |

For deploy, a callback URL supplied only through `ATOMS_CALLBACK_URL` is an
error because it is undeclared in the selected environment. A differing
ambient value is also an error. If the file has no entry and no ambient value,
deploy proceeds with a warning and no callback variable. For dev, a tunnel or
another local callback source may intentionally differ from the committed file
value; if both local sources are supplied, they must agree. All callback
values are validated by the Worker: HTTPS is required except for loopback HTTP.

## Migrating older configuration

1. Keep callback URLs in the top-level `callback_url.<environment>` map. Use a
   literal or a whole-value `${ENV_VAR}` expansion. Do not add a deploy-only
   environment variable as a replacement: an ambient `ATOMS_CALLBACK_URL` is
   an agreement check, not a fallback.
2. Remove `endpoint` when convenient. It is tolerated and ignored indefinitely.
   Put the deployed Worker URL in the monolith's
   `ATOMS_ENDPOINT` setting instead.
3. Add a non-empty `worker_name` to every environment. The top-level `project`
   is no longer used as a fallback.
4. Move account selection to the environment's `account_id` or
   `CLOUDFLARE_ACCOUNT_ID`, and make sure both values agree if both are set.
5. Remove `--callback-url` from deploy scripts. Keep it for local `dev` when a
   tunnel or local callback must differ from the file; make `--callback-url`
   and `ATOMS_CALLBACK_URL` agree when both are present.

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
