## What changed, and why

<!-- The problem, then the change. If it fixes an issue, link it. -->

## Suites run

<!-- Name the commands and their outcome. Delete rows that do not apply. -->

- [ ] `composer test` (or `composer test -- --testsuite=<name>`, say which)
- [ ] `composer stan`
- [ ] Worker conformance: `cd cloudflare/worker && npm ci && npm run bundle`,
      then `npx wrangler dev` and
      `ATOMS_BASE_URL=http://127.0.0.1:8787 node test/conformance.mjs`
      — 12/12, or say which checks were skipped and why

## Contract check

`atoms/core` public signatures are frozen: add, never change. The error catalog
(`packages/core/resources/errors.json`) is append-only and never renumbered.

- [ ] This PR neither changes an existing `atoms/core` signature nor renumbers
      an error code — or it does, and the description explains why that is
      correct.
