# Public API Contract — v1 (frozen for the `laravel/atoms` SDK)

This document freezes the customer-facing HTTP contract the SDK consumes.
Breaking changes require a new version prefix (`/v2/...`); additive changes
(new error codes, new optional fields) are allowed within v1.

Host: the platform edge (Fly anycast). All requests require:

```
Authorization: Bearer {api_key}
```

API keys look like `atoms_v1_{43 chars of base64url}`. Keys are verified by
SHA-256 hash; revocation takes effect within the edge auth-cache TTL
(default 30s).

## Invoke

```
POST /v1/{customer}/invoke/{type}/{id}/{method}
Content-Type: application/json

{ "args": [ ... ] }
```

- `customer` — customer slug (must match the API key's customer, else 403).
- `type` — Atom class basename, e.g. `GameRoom`. `[A-Za-z_][A-Za-z0-9_]*`.
- `id` — Atom instance id. Any non-empty string ≤ 256 bytes without `/`.
- `method` — public method name on the Atom class.
- `args` — positional arguments, JSON-serialized. Optional (defaults to `[]`).

Success — `200 OK`:

```json
{ "result": <json>, "atom": {"type": "GameRoom", "id": "g-1"}, "version": "v3" }
```

`version` is the code-bundle version the invocation executed on.

First call to an Atom activates it transparently (virtual actor semantics);
there is no create API.

## Destroy

```
DELETE /v1/{customer}/atoms/{type}/{id}
```

Explicitly destroys an Atom: its directory placement is released, and its
local and S3 state are deleted. `200 OK` with `{"destroyed": true}`.
Destroying a non-existent Atom is idempotent (`200`, `"destroyed": false`).

## Deploy

```
POST /v1/{customer}/deploys
Content-Type: application/gzip        (body: tar.gz of app/Atoms/ + atoms-composer.json)
```

Success — `201 Created`:

```json
{
  "version": "v4",
  "manifest": { "atoms": [...], "methods": [...] },
  "created_at": "2026-07-07T00:00:00Z"
}
```

```
GET  /v1/{customer}/deploys                → {"versions": [...], "current_version": "v4"}
POST /v1/{customer}/rollback  {"version": "v2"}  → {"current_version": "v2"}
```

Rollback flips the version pointer to any retained version; new activations
use it within the Machine poll interval (default 10s).

## Errors

Every non-2xx response has this envelope:

```json
{
  "error": {
    "code": "capacity_refused",
    "message": "no placeable machine available for customer acme",
    "retryable": true
  }
}
```

| HTTP | `code`                   | Meaning                                                        | Retryable |
|------|--------------------------|----------------------------------------------------------------|-----------|
| 400  | `invalid_request`        | Malformed path, body, or arguments                             | no  |
| 401  | `unauthenticated`        | Missing/unknown/revoked API key                                | no  |
| 403  | `forbidden`              | Key does not belong to `{customer}`                            | no  |
| 404  | `unknown_atom_type`      | Type not present in the customer's current deploy manifest     | no  |
| 404  | `not_found`              | Unknown route / unknown deploy version                         | no  |
| 409  | `deploy_in_progress`     | Another deploy for this customer is running                    | yes |
| 422  | `validation_failed`      | Deploy rejected by static analysis (details in `message`)      | no  |
| 429  | `rate_limited`           | Per-customer rate limit exceeded (`Retry-After` header set)    | yes |
| 503  | `capacity_refused`       | No placeable Machine / Machine refused activation (admission)  | yes |
| 503  | `directory_unavailable`  | Placement directory unreachable and Atom not cached (grace)    | yes |
| 502  | `machine_unavailable`    | Owning Machine unreachable/crashed; retry after failover       | yes |
| 504  | `turn_deadline_exceeded` | The Atom's turn exceeded the configured deadline               | yes |
| 500  | `internal`               | Unexpected platform error                                      | yes |

`retryable: true` means the SDK may retry with backoff without side-effect
concerns *at the platform level* (the platform did not begin executing the
method, or execution is idempotent to retry per the SDK's own policy for
`turn_deadline_exceeded`).
