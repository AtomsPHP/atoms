---
title: Environments
description: What an environment is, how --env selects one, where each of its values comes from, and where its Worker is served.
---

An environment is a named entry under `environments` in `atoms.json`: one
Worker, and the settings that belong to it. You choose the names; `atoms init`
scaffolds `production` and `staging`:

```jsonc
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
```

`atoms init` writes this shape with each `callback_url` empty. The URLs above
show a completed file.

## Selecting one

Every Cloudflare-facing command takes `--env <name>` and requires it:
`deploy`, `dev`, `status`, `rollback`, `secrets:set`, `secrets:list`,
`shared-secret:set` and `shared-secret:unset`. The names are yours, so there is
no name Atoms could guess, and no command supplies a default. The flag selects
one entry for that invocation: for the file above,
`vendor/bin/atoms deploy --env production` deploys to `my-app` with `/debug`
routes off, and `--env staging` selects the other Worker and its callback
configuration.

Two commands stand apart. `atoms build` is project-wide: it selects no
environment and resolves no callback URL or account, though it validates the
required shape of every configured environment, including its `worker_name`.
`atoms token` takes no `--env` at all, because the bearer comes from
`ATOMS_SHARED_SECRET`, which is not per-environment.

## What an entry holds

`worker_name` is mandatory and must be non-empty. It is the exact name passed
to Wrangler; the top-level `project` names the project and never stands in for
it.

`debug_endpoints` turns the Worker's `/debug` routes on for that environment.
It is a boolean, default `false`.

`account_id` names the Cloudflare account to deploy into. It may be empty: when
the credentials reach one account, Wrangler resolves it alone. When they reach
more than one, set it here or set `CLOUDFLARE_ACCOUNT_ID`, which wins over the
file. An ambiguous Wrangler login is
[ATOMS-E075](/reference/errors/#atoms-e075).

`callback_url` is where the Worker reaches your application for `app()` and
`dispatch()`. It is the committed default, and any nearer source overrides it.
The Worker requires HTTPS, except that HTTP loopback URLs such as
`http://127.0.0.1:8000/...`, `http://localhost:8000/...` and
`http://[::1]:8000/...` are valid for local development. It is independent of
the monolith's `ATOMS_ENDPOINT`, which points the application at the Worker
for ordinary RPC. When no source at all supplies a callback URL, `deploy`
warns, forwards no callback variable, and `$this->app()` and
`$this->dispatch()` fail with [ATOMS-E080](/reference/errors/#atoms-e080).

`custom_domains` names the hostnames the Worker answers on. It is covered in
[Where each environment is served](#where-each-environment-is-served).

The CLI never reports a Worker URL. Wrangler's deploy output is passed through
as Wrangler printed it, and `atoms status` reports Worker version data only.
Where the deployed Worker is reachable from your application is the monolith's
own setting, not a deploy input.

## Precedence

`account_id` and `callback_url` are committed defaults, and the API token is
never committed at all. Each of those values resolves in one order, for every
command:

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
| Custom domains | — | — | — | `custom_domains`, default `[]` |
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

A blank value is not a value. An empty or whitespace-only string means "unset"
in every source alike — the flag, both environment layers, and the `atoms.json`
entries — so a blank nearer source falls through to the next rather than
winning with nothing. Resolved values are trimmed. A missing file entry is
therefore not by itself a missing callback: only when no source at all supplies
one does `deploy` warn.

### References in `atoms.json`

The value can be a URL or a placeholder for an environment variable using the
syntax `${VAR_NAME}`:

```json
{ "environments": { "production": { "callback_url": "${PRODUCTION_CALLBACK_URL}" } } }
```

A placeholder is looked up in the same two environment layers as everything
else, and nowhere else. The placeholder has to be the entire value; the CLI
does not build a URL out of a variable and other text, so
`"https://${HOST}/callback"` is rejected. On `deploy` the variable must be set
to a non-empty value or the command raises
[ATOMS-E070](/reference/errors/#atoms-e070); on `dev` an unset variable is a
warning and no callback.

The `atoms.json` entry is **read** only when no nearer source already supplied
a value, so a reference naming a variable that is unset cannot fail when a
nearer source answered. That short-circuit is deliberate, and it covers
malformed entries too: a rejected placeholder is
[ATOMS-E070](/reference/errors/#atoms-e070) **when the file wins**, and is
never inspected at all otherwise, so the command succeeds.

## `.env.atoms.<environment>`

Each environment may have one optional, uncommitted file beside `atoms.json`,
named after it:

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

The file is read on every command that takes `--env` and talks to Cloudflare,
once the target is selected. **Only the named target's file is read.** There is
no fallback to your application's `.env`, to another target's file, to a
generic `.env.atoms`, or to framework variants such as `.env.local`. That is
the point: a value you keep for local work cannot decide a production
deployment by accident.

### Framework commands read the same sources

`php artisan atoms:deploy` and `bin/console atoms:deploy` run after your
framework has already loaded the application's `.env`. Those values stay on the
application's side of the process boundary: the wrappers hand the `atoms`
binary the environment the *command* was started with, not the one the
framework built. So a local `ATOMS_CALLBACK_URL` in your app's `.env` does not
reach a deploy, while one exported by your shell or set as a CI job variable
still does — and it resolves identically whether you go through the framework
or run `vendor/bin/atoms` directly.

## Where each environment is served

`custom_domains` names the hostnames an environment's Worker answers on. It is
optional; without it, the Worker is reachable at its `workers.dev` URL only.

```jsonc
"environments": {
    "production": {
        "worker_name": "my-app",
        "custom_domains": ["atoms.example.com"]
    },
    "staging": {
        "worker_name": "my-app-staging",
        "custom_domains": ["atoms-staging.example.com"]
    }
}
```

Each hostname must be in a zone on your Cloudflare account. Cloudflare creates
the DNS record and certificate for it when the Worker deploys, and the Worker
answers every request on that hostname.

There is no `routes` key. A Cloudflare route pattern can send part of a
hostname, such as `app.example.com/atoms/*`, to a Worker, but the Atoms Worker
serves only its own paths, so a request arriving under a prefix would be
answered with `not_found`. Declare a whole hostname as a custom domain instead.
Routes declared at the top level of `atoms-worker/wrangler.jsonc` are left out
of the config `atoms deploy` generates, and the deploy prints a notice naming
them.

Two things about custom domains do not fail loudly, and both were measured
against a real account:

- **A hostname claimed by two environments moves.** Cloudflare hands it to
  whichever Worker deployed last, with no error on either deploy. Your
  production hostname ends up on the staging Worker, and production traffic
  reaches staging code with nothing to see. Give each hostname to one
  environment.
- **An existing DNS record is replaced.** If the hostname already has a DNS
  record, or is already a custom domain of another Worker, the deploy
  replaces it without asking. Wrangler would prompt in a terminal, but the CLI
  runs it as a child process, where Wrangler overrides instead.

`atoms deploy` prints the hostnames it is about to claim, on the `Serving:`
row of its resolved-configuration table, before it uploads anything. Custom
domains need no zone permission beyond the account's Workers Scripts → Edit;
see [Authenticate with Cloudflare](/guides/deploy/#authenticate-with-cloudflare).

## These are not Wrangler environments

`atoms deploy` and `atoms dev` never pass Wrangler's own `-e`/`--env`, and
Wrangler's `env.<name>` sections in `wrangler.jsonc` do not apply to anything
Atoms deploys. Instead the CLI uses Wrangler's [generated
configuration](https://developers.cloudflare.com/workers/wrangler/configuration/#generated-wrangler-configuration)
redirect. Before running Wrangler it writes a copy of your `wrangler.jsonc`
for the selected environment to `atoms-worker/.wrangler/deploy/wrangler.json`,
with a `config.json` beside it that points Wrangler at the copy, and it
removes both once Wrangler exits. Wrangler prints "Using redirected Wrangler
configuration" when it reads one.

In that copy, `name` is the environment's `worker_name`, its
`custom_domains` become Wrangler's `routes`, the callback URL and `debug_endpoints`
switch are merged into `vars`, and any `env` blocks are dropped. Everything
else in your file travels through unchanged. The path is printed on the
`Generated config:` line of the deploy output.

Naming environments in `wrangler.jsonc` as well would mean naming every
environment twice, once here and once there, with nothing checking the two
agree, and Wrangler's non-inheritable keys would force each block to restate
the Durable Object binding and migrations that `atoms-runtime-cloudflare
upgrade` owns. The rationale in full is in
[`docs/cloudflare-toolchain.md`](https://github.com/AtomsPHP/atoms/blob/main/docs/cloudflare-toolchain.md).

Put logging, placement, limits and other runtime settings at the **top level**
of `atoms-worker/wrangler.jsonc`. Those reach every environment's generated
config alike, which is why per-environment settings such as `debug_endpoints`,
`callback_url` and `custom_domains` live in `atoms.json`.

`atoms status`, `atoms rollback` and the secrets commands still name the
Worker with `--name`: Wrangler consults the redirect only for `deploy`, `dev`
and `versions upload`/`versions deploy`.
