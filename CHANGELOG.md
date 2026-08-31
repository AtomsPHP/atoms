# Changelog

All notable changes to Atoms are documented here. The eight Composer packages,
the Cloudflare runtime, and deploy Action use one coordinated version.

## [Unreleased]

- **Added:** `atoms/database-illuminate`: the Laravel query builder and Eloquent
  models against an Atom's own SQLite database, as an optional bridge that
  ships inside the Atom bundle. Nested `transaction()` calls reuse the outer
  transaction (the runtime has no savepoints), schema work is refused
  (`ATOMS-E106`), and `getServerVersion()` answers from connection config.
- **Added:** `atoms build` now ships approved `atoms-composer.json` packages
  in the bundle. The vendor stage (`Atoms\Cli\Build\VendorStage`) resolves
  with `composer install --no-scripts --no-plugins` in an isolated directory,
  writes `atoms-composer.lock` back for reproducibility, caches the pruned
  tree under `.atoms/vendor-cache` (so `atoms dev` rebuilds stay offline),
  ships every vendor `.php` + LICENSE file plus a generated classmap
  autoloader, and records the additive manifest `vendor` key. Failures refuse
  loudly with `ATOMS-E079`. The php-scoper stage is retired: the tree ships
  unprefixed (prefixing vendor without rewriting the customer's call sites
  would break the app; the guest has no co-tenant), documented in
  `docs/cloudflare-toolchain.md` §3.
- **Added:** The Worker honours the manifest's `vendor.autoload` key: the
  vendor subtree is excluded from the line-scanning bundle autoloader and the
  declared classmap loader is required at activation (conformance check 45;
  `bundle_format` stays 0, absent key changes nothing).
- **Added:** `Atoms\Serialization\Serializer::denormalizeNamedArguments(string $class, array $wireArgs)`
  binds a dispatched job's `{"job": FQCN, "args": {...}}` argument map to a
  constructor by parameter name — wire value, else declared default, else null
  when the parameter is nullable, else `ATOMS-E024`. Additive; no existing
  signature changed. It is now the single implementation of that algebra:
  `atoms/client`'s callback kernel, `atoms/laravel`'s `AtomJobEnvelope`,
  `atoms/symfony`'s `AtomJobHandler`, `atoms/testing`'s `AtomHarness` and
  core's own Payload hydration all bind through it, where each previously
  carried its own copy of the loop.
- **Fixed:** A job delivered through Symfony Messenger with a missing
  constructor argument passed `null` for it regardless of whether the
  parameter was nullable, so a non-nullable parameter produced a raw PHP
  `TypeError` (and, for a nullable one, a half-built job) instead of an Atoms
  error. It now fails with `ATOMS-E024`, naming the argument.
- **Fixed:** `atoms/laravel`'s `AtomJobEnvelope` threw an uncatalogued
  `\RuntimeException` for a missing argument and instantiated whatever class
  the queue payload named without checking it is an `AtomJob`. Missing or
  ill-typed arguments are now `ATOMS-E024` and a non-`AtomJob` class is
  `ATOMS-E033`, matching the Symfony handler and the callback kernel. Both
  still extend `\RuntimeException`. `handle()` also takes an optional
  `Serializer`, container-injected in a queue worker.
- **Changed:** `AtomHarness::dispatched()` reports a job it cannot rebuild as
  `ATOMS-E024` rather than a generic `\InvalidArgumentException`, and fills
  null for an absent nullable constructor argument instead of reporting it
  missing — the same reconstruction the callback kernel performs, which is
  what the harness exists to imitate.
- **Added:** `MethodsResolver::registerManifest()` and `Manifest::typeMap()`
  (`atoms/client`) register every Atom a build manifest declares, so a host
  resolves wire types to Atom classes in one call instead of one line per
  Atom. The Laravel provider already did this, but by walking the manifest
  itself; the shape knowledge now lives in the package that owns the
  manifest, where the Symfony bundle and a plain-PHP host can reach it too.
  A Laravel app sees no behaviour change.
- **Fixed:** `AtomHarness` no longer reports itself booted before boot has
  succeeded. It marked itself booted on entry to `boot()`, so a failure while
  opening the temp database, loading migrations or running `onActivation()`
  left a harness that answered "booted" over a database that had never
  finished being set up — and the test that followed failed somewhere else,
  hiding the real error. A boot that throws now leaves the harness refusing
  further use, so the first exception is the one the test reports.
- **Changed:** a shut-down `AtomHarness` throws from anything needing a live
  Atom (`boot()`, `invoke()`, `db()`, `atom()`, `connect()`...) instead of
  serving it over the temp directory `shutdown()` deleted. The recorders —
  `dispatched()`, `broadcasts()` and their assertions — hold plain values and
  stay readable, so asserting after `shutdown()` still works. Calling a
  harness method from inside the boot sequence (an Atom's `onActivation()`)
  now throws for the same reason.
- **Changed:** `atoms/client` no longer auto-retries an error frame that carries
  both `remote_class` and `turn_deadline_exceeded` when the call site opted into
  `retryTurnDeadline`. Such a frame is your own Atom's exception, which
  `AtomsClient` surfaces as a `RemoteAtomException`; re-running the code that
  threw cannot help. Previously the retry decision re-read the raw envelope's
  `code`, saw the deadline code, and retried the call anyway. Retryability is
  now read off the mapped exception, which is the same object the caller
  receives, so the two can no longer disagree.
- **Changed:** `AtomsClient` decides whether to retry by asking the exception it
  mapped the failure to, rather than by re-matching the raw error envelope's
  `code`. The retry decision and the exception the caller receives can no longer
  disagree. One observable consequence: an error frame carrying both
  `remote_class` and `turn_deadline_exceeded` is no longer auto-retried under
  the `retryTurnDeadline` opt-in, because such a frame is your own Atom's
  exception — surfaced as a `RemoteAtomException` — and re-running code that
  threw cannot help. No deployed Worker emits that combination today: the
  Cloudflare runtime reports a throwing class as `class`, not `remote_class`,
  so nothing in live traffic changes. It is recorded because it is a change to
  the client's documented handling of the frame shape it reads.
- **Changed:** `atoms/phpstan-rules` gains an `Atoms\PHPStan\Zone` value
  object, and `AtomsLayeringConfig::zones()` / `zonesContaining()` now return
  `list<Zone>` instead of the raw `list<array{paths, forbid, allow}>` shape.
  A zone's `forbid`/`allow` prefixes are normalized once, when the config is
  constructed, rather than three divergent ways inside `LayeringRule`'s loops
  over zones × nodes × prefixes. The `parameters.atomsLayering` neon shape and
  every rule verdict are unchanged; this is a source-level API change for
  anything that consumed those two methods directly.

## [0.3.1] - 2026-08-17

- **Fixed:** the published `@atomsphp/runtime-cloudflare` tarball was missing
  `src/derive.js` and `src/tickets.js`, so every scaffolded worker failed its
  first esbuild pass and `atoms dev`/`atoms deploy` were broken for anyone
  consuming the 0.3.0 package. The packed module list is now derived by
  walking the import graph from the package entrypoints, so a module reachable
  from shipped code is packaged because it is reachable — adding a module can
  no longer silently ship a broken package.
- **Fixed:** `atoms shared-secret:set` and `atoms shared-secret:unset` failed
  to decode `wrangler secret list` whenever Wrangler printed a proxy or update
  notice on stdout alongside its JSON. `set` read an existing secret as absent
  and re-put it on every deploy, minting a Worker version each time and
  dropping the idempotence its docblock promises; `unset` read a closed
  rotation window as open and failed the pipeline step with `ATOMS-E074` for
  work already done. Both now decode through the same warning-tolerant reader
  `SecretsListCommand` already used.
- **Fixed:** `atoms build` discovery reported a valid Atom as `ATOMS-E001`
  "Unclassifiable file" when a later file in the same app declared the same
  fully-qualified class name, because the pass that reports unclassifiable
  files read a set the last-writer-wins index had already dropped that file
  from. The FQCN collision itself was never mentioned. Discovery now
  classifies every parsed class rather than only index survivors, and the
  collision is reported as a new error, `ATOMS-E002` — a bundle carrying both
  files would fatal in the guest at class-declaration time on whichever one
  loads second.
- **Fixed:** a Durable Object's PHP residency could inherit a dead instance's
  failure. `php.run()` is never awaited and a discarded instance's run promise
  can settle after a fresh instance has already booted into the same
  residency; the settle handlers wrote flags with no record of which instance
  they belonged to, so a late-ending run from a poisoned, discarded instance
  could throw its cause of death at a healthy new session. Residency state is
  now one record scoped to the instance it describes, and a stale settle is
  logged (`atoms.do.stale_run_settled`) instead of overwriting a live one.

## [0.3.0] - 2026-08-17

- **Added:** `atoms shared-secret:set --env X` stores `ATOMS_SHARED_SECRET`
  (or, with `--previous`, the rotation overlap value) on the Worker, read from
  stdin and validated as 32 bytes of base64 before it is sent. It is the only
  CLI path to this key — `atoms secrets:set` still refuses it (`ATOMS-E077`),
  since that command writes the guest-readable `ATOMS_CONFIG_` namespace.
  Idempotent unless `--force`, so a pipeline can run it every deploy without
  minting a Worker version each time.
- **Added:** `atoms shared-secret:unset --env X` removes
  `ATOMS_SHARED_SECRET_PREVIOUS`, closing a rotation window. It succeeds when
  the key is already gone, so a pipeline can run it twice. The overlap key is
  the only one it removes: `ATOMS_SHARED_SECRET` has no unset path, since a
  Worker without it answers `misconfigured` on every route but `GET /healthz`.
  Previously a rotation could be started from CI but only finished by hand.
- **Added:** the deploy Action takes `shared-secret`, `shared-secret-previous`,
  `rotate-shared-secret` and `retire-shared-secret-previous`, masking the
  secret values and piping them to the CLI on stdin. Previously the Action
  could deploy a Worker but not configure one, so a first CI deploy shipped
  something that answered `misconfigured` on every
  route but `GET /healthz` — a green health check over a broken Worker — until
  someone ran `wrangler secret put` by hand. See `docs/shared-secret.md`
  §Setting it.
- **Changed:** `atoms dev` takes the dev shared secret from the app's `.env`
  (or `.env.local` where `.env` is committed, as in Symfony) rather than
  `.dev.vars`: a secret is generated there when the key is absent, an existing
  one is adopted untouched, and the Worker's gitignored `.dev.vars` becomes a
  generated one-line projection rewritten whenever the two differ. No manual
  copy, and the value is never printed. `.dev.vars` exists only because
  `wrangler dev` reads that file and nothing else — treat it as a build
  artifact. A project with no dotenv file keeps the secret in `.dev.vars`
  alone.
- **Added:** `Atoms\Client\Tickets\TicketIssuer`, which mints WebSocket
  connection tickets locally — pure computation, no HTTP call — from the
  same HKDF-derived key the Worker verifies against. `AtomsConfig::$wsTicketTtlMs`
  (`ws_ticket_ttl_ms`, Laravel env `ATOMS_WS_TICKET_TTL_MS`, default 60000)
  sets the default ticket lifetime, overridable per call via `issue()`'s
  `$ttlMs` argument. Invalid claims or scope throw
  `Atoms\Client\Exception\InvalidTicketClaims` (`ATOMS-E068`). See
  `docs/ws-ticket-protocol.md`, the new normative wire-format document.
- **Changed:** WebSocket tickets are issued locally instead of minted by the
  Worker: there is no HTTP round trip, and `docs/ws-ticket-protocol.md`
  documents why asking the Worker to sign on the application's behalf was
  never necessary — the application already holds `ATOMS_SHARED_SECRET`. The
  ticket expiry rule is now exactly `verifierNow >= exp`, with no clock skew
  allowance; the skew setting is deleted, not just defaulted to zero.
- **Changed:** Ticket signature verification joins the rotation overlap
  alongside the bearer and callback checks, so `ATOMS_SHARED_SECRET_PREVIOUS`
  is now accepted at three verification sites rather than two. A ticket signed
  under the previous secret is accepted for the length of the overlap window.
  This reverses 0.2.0's no-overlap decision for tickets, whose stated reason —
  that a ticket is cheap to re-mint through the Worker — no longer holds now
  that minting is local. See `docs/shared-secret.md` §Rotation.
- **Removed:** `POST /tickets/{type}/{id}`, `Atoms\Client\Tickets\TicketClient`,
  and `Atoms\Client\Exception\TicketAcquisitionFailed` — deleted outright, no
  deprecation period. Also removed from the Worker:
  `ATOMS_WS_TICKET_TTL_MS`, `ATOMS_WS_TICKET_SKEW_MS`,
  `ATOMS_WS_TICKET_MAX_CLAIMS`, `ATOMS_WS_TICKET_MAX_CLAIM_BYTES` — all
  mint-side settings with no minting left on the Worker to configure, or the
  now-deleted skew allowance. `ATOMS_WS_TICKET_MAX_BYTES` is kept, since the
  Worker still bounds how large a ticket string it will look at.

- **Added:** `Atoms\Websocket\Connection::sendJson(array $payload): void` and
  `Atoms\Websocket\Message::json(): array`, so a structured reply to one
  connection is symmetric with `broadcast()`'s array-in call instead of the
  hand-rolled `$conn->send(json_encode(...))` and
  `json_decode($msg->payload(), true, ...)` every application was writing.
  Both go through the new `Atoms\Websocket\JsonFrame`, the single encoder
  `broadcast()` now uses too, so the normalization rules cannot drift between
  them: `Serializer::normalize()`, then `json_encode()` with
  `JSON_UNESCAPED_SLASHES`. A `sendJson()` frame is sent **bare** — there is no
  channel to name, so there is no `kind`/`channel` envelope, unlike a broadcast
  — and always arrives as a text frame. `json()` throws `\JsonException` for
  malformed input *and* for a top-level value that is not a JSON object, so an
  `onMessage()` handler needs one catch rather than a shape check; no
  `ATOMS-E###` is involved, because what to do about a bad inbound frame is the
  application's decision. Conformance check 43 pins the wire bytes. See
  `cloudflare/docs/mvp-spec.md` §The three client-facing frame formats.
  **Implementors of the two interfaces** — meaning a runtime or a test double,
  not application code — must add one method each.
- **Added:** `Atoms\Client\CallOptions`, per-call options passed to
  `AtomsClient::get()` / `AtomsManager::get()`, so the proxy covers every call
  site: `Atoms::get(GameRoom::class, $id, new CallOptions(retryTurnDeadline: true))->method(...)`.
  Previously `retryTurnDeadline` was reachable only through the positional
  `call()` form, which names the Atom twice and loses the return type. It also
  carries `idempotencyKey` and a per-call `traceparent`. Deliberately *not*
  fluent methods on `AtomProxy`: a declared method beats `__call()` silently, so
  `->retryingTurnDeadline()` would make a customer Atom method of that name
  permanently unreachable. `docs/conventions.md` §Per-call options records that
  the proxy declares nothing else, ever.
- **Added:** `AtomsClient::wsUrl()` / `AtomsManager::wsUrl()` (and
  `AtomsConfig::wsBaseUrl()`), deriving `ws`/`wss` and the `/ws/{type}/{id}`
  path from the one configured endpoint, with a `channels` list joined the way
  the Worker parses it. Applications were reconstructing this in JavaScript from
  a bare endpoint string. A ticket is passed in rather than minted, so
  issuance failures stay visible at the call site. Also
  `AtomsClient::wireType()`, the class-basename rule three call sites had each
  derived for themselves.
- **Added:** `Atoms::ticket(GameRoom::class, $id, $claims)` on the Laravel
  manager and facade — sugar over `TicketIssuer::issue()`, which takes the wire
  type, so a call site names the Atom class once instead of repeating its
  basename as a string. `AtomsFake` gained `stubTicket()`,
  `assertTicketIssued()` and `issuedTickets()`, so a test can assert the scope
  and claims of an issued ticket without standing up a PSR-18 fake.
- **Added:** `TicketIssuer` is now part of the normative adapter contract —
  a "Ticket minting" row in `docs/adapters.md`, wired into
  `examples/plain-php`'s `AtomsBootstrap`/`PlainPhpApp`, and asserted by two new
  conformance cases (S8: the issued ticket verifies under the host's own shared
  secret; S9: the host's config path yields a usable `wsUrl()`). Both are gated
  on the existing `'client'` capability, because an issuer is built from the
  same `AtomsConfig` the client is — a host supplying one and not the other has
  a wiring bug, not a missing capability.
- **Changed:** `AtomsClient::get()`, `AtomsManager::get()` and
  `AtomsFake::get()` declare `object` rather than a concrete proxy class, so
  they can carry `@template T` / `@return T`. This is what makes
  `Atoms::get(GameRoom::class, $id)->join($player)` statically analysable and a
  misspelled method name an error rather than a runtime 404 — the other reason
  applications were dropping to `Atoms::call()`. Reading a *property* through a
  proxy now throws `\LogicException` naming the problem: the annotation makes
  `->id` look legal, but an Atom's properties live on the platform and nothing
  was fetched, so a warning and `null` would have been the worst answer. A
  Laravel facade's `@method` block cannot carry a template, so inject
  `AtomsManager` or `AtomsClient` where you want full inference.

### UPGRADING from 0.2.0

1. Any code calling `Atoms\Client\Tickets\TicketClient::acquire()` must
   switch to `Atoms\Client\Tickets\TicketIssuer::issue()` — the class, and
   the `POST /tickets/{type}/{id}` route it called, are both deleted with no
   fallback. Issuance is now a local, synchronous call with no exceptions to
   catch for a network failure; only `InvalidTicketClaims` (ATOMS-E068) can
   be thrown, and only for a malformed scope or claims map. See
   `docs/ws-ticket-protocol.md`.
2. Remove `ATOMS_WS_TICKET_SKEW_MS`, `ATOMS_WS_TICKET_TTL_MS`,
   `ATOMS_WS_TICKET_MAX_CLAIMS` and `ATOMS_WS_TICKET_MAX_CLAIM_BYTES` from the
   Worker's configuration; the ticket lifetime is now `ws_ticket_ttl_ms` on
   the application side.
3. A rotation window now also covers WebSocket tickets, so outstanding
   tickets survive a flip while `ATOMS_SHARED_SECRET_PREVIOUS` is set on the
   Worker. Closing the window with `atoms shared-secret:unset` invalidates
   them, and a reconnecting browser is issued a fresh one automatically.

## [0.2.0] - 2026-08-16

- **Security:** One operator-facing secret, `ATOMS_SHARED_SECRET` (32 random
  bytes, base64, identical on the app and the Worker, never transmitted),
  replaces `ATOMS_APP_KEY`, `ATOMS_API_KEY`, `ATOMS_CALLBACK_SIGNING_KEY` and
  `ATOMS_PLATFORM_PUBLIC_KEY`. Every key on the app ↔ Worker boundary — the
  bearer, WebSocket ticket signing, callback signing — is HKDF-SHA256 derived
  from it with per-purpose domain separation (`atoms/bearer/v1`,
  `atoms/ws-ticket/v1`, `atoms/callback/v1`). The four old variables are
  deleted, not aliased: a deployment still setting only the old names fails
  loudly. `ATOMS-E105` covers a missing or malformed secret. See
  `docs/shared-secret.md`, the decision record.
- **Added:** `atoms token` prints the bearer derived from
  `ATOMS_SHARED_SECRET`, so an operator can curl the Worker without ever
  putting the secret itself in an `Authorization` header.
- **Added:** `ATOMS_BEARER_AUTH` — `required` (the default) or `disabled` for
  an authenticating proxy such as Cloudflare Access in front of the Worker —
  is the explicit bearer-auth posture. An absent or malformed
  `ATOMS_SHARED_SECRET` makes every route except `GET /healthz` answer
  `misconfigured` (HTTP 500, `retryable: false`): a misconfigured Worker is
  loudly broken, never silently open.
- **Changed:** Callback signing uses HMAC-SHA256 over the existing envelope
  (`"v1\n{ts}\n{nonce}\n" + body`, the same headers), the key HKDF-derived
  from `ATOMS_SHARED_SECRET`, verified with `hash_equals()` against a 32-byte
  tag. `atoms/client` declares no `ext-sodium` dependency.
- **Removed:** The unsigned `v1u.` WebSocket ticket form. `POST /tickets`
  always mints a signed `v1.` ticket, including under
  `ATOMS_BEARER_AUTH=disabled`, since a shared secret — and therefore a
  signing key — is always configured.
- **Fixed:** A ticket signature's base64url decoding discarded a trailing
  segment's unused "wasted" bits, so a tampered signature could sometimes
  decode to the identical bytes as the real one and still verify — a
  malleability gap in the ticket check above. `verifyTicket()`'s base64url
  decoder now rejects any non-canonical encoding (round-trip re-encoding must
  reproduce the exact input) for both the signature and payload segments.
- **Added:** Rotation overlap via `ATOMS_SHARED_SECRET_PREVIOUS`, accepted at
  exactly two verification sites (the Worker's bearer check, the monolith's
  callback verification), try-both, never a key selector; a sender always
  emits under the current secret only. Tickets get no overlap — rotating the
  secret invalidates every outstanding ticket at once. See
  `docs/shared-secret.md` §Rotation for the runbook.
- **Changed:** `atoms dev` provisions a fresh per-machine dev secret into the
  Worker project's gitignored `.dev.vars` (creating the file if needed, never
  overwriting a value already there) instead of running keyless. Local and
  production run the identical auth code path, including signed tickets.

### UPGRADING to 0.2.0

This release deletes four variables outright — `ATOMS_APP_KEY`,
`ATOMS_API_KEY`, `ATOMS_CALLBACK_SIGNING_KEY`, `ATOMS_PLATFORM_PUBLIC_KEY` —
with no compatibility shim. A deployment still setting only the old names
fails loudly: the Worker answers `misconfigured` on every route but
`GET /healthz`.

1. Generate one secret: `openssl rand -base64 32`.
2. Set it as `ATOMS_SHARED_SECRET`, identically, on both sides: on the
   Worker, `wrangler secret put ATOMS_SHARED_SECRET`; on the monolith,
   wherever its other secrets already live. It must never be sent over the
   wire — it is the root the bearer, ticket, and callback keys all derive
   from, not a bearer token itself.
3. Delete the four old variables — `ATOMS_APP_KEY`, `ATOMS_API_KEY`,
   `ATOMS_CALLBACK_SIGNING_KEY`, `ATOMS_PLATFORM_PUBLIC_KEY` — from both
   sides.
4. Set `ATOMS_BEARER_AUTH` if the deployment is not using the `required`
   default: `disabled` only when an authenticating proxy such as Cloudflare
   Access already sits in front of the Worker.
5. Every WebSocket ticket outstanding at cutover is invalidated — rotating
   the signing root always invalidates tickets, with no overlap. A
   reconnecting browser mints a fresh one automatically.
6. Update curl/troubleshooting examples: `-H "Authorization: Bearer
   $ATOMS_APP_KEY"` becomes `-H "Authorization: Bearer $(atoms token)"`.

Rolling this out with zero downtime for bearer auth and callbacks: set
`ATOMS_SHARED_SECRET_PREVIOUS` to the old secret alongside the new
`ATOMS_SHARED_SECRET` on both sides during the overlap window, then remove
`ATOMS_SHARED_SECRET_PREVIOUS` from both once every instance holds the new
secret. See `docs/shared-secret.md` §Rotation for the full runbook.

- **Changed:** the scaffolded Worker project (`@atomsphp/runtime-cloudflare`)
  now ships with the Worker's `/debug` routes **off**, matching the Worker's
  own default. The template's `wrangler.jsonc` is built from a new
  `wrangler.scaffold.jsonc` (worker name `atoms-worker`) instead of the
  conformance harness's config, which had leaked its `ATOMS_DEBUG_ENDPOINTS=1`
  var and `atoms-mvp-conformance` name into customer deployments. The
  conformance suite still runs with the flag on via the harness's own
  `wrangler.jsonc`, which no longer ships.
- **Added:** a supported, re-scaffold-proof way to enable debug endpoints:
  `"debug_endpoints": true` on an environment in `atoms.json`. Both
  `atoms dev` and `atoms deploy` forward it to Wrangler as
  `--var ATOMS_DEBUG_ENDPOINTS:1`, and both print a line when it is in
  force. Editing the Worker directory's `wrangler.jsonc` was never durable —
  `.atoms/worker` is gitignored and the deploy Action regenerates it on a
  fresh checkout. The value must be a JSON boolean; a string such as
  `"false"` is refused (ATOMS-E070) rather than coerced. `/debug` stays
  behind the Worker's auth check; the flag is a second gate, except under
  `ATOMS_BEARER_AUTH=disabled` (an authenticating proxy in front of the
  Worker), where it is the only one — see `docs/cloudflare-toolchain.md`
  §Debug endpoints.

## [0.1.1] - 2026-08-15

- **Fixed:** `dispatch()` took an `AtomJob` instance, which could never work —
  a job's source lives in World B and is never packed into a deployed bundle,
  so every dispatch died at the call site with `Class "..." not found` (or
  vanished silently inside a `catch (\Throwable)`). `dispatch()` now takes the
  job's class name (`$this->dispatch(RecordGameResult::class, [...])`),
  resolved by the compiler from the caller's own `use` statement — no job code
  reaches the platform. This is a signature change, not an addition: the old
  `dispatch(AtomJob $job)` shape could never have worked from a deployed Atom,
  so there is no working caller to keep compatible.
- **Added:** WebSocket connection tickets, so a browser-facing deployment can
  run with `ATOMS_APP_KEY` set at all. `POST /tickets/:type/:id` mints a
  short-TTL, HMAC-SHA256-signed, atom-scoped ticket (reusable within its TTL,
  not single-use) presented as `?ticket=` on the `/ws` upgrade, since a
  browser `WebSocket` cannot send an `Authorization` header. Previously,
  enabling auth meant running with it off entirely, leaving `POST /invoke`
  open to the world. New wire codes `ticket_invalid` / `ticket_expired` and
  client-side catalog code `ATOMS-E067`. See `cloudflare/docs/mvp-spec.md`
  §Routing and auth.

## [0.1.0] - 2026-08-14

Initial open-source release of the Atoms programming model, Laravel and
Symfony adapters, testing and PHPStan tooling, deterministic CLI build and
deploy workflow, and the Cloudflare Durable Object PHP runtime.

[Unreleased]: https://github.com/AtomsPHP/atoms/compare/v0.3.1...main

[0.1.0]: https://github.com/AtomsPHP/atoms/releases/tag/v0.1.0

[0.1.1]: https://github.com/AtomsPHP/atoms/releases/tag/v0.1.1

[0.2.0]: https://github.com/AtomsPHP/atoms/releases/tag/v0.2.0

[0.3.0]: https://github.com/AtomsPHP/atoms/releases/tag/v0.3.0

[0.3.1]: https://github.com/AtomsPHP/atoms/releases/tag/v0.3.1
