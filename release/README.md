# Releasing Atoms

`manifest.json` is the machine-readable source of truth for the coordinated
PHP packages, Cloudflare runtime, deploy Action, and compatibility table.
Publication workflows reject a release tag unless its status is `ready`.

`php.tested` lists the host PHP versions CI tests. A Laravel release can set
`laravel.ci[].php_max` to cap its PHP range. The Worker suite runs on each
Node.js major in `runtime.node_tested`. After changing these fields, run
`php scripts/release/release.php generate` and `check`.

Use the release tool rather than editing version fields by hand:

```sh
php scripts/release/release.php check
php scripts/release/release.php set 0.4.1 ready
php scripts/release/release.php validate-splits
```

`check` verifies all package metadata, internal dependency constraints,
runtime-version stamps, compatibility pins, changelog entry, and mirror
artifacts. `set` updates the coordinated version and regenerates the known
stamps atomically; pass `ready` (or run `status ready` in a follow-up commit
on the same PR) so the one release pull request both bumps the version and
clears it to publish — a separate ready-only PR is unnecessary ceremony for
an ordinary release.

`set` still defaults to `candidate` when called without a status, for the one
case that genuinely needs two steps: a version that must land before a manual
preflight is finished (as the 0.1.0 mirrors bootstrap required — see
`CUTOVER.md`). There, open the version PR as `candidate` and flip it to
`ready` with `php scripts/release/release.php status ready` once the
preflight passes.

The customer recovery job rehearses upgrades from the published 0.11.0
scaffold and the frozen interpreter stack at `321a2906`. It restores the saved
bundle and pins after writing with each supported PHP minor, then appends
another row in the original Durable Object.

Before upgrading an environment, record its known-good Worker version ID
with `atoms status --env <env>`. Save the matching bundle, manifest, scaffold
revision, package lock and interpreter pins. To restore the running code,
use `atoms rollback <version-id> --env <env>`. Restore those saved inputs in
the application checkout and run `npm ci` before the next deploy. The
Durable Object namespace, customer Wrangler configuration and SQLite data
belong to the same environment throughout recovery.

## Publishing credentials

Configure npm [trusted publishing] for `@atomsphp/php-wasm-8-3`,
`@atomsphp/php-wasm-8-4`, `@atomsphp/php-wasm-8-5` and
`@atomsphp/runtime-cloudflare` before tagging. The release workflow publishes
the three qualified interpreter tarballs first. It verifies the registry
integrities, installs the release scaffold using the registry and boots each
interpreter before publishing the scaffold tarball. A retry verifies any
already-published version against the expected integrity.

The `publish-npm` job exchanges its
GitHub OIDC token for a short-lived, single-publish registry credential, which
npm grants only against the trusted publisher configured on the package —
this repository, `.github/workflows/release.yml`, and the `release`
environment. Change any of those three and publishing stops until the package's
trusted-publisher entry on npmjs.com is updated to match. There is no
`NPM_TOKEN` secret to rotate, and provenance is attested automatically.

To prepare a new interpreter revision, run the **PHP Wasm qualification**
workflow on the proposed branch with `package_revision` set to the numeric
suffix. For example, `8` packages each pinned PHP patch as `-atoms.8`.
Download the three `pin-*` artifacts into the corresponding target
directories as `candidate.json`, then run the Worker’s
`node scripts/update-interpreters.mjs`. Pull requests and tagged releases
compare both rebuilt packages to these reviewed pins.

The protected job publishes interpreter versions such as `8.3.32-atoms.5`
with the npm dist-tag `runtime`. npm 11 requires an explicit tag for these
prerelease version strings. Scaffold lockfiles select each exact version and
integrity.

The eight Composer mirrors are a separate credential path, an installation
token scoped to those repositories — see `MIRRORS.md`.

[trusted publishing]: https://docs.npmjs.com/trusted-publishers

See `MIRRORS.md` for the one-time repository controls and `CUTOVER.md` for the
first-release order. Neither document is an executable invitation to publish:
all public changes remain protected release actions.
