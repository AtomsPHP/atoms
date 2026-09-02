# The WebSocket ticket protocol

**Status: accepted, 2026-08-16.** This document is the normative contract for
the WebSocket connection ticket wire format: its byte layout, its
serialization rule, its limits, and its expiry rule. It does not own key
material — `docs/shared-secret.md` is supreme for the secret, its derivation,
and rotation, and this document defers to it for the `atoms/ws-ticket/v1` row
of the derivation table. `cloudflare/docs/runtime-spec.md` remains binding for the
Worker's observable verification behaviour at `GET /ws/{type}/{id}` and
cross-references this document for the format it verifies. Where this
document and a restatement elsewhere disagree on the wire format or the
vectors, this document wins.

## Why the ticket exists

A browser's `new WebSocket(url)` cannot set an `Authorization` header, so a
WebSocket upgrade cannot carry the bearer the way every other route does.
Instead, the application mints a short-lived, atom-scoped ticket and hands it
to the browser, which presents it as `?ticket=` on the upgrade. The Worker
verifies the ticket's signature and expiry at the edge before completing the
upgrade; nothing is claimed or looked up, so the check is entirely stateless.

## Issuance is local, not a round trip

Tickets are minted by the application itself, via
`Atoms\Client\Tickets\TicketIssuer::issue()`
(`packages/client/src/Tickets/TicketIssuer.php`) — pure local computation, no
HTTP call. There used to be a `POST /tickets/{type}/{id}` route on the Worker
that minted tickets on the application's behalf, called through
`Atoms\Client\Tickets\TicketClient`; that route, `TicketClient`, and the
`TicketAcquisitionFailed` exception are all deleted, with no deprecation
period and no fallback. The application already holds `ATOMS_SHARED_SECRET`
and can derive the ticket-signing key itself (`docs/shared-secret.md`), so the
round trip was the Worker acting as a remote signing function for a key the
caller already had.

The Worker remains the strict, stateless verifier at `GET /ws/{type}/{id}`. It
never mints a ticket, and it holds no ticket state — verification is a pure
function of the ticket string, the current time, and the current (and,
during rotation, previous) key.

## Public API

```php
$issuer = new TicketIssuer($config);                 // + optional ?callable $clock, ?callable $randomBytes test seams
$ticket = $issuer->issue('Room', 'lobby', ['client_id' => '42'], ttlMs: null);  // returns Atoms\Client\Tickets\Ticket
(string) $ticket;         // v1.<payload>.<sig>
$ticket->expiresAtMs;     // epoch ms
```

Class constants on `TicketIssuer`: `VERSION = 'v1'`, `MAX_CLAIMS = 16`,
`MAX_CLAIM_BYTES = 2048`, `MAX_TICKET_BYTES = 8192`,
`RESERVED_CLAIM_KEYS = ['ticket', 'channels']`. A scope or claims map that
does not fit the protocol throws `Atoms\Client\Exception\InvalidTicketClaims`
(extends `\InvalidArgumentException`, catalog code **ATOMS-E068**) — a
programming error in the caller, not a platform failure, since nothing was
sent anywhere and retrying the same input cannot help.

## Wire format

The wire form is `v1.<base64url(payload)>.<base64url(sig)>`.

base64url is RFC 4648 §5, **unpadded and canonical**: the verifier rejects any
segment whose decode/re-encode round trip is not byte-identical to the
segment as presented. This closed a real signature-malleability gap (a
tampered signature could decode to the same bytes as the real one under a
non-canonical encoding and still verify) and is already shipped.

The payload is a UTF-8 JSON object with keys in this exact order:

| key | type | meaning |
|---|---|---|
| `t` | string | atom type |
| `i` | string | atom id |
| `exp` | integer | expiry, epoch **milliseconds** |
| `jti` | string | 32 lowercase hex characters — 128 random bits |
| `claims` | object | `{}` when empty; a flat string→string map; keys `ticket` and `channels` are reserved |

### Issuer serialization rule

This is the rule that makes PHP and JS byte-identical, empirically verified,
and it is an **issuer-determinism** rule, not a verification requirement —
say that precisely, because it is the subtlety a future implementer is likely
to get wrong. **The verifier never re-serializes the payload; the signature
covers the payload segment exactly as presented on the wire.** Canonical JSON
only matters because the issuer must produce the same bytes every time it
signs the same logical payload (and because the PHP and JS issuers, if both
exist, must agree with each other) — not because the verifier recomputes and
compares JSON.

The issuer serializes with no inserted whitespace, `/` unescaped, and
non-ASCII characters unescaped as raw UTF-8. In PHP that is exactly:

```php
json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
```

with the `claims` map cast to `(object)` so an empty map serializes as `{}`
rather than `[]`, and so a numeric-string claim key cannot turn the map into a
JSON list.

## Signing key and signature

Signing key: HKDF-SHA256, `ikm` = the decoded 32 raw secret bytes, empty
salt, `info = "atoms/ws-ticket/v1"`, 32-byte output — see
`docs/shared-secret.md` for the derivation this shares with the bearer and
callback keys.

Signature: HMAC-SHA256 over `"v1\n" + <payload segment>` (the payload segment
as it appears on the wire, i.e. the base64url text, not the decoded JSON).

## Limits

Mint-side, enforced by `TicketIssuer`:

- at most 16 claims (`MAX_CLAIMS`);
- at most 2048 UTF-8 bytes total over claim keys plus values
  (`MAX_CLAIM_BYTES`);
- the assembled ticket string at most 8192 bytes (`MAX_TICKET_BYTES`).

Verify-side, the Worker keeps its own length cap,
`ATOMS_WS_TICKET_MAX_BYTES` (default 8192) — the only mint-side ticket
environment variable that survives; see "What moved off the Worker" below.

## Expiry

**A ticket is expired when `verifierNow >= exp`.** There is no clock skew
allowance and no setting for one. (This is unrelated to callback timestamp
tolerance, which is a separate mechanism and is unchanged.)

A ticket is **reusable until it expires**, not single-use: nothing is claimed
or burned on connect, and the Worker holds no ticket state. The short
lifetime is the entire defence against a leaked URL — there is no revocation
mechanism narrower than rotating the secret.

Default lifetime is 60 seconds, and it is application-side configuration now:
`AtomsConfig::$wsTicketTtlMs` (`ws_ticket_ttl_ms`, Laravel env
`ATOMS_WS_TICKET_TTL_MS`), with a per-call `ttlMs` override on
`TicketIssuer::issue()`.

## What moved off the Worker

Deleted from the Worker along with the mint route: `ATOMS_WS_TICKET_TTL_MS`,
`ATOMS_WS_TICKET_SKEW_MS`, `ATOMS_WS_TICKET_MAX_CLAIMS`,
`ATOMS_WS_TICKET_MAX_CLAIM_BYTES` — all of them either mint-side settings with
no minting left to configure, or the now-deleted skew allowance. Kept:
`ATOMS_WS_TICKET_MAX_BYTES`, since the Worker still bounds how large a string
it will even look at.

## Deliberately not added

No `iat` field, and no Worker-enforced maximum signed lifetime, and neither is
an oversight. The signer holds the root secret, so a verify-side lifetime
bound would defend against nothing: a hostile signer would simply mint fresh
tickets forever, and the bound would be the only cap enforced against the
*trusted* party rather than an attacker. Signature trust plus an absolute
`exp` is the whole contract; there is no untrusted clock or untrusted issuer
in this picture for a skew allowance or an `iat` check to protect against.

## Unknown or non-WebSocket atom types

The upgrade itself stays authoritative: an unknown type answers
`unknown_atom_type` (404), and a type that does not accept WebSockets answers
`not_supported` (501). `TicketIssuer` deliberately does not pre-check a local
manifest before issuing — a locally bundled manifest can lag the deployed
Worker, and pre-checking would make issuance depend on whether a manifest
path happens to be configured for that call site. A manifest-aware optional
check remains a possible future addition, not a gap in this one.

## Rotation

Tickets join the rotation overlap. The Worker verifies a ticket's HMAC under
the current key and, while `ATOMS_SHARED_SECRET_PREVIOUS` is configured,
under the previous key too — the identical try-both pattern already used for
the bearer check, never a key selector and never chosen by an
attacker-controlled input. The sender side is unchanged: the application
always signs with its current secret; the previous key only widens what the
Worker will *accept*, never what anything *emits*.

This reverses an earlier design that gave tickets no overlap at all, on the
now-dead justification that a ticket was cheap to re-mint through a round
trip to the Worker so a rotation flip cost at most one re-mint. With local
minting that justification no longer holds: an application instance that has
not yet been redeployed with the new secret keeps signing with the old one
for the whole rollout window, and re-minting cannot help, because the same
un-redeployed instance signs the "fresh" ticket the same way. See
`docs/shared-secret.md` §Rotation for the full runbook and the three
verification sites the overlap now covers.

The rejected alternative — keeping tickets strict, i.e. verified only against
the current key — was considered and rejected: it would mean every rotation
carries a WebSocket outage for the length of the application rollout, and it
buys nothing, because during that same window the previous secret is already
fully trusted for ordinary API calls via the bearer. There is no security
reason to hold WebSockets to a stricter standard than invocation.

## Test vectors

These are asserted in both the PHP client test suite and the Worker
conformance suite, and must only change alongside both — a change to either
side without the other breaks cross-language agreement silently.

Secret (base64, the bytes `0x00…0x1f`): `AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8=`

Derived ticket key (base64): `oAhR1o7PQdNULciqv8FZkgnlJ89a48C5wpdSEMXHBoA=`

Fixed clock `1755200000000`, TTL `60000` → `exp` `1755200060000`. `jti` from
bytes `0x00..0x0f` → `000102030405060708090a0b0c0d0e0f`.

**Vector 1** — type `Room`, id `vector-1`, claims
`{"client_id":"u-42","name":"Zoë ✨","path":"a/b"}` (covers non-ASCII and an
unescaped slash):

payload JSON:
```
{"t":"Room","i":"vector-1","exp":1755200060000,"jti":"000102030405060708090a0b0c0d0e0f","claims":{"client_id":"u-42","name":"Zoë ✨","path":"a/b"}}
```

ticket:
```
v1.eyJ0IjoiUm9vbSIsImkiOiJ2ZWN0b3ItMSIsImV4cCI6MTc1NTIwMDA2MDAwMCwianRpIjoiMDAwMTAyMDMwNDA1MDYwNzA4MDkwYTBiMGMwZDBlMGYiLCJjbGFpbXMiOnsiY2xpZW50X2lkIjoidS00MiIsIm5hbWUiOiJab8OrIOKcqCIsInBhdGgiOiJhL2IifX0.p3PJLrBSNdsUUEiq4nL3zvnKq7iiozRibGPGd87zgyM
```

**Vector 2** — type `Room`, id `vector-2`, claims `{}` (pins that empty
claims serialize as `{}`, not `[]`):

payload JSON:
```
{"t":"Room","i":"vector-2","exp":1755200060000,"jti":"000102030405060708090a0b0c0d0e0f","claims":{}}
```

ticket:
```
v1.eyJ0IjoiUm9vbSIsImkiOiJ2ZWN0b3ItMiIsImV4cCI6MTc1NTIwMDA2MDAwMCwianRpIjoiMDAwMTAyMDMwNDA1MDYwNzA4MDkwYTBiMGMwZDBlMGYiLCJjbGFpbXMiOnt9fQ.1C0xNRHM-ev1U6yv8G0pEPLcO0jhGhv5YItL6Yku9-o
```
