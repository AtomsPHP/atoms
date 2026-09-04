# `@atomsphp/runtime-cloudflare`

The Cloudflare runtime for Atoms. It scaffolds a deployable Worker directory
into your application — a directory you **commit** — without embedding a copy
of PHP/WebAssembly in this package.

## Scaffold it once

```sh
npm exec --yes --package=@atomsphp/runtime-cloudflare@@VERSION@ -- \
  atoms-runtime-cloudflare init atoms-worker
cd atoms-worker
npm ci
cd .. && git add atoms-worker
```

`atoms init` prints this command with the version that matches your Atoms
packages. `init` refuses a non-empty directory. `npm ci` fetches the exact
php-wasm dependency in the committed lockfile, verifies its size and SHA-256,
and stages it in the gitignored `.php-wasm/` directory.

`atoms-worker/` beside `atoms.json` is where the Atoms CLI and the deploy
Action look. Keep it somewhere else if you must, and pass `--worker-dir` to
every `atoms` command (or `worker-directory` to the Action); `atoms.json`
does not name it.

## What you own, and what the runtime owns

The directory is part of your repository, and edits to it are durable. It
holds two kinds of file, and the split is recorded in `atoms-runtime.json`
at its root, which `init` and `upgrade` write:

| Files | Owner | On `upgrade` |
|---|---|---|
| `wrangler.jsonc` | **You.** Seeded once by `init`. | Left as it is. The keys marked `RUNTIME-REQUIRED` in it are what the runtime needs; a release that changes one says so in its changelog. |
| `src/`, `php/`, `scripts/`, `release/`, `package.json`, `package-lock.json`, `.gitignore`, `README.md`, `LICENSE`, `THIRD_PARTY_NOTICES.md`, `atoms-runtime.json` | **The runtime.** | Rewritten to the release's copy. Files the release no longer ships are removed. A local edit is overwritten; `git diff` shows it, and it stays in your history. |
| Anything else you add | You. | Left alone. |

Put project settings in `wrangler.jsonc`: routes, custom domains,
observability, placement, limits, `vars`. Two `vars` are exceptions:

- **`ATOMS_SHARED_SECRET`** is a secret, never a var. Set it with
  `atoms shared-secret:set` or `wrangler secret put`; `atoms dev` provisions
  a per-machine dev secret into the gitignored `.dev.vars`.
- **`ATOMS_DEBUG_ENDPOINTS`** stays out of this file. `wrangler.jsonc` is
  shared by every environment you deploy (the CLI selects the Worker with
  `--name`, never Wrangler's `-e`), so a var here would turn the Worker's
  `/debug` routes on for staging and production alike. Set
  `"debug_endpoints": true` on one environment in `atoms.json` instead; the
  CLI forwards it to Wrangler as a `--var` on `atoms dev` and `atoms deploy`,
  and prints a line when it is in force. The routes are **off by default**.
  Under the default `ATOMS_BEARER_AUTH=required` posture they also sit behind
  the Worker's bearer check, so the flag is a second gate; under
  `ATOMS_BEARER_AUTH=disabled` (an authenticating proxy in front of the
  Worker) the flag is the **only** gate, which is why it defaults off and why
  enabling it is a per-environment declaration.

Generated files are gitignored, so a deploy never dirties the directory:
`src/bundle.generated.js` (your app, staged by `atoms deploy`/`atoms dev`),
`node_modules/`, `.php-wasm/`, `.dev.vars`, `.wrangler/`. Add project ignores
to your repository's root `.gitignore` as `atoms-worker/<path>`; the
directory's own `.gitignore` is runtime-owned.

## Upgrading

The Worker directory is released together with the Atoms Composer packages
and CLI, under one version. When you update the packages, update the
directory too — the CLI refuses to `deploy` or `dev` a directory whose
`atoms-runtime.json` names another release (`ATOMS-E108`), and prints the
command:

```sh
npm exec --yes --package=@atomsphp/runtime-cloudflare@@VERSION@ -- \
  atoms-runtime-cloudflare upgrade atoms-worker
cd atoms-worker && npm ci
```

Then review `git diff`, and commit. `upgrade` prints how many files it
wrote, which it removed, and which it left alone. If a release needs
something from your `wrangler.jsonc` — a new compatibility flag, a new
migration tag — its changelog entry says so. The version check is exact: a
directory from release A does not deploy with a CLI from release B, whatever
the distance between them.

`upgrade` needs the `atoms-runtime.json` that `init` writes. A directory
scaffolded before that file existed cannot be upgraded in place: scaffold a
fresh `atoms-worker/` with `init`, carry your `wrangler.jsonc` changes across,
and commit that.

## Licensing

Atoms-authored source in this package is MIT. The upstream component inventory
is in [`THIRD_PARTY_NOTICES.md`][notices]. This package does not contain the
php-wasm binary. The matching upstream source identity and retrieval recipe
are recorded in [`corresponding-source/`][source].

Atoms is developed in the [AtomsPHP/atoms monorepo][repository]. Please file
issues and pull requests there rather than against generated package mirrors.

[notices]: https://github.com/AtomsPHP/atoms/blob/main/cloudflare/THIRD_PARTY_NOTICES.md
[source]: https://github.com/AtomsPHP/atoms/tree/main/cloudflare/corresponding-source
[repository]: https://github.com/AtomsPHP/atoms
