# Monorepo Conventions & Cross-Package Contracts

This document pins every decision that more than one package depends on. If a
package and this document disagree, the package is wrong.

## Decisions already made (do not reopen)

- Vendor prefix: **`atoms/*`** — `atoms/core`, `atoms/client`, `atoms/laravel`,
  `atoms/symfony`, `atoms/testing`, `atoms/phpstan-rules`, `atoms/cli`,
  `atoms/database-illuminate`.
- PHP baseline: **`^8.3`**. No 8.4-only features (no property hooks, no
  asymmetric visibility) anywhere — `atoms/core` executes inside the platform's
  PHP 8.3 runtime image.
- Scope: full Phase 1 of the integration plan (§11).
- All packages: `declare(strict_types=1);`, final classes unless the class is
  explicitly designed for extension (`Atom`, `AtomMethods`, `AtomJob`).
- Monorepo package versions are pinned at `0.1.0` in each package's
  `composer.json`; inter-package constraints are `^0.1`. Release tooling will
  own versions later — do not hand-edit them.

## Layout

```
packages/
├── core/           atoms/core            namespace Atoms\            the runtime ABI
├── client/         atoms/client          namespace Atoms\Client\     framework-free monolith SDK
├── laravel/        atoms/laravel         namespace Atoms\Laravel\    thin adapter (< ~1,500 lines)
├── symfony/        atoms/symfony         namespace Atoms\Symfony\    Symfony adapter bundle
├── testing/        atoms/testing         namespace Atoms\Testing\    AtomHarness etc.
├── phpstan-rules/  atoms/phpstan-rules   namespace Atoms\PHPStan\    boundary rules
├── cli/            atoms/cli             namespace Atoms\Cli\        `atoms` binary + build library
└── database-illuminate/  atoms/database-illuminate  namespace Atoms\DatabaseIlluminate\  Illuminate bridge: query builder + Eloquent against the Atom's own SQLite
```

Tests live in `packages/<pkg>/tests`, namespace `Atoms\<Pkg>\Tests\`
(core uses `Atoms\Core\Tests\`; cli uses `Atoms\Cli\Tests\`). Test-namespace →
directory mappings are registered in the **root** `composer.json`
`autoload-dev` (package `autoload-dev` sections are ignored by the root
install — keep them present anyway for standalone package installs).

### Layering (the §11 discipline)

```
core  ←  client  ←  laravel
      ←  testing            ←  symfony (client only!)
      ←  phpstan-rules
      ←  cli
      ←  database-illuminate
```

- `atoms/core` depends on **nothing** framework-ish. `psr/*` interfaces only.
- `atoms/client` depends on core + PSR-7/17/18/15 interfaces. No framework code.
- `atoms/laravel` and `atoms/symfony` depend on `atoms/client`. If the Symfony
  skeleton needs anything from `atoms/laravel`, the layering is broken — fix
  the layering, not the skeleton.
- `atoms/testing` depends on core (+ phpunit). NOT on client.
- `atoms/cli` depends on core (+ symfony/console, nikic/php-parser). NOT on
  client — it speaks the deploy HTTP API itself via ext-curl.
- `atoms/database-illuminate` depends on core (+ illuminate/database). NOT on
  client or the adapters.
- Nothing ships in `atoms/laravel` that could live in `atoms/client` or
  `atoms/core`.

This diagram is enforced: `LayeringRule` (atoms/phpstan-rules) runs over every
`packages/*/src` in `composer stan`, and a reference across a layer boundary is
ATOMS-E100. The adapter-facing half of the contract — what each host
supplies — is docs/adapters.md.

## The `atoms/core` ABI (frozen surface)

Everything below is wire-protocol-grade API. Implement exactly these
signatures; additions are fine, changes are not.

**Through the 0.x line, that freeze is a default, not a wall.** The packages
are on Packagist — `atoms/core` 0.1.0 published 2026-08-14 — but they are
explicitly pre-release, and a `^0.1` constraint promises nothing across a minor.
Publication is not the line; 1.0 is. Until then a signature that cannot be used
correctly is worth fixing while fixing it is still cheap: `dispatch()` was
changed in place that way, having taken an `AtomJob` instance that required a
class the platform never had, and now taking the class name plus its arguments.

A change like that needs a deliberate decision, a build-time error that names
the new shape (`ATOMS-E104` does it here), and a note in the release notes for
the minor that carries it — never a quiet edit, and never a call to make
mid-task. At 1.0 the freeze becomes a wall.

```php
namespace Atoms;

/** @template TApp of AtomMethods */
abstract class Atom
{
    public readonly string $id;

    final public function __construct(string $id, Runtime\AtomContext $context);

    protected function db(): Database;
    /** @return TApp (proxy) */
    protected function app(): object;
    /** @param class-string<AtomJob> $job @param array<string, mixed> $args */
    protected function dispatch(string $job, array $args = []): void;
    protected function config(string $key): mixed;
    protected function broadcast(string $channel, array $payload): void;
    protected function timers(): Timers\Timers;

    // lifecycle (invoked by the runtime via Atoms\Runtime\LifecycleInvoker)
    protected function onActivation(): void {}
    protected function onDeactivation(): void {}
    protected function onTimer(string $name): void {}

    // WebSocket handlers (optional overrides). Reply to one connection with
    // $conn->sendJson([...]); read an inbound frame with $msg->json().
    public function onConnect(Websocket\Connection $conn, array $params): void {}
    public function onMessage(Websocket\Connection $conn, Websocket\Message $msg): void {}
    public function onDisconnect(Websocket\Connection $conn): void {}
}

abstract class AtomMethods {}   // World B base: callback methods, full framework access
abstract class AtomJob {}       // World B base: constructor args are the contract;
                                // params MUST be promoted public properties with
                                // serialization-algebra types. The class does NOT
                                // ship, so Atom code dispatches it BY NAME:
                                // dispatch(X::class, ['param' => $v]). Building
                                // one with `new` inside an Atom is ATOMS-E104 —
                                // the class does not exist on the platform.

interface Database
{
    public function pdo(): \PDO;
    public function query(string $sql, array $bindings = []): array;
    public function execute(string $sql, array $bindings = []): int;
    public function transaction(callable $fn): mixed;
}
```

Supporting types in core (exact FQCNs — other packages reference these):

- `Atoms\Runtime\AtomContext` — interface the runtime/harness implements:
  `db(): Database`, `app(): object`,
  `dispatch(string $job, array $args = []): void`,
  `config(string $key): mixed`, `broadcast(string $channel, array $payload): void`,
  `timers(): Timers\Timers`.
- `Atoms\Timers\Timers` — interface for named one-shot timers:
  `schedule(string $name, \DateTimeImmutable $at): void`, `cancel(string $name): void`,
  `scheduledAt(string $name): ?\DateTimeImmutable`. Delivery invokes the Atom's
  `onTimer($name)` hook.
- `Atoms\Runtime\LifecycleInvoker` — static helpers to invoke the protected
  lifecycle hooks from outside (Closure::bind), used by runtime + harness.
- `Atoms\Sqlite\SqliteDatabase implements Database` — PDO(sqlite) wrapper.
  Opens with `journal_mode=WAL`, `synchronous=NORMAL`, `busy_timeout=5000`,
  foreign keys on. Constructor takes a `\PDO` OR a static `open(string $path)`.
- `Atoms\Serialization\Payload` — empty marker interface for boundary DTOs.
- `Atoms\Serialization\Serializer` — `normalize(mixed): mixed` (JSON-safe
  tree) and `denormalize(mixed $data, string $type): mixed`. Implements the
  type algebra below. Throws `Atoms\Serialization\SerializationException`
  (which carries an `Atoms\Errors\ErrorCode`). Two argument-binding helpers sit
  on the same class: `denormalizeArguments(array $args, \ReflectionFunctionAbstract $fn): list<mixed>`
  for positional RPC arguments, and — added 2026-08-16 —
  `denormalizeNamedArguments(string $class, array $wireArgs): list<mixed>`,
  which binds a `{"job": FQCN, "args": {...}}` argument map to a constructor by
  parameter name (wire value, else declared default, else null when nullable,
  else ATOMS-E024). It owns that algebra: code rehydrating a dispatched job —
  in `atoms/client`, `atoms/laravel`, `atoms/symfony`, `atoms/testing` or an
  adapter of your own — binds through it rather than reimplementing the loop.
- `Atoms\Migrations\Migrator` — applies ordered migrations to a `Database`
  using SQLite's `user_version` pragma; `apply(Database $db, MigrationSet $set): int`.
- `Atoms\Migrations\MigrationSet` — ordered collection loaded from a directory
  of `NNN_name.sql` files (and optionally `NNN_name.php` returning an object
  implementing `Atoms\Migrations\Migration` with `up(Database $db): void`).
  Validates: strictly increasing numbers, no duplicates; exposes
  `headVersion(): int` and per-migration content hashes (sha256).
- `Atoms\Websocket\Connection` — interface: `id(): string`,
  `send(string $payload): void`, `sendJson(array $payload): void`,
  `close(int $code = 1000, string $reason = ''): void`.
- `Atoms\Websocket\Message` — interface: `payload(): string`, `json(): array`,
  `isBinary(): bool`. `json()` throws `\JsonException` for malformed input and
  for a top-level value that is not a JSON object, so one catch covers every
  unusable frame.
- `Atoms\Websocket\JsonFrame` — `encode(array, ?Serializer): string` and
  `decode(string): array`, the **single encoder** for structured frames.
  `Connection::sendJson()`, `Message::json()` and the runtime's `broadcast()`
  all pass through it, which is what keeps their normalization identical. The
  rule: `Serializer::normalize()`, then `json_encode()` with
  `JSON_UNESCAPED_SLASHES` and the default depth; an empty top-level map
  encodes as `{}` so it round-trips.
  What it does **not** decide is the envelope — `broadcast()` wraps its payload
  in `{"kind":"broadcast","channel":…,"payload":…}` because a socket on several
  channels must tell two broadcasts apart, while `sendJson()` has no channel and
  sends the object bare. That asymmetry is the runtime's, not the encoder's.
- `Atoms\Attributes\SharedWithAtoms` — class attribute marking a DTO outside
  `Shared/` as boundary-shared.
- `Atoms\Attributes\MethodsFor` — class attribute:
  `#[MethodsFor(GameRoom::class)]` overrides Methods-class resolution.
- `Atoms\Errors\ErrorCode` — string-backed enum of every `ATOMS-E###` code.
- `Atoms\Errors\ErrorCatalog` — loads `packages/core/resources/errors.json`;
  `get(ErrorCode): CatalogEntry` (title, message template, fix, docsUrl,
  severity, phase). The JSON file is the **single source of truth**; the enum
  and JSON must stay in sync (a core test enforces this).
- `Atoms\Errors\AtomsError` — base `\RuntimeException` carrying an `ErrorCode`.

## Serialization type algebra

Legal boundary types (RPC args/returns, `app()` calls, `dispatch()` payloads,
Shared DTO properties, WebSocket structured frames):

- `null`, `bool`, `int`, `float`, `string`
- lists and string-keyed maps of legal types (`array`)
- classes implementing `Atoms\Serialization\Payload` — hydrated by promoted
  constructor-property name; nesting allowed
- `\DateTimeImmutable` ⇄ RFC 3339 string
- `\BackedEnum` ⇄ its backed value

Explicitly illegal (build error + PHPStan error + runtime
`SerializationException`): closures, resources, `\DateTime` (mutable),
Eloquent models / Doctrine entities / anything not on the list. PHP native
`serialize()`/`unserialize()` never appears anywhere in this codebase.

Wire form of a Payload object: plain JSON object of its promoted properties.
Wire form of an AtomJob: `{"job": "FQCN", "args": {"param": value, ...}}`
(constructor args by name through the same serializer).

## Runtime HTTP contract (client + cli)

The Worker is single-tenant, so there is no customer prefix and no
Atoms-operated service. `docs/cloudflare-toolchain.md` is normative for the
decisions below.

`atoms/client` calls the Worker:

- `POST {baseUrl}/invoke/{type}/{id}/{method}` body `{"args": [...]}`.
- WebSocket connection tickets are **not** minted over HTTP: there is no
  `POST /tickets` route. `Atoms\Client\Tickets\TicketIssuer` issues them
  locally, in the application process, from the same derived key the Worker
  verifies against — no round trip. `docs/ws-ticket-protocol.md` is
  normative for the wire format, limits, expiry rule, and vectors; the
  Worker's `GET /ws/{type}/{id}` upgrade remains the strict, stateless
  verifier, spec'd in `cloudflare/docs/mvp-spec.md` §Routing and auth.
- `Authorization: Bearer {bearer}`, where `{bearer}` is
  `HKDF(ATOMS_SHARED_SECRET, "atoms/bearer/v1")` — `AtomsConfig::$sharedSecret`
  is a required, validated string (`docs/shared-secret.md`), so the client
  always sends a bearer. `ATOMS_BEARER_AUTH=disabled` on the Worker skips the
  comparison; it does not change what the client sends.
- Additive headers we send (allowed within v1):
  - `Idempotency-Key: <32 hex chars>` — stable across retries of one logical call.
  - `X-Atoms-Manifest-Hash: <sha256>` — manifest hash the monolith was built
    against (omitted when no local manifest is present).
  - `traceparent` — W3C trace context, generated if absent.
- Retry policy: retry only errors marked `retryable: true` in the contract's
  table AND transport-level failures, with exponential backoff + jitter;
  never retry non-retryable codes. `turn_deadline_exceeded` retries are
  opt-in per call site (default off).
- The WebSocket URL for an Atom is `AtomsClient::wsUrl()`, which derives
  `ws`/`wss` from the one configured endpoint (`AtomsConfig::wsBaseUrl()`).
  There is deliberately no second `ws_endpoint` setting: the Worker serves
  `/invoke` and `/ws` from one origin, so a second key would only be a second
  thing to get wrong. A ticket is passed in, never minted by the builder.

### Per-call options, and why the proxy declares nothing

Per-call configuration is an `Atoms\Client\CallOptions` passed to
`AtomsClient::get()` (and `AtomsManager::get()`), not a fluent method on the
proxy:

```php
Atoms::get(GameRoom::class, $id, new CallOptions(retryTurnDeadline: true))
    ->recordResult($score);
```

**`Atoms\Client\AtomProxy` declares `__construct`, `__call` and `__get`, and
nothing else, permanently.** Every other name on it belongs to the Atom. A
declared method beats `__call()` in PHP, silently, so a fluent
`->retryingTurnDeadline()` would make a customer Atom method of that name
unreachable — the wrong code would run, with no error at either end. This is
the same hazard the Worker's `invocable_method()` denylist exists for, and the
reason options arrive before the proxy does rather than through it.

`get()` is annotated `@template T` / `@return T`, so
`Atoms::get(GameRoom::class, $id)->join($player)` is statically checked and a
typo is an error rather than a runtime 404. `__get()` throws for the same
reason: the annotation makes `->id` look legal, but an Atom's properties live
on the platform and nothing was fetched, so the honest answer is a loud
exception instead of a warning and `null`. A Laravel facade's `@method` block
cannot carry a template, so full inference is available where the manager or
client is injected.
- Error mapping (`Atoms\Client\Exception\*`): `unknown_atom_type` →
  `AtomNotDeployed`; `turn_deadline_exceeded` → `TurnDeadlineExceeded`;
  `capacity_refused`/`rate_limited` → `CapacityRefused` (carries retry-after);
  `machine_unavailable`/`directory_unavailable` → `PlatformUnavailable`;
  a remote PHP exception inside a 200-with-error-frame or 5xx w/ atom error
  detail → `RemoteAtomException` (original class name + sanitized trace);
  anything else → `AtomsRequestFailed`.

`atoms/cli` has no HTTP client at all. `deploy`, `status`, `rollback` and
`secrets` drive a pinned, locally installed Wrangler as a subprocess
(`Atoms\Cli\Cloudflare\`), with the user's own `CLOUDFLARE_API_TOKEN` and
`CLOUDFLARE_ACCOUNT_ID` passed to that child process and nowhere else. `npx` is
never used, so no toolchain is fetched at deploy time. See
`docs/cloudflare-toolchain.md` §2.

## Callback signing (platform → monolith)

Implemented by `Atoms\Client\Callback\CallbackKernel` (PSR-15); verified
**before** anything else runs:

- Headers: `X-Atoms-Signature` (standard base64 of a 32-byte HMAC-SHA256
  tag), `X-Atoms-Timestamp` (unix seconds), `X-Atoms-Nonce` (32 hex),
  `X-Atoms-Kind` (`methods` | `job`).
- Signed message: `"v1\n" . timestamp . "\n" . nonce . "\n" . rawBody`.
- Verify with `hash_equals()` against `HKDF(ATOMS_SHARED_SECRET,
  "atoms/callback/v1")`, rejecting any signature that does not decode to
  exactly 32 bytes before comparing; during a rotation overlap the same
  check also tries the key derived from `ATOMS_SHARED_SECRET_PREVIOUS`,
  accepting a signature that verifies under either (`Atoms\Client\Crypto\
  KeyDerivation::callbackKeys()`). Reject if |now − timestamp| > 300s
  (configurable); nonce LRU (default 10,000 entries, configurable) rejects
  replays.
- `methods` body: `{"atom":{"type":"GameRoom","id":"g-1"},"method":"getPlayer",
  "args":[...],"manifest_hash":"..."}` → resolve Methods class, denormalize
  args against the method signature, invoke, respond `{"result": <json>}`.
- `job` body: `{"job":"FQCN","args":{...}}` → denormalize, hand the constructed
  `AtomJob` to the configured `Atoms\Client\Callback\QueueBridge` interface
  (`enqueue(AtomJob $job): void`); respond `{"queued": true}`.
- Error responses use the platform error envelope shape
  `{"error":{"code":"ATOMS-E064","message":"..."}}` with 401/403/422 as apt.

Methods resolution default: Atom class `App\Atoms\GameRoom` → Methods class
`App\Atoms\GameRoom\Methods`; `#[MethodsFor]` or an explicit map overrides.
(`Atoms\Client\Callback\MethodsResolver`.)

The Cloudflare Worker is the production signer of this channel
(`cloudflare/worker/src/callbacks.js`, HMAC-SHA256 WebCrypto — see
`cloudflare/docs/mvp-spec.md` §The callback channel for headers and message
construction, and `docs/shared-secret.md` for the key derivation). It omits
`manifest_hash` from the `methods` body — a documented gap, safe today
because `CallbackKernel::handleMethods()` never reads that key — and its
dispatch job bodies are encoded dual to `CallbackKernel::constructJob()`:
both sides walk the job's constructor parameters and key `args` by
**parameter name**, resolved from a same-named **promoted public**
constructor property on the guest side.

`atoms/client` verifies with PHP's built-in `hash_hmac()`/`hash_equals()`
and declares no `ext-sodium` dependency.

## `atoms.json` (toolchain anchor) and `atoms-composer.json`

Exactly as integration plan §1. The CLI must not assume `app/Atoms` — always
read `paths.atoms` / `paths.shared` from `atoms.json`. `atoms-composer.json`
is a normal composer.json restricted to `require` + `repositories`; the beta
package allowlist lives in `packages/cli/resources/allowed-packages.json`.

## Manifest schema (CLI emits, client loads)

`manifest.json`, `"schema": 1`. Top-level keys: `project`, `atoms` (list of
`{type, class, file, methods: [{name, params: [{name, type, optional,
default?}], return}], websocket: bool, migrations: {head: int, files:
[{version, name, sha256, path}]}}`), `methods` (list of
`{atom_type, class, methods: [...]}`), `jobs`
(list of `{class, params: [...]}`), `shared` (list of `{class, properties:
[{name, type}]}`), `toolchain` (`{core_version, php, extensions: [...],
scoper_prefix}`), `vendor` (optional, present only when the build shipped
`atoms-composer.json` packages: `{autoload, packages: {name: version}}`,
where `autoload` is the bundle-relative path of the build-generated vendor
autoload file — see `docs/cloudflare-toolchain.md` §3), `content_hash`
(sha256 of the bundle tarball, hex).
`file` and `migrations.files[].path` are bundle-relative paths:
the Cloudflare Worker must `require` and migrate exactly those files, and
`MigrationEntry::$name` keeps only the descriptive part of `NNN_name.sql`, so
neither is reconstructable from the rest of the manifest.

`manifest_hash` anywhere = sha256 of the canonical JSON encoding of the
manifest **without** the `content_hash` key: keys recursively sorted
(lists keep order), no whitespace, encoded with
`JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE`.
Type strings in the manifest use PHP type syntax (`string`, `?int`,
`App\Atoms\Shared\PlayerSnapshot`, `list<string>` for PHPDoc-refined arrays).

## Error catalog

`packages/core/resources/errors.json` is seeded with the initial codes — read
it before inventing a code. Ranges:

| Range | Domain | Primary producer |
|---|---|---|
| E00x | discovery/classification | cli |
| E01x | boundary violations | phpstan-rules + cli |
| E02x | serialization algebra | phpstan-rules + core runtime |
| E03x | Methods/Job contract mismatches | cli + phpstan-rules |
| E04x | deploy / version skew | cli + client |
| E05x | migrations | cli + core |
| E06x | client / callback runtime | client |
| E07x | CLI / configuration | cli |
| E08x | worker runtime seams (callback channel, timers) | worker runtime |
| E10x | adapter discipline: layering, frozen clock, adapter supply contracts | phpstan-rules + client |

Every user-facing failure message in every package includes its `ATOMS-E###`
code and the catalog fix line. New codes: add to the JSON **and** the
`ErrorCode` enum; append-only, never renumber.

**What "append-only" protects, and what it does not** (clarified 2026-08-09).
The rule exists so a code means the same thing forever, because codes end up in
runbooks, search boxes and support threads. So:

- **Never** reuse a retired number, renumber an existing one, or repoint one at
  a different kind of failure. Those break the promise.
- **Updating the `message` or `fix` text of an existing code is allowed**, and
  is sometimes required: a code that is still thrown must describe the failure
  as it is now, under the same number.

Through 0.x the bar for rewording stays low: the packages are published, but
pre-release, and message text is not something a `^0.1` constraint promises. At
1.0 the bar rises — a code's wording starts showing up in users' runbooks — but
the two bullets above stay the rule either way.

## Testing & tooling

- One root `composer install`; one root `vendor/`. Run everything from repo
  root: `composer test` (phpunit, all suites), `composer test -- --testsuite=core`,
  `composer stan` (phpstan on all `packages/*/src`).
- Do NOT run `composer install/update` yourself while other work is in flight;
  if you need a new dependency, add it to **your package's** composer.json,
  write the code, and report it — the integrator refreshes the root install.
- Never edit root files (`composer.json`, `phpunit.xml.dist`,
  `phpstan.neon.dist`, this file) or another package's directory.
- Tests must not hit the network. HTTP is tested against an in-memory PSR-18
  fake (client) or a local socket where unavoidable (none expected).
- SQLite tests use `:memory:` or a temp dir under `sys_get_temp_dir()`.
- Determinism: `atoms build` output must be byte-identical for identical
  input trees — sorted file order, fixed mtimes (0), fixed uid/gid, gzip with
  fixed mtime. There is a test asserting two builds of the same fixture hash
  identically.
