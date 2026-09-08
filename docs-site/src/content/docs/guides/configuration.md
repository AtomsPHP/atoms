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

Alongside them, each deployment target may have one **uncommitted** file —
[`.env.atoms.<environment>`](#envatomsenvironment), described below. It is optional, it is gitignored, and it
exists for values that belong to one machine or one secret store rather than to
the repository.

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
            "debug_endpoints": false,
            // Where the Worker reaches your app for app() and dispatch().
            "callback_url": "https://example.com/atoms/callback"
        },
        "staging": {
            "worker_name": "my-app-staging",
            "account_id": "",
            "debug_endpoints": true,
            "callback_url": "https://staging.example.com/atoms/callback"
        }
    }
}
```

`atoms init` writes this shape with each `callback_url` **empty** — an empty or
whitespace-only value declares nothing, so unless `ATOMS_CALLBACK_URL` or
`--callback-url` supplies one, `deploy` warns and `$this->app()` and
`$this->dispatch()` fail with
[ATOMS-E080](/reference/errors/#atoms-e080). The URLs above show a completed
file.

Every Cloudflare-facing command takes `--env <name>` and **requires** it:
`deploy`, `dev`, `status`, `rollback`, `secrets:set`, `secrets:list`,
`shared-secret:set` and `shared-secret:unset`. No command invents a default —
the names are yours to choose, so there is no name Atoms could guess. (`token`
takes no `--env` at all: the bearer comes from `ATOMS_SHARED_SECRET`, which is
not per-environment.) The flag selects one entry for that invocation. A build is project-wide and does
not select or resolve an environment, callback URL, or account; it does
validate the required shape of every configured environment, including its
`worker_name`.

For the file above, `vendor/bin/atoms deploy --env production` deploys to
`my-app` with `/debug` routes off. `--env staging` selects the other Worker and
its callback configuration.

`worker_name` is mandatory and must be non-empty in every configured
environment. It is the exact name passed to Wrangler; there is no fallback to
the top-level `project`.

`callback_url` is optional, and is the committed default rather than
the last word: an `ATOMS_CALLBACK_URL` from the environment the command was
started with, or failing that from `.env.atoms.<name>`, overrides it, and
`--callback-url` overrides all three (see [Precedence](#precedence) below). An empty or
whitespace-only value means unset — from the flag and both environment layers
as well as from the file; every source normalises the same way, so a value that
is only whitespace never wins precedence over a real one. A non-empty literal
is passed to the Worker, and a whole-value reference such as
`"${PRODUCTION_CALLBACK_URL}"` — read only when no nearer source supplied a
value — must resolve to a non-empty value or `deploy` raises
[ATOMS-E070](/reference/errors/#atoms-e070); on `atoms dev` an unresolved
reference is a warning and no callback (see [Precedence](#precedence)). For
example:

```json
{ "environments": { "production": { "callback_url": "${ATOMS_CALLBACK_URL}" } } }
```

The Worker requires HTTPS, except that HTTP loopback URLs such as
`http://127.0.0.1:8000/...`, `http://localhost:8000/...`, and
`http://[::1]:8000/...` are valid for local development. This URL is where the
Worker sends `app()` and `dispatch()` callbacks; it is independent of the
monolith's `ATOMS_ENDPOINT`, which points the application at the Worker for
ordinary RPC. A missing file entry is not by itself a missing callback: only
when no source at all supplies one does deploy warn that `app()` and
`dispatch()` are unavailable and forward no callback variable.

`account_id` may be empty. A non-empty `CLOUDFLARE_ACCOUNT_ID` — from the
environment the command was started with, or failing that from
`.env.atoms.<name>` — wins over whatever `atoms.json` says, on every command; there is no
`--account-id` flag, and the values are never compared. If neither is set,
Wrangler may still resolve a single reachable account. An ambiguous Wrangler
login remains an [ATOMS-E075](/reference/errors/#atoms-e075) failure.

The CLI never reports a Worker URL. Wrangler's deploy output is passed
through as Wrangler printed it, and `atoms status` reports Worker version data
only. Where the deployed Worker is reachable from your application is the
monolith's own setting, not a deploy input.

### These are not Wrangler environments

`atoms deploy` always selects the Worker with `wrangler deploy --name`, and
never passes Wrangler's own `-e`/`--env`. Wrangler's `env.<name>` sections in
`wrangler.jsonc` therefore do not apply to anything Atoms deploys.

Using them would mean naming every environment twice — once here, once in
`wrangler.jsonc` — with nothing checking the two agree, and Wrangler's
non-inheritable keys would force each block to restate the Durable Object
binding and migrations that `atoms-runtime-cloudflare upgrade` owns. The
rationale in full is in
[`docs/cloudflare-toolchain.md`](https://github.com/AtomsPHP/atoms/blob/main/docs/cloudflare-toolchain.md).

Put logging, placement, limits and other runtime settings at the **top level**
of `atoms-worker/wrangler.jsonc`. That one file serves every environment,
which is why per-environment settings such as `debug_endpoints`,
`callback_url`, `routes` and `custom_domains` live in `atoms.json` and are
forwarded for the selected target.

### Where each environment is served

`routes` and `custom_domains` name the hostnames an environment's Worker
answers on. Both are optional; with neither, the Worker is reachable at its
`workers.dev` URL only.

```jsonc
"environments": {
    "production": {
        "worker_name": "my-app",
        // Patterns on a zone you already have. `wrangler deploy --route`.
        "routes": ["my-app.example.com/*"],
        // Hostnames Cloudflare also creates DNS for. `wrangler deploy --domain`.
        "custom_domains": ["atoms.example.com"]
    },
    "staging": {
        "worker_name": "my-app-staging",
        "custom_domains": ["atoms-staging.example.com"]
    }
}
```

:::danger
**Do not declare routes or custom domains in `atoms-worker/wrangler.jsonc`.**
That file is shared by every environment — `atoms deploy` selects the Worker
with `--name` and never passes Wrangler's `-e` — so a hostname there ships with
*every* deploy. Both kinds break, differently:

- **A custom domain moves.** Cloudflare hands it to whichever Worker deployed
  last, with no error on either deploy. Your production hostname ends up on the
  staging Worker, and production traffic reaches staging code with nothing to
  see.
- **A route is refused.** Cloudflare rejects a pattern already assigned to
  another Worker, so it cannot be stolen — but the deploy fails *after the
  script has uploaded*. That environment ends up running new code behind stale
  routing, and the command exits with
  [ATOMS-E110](/reference/errors/#atoms-e110), which says so.

`atoms deploy` warns when it finds routing in that file.
:::

Routes also need more Cloudflare permission than the rest of a deploy: Zone →
Workers Routes → Edit and Zone → Zone → Read on the zone, on top of the
account's Workers Scripts → Edit. Without them a route attach fails with
`Authentication error [code: 10000]`, once again after the script has uploaded
— also [ATOMS-E110](/reference/errors/#atoms-e110).
Custom domains need no zone grant. See [Authenticate with
Cloudflare](/guides/deploy/#authenticate-with-cloudflare).

`atoms deploy` prints the hostnames it is about to claim, on the `Serving:`
row of its resolved-configuration table, before it uploads anything.

## Configuration mental model

The project file is the committed default for deployment identity, overridden
by the target's environment file, then by the environment the command was
started with, then by an explicit flag. The application owns the URL it uses to
reach the Worker, and its own configuration never crosses into a deployment.
Wrangler owns the final deployment output and any values configured directly in
its Worker project.

| Reader | When it reads configuration | What it reads | What it does not do |
|---|---|---|---|
| `atoms build` | Build | Project paths, PHP version, Atom dependencies; validates environment shape | It does not select an environment or resolve callback/account values |
| `atoms dev` | Before starting local Wrangler | Selected `worker_name`, `debug_endpoints`, the account id and the callback URL in the one precedence order | It does not need an account at all — `wrangler dev` runs workerd locally |
| `atoms deploy` | Before staging the selected target | Selected `worker_name`, runtime vars, and the account id and callback URL in the same order as `dev` | It does not compare two sources or refuse a value for differing from the file |
| `.env.atoms.<name>` | Read once the target is selected, on **every** command that takes `--env` and talks to Cloudflare | Whatever it sets, below the environment the command was started with | It is never read for another target, and never as a fallback for the app's `.env` |
| `atoms status` / `rollback` / secrets | Before invoking Wrangler | Selected `worker_name`, and the account id and API token in the same precedence order as `deploy` | They resolve no callback URL, and status does not claim an endpoint URL |
| Wrangler | `dev` or deploy invocation | Its own Worker project, command-line vars, credentials, and config | It does not choose the Atoms environment |
| Deployed Worker | Request and callback handling | Deployed vars/secrets, including `ATOMS_CALLBACK_URL`, and the bundle manifest | It does not read `atoms.json` or the monolith's environment |
| Laravel, Symfony, or plain PHP | Application startup and callback requests | `ATOMS_ENDPOINT`, shared secret, `ATOMS_ENVIRONMENT` for logging, and the callback route | It does not read `atoms.json` to choose its Worker |

The lifecycle is therefore: write the project file, build the manifest
artifact while validating every environment's shape, select one environment
with the invocation flag, resolve target facts — flag, then the environment the
command was started with, then `.env.atoms.<name>`, then `atoms.json` — and let
Wrangler deploy the bundle and its manifest. The deployed Worker then serves that
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
| `environments.<name>.account_id` | no | overridden by `CLOUDFLARE_ACCOUNT_ID` from either environment layer; used when that is unset |
| `environments.<name>.callback_url` | no | overridden by `--callback-url` and by `ATOMS_CALLBACK_URL` from either environment layer; empty, whitespace-only or unset means the file supplies nothing, not that callbacks are unavailable; literal or whole-value `${ENV_VAR}` |
| `environments.<name>.debug_endpoints` | no | `false` |
| `environments.<name>.routes` | no | `[]`; route patterns, forwarded as `wrangler deploy --route` |
| `environments.<name>.custom_domains` | no | `[]`; hostnames, forwarded as `wrangler deploy --domain` |

Structural problems in this file are reported as
[ATOMS-E070](/reference/errors/#atoms-e070).

`debug_endpoints` takes a JSON boolean and nothing else:
`"debug_endpoints": "false"` is refused rather than read as `true`.

## `.env.atoms.<environment>`

Each deployment target may have one optional file beside `atoms.json`, named
after the target:

```text
.env.atoms.staging
.env.atoms.production
```

`--env production` selects `atoms.json`'s `production` entries **and**
`.env.atoms.production`, in that order — the target is chosen first, and
nothing else may change it. `atoms init` adds `/.env.atoms.*` to `.gitignore`,
because the reason this file exists is values that should not be committed: a
Cloudflare API token for local deploys, a callback URL pointing at your own
tunnel. Anything shared and non-secret belongs in `atoms.json` instead.

```bash
# .env.atoms.production
CLOUDFLARE_API_TOKEN=…
ATOMS_CALLBACK_URL=https://example.com/atoms/callback
```

The syntax is `KEY=value` per line; `export KEY=value` is accepted; `#` starts
a comment line; values may be bare, `'single-quoted'` (literal) or
`"double-quoted"` (`\n`, `\t`, `\"`, `\\` unescape). There is no
interpolation and no multi-line value — the file supplies values, it does not
compute them. A file that exists but cannot be read or parsed is
[ATOMS-E109](/reference/errors/#atoms-e109), naming the file and the line.

**Only the named target's file is read.** There is no fallback to your
application's `.env`, to another target's file, to a generic `.env.atoms`, or
to framework variants such as `.env.local`. That is the point: a value you keep
for local work cannot decide a production deployment by accident.

### Framework commands read the same sources

`php artisan atoms:deploy` and `bin/console atoms:deploy` run after your
framework has already loaded the application's `.env`. Those values stay on the
application's side of the process boundary: the wrappers hand the `atoms`
binary the environment the *command* was started with, not the one the
framework built. So a local `ATOMS_CALLBACK_URL` in your app's `.env` does not
reach a deploy, while one exported by your shell or set as a CI job variable
still does — and it resolves identically whether you go through the framework
or run `vendor/bin/atoms` directly.

## Precedence

**One order, for every value and every command:**

```text
explicit CLI flag
    > the environment the command was started with (shell, CI, supervisor)
    > .env.atoms.<environment>, beside atoms.json
    > the selected entry in atoms.json
```

The nearer source wins, silently. Nothing is compared against anything else, no
combination of sources is an error, and there is no agreement check anywhere —
the model the AWS CLI and npm document for their own configuration. A setting
with no flag simply has no flag layer.

The variable names in the two environment columns are the same names; only
which of the two supplied the value differs, and the deploy output says which.

| Setting | 1. Flag | 2. Caller's environment | 3. `.env.atoms.<name>` | 4. `atoms.json` |
|---|---|---|---|---|
| Callback URL | `--callback-url`, on `deploy` and `dev` | `ATOMS_CALLBACK_URL` | `ATOMS_CALLBACK_URL` | `environments.<name>.callback_url` |
| Account id | — (there is no `--account-id`) | `CLOUDFLARE_ACCOUNT_ID` | `CLOUDFLARE_ACCOUNT_ID` | `environments.<name>.account_id` |
| API token | — (a credential in argv is visible to every process) | `CLOUDFLARE_API_TOKEN` | `CLOUDFLARE_API_TOKEN` | — (never in a committed file) |
| Worker name | — | — | — | `worker_name`, required |
| Debug endpoints | — | — | — | `debug_endpoints`, default `false` |
| Routes, custom domains | — | — | — | `routes`, `custom_domains`, default `[]` |
| Worker directory | `--worker-dir` | — | — | not a key; `atoms-worker/` beside `atoms.json` |

Because silent precedence is easy to be surprised by, `deploy` and `dev` print
what they resolved and which source supplied it — before the build, before
anything is staged, and before `dev` touches a dev secret. That output is the
answer to "why did it use that URL". In it, layer 2 is labelled
`caller environment`, and layer 3 by the file's own name:

```text
  Callback:  https://example.com/atoms/callback  (.env.atoms.production: ATOMS_CALLBACK_URL)
  Account:   cf-account-1234                     (caller environment: CLOUDFLARE_ACCOUNT_ID)
```

`atoms.json` is the committed default, which is why a tunnel host or a local
port — a fact about a machine rather than about the deployment — goes in
`.env.atoms.<name>` or in `--callback-url`, without editing anything committed.

`atoms dev` resolves in exactly the same order as `atoms deploy`. It differs in
two ways that are not about precedence: it needs no account, since
`wrangler dev` runs workerd locally, and a file `${ENV_VAR}` reference that
resolves to nothing is simply no callback plus a warning, where `deploy` makes
it [ATOMS-E070](/reference/errors/#atoms-e070) — the variable may be one only
CI holds.

The `atoms.json` entry is **read** only when no nearer source already supplied
a value, so a reference naming a variable that is unset here cannot fail when a
nearer source answered. That short-circuit is deliberate, and it covers
malformed entries too: a value containing `${` that is not a whole-value
`${NAME}` — `"https://${HOST}/callback"`, say — is
[ATOMS-E070](/reference/errors/#atoms-e070) **when the file wins**, and is
never inspected at all otherwise, so the command succeeds. A reference resolves
through the same two environment layers as everything else, and through nothing
else.

A blank value is not a value. An empty or whitespace-only string means "unset"
in every source alike — the flag, both environment layers, and the `atoms.json`
entries — so a blank nearer source falls through to the next rather than
winning with nothing. Resolved values are trimmed.

If nothing supplies a callback at all, deploy proceeds with a warning and
forwards no callback variable. All callback values are validated by the Worker:
HTTPS is required except for loopback HTTP.

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
