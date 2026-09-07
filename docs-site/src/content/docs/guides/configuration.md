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

`atoms init` writes this shape with `callback_url` **empty** — an empty or
whitespace-only value declares nothing, so unless `ATOMS_CALLBACK_URL` or
`--callback-url` supplies one, `deploy` warns and `$this->app()` and
`$this->dispatch()` fail with
[ATOMS-E080](/reference/errors/#atoms-e080). The URLs above show a completed
file.

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

`callback_url.<name>` is optional, and is the committed default rather than
the last word: `ATOMS_CALLBACK_URL` overrides it, and `--callback-url`
overrides both (see [Precedence](#precedence) below). An empty or
whitespace-only value means unset — from the flag and the environment variable
as well as from the file; every source normalises the same way, so a value that
is only whitespace never wins precedence over a real one. A non-empty literal
is passed to the Worker, and a whole-value reference such as
`"${PRODUCTION_CALLBACK_URL}"` — read only when neither the flag nor
`ATOMS_CALLBACK_URL` supplied a value — must resolve to a non-empty value or
`deploy` raises [ATOMS-E070](/reference/errors/#atoms-e070); on `atoms dev` an
unresolved reference is a warning and no callback (see
[Precedence](#precedence)). For example:

```json
{ "callback_url": { "production": "${ATOMS_CALLBACK_URL}" } }
```

The Worker requires HTTPS, except that HTTP loopback URLs such as
`http://127.0.0.1:8000/...`, `http://localhost:8000/...`, and
`http://[::1]:8000/...` are valid for local development. This URL is where the
Worker sends `app()` and `dispatch()` callbacks; it is independent of the
monolith's `ATOMS_ENDPOINT`, which points the application at the Worker for
ordinary RPC. A missing file entry is not by itself a missing callback: only
when no source at all supplies one — no `--callback-url`, no
`ATOMS_CALLBACK_URL`, no file entry — does deploy warn that `app()` and
`dispatch()` are unavailable and forward no callback variable.

`account_id` may be empty. A non-empty `CLOUDFLARE_ACCOUNT_ID` in the
environment wins over whatever the file says, on every command; there is no
`--account-id` flag, and the two values are never compared. If neither is set,
Wrangler may still resolve a single reachable account. An ambiguous Wrangler
login remains an [ATOMS-E075](/reference/errors/#atoms-e075) failure.

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

The project file is the committed default for deployment identity, overridden
by the process environment and then by an explicit flag. The application owns
the URL it uses to reach the Worker. Wrangler owns the final deployment output
and any values configured directly in its Worker project.

| Reader | When it reads configuration | What it reads | What it does not do |
|---|---|---|---|
| `atoms build` | Build | Project paths, PHP version, Atom dependencies; validates environment shape | It does not select an environment or resolve callback/account values |
| `atoms dev` | Before starting local Wrangler | Selected `worker_name`, `debug_endpoints`, the account id and the callback URL in the one precedence order | It does not need an account at all — `wrangler dev` runs workerd locally |
| `atoms deploy` | Before staging the selected target | Selected `worker_name`, runtime vars, and the account id and callback URL in the same order as `dev` | It does not compare two sources or refuse a value for differing from the file |
| `atoms status` / `rollback` / secrets | Before invoking Wrangler | Selected `worker_name` and account target | Status does not claim an endpoint URL |
| Wrangler | `dev` or deploy invocation | Its own Worker project, command-line vars, credentials, and config | It does not choose the Atoms environment |
| Deployed Worker | Request and callback handling | Deployed vars/secrets, including `ATOMS_CALLBACK_URL`, and the bundle manifest | It does not read `atoms.json` or the monolith's environment |
| Laravel, Symfony, or plain PHP | Application startup and callback requests | `ATOMS_ENDPOINT`, shared secret, `ATOMS_ENVIRONMENT` for logging, and the callback route | It does not read `atoms.json` to choose its Worker |

The lifecycle is therefore: write the project file, build the manifest
artifact while validating every environment's shape, select one environment
with the invocation flag, resolve target facts — flag, then environment, then
file — and let Wrangler deploy the bundle and its manifest. The deployed Worker then serves that
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
| `environments.<name>.account_id` | no | overridden by `CLOUDFLARE_ACCOUNT_ID`; used when that is unset |
| `callback_url.<name>` | no | overridden by `--callback-url` and `ATOMS_CALLBACK_URL`; empty, whitespace-only or unset means the file supplies nothing, not that callbacks are unavailable; literal or whole-value `${ENV_VAR}` |
| `environments.<name>.debug_endpoints` | no | `false` |

Structural problems in this file are reported as
[ATOMS-E070](/reference/errors/#atoms-e070). A legacy `endpoint` key is
accepted for migration and ignored.

`debug_endpoints` takes a JSON boolean and nothing else:
`"debug_endpoints": "false"` is refused rather than read as `true`.

`environments.<name>.region` is accepted so older files still load, and is
ignored — Cloudflare places a Durable Object itself.

## Precedence

**One order, for every value and every command: flag, then environment, then
file.** The nearer source wins, silently. Nothing is compared against anything
else, no combination of sources is an error, and there is no agreement check
anywhere — the model the AWS CLI and npm document for their own
configuration.

| Setting | Flag | Environment | File |
|---|---|---|---|
| Callback URL | `--callback-url`, on `deploy` and `dev` | `ATOMS_CALLBACK_URL`, on `deploy` and `dev` | `callback_url.<name>` |
| Account id | — (there is no `--account-id`) | `CLOUDFLARE_ACCOUNT_ID` | `environments.<name>.account_id` |
| Worker name | — | — | `worker_name`, required |
| Debug endpoints | — | — | `debug_endpoints`, default `false` |
| Worker directory | `--worker-dir` | — | not a key; `atoms-worker/` beside `atoms.json` |

The file is the committed default, which is why a tunnel host or a local port —
a fact about a machine rather than about the deployment — can be exported as
`ATOMS_CALLBACK_URL` or passed as `--callback-url` without editing anything
that is committed.

`atoms dev` resolves in exactly the same order as `atoms deploy`. It differs in
two ways that are not about precedence: it needs no account, since
`wrangler dev` runs workerd locally, and a file `${ENV_VAR}` reference that
resolves to nothing is simply no callback plus a warning, where `deploy` makes
it [ATOMS-E070](/reference/errors/#atoms-e070) — the variable may be one only
CI holds.

The file entry is **read** only when neither the flag nor `ATOMS_CALLBACK_URL`
already supplied a value, so a reference naming a variable that is unset here
cannot fail when a nearer source answered. That short-circuit is deliberate,
and it covers malformed entries too: a value containing `${` that is not a
whole-value `${NAME}` — `"https://${HOST}/callback"`, say — is
[ATOMS-E070](/reference/errors/#atoms-e070) **when the file wins**, and is
never inspected at all when the flag or the variable answered, so the command
succeeds.

A blank value is not a value. An empty or whitespace-only string means "unset"
in every source alike — `--callback-url`, `ATOMS_CALLBACK_URL`,
`CLOUDFLARE_ACCOUNT_ID` and the file entries — so a blank nearer source falls
through to the next rather than winning with nothing. Resolved values are
trimmed.

If nothing supplies a callback at all, deploy proceeds with a warning and
forwards no callback variable. All callback values are validated by the Worker:
HTTPS is required except for loopback HTTP.

## Migrating older configuration

1. Keep callback URLs in the top-level `callback_url.<environment>` map, as a
   literal or a whole-value `${ENV_VAR}` reference. It is the committed default
   for `deploy` and `dev` alike.
2. Remove `endpoint` when convenient. It is tolerated and ignored indefinitely.
   Put the deployed Worker URL in the monolith's
   `ATOMS_ENDPOINT` setting instead.
3. Add a non-empty `worker_name` to every environment. The top-level `project`
   is no longer used as a fallback.
4. Move account selection to the environment's `account_id`, to
   `CLOUDFLARE_ACCOUNT_ID`, or to neither. Both may be set and differ; the
   variable wins.
5. Deploy scripts that pass `--callback-url`, or that export
   `ATOMS_CALLBACK_URL`, keep working: both override the file entry, and
   neither can collide with it.

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
