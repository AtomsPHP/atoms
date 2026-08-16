# `@atomsphp/runtime-cloudflare`

The Cloudflare runtime for Atoms. It installs a deployable Worker project in
your application without embedding a copy of PHP/WebAssembly in this package.

```sh
npm exec --yes --package=@atomsphp/runtime-cloudflare@@VERSION@ -- \
  atoms-runtime-cloudflare init .atoms/worker
cd .atoms/worker
npm ci
```

The initializer refuses to overwrite a non-empty directory. `npm ci` fetches
the exact php-wasm dependency in the shipped lockfile, verifies its size and
SHA-256, and stages it in the template's gitignored `.php-wasm/` directory.

The scaffolded directory is regenerated tooling, not configuration: the
deploy Action re-creates it on a fresh checkout, so local edits to its
`wrangler.jsonc` do not survive. Durable settings belong in your project's
`atoms.json`. In particular, the Worker's `/debug` routes are **off by
default**; to enable them for an environment, set
`"debug_endpoints": true` on that environment in `atoms.json`, and
`atoms dev`/`atoms deploy` forward it to Wrangler as a `--var`. The routes
also sit behind the Worker's bearer check under the default
`ATOMS_BEARER_AUTH=required` posture — the flag is a second gate — but under
`ATOMS_BEARER_AUTH=disabled` (an authenticating proxy in front of the
Worker) the flag is the only gate, which is why it defaults off.

Atoms-authored source in this package is MIT. The upstream component inventory
is in [`THIRD_PARTY_NOTICES.md`][notices]. This package does not contain the
php-wasm binary. The matching upstream source identity and retrieval recipe
are recorded in [`corresponding-source/`][source].

Atoms is developed in the [AtomsPHP/atoms monorepo][repository]. Please file
issues and pull requests there rather than against generated package mirrors.

[notices]: https://github.com/AtomsPHP/atoms/blob/main/cloudflare/THIRD_PARTY_NOTICES.md
[source]: https://github.com/AtomsPHP/atoms/tree/main/cloudflare/corresponding-source
[repository]: https://github.com/AtomsPHP/atoms
