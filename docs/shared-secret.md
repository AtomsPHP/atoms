# The shared secret (decision record)

**Status: accepted, 2026-08-15.** This document is the decision record and
the normative contract for the single symmetric root on the app ↔ Worker
boundary. `cloudflare/docs/mvp-spec.md` §The callback channel, §Routing and
auth and `docs/cloudflare-toolchain.md` restate the parts each half
implements; where a restatement and this document disagree, this document
wins. `docs/ws-ticket-protocol.md` is normative for the WebSocket ticket wire
format and its vectors; this document stays supreme for the key material,
its derivation, and rotation.

## The decision

One operator-facing secret, **`ATOMS_SHARED_SECRET`**: 32 random bytes,
base64-encoded, configured identically on the monolith and the Worker, and
never transmitted anywhere. Every key on the boundary is derived from it
with HKDF-SHA256 domain separation — empty salt, a fixed per-purpose `info`
string, 32-byte output:

| purpose | `info` | output form | use |
|---|---|---|---|
| bearer | `atoms/bearer/v1` | 32 bytes, then **standard base64 (RFC 4648, padded — exactly 44 characters)** | the `Authorization: Bearer` value: the monolith derives and sends it, the Worker derives and compares |
| WebSocket tickets | `atoms/ws-ticket/v1` | 32 bytes: raw bytes via `hash_hkdf()` on the monolith, imported as a non-extractable HMAC-SHA256 WebCrypto key on the Worker | ticket signing (application, `TicketIssuer`) and verification (Worker) — see `docs/ws-ticket-protocol.md` |
| callbacks | `atoms/callback/v1` | 32 bytes: non-extractable HMAC-SHA256 WebCrypto key on the Worker; the same 32 raw bytes via `hash_hkdf()` on the monolith | callback HMAC-SHA256 signing (Worker) and verification (monolith) |

The IKM for every derivation is the **decoded 32 raw bytes**, not the
base64 string. Both sides trim ASCII whitespace, strict-decode the base64,
and require **exactly 32 bytes** — anything else is a hard configuration
error, never a warning and never a fallback. Decoding first is what makes
the two languages provably agree: PHP `hash_hkdf('sha256', $ikm, 32,
$info, '')` and WebCrypto `deriveBits({name:'HKDF', hash:'SHA-256', salt:
new Uint8Array(0), info}, ikm, 256)` are byte-identical over the same IKM
(verified; the conformance suite and the client test suite both pin the
vector below).

This replaces two secrets and two fossil names. `ATOMS_APP_KEY` (Worker),
`ATOMS_API_KEY` (client and adapters — the hosted-platform-era name for the
same value), `ATOMS_CALLBACK_SIGNING_KEY` (Worker, Ed25519 seed) and
`ATOMS_PLATFORM_PUBLIC_KEY` (monolith, its public half) are all **deleted**,
not aliased. Pre-release: no compatibility shim reads the old names, and
nothing warns-then-continues — a deployment still setting only the old
variables fails loudly.

### Reference vector

Test secret (the bytes `0x00…0x1f`, base64): `AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8=`

| `info` | derived 32 bytes (base64) |
|---|---|
| `atoms/bearer/v1` | `Dx6RY9LS43pOQhM4PMdaUWx3lk9mfyiiJZFfJtvl9E0=` |
| `atoms/ws-ticket/v1` | `oAhR1o7PQdNULciqv8FZkgnlJ89a48C5wpdSEMXHBoA=` |
| `atoms/callback/v1` | `o5hmDR6tAEEoECTVtZm/BT1yzFkGWZYcDXXI/V1cYSM=` |

The bearer row doubles as the wire value: `Authorization: Bearer
Dx6RY9LS43pOQhM4PMdaUWx3lk9mfyiiJZFfJtvl9E0=` is exactly what a client
configured with the test secret sends. The bearer's length and encoding are
part of the contract, not an implementation detail: a Worker may refuse a
presented token that is not 44 characters before comparing, and the
comparison itself is constant-time.

## The root never travels

The wire-exposed bearer is `HKDF(secret, "atoms/bearer/v1")`, never the
secret itself. This one-wayness is half the point of the design: the
channels that routinely capture request headers — proxy logs, APM header
capture, exception reporters that dump requests, HAR files — see only the
derived bearer, so a leak through any of them exposes invocation
capability alone. The bearer cannot be walked back to the secret or
sideways to the ticket or callback keys.

Two obligations follow, and they are requirements, not suggestions:

- **`atoms token` exists** so operators can still curl the Worker: it
  prints the derived bearer (never the secret). Without it, people would
  paste the secret into an `Authorization` header and silently undo the
  property.
- **Every place the variable is documented says it is not a bearer token
  and must never be sent.** The name says "symmetric, match on both sides"
  — correct, and why it was chosen — but it does not say "never transmit
  this", so the docs must. Every troubleshooting example that used to show
  `-H "Authorization: Bearer $ATOMS_APP_KEY"` now shows
  `-H "Authorization: Bearer $(atoms token)"`.

## Bearer auth: mandatory secret, explicit opt-out

The secret is mandatory — callbacks cannot be signed without it — so
whether a secret is configured cannot double as the auth on/off switch,
and the auth posture is stated explicitly instead. A fumbled secret in
production is a loud configuration error, never an open Worker:

- **`ATOMS_SHARED_SECRET` absent, or not exactly 32 bytes of base64, or
  `ATOMS_SHARED_SECRET_PREVIOUS` set but malformed** → every route except
  `GET /healthz` answers the wire code `misconfigured` (HTTP 500,
  `retryable: false`), with a message naming the variable and the rule.
  `loadConfig()` stays total — `/healthz` still answers on a broken Worker
  — but nothing else runs. A misconfigured Worker is loudly broken, never
  silently open.
- **`ATOMS_BEARER_AUTH`** is the explicit posture switch: `required` (the
  default) or `disabled`. Anything else logs a warning and behaves as
  `required` — a typo fails closed. `disabled` exists for exactly one
  legitimate posture: an authenticating proxy such as Cloudflare Access in
  front of the Worker. It disables **only** the bearer comparison; the
  secret stays mandatory, tickets stay signed, callbacks stay signed.

Local development rebuilds its keyless convenience on the explicit flag,
not on a committed secret: `atoms dev` generates a fresh per-machine dev
secret into the Worker project's gitignored `.dev.vars` (creating the file
if needed, never overwriting an existing value, warning if the file is not
gitignored). A committed or fixed dev secret would be a known master key
and is not acceptable. Local and production run the same code path —
including ticket signing, which is why the unsigned `v1u.` dev ticket form
no longer exists (below).

## Tickets: always signed, `v1u.` deleted, now issued locally

The unsigned dev ticket existed only because the auth-off posture had no
key to sign with. There is now always a key, so ticket issuance always
produces the signed `v1.` form — under `ATOMS_BEARER_AUTH=disabled` too — and
`/ws` always verifies the signature. The `v1u.` form is deleted outright:
minting it, accepting it, and the special-case rejection text all go. A dev
machine's tickets are signed by that machine's dev secret and are
worthless anywhere else.

Tickets are no longer minted by the Worker. `Atoms\Client\Tickets\TicketIssuer`
mints them locally, in the application process, from the same derived key —
pure computation, no HTTP round trip. The wire format, its limits, and the
expiry rule are normative in `docs/ws-ticket-protocol.md`; this document
stays authoritative for the key derivation alone.

The ticket HKDF `info` stays `atoms/ws-ticket/v1`; only the IKM changes
(decoded shared-secret bytes instead of the old app-key string). Rotating
the secret still invalidates every outstanding ticket that was signed under
the fully-retired secret — but, per below, tickets now join the rotation
overlap, so a ticket signed under `ATOMS_SHARED_SECRET_PREVIOUS` is accepted
for the length of the overlap window, not refused outright.

## Callbacks: same envelope, HMAC instead of Ed25519

Callbacks become symmetric HMAC-SHA256 over the **existing envelope,
unchanged**: the signed message is `"v1\n{unix_ts}\n{nonce}\n" + body`, the
headers are the same `x-atoms-signature` / `x-atoms-timestamp` /
`x-atoms-nonce` / `x-atoms-kind`, the timestamp window and the single-use
nonce store are untouched. Only the algorithm and the key source change:
`x-atoms-signature` is now the standard base64 of the **32-byte**
HMAC-SHA256 tag, and the verifier rejects any signature that does not
decode to exactly 32 bytes before comparing. Comparison is
`hash_equals()`; PHP has `hash_hkdf()`, `hash_hmac()` and `hash_equals()`
built in, so `atoms/client` drops its `ext-sodium` dependency entirely —
`Ed25519Verifier` was its only consumer, and it is deleted.

### The "key never enters wasm" property survives verbatim

The spec's statement that the callback signing key is imported
`extractable: false` and never enters wasm is about *where signing
happens*, not about symmetric versus asymmetric, and it holds identically
afterwards: host JS derives all three keys from the secret, imports the
ticket and callback keys as non-extractable WebCrypto HMAC keys, and signs
guest-built bodies. The guest never sees the secret or any derived key.
Customer PHP builds callback bodies; host JS signs them; an Atom that
reads arbitrary guest memory still cannot exfiltrate a key.

### The deny lists are load-bearing

`config.js`'s built-in `config.get()` deny list holds
`ATOMS_SHARED_SECRET` and `ATOMS_SHARED_SECRET_PREVIOUS`, replacing the
retired names. The operator's deny list remains additive to the built-in
one, never a replacement. `packages/cli`'s
`WorkerConfig::DEFAULT_DENY_KEYS` mirrors the same set, so
`atoms deploy`/`atoms secrets` never bless writing either as a plaintext
`var`. Both lists carry an assertion (conformance on the Worker side, a
unit test on the CLI side) that a guest cannot resolve either name.

A customer Atom that could read the shared secret through `config.get()`
would hold the root of everything — strictly worse than the two-key design
this replaces. That is why the deny list is part of the contract, not
hygiene.

## Rotation: `ATOMS_SHARED_SECRET_PREVIOUS`, three sites, try-both

One secret means one flip would invalidate bearer auth, tickets and
callback trust together. The overlap mechanism is a second, *optional*
secret — never a second live secret — accepted at **exactly three
verification sites**:

1. **The Worker's bearer check.** It derives `bearer(current)` and, when
   `ATOMS_SHARED_SECRET_PREVIOUS` is set, `bearer(previous)`, and accepts
   a request whose token matches either — constant-time compare against
   each.
2. **The Worker's ticket signature check.** It verifies a ticket's HMAC
   under the current derived ticket key and, when
   `ATOMS_SHARED_SECRET_PREVIOUS` is set, under the previous one, and
   accepts on either match. See `docs/ws-ticket-protocol.md` §Rotation.
3. **The monolith's callback verification.** It derives the callback key
   from both secrets and accepts a callback whose HMAC verifies under
   either.

All three sites are **try-both, never a key selector**: verification always
attempts the current key, then the previous, and accepts on the first
match. A key id is not a trusted input — the previous secret is a fixed,
operator-provisioned fallback tried unconditionally during the window,
never chosen by an attacker-controlled header. If a key id is ever emitted
for logging, it is advisory only and selects nothing.

The overlap is asymmetric on purpose: **a verifier accepts both, a sender
emits only the current value.** The monolith always sends
`bearer(current)` and always signs tickets with the current key; the
Worker always signs callbacks with the current key. The previous secret
widens *acceptance*, never *emission*, so neither side guesses which value
a straggler still uses. This now covers tickets too, not just bearer and
callbacks — the earlier design gave tickets no overlap at all, on the
justification that a ticket was cheap to re-mint through the Worker; that
justification died along with the mint route (`docs/ws-ticket-protocol.md`
§Rotation explains why, and records the rejected alternative of keeping
tickets strict).

Runbook (zero downtime for bearer, tickets and callbacks):

1. Worker: set `ATOMS_SHARED_SECRET` = new, `ATOMS_SHARED_SECRET_PREVIOUS`
   = old, deploy. The Worker now accepts both bearers and both ticket
   signatures.
2. Monolith: deploy with the same pair. New instances emit the new bearer
   and sign new tickets with the new key, and verify callbacks under both
   keys; a still-old instance emits the old bearer and signs tickets with
   the old key, both of which the Worker still accepts.
3. Once every instance on both sides holds the new secret, delete
   `ATOMS_SHARED_SECRET_PREVIOUS` from both and redeploy. This step still
   invalidates every outstanding old-secret ticket at once, along with the
   old bearer and old callback key.

## What this trades away — recorded, not to be re-litigated

The Ed25519 asymmetry denied exactly one attacker capability: someone with
a **read-only** copy of the monolith's config (a leaked `.env`, a
`config:cache` artifact in a web root, a backup, a debug page) could
invoke the Worker but could not fabricate callbacks. After this change
they can POST arbitrary validly-signed callbacks at the monolith — an
execution surface, not just a data surface.

Accepted, on these grounds: the same leak already yields full invoke
capability over every Atom's state; in Laravel the same file holds
`APP_KEY`; and — for the record — the leak already yielded a third
capability today, unchanged by this design: the monolith held the bearer,
which was also the ticket-signing root, so it could already forge
WebSocket tickets. This change adds callback forgery only — no new ticket
or invoke capability — while the asymmetry it removes hardened one link of
a chain whose other links fail from the identical leak, at the cost of a
keypair ceremony, two rotation procedures and doubled documentation for
every operator, in a project where misconfiguration, not cryptanalysis, is
the dominant failure mode.

**The trigger for revisiting:** if callbacks ever fan out to verifiers
beyond the single monolith — an analytics service, an audit logger, any
third party — symmetric signing stops being adequate, because with HMAC
every verifier can also forge. That is the point to introduce an
asymmetric `v2` envelope; the existing `"v1\n"` version prefix is what
keeps that door open. A *separate* Worker → third-party channel gets its
own auth scheme rather than reusing this envelope.

## Conformance and process

The spec and the conformance suite are acceptance gates and do not bend to
implementations. This change lands spec-first; the suite then follows the
spec, including:

- a cross-language bearer-derivation equality case (both languages must
  reproduce the reference vector, and the derived bearer must be accepted
  live by an auth-on Worker);
- HMAC verification of every callback the suite's listener receives, tag
  length asserted;
- rotation cases: `bearer(previous)` accepted when the overlap is
  configured, and a previous-secret-signed ticket accepted under the same
  overlap (`docs/ws-ticket-protocol.md` §Rotation);
- deny-list cases: a guest `config.get()` of either new name resolves
  null, whatever the allowlist says;
- the misconfigured-Worker case: no secret → `misconfigured` on `/invoke`,
  `/healthz` still 200.

The conformance runner accepts either `ATOMS_SHARED_SECRET` (full
capability: derive, forge test tickets, verify callbacks — the local/CI
posture) or a pre-derived bearer via `ATOMS_BEARER_TOKEN` (invoke-only checks
against a deployed Worker, so the root does not have to travel to the
runner; the checks that need the root skip). CI generates a fresh secret
per run; nothing commits one.
