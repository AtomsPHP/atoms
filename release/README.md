# Releasing Atoms

`manifest.json` is the machine-readable source of truth for the coordinated
PHP packages, Cloudflare runtime, deploy Action, and compatibility table. M7
ships it as `candidate`; publication workflows reject a release tag until a
standalone, reviewed cutover pull request changes it to `ready` after the full
private preflight passes.

Use the release tool rather than editing version fields by hand:

```sh
php scripts/release/release.php check
php scripts/release/release.php set 0.1.1 candidate
php scripts/release/release.php validate-splits
```

`check` verifies all package metadata, internal dependency constraints,
runtime-version stamps, compatibility pins, changelog entry, and mirror
artifacts. `set` updates the coordinated version and regenerates the known
stamps atomically. It deliberately defaults to `candidate`; readiness is a
separate review decision, not a side effect of changing a version.

See `MIRRORS.md` for the one-time repository controls and `CUTOVER.md` for the
first-release order. Neither document is an executable invitation to publish:
all public changes remain protected release actions.
