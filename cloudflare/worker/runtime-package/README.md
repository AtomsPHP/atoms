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

Atoms-authored source in this package is MIT. The upstream component inventory
is in [`THIRD_PARTY_NOTICES.md`][notices]. This package does not contain the
php-wasm binary. The matching upstream source identity and retrieval recipe
are recorded in [`corresponding-source/`][source].

Atoms is developed in the [AtomsPHP/atoms monorepo][repository]. Please file
issues and pull requests there rather than against generated package mirrors.

[notices]: https://github.com/AtomsPHP/atoms/blob/main/cloudflare/THIRD_PARTY_NOTICES.md
[source]: https://github.com/AtomsPHP/atoms/tree/main/cloudflare/corresponding-source
[repository]: https://github.com/AtomsPHP/atoms
