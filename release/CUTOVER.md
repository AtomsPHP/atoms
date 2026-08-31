# 0.1.0 cutover

This is an ordered, human-approved operation. Do not combine or reorder the
steps: claiming the Composer vendor immediately after repository visibility
changes is the only time-sensitive, difficult-to-recover action.

1. Merge M7 with `release/manifest.json` set to `candidate` and pass the full
   private CI and local package preflight.
2. Create the eight private mirrors, install the mirror-only release App, run
   the `seed-mirrors` workflow dispatch, verify the mirrors, and merge a
   standalone pull request changing release status to `ready`.
3. Make `AtomsPHP/atoms` and all eight mirrors public.
4. Immediately submit `https://github.com/AtomsPHP/core` to Packagist as
   `atoms/core`. Do this before tags, npm, Pages, or DNS work.
5. Push the immutable monorepo tag `v0.1.0` and approve the protected release
   environment. Confirm npm, all eight mirror tags, and the GitHub release.
6. Deploy GitHub Pages, set `docs.atomsphp.dev` DNS, and verify HTTPS and the
   canonical error links.
7. Verify tagged `atoms/core`, then submit `atoms/client` and the remaining
   packages in dependency order. Enable Packagist update hooks.
8. From a machine without the monorepo, follow only public documentation and
   install from Packagist and npm. Run the literal public scaffold command,
   deploy to a fresh supported Cloudflare account, invoke the same persistent
   Atom twice, and perform a code rollback in under 30 minutes.

Published versions and tags are immutable. If a later publication job fails,
rerun that idempotent job; never move or delete an already published version.
