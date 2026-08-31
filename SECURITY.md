# Security Policy

## Reporting a vulnerability

Report privately. Do not open a public issue, a discussion, or a pull request
that describes the problem before it is fixed.

Use GitHub's private vulnerability reporting: the **Security** tab of this
repository → **Report a vulnerability**. That opens a private advisory visible
only to the maintainers, and it is the channel we watch.

> **To be filled in by a maintainer:** a dedicated security contact address
> (and, if we publish one, a PGP key) still needs to go here for reporters who
> cannot or will not use GitHub. Until that line exists, GitHub's private
> reporting is the only channel — please do not read its absence as an
> invitation to disclose publicly.

We aim to acknowledge a report within a few business days. Atoms is a
small-scale, unfunded project: we will not commit to a fix deadline, and there
is no bug bounty. We will tell you honestly what we intend to do and when, and
we will credit you in the advisory unless you ask us not to.

If you have already deployed a Worker to your own Cloudflare account, note
that you control that deployment entirely — there is no hosted Atoms service
for us to patch on your behalf.

## Supported versions

Atoms is pre-1.0. The latest tagged 0.1.x release and the default branch are
supported; fixes land on the default branch first. Before `v0.1.0` exists,
only the default branch is supported.

## What is in scope

Everything in this repository: the eight PHP packages under `packages/`, the
deploy Action in `action/`, and the Cloudflare Worker runtime under
`cloudflare/`.

The security model worth stating plainly: an Atom is customer PHP running
inside a Durable Object, and the runtime's job is to keep that code inside its
own database and its own turn. Reports at these boundaries are the most
valuable:

- **The SQL bridge** (`cloudflare/worker/src/bridge.js`). Guest PHP reaches
  SQLite through the host. Anything that lets a statement escape the Atom's
  own storage, corrupt the bridge's parsing of a statement it thought it
  understood, or desynchronise the guest's view of a transaction from the
  host's, is in scope.
- **Reserved-table protection.** Tables named `__atoms_*` are the runtime's
  own bookkeeping and must be unreachable from customer SQL. Any statement
  that reads or writes one — including through comments, quoting tricks,
  aliases, `ATTACH`, pragmas, or anything else the scanner does not model — is
  a vulnerability, not a curiosity. Conformance check 10 covers the known
  cases; a bypass it misses is exactly what we want to hear about.
- **Turn serialization.** Invocations against one Atom must serialize
  strictly. A way to interleave two turns, or to observe another turn's
  in-memory state, breaks the model every Atom is written against.
- **Eviction and residency.** A poisoned residency that survives an error, or
  in-memory state that outlives an eviction it should not have, is in scope.
- **The signed callback path** in `atoms/client`
  (`packages/client/src/Callback/`). Callbacks from the Worker to a monolith
  carry an HMAC-SHA256 signature — the key HKDF-derived from
  `ATOMS_SHARED_SECRET` (`docs/shared-secret.md`) — over a canonical
  `v1\n{ts}\n{nonce}\n{body}` message, with a timestamp-skew window and a
  single-use nonce store. Signature-verification bypasses, replay through a
  weak or shared nonce store, canonicalisation mismatches between signer and
  verifier, and anything that makes the kernel act on an unverified request
  are in scope.
- **Bearer authentication** on the Worker — the `Authorization` header is
  checked against the bearer derived from `ATOMS_SHARED_SECRET`, under
  `ATOMS_BEARER_AUTH=required` — and any way to reach a debug endpoint that
  `ATOMS_DEBUG_ENDPOINTS` should have gated.
- **Build integrity.** `atoms build` must never execute customer code, and
  `cloudflare/worker/scripts/prepare-runtime.mjs` must fail rather than stage
  a runtime whose hash does not match the pin. A way around either is in
  scope.

## What is not in scope

- The PHP interpreter itself. It is built by the WordPress Playground project
  and fetched from npm at install time; it is not in this repository. Report
  interpreter bugs upstream. We do want to hear about it if our *pinning* of
  it is wrong.
- Vulnerabilities in Cloudflare Workers, Durable Objects, or wrangler. Report
  those to Cloudflare.
- Anything that requires the attacker to already control the deployment, the
  Cloudflare account, the `ATOMS_SHARED_SECRET` secret, or the source tree
  that gets built. Atoms trusts whoever deploys it.
- Customer PHP that is malicious toward its own Atom's data. An Atom's
  database belongs to that Atom; the boundary we defend is the one *between*
  Atoms and the one between the guest and the host.
- Findings from an automated scanner with no demonstrated impact, and reports
  about missing hardening headers on a `workers.dev` URL.

## Disclosure

We prefer coordinated disclosure and will work with you on timing. Once a fix
is on the default branch we publish a GitHub Security Advisory describing the
issue, its impact, and what a deployer needs to do — which, since Atoms is
self-hosted, will usually be "rebuild and redeploy".
