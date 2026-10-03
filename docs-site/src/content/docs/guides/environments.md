---
title: Environments
description: Select an environment, set its values, and serve its Worker on your own hostname.
---

An environment is a named entry under `environments` in `atoms.json`, and each
one deploys to its own Worker. `atoms init` creates `production` and `staging`,
and you can rename them or add more. [Configuration](/guides/configuration/#atomsjson)
lists every key an environment accepts.

## Selecting an environment

Pass `--env <name>` to each command that talks to Cloudflare: `deploy`, `dev`,
`status`, `rollback`, `secrets:set`, `secrets:list`, `shared-secret:set` and
`shared-secret:unset`. For example, this deploys the Worker named in the
`staging` entry, with that entry's settings:

```bash
vendor/bin/atoms deploy --env staging
```

`atoms build` covers the whole project and takes no `--env`.

## Where values come from

The callback URL, Cloudflare account ID and Cloudflare API token can each be
set in more than one place. A command uses the first of these that has a value:

1. A command-line flag
2. Your shell or CI environment
3. `.env.atoms.<environment>`
4. `atoms.json`

| Setting | Flag | Variable | `atoms.json` key |
|---|---|---|---|
| Callback URL | `--callback-url` (`deploy` and `dev`) | `ATOMS_CALLBACK_URL` | `callback_url` |
| Account ID | — | `CLOUDFLARE_ACCOUNT_ID` | `account_id` |
| API token | — | `CLOUDFLARE_API_TOKEN` | — |

The variable has the same name in your shell and in `.env.atoms.<environment>`.
An empty value is skipped. Every other setting comes from `atoms.json` alone.

Commit values your whole team shares to `atoms.json`. In CI, set the API
token and anything else that varies per deployment as job variables. On your
own machine, use `.env.atoms.<environment>`.

`atoms deploy` and `atoms dev` print each value and where it came from before
they start:

```text
Environment: production
  Worker:       my-app                              (atoms.json)
  Account:      cf-account-1234                     (caller environment: CLOUDFLARE_ACCOUNT_ID)
  API token:    (hidden)                            (.env.atoms.production: CLOUDFLARE_API_TOKEN)
  Callback:     https://example.com/atoms/callback  (atoms.json "environments.production.callback_url")
  Debug routes: disabled                            (atoms.json "debug_endpoints")
  Serving:      atoms.example.com                   (atoms.json "custom_domains")
```

### Placeholders in `callback_url`

Instead of a URL, `callback_url` can name an environment variable:

```json
{ "environments": { "staging": { "callback_url": "${STAGING_CALLBACK_URL}" } } }
```

The CLI looks the variable up in your shell and in `.env.atoms.<environment>`.
The placeholder must be the entire value. `atoms deploy` stops if the variable
is unset, and `atoms dev` warns and starts without a callback URL.

## Framework commands

`php artisan atoms:deploy` and `bin/console atoms:deploy` read the same sources
as `vendor/bin/atoms`, and ignore your application's `.env`. Set deployment
values in your shell, in CI, or in `.env.atoms.<environment>`.

## Custom domains

Without a custom domain, a Worker is reachable at its `workers.dev` URL. To
serve it on your own hostname, list the hostname under `custom_domains`:

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

Each hostname must be in a zone on your Cloudflare account, and the Worker
answers every request on it, so give it a hostname of its own. Cloudflare
creates the DNS record and certificate when the Worker deploys. `atoms deploy`
lists the hostnames on its `Serving:` line before it uploads anything.

:::caution
Give each hostname to one environment. A hostname listed by two environments
moves to whichever Worker deployed last. A deploy also replaces any existing
DNS record or custom domain on the hostname without asking.
:::
