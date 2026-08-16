# Releasing Atoms

`manifest.json` is the machine-readable source of truth for the coordinated
PHP packages, Cloudflare runtime, deploy Action, and compatibility table.
Publication workflows reject a release tag unless its status is `ready`.

Use the release tool rather than editing version fields by hand:

```sh
php scripts/release/release.php check
php scripts/release/release.php set 0.1.1 ready
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

See `MIRRORS.md` for the one-time repository controls and `CUTOVER.md` for the
first-release order. Neither document is an executable invitation to publish:
all public changes remain protected release actions.
