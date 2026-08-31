# Atoms — Framework Integration Plan

**Status:** Proposal v1 — July 2026
**Scope:** Everything that lives in the customer's codebase and the seam between it and the platform: the SDK packages, the code-extraction pipeline, the callback surface, local development, testing, CI/CD, and agent tooling.
**Inputs:** the project's internal requirements, overview, app-integration and platform design documents.

> **Read as design rationale, not as current direction (noted 2026-08-09, M3).**
> Why the packages are split, the two-worlds model, the closure-walk
> extraction pipeline and the callback surface are all still exactly right, and
> that is what this document is kept for.
>
> Everything it says about *where an Atom runs and how it gets there* is
> superseded. It describes the hosted Fly-era platform: a Docker runtime image
> for local development (§6.2), OIDC token exchange for CI credentials (§7.2),
> and Atoms-operated deploy endpoints. Atoms now deploys into the user's own
> Cloudflare account by driving their own Wrangler, and runs as PHP-in-Wasm
> inside a Durable Object. The superseding documents are
> `docs/cloudflare-toolchain.md` (deploy, auth, bundles) and
> `cloudflare/docs/mvp-spec.md` (the runtime itself). Individual corrections
> are marked inline below.

---

## 0. Headline calls

1. **The Atom programming model is not a Laravel package — it's a framework-agnostic core with framework adapters.** Everything an Atom class can touch at runtime lives in `atoms/core`, a package with **zero** framework dependencies. `laravel/atoms` becomes a thin adapter over it. This is not speculative generality: the production runtime already runs no Laravel, so any Laravel symbol an Atom references is a bug today. Making the boundary a *package boundary* turns that bug class into a Composer-resolvable, statically-checkable fact.
2. **Extraction is a dependency-closure build, and it must be a pure function of the repository.** `atoms build` run locally, in CI, and inside the platform build service must produce byte-identical bundles from the same commit. The build service validates and re-executes; it never does anything a developer can't reproduce on their laptop. Debugging "works locally, rejected on deploy" is the fastest way to lose customers.
3. **Boundary enforcement moves left, into the customer's own CI, as a PHPStan extension.** Deploy-time rejection (as currently specified in the requirements' build-service section) is the *last* line of defense, not the developer experience. `atoms/phpstan-rules` makes "you referenced an Eloquent model inside an Atom" a red squiggle in the IDE and a failed CI check, with a machine-readable error catalog (which agents consume too — §8).
4. **`$this->db()` returns a PDO-level interface from core, not "a standard Laravel database connection."** The requirements doc's phrasing is a trap: there is no Laravel in the runtime. Query-builder ergonomics come from `illuminate/database` used as a *standalone approved dependency* (it works fine without the framework), via an optional bridge package — a choice the customer makes in `atoms-composer.json`, not something the platform smuggles in.
5. **The CLI is a standalone binary (`atoms`), not an Artisan command.** Artisan requires booting the customer's app; a broken app then blocks deploys, and Symfony users have no Artisan at all. The `atoms` binary reads a framework-neutral `atoms.json` at the repo root. `php artisan atoms:*` commands survive as thin wrappers for muscle memory.
6. **Per-Atom SQLite schema migrations are a first-class feature nobody has specified yet.** Every Atom type with a database needs schema evolution across thousands of independently-activated database files. Version-stamped migrations ship in the bundle and run at activation (§4.3). Without this, the first schema change any beta customer makes is a support ticket.
7. **Bundled vendor code is isolated with PHP-Scoper.** The requirements say dependencies "cannot override or conflict with the platform runtime's own dependencies" but don't say how. Namespace-prefixing bundled vendors at build time is how; anything else is hope.
8. **Version skew between the monolith and deployed Atoms is a permanent condition, not an edge case.** The monolith and the Atom fleet deploy on different schedules by design. The manifest becomes a versioned contract; the SDK sends a manifest hash on every call; the CLI can diff live-vs-local; docs teach expand/contract. (§7.4)
9. **Agent tooling is generated, project-specific, and installed into the customer's repo** — a set of Claude Code / agent skills produced from the customer's own manifest, regenerated on deploy, so an AI agent working in the repo knows this project's Atoms, this project's boundary rules, and the exact error codes it will hit. (§8)
---

## 1. Package architecture

Eight packages. The split is dictated by *where code executes*, not by feature area — that's the discipline that keeps the Symfony adapter a bundle-sized project instead of a fork.

| Package | Executes in | Depends on | Contents |
|---|---|---|---|
| `atoms/core` | **Both** (runtime + monolith) | nothing framework-ish; `psr/*` interfaces only | `Atom` base class, `AtomMethods` base, `AtomJob` base, lifecycle hooks, serialization contracts, `Database` interface, migration runner, config/secrets accessor, error taxonomy, attributes |
| `atoms/client` | Monolith | `atoms/core`, PSR-18/17/7 | Stub-proxy generator, `AtomsClient` (RPC transport, retries, idempotency keys), ticket acquisition, `CallbackKernel` (PSR-15), Methods dispatcher, manifest loader, trace propagation |
| `atoms/laravel` | Monolith | `atoms/client`, `illuminate/support` | Service provider, `Atoms` facade, callback route registration, queue bridge for `AtomJob`, Artisan wrappers, config publishing, `Atoms::fake()` |
| `atoms/symfony` (Phase 2/3) | Monolith | `atoms/client` | Bundle, DI extension, callback controller, Messenger bridge, console wrappers |
| `atoms/testing` | Dev | `atoms/core` | `AtomHarness` (in-process Atom execution against temp SQLite), fake `app()` proxy that executes real Methods classes locally, dispatched-job recorder, WebSocket test client |
| `atoms/phpstan-rules` | Dev/CI | phpstan | Boundary rules, serialization rules, call-site collector for contract diffing |
| `atoms` CLI | Dev/CI | (distributed as PHAR/binary) | `init`, `make`, `validate`, `build`, `deploy`, `rollback`, `diff`, `secrets`, `local`, `tunnel`, `ai:install` |
| `atoms/database-illuminate` | Runtime (inside the Atom) | `atoms/core`, `illuminate/database` | The optional query-builder/Eloquent bridge designed in §4.4, now built: Illuminate's builder and models over the Atom's own SQLite database |

Two consequences worth stating explicitly:

**`atoms/core` is the runtime ABI.** The platform runtime ships a copy of `atoms/core`; customer bundles are compiled against a version of it. The bundle manifest records the `atoms/core` version it was built with, and the runtime declares a supported range (`^1.0`). Core therefore carries the strictest semver discipline in the whole product — a breaking change to the `Atom` base class is a breaking change to every deployed customer bundle. Treat its public API like a wire protocol.

**The customer's `composer.json` requires `laravel/atoms` (or `atoms/symfony`), and gets `core` + `client` transitively.** Inside `app/Atoms/`, only `atoms/core` symbols (plus approved deps and shared code, §3.3) are legal. The boundary is now expressible as one sentence — *code under the Atoms path may only import from `atoms/core`, `app/Atoms/Shared`, and packages listed in `atoms-composer.json`* — which is exactly the sentence the PHPStan rules, the build service, and the agent skill all implement.

### Repo-root anchor: `atoms.json`

The framework-neutral configuration the CLI and build pipeline key off:

```json
{
    "project": "acme-games",
    "paths": {
        "atoms": "app/Atoms",
        "shared": "app/Atoms/Shared"
    },
    "php": "8.4",
    "environments": {
        "production": { "endpoint": "https://api.atoms.cloud", "region": "iad" },
        "staging":    { "endpoint": "https://api.atoms.cloud", "region": "iad" }
    },
    "callback_url": { "production": "https://myapp.com", "staging": "https://staging.myapp.com" }
}
```

Runtime-side credentials, timeouts, and retry policy stay in the framework's own config (`config/atoms.php`, Symfony `atoms.yaml`) because they're consumed by the running app, not the toolchain. Nothing in `atoms.json` mentions Laravel.

---

## 2. The two-worlds model

Every design decision in this document flows from one fact: **the customer's repository contains code for two different runtimes**, and the file layout must make it impossible to be confused about which is which.

```
app/Atoms/
├── GameRoom.php                    ← WORLD A: ships to platform, runs on Amp runtime
├── GameRoom/
│   ├── Methods.php                 ← WORLD B: stays in monolith, full Laravel access
│   └── migrations/
│       ├── 001_create_events.sql   ← WORLD A: ships, runs at activation (§4.3)
│       └── 002_add_round_index.sql
├── Shared/
│   └── PlayerSnapshot.php          ← BOTH: DTO crossing the RPC boundary (§3.3)
├── Jobs/
│   └── RecordGameResult.php        ← WORLD B: handle() runs in monolith; constructor
│                                      signature is part of the contract (§3.4)
└── ...
atoms.json                          ← toolchain anchor
atoms-composer.json                 ← World A dependencies only
```

World A code obeys the boundary rules. World B code is ordinary application code. `Shared/` is the only place that must satisfy *both* worlds' constraints — it ships in the bundle and is autoloaded in the monolith, so it may only reference `atoms/core` and PHP stdlib.

The rule of thumb we teach (docs, error messages, agent skill, all use the same phrasing): **"If it extends `Atom`, it leaves. If it extends `AtomMethods` or `AtomJob`, it stays. If it's in `Shared/`, it does both — so it must be pure data."**

---

## 3. Extraction: how Atom code leaves the Laravel app

### 3.1 The pipeline

`atoms build` is the canonical implementation; the platform build service runs the same code (the CLI's build library is a dependency of the build service). Stages:

1. **Discover.** Parse `atoms.json`; enumerate classes under the Atoms path via the repo's own Composer classmap (no reflection-by-inclusion — never *execute* customer code at build time). Classify each file: Atom class, Methods class, AtomJob, Shared, migration, or unknown (warn).
2. **Closure walk.** Using `nikic/php-parser` + `roave/better-reflection` (static reflection, no autoload side effects), compute the transitive symbol closure of every Atom class and everything in `Shared/`: parent classes, interfaces, traits, attribute classes, `use` imports, `new`/static-call/`instanceof` targets, typed properties and signatures, constants.
3. **Classify every symbol** in the closure:
   - `atoms/core` → provided by runtime; recorded in manifest, not bundled.
   - Atoms path / `Shared/` → bundled.
   - Package in `atoms-composer.json` → bundled via vendor step (3.2).
   - PHP stdlib / bundled extension in the runtime image → allowed; extension usage recorded in manifest for platform-side compatibility check.
   - **Anything else — monolith classes (`App\Models\*`, services), Laravel framework, global helpers (`config()`, `app()`, `env()`, `now()`), facades — hard error**, with the code and fix from the error catalog (§6.4). No polyfills, ever: a polyfilled `config()` that silently returns `null` in production is worse than a build failure.
4. **Vendor resolution.** `composer install` against `atoms-composer.json` in an isolated directory; `composer audit`; then **PHP-Scoper** prefixes the entire vendor tree (`AtomsScoped\{hash}\...`) and rewrites references in the bundled customer code. Customer deps now cannot collide with the runtime's own dependencies or with another deploy's, and the requirements doc's sandboxing claim becomes true by construction. Scoper config ships with sane excludes (never prefix `atoms/core` — it must unify with the runtime's copy).
   > **Superseded (2026-08-30).** The vendor stage as built ships the resolved tree **unprefixed** with a generated classmap autoloader, pins resolution with a written-back `atoms-composer.lock`, and refuses failures loudly (ATOMS-E079). On the Cloudflare runtime there is no co-tenant to collide with — one guest holds one bundle — and prefixing vendor without rewriting the customer's own call sites would break the app against its own tree. Scoping returns, if ever, only as a whole-bundle rewrite. `docs/cloudflare-toolchain.md` §3 is normative.
5. **Manifest generation.** One JSON document, the contract artifact for everything downstream:
   - Atom types: public method names, parameter/return types (serialization-checked, §4.2), WebSocket handler presence, migration head version.
   - Methods classes: per-Atom callback method signatures (this is what the platform uses to validate `$this->app()` calls per the app-integration doc).
   - AtomJob classes: constructor signatures.
   - Shared DTO schemas.
   - Toolchain fingerprint: `atoms/core` version, PHP version, extension list, scoper prefix, content hash of the bundle.
6. **Emit.** `.atoms/build/bundle-{contenthash}.tar.gz` + `manifest.json`. Deterministic: sorted file order, stripped timestamps, pinned scoper prefix derived from content. Same commit ⇒ same hash ⇒ deploys are trivially idempotent and rollback targets are content-addressed.
`atoms validate` runs stages 1–3 and 5 only (no vendor install) in a few seconds — this is the pre-commit / fast-CI entry point.

### 3.2 What deliberately does *not* ship

Methods classes, AtomJobs (their *signatures* are in the manifest; their code stays home), tests, and anything outside the closure. The deploy command from the app-integration doc ("packages files extending `Atom`") is under-specified — it's the *closure* of those files, or trait-using Atoms break on day one.

### 3.3 Shared code: the DTO zone

Developers will immediately want types that cross the boundary — `$this->app()->getPlayer()` returning `array` is beneath the DX bar the rest of the product sets. `Shared/` is the sanctioned mechanism:

```php
namespace App\Atoms\Shared;

use Atoms\Serialization\Payload;

final class PlayerSnapshot implements Payload
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly int $elo,
    ) {}
}
```

Methods classes return these; Atoms receive them typed; the serializer (§4.2) hydrates on both sides. Shared classes are subject to the strictest rules: `atoms/core` + stdlib only, no behavior beyond accessors/factories (enforced heuristically: no I/O calls, warn on non-trivial method bodies). An escape hatch attribute `#[SharedWithAtoms]` marks classes outside the `Shared/` directory for teams with existing DTO layers — same rules apply wherever the class sits.

### 3.4 AtomJobs and Methods: contract classes that stay home

Both are World B code whose *signatures* are World A contract. The manifest captures them; the build fails if an Atom dispatches a job or calls a Methods method whose signature doesn't match (detectable statically because the Atom names the job in `$this->dispatch(RecordGameResult::class, ['ref' => $ref])` and calls `$this->app()->getPlayer(...)` — the closure walk sees both).

The dispatch site is **by name**, not `new RecordGameResult(...)`, and that is forced rather than stylistic: neither class ships, so neither can be instantiated in World A. `X::class` is a compile-time constant, so naming one costs nothing and loads nothing; constructing one is `ATOMS-E104` at build time, caught before it can become a runtime `Class "..." not found` that a best-effort `catch (\Throwable)` around the dispatch would swallow whole. The wire form is unchanged either way — `{"job": FQCN, "args": {...}}`, keyed by constructor parameter name — because that map was always what crossed; only where it gets built moved. This closes a gap in the current app-integration design: today nothing stops an Atom from calling `$this->app()->getPlyaer()` and finding out at runtime, 10–20ms and one confused on-call engineer later.

---

## 4. The runtime contract (`atoms/core`)

What an Atom is allowed to see. Keep this surface brutally small — every method here is frozen ABI (§1).

### 4.1 The base class

```php
namespace Atoms;

/** @template TApp of AtomMethods */
abstract class Atom
{
    public readonly string $id;

    protected function db(): Database;              // §4.4
    /** @return TApp proxy */
    protected function app(): object;               // reverse RPC (app-integration doc)
    protected function dispatch(string $job, array $args = []): void;  // World A: by name
    protected function config(string $key): mixed;  // §4.5 — secrets/config, NOT env()
    protected function broadcast(string $channel, array $payload): void;

    // lifecycle
    protected function onActivation(): void {}
    protected function onDeactivation(): void {}    // best-effort, per requirements

    // WebSocket handlers (optional)
    public function onConnect(Connection $conn, array $params): void {}
    public function onMessage(Connection $conn, Message $msg): void {}
    public function onDisconnect(Connection $conn): void {}
}
```

The `@template TApp` generic from the app-integration doc carries over unchanged — it's plain PHPDoc, framework-free, and gives IDE completion in both Laravel and Symfony with zero codegen.

### 4.2 Serialization contract

Everything that crosses a boundary — RPC args/returns, `app()` calls, `dispatch()` payloads, WebSocket frames — is JSON with a fixed type algebra:

- Scalars, null, lists/maps of allowed types.
- `Payload` DTOs (public promoted properties, hydrated by name; nesting allowed).
- `DateTimeImmutable` ⇄ RFC 3339. `BackedEnum` ⇄ its value.
- **Explicitly illegal**: closures, resources, Eloquent models/Doctrine entities, anything requiring the container. PHP's native `serialize()` never appears anywhere in the product — it's an RCE surface on a boundary that spans trust zones.
The PHPStan rules type-check every boundary signature against this algebra at CI time, so "you can't pass a `User` model to an Atom" is an IDE error with the fix in the message (*"pass `PlayerSnapshot::fromUser($user)` or `$user->only([...])`"*), not a deploy failure.

### 4.3 SQLite migrations (new, and mandatory)

Each Atom type may ship ordered migrations (`app/Atoms/GameRoom/migrations/NNN_*.sql`, or a PHP class for data migrations). The runtime stores the applied version in SQLite's `user_version` pragma and applies pending migrations inside the activation path, before the first turn, under the Atom's single-writer guarantee — no cross-Atom coordination needed, which is the quiet superpower of per-Atom databases: a "migration" is thousands of independent, lazy, per-activation migrations that each take microseconds.

Rules the toolchain enforces: migrations are append-only (editing a shipped migration fails `atoms validate` via manifest hash comparison); the manifest records the head version; rollback of a *code* deploy does not roll back schema, so migrations must be backward-compatible one version (expand/contract — the docs and the agent skill teach the pattern). Activation-time cost gets a budget: a migration exceeding it (default 250ms) trips a warning at build time based on static heuristics and a runtime metric in production.

### 4.4 `db()` and the Illuminate question

Core defines:

```php
interface Database
{
    public function pdo(): \PDO;
    public function query(string $sql, array $bindings = []): array;
    public function execute(string $sql, array $bindings = []): int;
    public function transaction(callable $fn): mixed;
}
```

That's the guaranteed surface. Query-builder ergonomics come from `illuminate/database` **as a standalone approved dependency**: the optional `atoms/database-illuminate` bridge boots a Capsule per Atom database file and exposes `$this->table('events')->insert([...])`. This threads the needle: Laravel developers get the API they expect, the runtime stays framework-free, Symfony users can pick a Doctrine DBAL bridge instead, and the requirements doc's "standard Laravel database connection" phrasing gets corrected to something that's actually true. Eloquent-in-Atoms (models against the Atom's own SQLite) is technically possible via the same bridge but ships **off** by default — model boot side effects and the temptation to share model classes with the monolith violate the Shared-zone purity rule; revisit on beta feedback.

### 4.5 Config and secrets

Atoms cannot read the monolith's `.env`, and nothing in the current docs says how an Atom gets an API key (relevant the moment requirements Open Question 3 — outbound network — resolves to "yes", and already relevant for per-environment tuning values). Mechanism: `atoms secrets set PAYMENTS_API_KEY --env production` stores platform-side, injected into the Machine at code-bundle load, surfaced via `$this->config()`. Non-secret config can live in `atoms.json` under an `atom_config` key, baked into the bundle. `env()` inside an Atom is a boundary error pointing here.

---

## 5. Monolith-side integration

### 5.1 Framework-agnostic middle (`atoms/client`)

Everything hard lives here, framework-free:

- **Stub proxy.** `AtomsClient::get(GameRoom::class, $id)` returns a proxy whose method calls serialize → sign → POST to the platform. Retries only on transport errors and 5xx *before* the request was accepted; every invocation carries an idempotency key so a retried-after-timeout call can't double-apply a turn. Typed error mapping: platform error frames become an exception taxonomy (`AtomNotDeployed`, `TurnDeadlineExceeded`, `QueueFull` with retry-after, `RemoteAtomException` carrying the original class name + sanitized trace).
- **`CallbackKernel`** — a PSR-15 handler implementing the inbound side of `$this->app()` and `$this->dispatch()`: verify the platform's Ed25519 signature + timestamp window (replay protection), resolve the Methods class, coerce args through the serializer, execute, serialize the response; or deserialize an AtomJob and hand it to a queue bridge interface. Pure PSR-7 in/out — adapters just mount it.
- **Methods resolution.** Default resolver: namespace convention (`App\Atoms\{Name}\Methods`), matching the app-integration doc. Override: `#[MethodsFor(GameRoom::class)]` attribute or an explicit map. The convention is Laravel-*flavored* but PSR-4-generic; Symfony apps get it for free.
- **Ticket acquisition** for WebSockets; **trace propagation** (W3C traceparent on every RPC out, echoed into callbacks, so a monolith APM trace spans monolith → Atom → callback → queue — nobody has thought about observability *stitching* yet, and it's the first thing a production customer asks for).
### 5.2 The Laravel adapter (thin by design)

Service provider binds `AtomsClient`; `Atoms` facade; auto-registers `POST /atoms/callback` → `CallbackKernel` (route name published, middleware-configurable, CSRF-exempted); queue bridge wraps AtomJobs in a `ShouldQueue` envelope so they flow through the customer's existing queue/Horizon setup; Artisan wrappers shell out to the `atoms` binary; `Atoms::fake()` for tests. Target: under ~1,500 lines. If the adapter grows past that, logic is leaking out of `atoms/client` and the Symfony adapter is quietly getting more expensive.

### 5.3 The Symfony adapter (proof, and Phase 2/3 deliverable)

Bundle + DI extension binding `AtomsClient`; a controller (or PSR-15 middleware via a runtime bridge) mounting `CallbackKernel`; a Messenger handler as the queue bridge; console commands wrapping the same binary. The Atoms path default becomes `src/Atoms` (configured in `atoms.json` — the toolchain never assumed `app/`). Estimated at a small bundle precisely because of the §1 split; building a skeletal version of it **during Phase 1, internally, as a design test** is cheap insurance — if the skeleton needs anything from `atoms/laravel`, the layering is wrong and we find out before the API is frozen.

---

## 6. Developer experience

### 6.1 Onboarding path

```bash
composer require laravel/atoms
php artisan atoms:install     # writes atoms.json, config/atoms.php, registers callback route,
                              # adds phpstan-rules to the project's phpstan.neon, offers ai:install (§8)
php artisan make:atom GameRoom --with-methods --with-migration --websocket
atoms dev                     # Worker up locally under `wrangler dev` (was: `atoms local`, a Docker runtime)
# ... write code, tests pass ...
atoms deploy --env staging
```

`make:atom` scaffolds the full two-world layout from §2 — the directory shape *is* the mental model, so the generator is a teaching tool.

### 6.2 Local development runtime

> **Superseded (M3; callback channel corrected for M2).** `atoms local` is
> gone, and with it the Docker image and the state-inspection UI. `atoms dev`
> builds the bundle, stages it into the Worker project and runs
> `wrangler dev` — the real runtime, locally, with no Cloudflare account. The
> callback loopback described below is wired and real as of M2: `--callback-url`
> reaches the Worker and `Atom::app()`/`dispatch()` call back through it (see
> `docs/cloudflare-toolchain.md` §"The callback channel's two variables"). The
> paragraph is kept because the *requirements* it states (real runtime, fast
> rebuild, callbacks reaching the host app) are the ones `atoms dev` still has
> to meet.

`atoms local` runs the **real Amp runtime image** (same Docker image the platform boots, local-mode flag) with `app/Atoms` bind-mounted and a file-watcher that hot-drains and re-activates Atoms on change. It performs the real build pipeline in `--fast` mode (skip scoper) so boundary violations surface on save, not on deploy. Callbacks loop back to the host app (`host.docker.internal`, configured from `atoms.json`). A `--platform-parity` flag runs the full pipeline including scoper for pre-deploy confidence. Includes a local state-inspection UI (browse each Atom's SQLite) — the dashboard's little sibling.

For developing against *cloud* staging Atoms with a local monolith (callbacks can't reach `localhost`): `atoms tunnel`, an authenticated reverse tunnel that gives the staging environment a temporary callback URL. Not Phase-1-critical, but the absence will be felt the first time someone debugs a Methods class against real cloud state.

### 6.3 Testing story (`atoms/testing`)

Three layers, fastest first:

1. **Unit — `AtomHarness`.** Instantiates an Atom in-process with a temp SQLite (migrations applied), a **fake `app()` proxy that executes the real Methods class in-process** — this is the killer feature: full Atom↔Methods integration coverage in a plain PHPUnit test with no network, no Docker — and a recorder for `dispatch()`/`broadcast()` with assertion helpers (`$harness->assertDispatched(RecordGameResult::class, fn($j) => $j->score === 100)`). Turn semantics are simulated (calls are sequential by construction in-process).
2. **Monolith — `Atoms::fake()`.** For controller tests: stub returns per (type, id, method), assert invocations, without any Atom actually running. Symfony gets the same via a test-mode client.
3. **Integration — against `atoms local`.** WebSocket test client included; used sparingly, e.g. for connection lifecycle and hibernation behavior.
CI runs layers 1–2 with zero infrastructure. This matters commercially: if testing Atoms requires Docker in CI, adoption stalls at the first enterprise pipeline that forbids it.

### 6.4 Error catalog

Every boundary/build/runtime error has a stable code (`ATOMS-E012: Reference to monolith class App\Models\User inside Atom code`), a one-line fix, and a docs anchor. The same catalog drives PHPStan messages, build-service rejections, CLI output, and the agent skill's troubleshooting table — one source of truth, four consumers. This is cheap to do from day one and impossible to retrofit consistently.

---

## 7. CI/CD

### 7.1 Pipeline primitives

- `atoms validate` — stages 1–3+5 of §3.1; seconds; no network; the PR check.
- `atoms build` — full deterministic bundle; content-hash versioned.
- `atoms deploy --env X [--bundle path]` — uploads a prebuilt bundle or builds; the platform re-validates (never trusts the client) but re-validation of a well-formed bundle is fast and *cannot fail for reasons `validate` wouldn't have caught locally* — that invariant is the whole point of §3.1.
- `atoms rollback --env X [version]`, `atoms diff --env X` (live manifest vs. local build — the "what will this deploy change" answer), `atoms status`.
### 7.2 Provided integrations

A first-party GitHub Action and GitLab CI template:

```yaml
- uses: atoms-cloud/deploy-action@v1
  with:
    environment: production
```

> **Superseded (M3).** There is no Atoms-operated token endpoint to exchange
> an OIDC token *at*, so the exchange is gone. The Action takes the user's own
> `cloudflare-api-token` and `cloudflare-account-id` and passes them to
> Wrangler. The reasoning below still identifies the right risk — a long-lived
> credential in CI — and the answer is now a Cloudflare API token scoped to one
> account with Workers Scripts:Edit, rotated by the user. Cloudflare supports
> OIDC federation for its own API; wiring the Action to it is a real future
> improvement, and it is not something Atoms would broker.

Auth via **OIDC token exchange** (the CI provider's identity token swapped for a short-lived deploy token scoped to one project+environment) rather than long-lived API keys in CI secrets — table stakes in 2026, and it means a leaked CI log never contains a credential that works tomorrow.

### 7.3 Environments and ordering

Environments are first-class platform objects (`staging`, `production`, arbitrary names); the SDK selects per app-environment via config. Ephemeral per-PR environments (`atoms deploy --env pr-123 --ttl 72h`) are cheap on the platform side given stopped-Machine economics — schedule for Phase 2; they turn "review this Atom change" from a thought experiment into a URL.

Deploy *ordering* between the monolith and Atoms is a doc-and-tooling problem: additive Atom changes deploy Atoms-first; contractions deploy monolith-first. `atoms diff` labels each manifest change **additive / contracting / breaking** so the pipeline (or an agent) can enforce ordering mechanically.

### 7.4 Version-skew detection

Every SDK invocation carries the manifest hash the monolith was built against; the platform compares against the deployed manifest and emits a skew metric (and a response header in dev). A monolith calling a method the deployed manifest lacks gets `ATOMS-E041 MethodNotInDeployedVersion` — a *precise* error, versus the undefined-method 500 the current design would produce. The PHPStan call-site collector (§1) inventories every `Atoms::get(...)->method()` in the monolith, so `atoms diff` can also answer the reverse question: "this deploy removes `startRound()`; the monolith calls it in 3 places."

---

## 8. Agent skills and AI tooling

Two deliverables: skills that teach agents to *write* Atoms code, and an MCP surface that lets agents *operate* deployments. Both are generated per-project, because generic knowledge about Atoms is worth far less to an agent than this repo's manifest, this repo's boundary config, and this team's environments.

### 8.1 `atoms ai:install`

Writes into the repo (and regenerates on `atoms deploy`, keeping a marker so hand edits are preserved):

```
.claude/skills/
├── atoms-authoring/SKILL.md      # programming model; two-worlds rule; serialization algebra;
│                                 #   the boundary rules AS THE AGENT WILL HIT THEM — full
│                                 #   ATOMS-E* catalog with fixes; migration rules; turn/
│                                 #   reentrancy hazards (A→B→A deadlock, app() head-of-line
│                                 #   blocking)
├── atoms-testing/SKILL.md        # AtomHarness patterns; Atoms::fake(); what NOT to mock
├── atoms-operating/SKILL.md      # CLI reference; validate→build→diff→deploy loop; expand/
│                                 #   contract; rollback; reading skew errors
└── atoms-project-context/SKILL.md  # GENERATED: this project's Atom types + signatures,
                                    #   Methods contracts, shared DTOs, environments, current
                                    #   deployed versions — the manifest, rendered for agents
```

Plus an `AGENTS.md` section for non-Claude tooling. The authoring skill's core trick is that it embeds the *same error catalog* CI enforces — an agent that writes a boundary violation sees `ATOMS-E012` in PHPStan output, finds `ATOMS-E012` in its skill with the canonical fix, and self-corrects without a human round-trip. Design the errors for that loop and humans benefit identically.

### 8.2 MCP server (Phase 2)

`atoms mcp` (or a hosted endpoint): list deployments and versions, fetch manifests, read an Atom's state (dashboard's inspection API), tail invocation logs, run `validate`/`diff`. Read-only by default; deploy/rollback tools gated behind explicit config. The concrete payoff is agent-driven debugging — "why did GameRoom `g_123` reject this bid" becomes answerable by an agent that can read that Atom's SQLite and its recent invocation log.

### 8.3 Why this is strategy, not garnish

A meaningful share of the PHP written against this platform in 2026 will be written by agents. The platform whose constraints are machine-legible — stable error codes, generated project context, a validate loop that runs in seconds — gets dramatically better agent-written code than one whose rules live in prose docs. That compounds into fewer build rejections, fewer support tickets, and a real adoption edge.

---

## 9. Security at the boundary

Mostly consolidating decisions implied elsewhere, plus gaps:

- **Callback endpoint**: Ed25519 signature over (body, timestamp, nonce); ±5min window; nonce LRU; reject before touching the container. Publish the platform's egress IP ranges for defense-in-depth allowlisting. The route is auto-registered but its exposure is documented loudly — it executes Methods code with full app access by design.
- **Methods are the authorization surface.** The docs should say plainly: anything a Methods class exposes is callable by any code running as your Atoms. Guidance + a lint: Methods that take a "user id and do privileged things" should verify context; the manifest gives security reviewers a complete inventory of the app's Atom-callable surface — that inventory is itself a feature.
- **No `serialize()`/`unserialize()` anywhere** (§4.2). AtomJob hydration is constructor-args-by-name through the same typed serializer.
- **Bundle integrity**: content-hash addressing + platform signature on stored bundles; Machines verify at load. `composer audit` in every build.
- **Deploy credentials**: OIDC in CI (§7.2); scoped per environment; `atoms deploy --env production` from a laptop can require a second factor per team policy.
---

## 10. Open questions

1. **Eloquent against Atom SQLite** — the bridge makes it possible; do we bless it? Recommendation: not for beta; let demand argue.
2. **`atoms-composer.json` policy** — allowlist curated by us vs. open-with-static-analysis? Beta: curated allowlist (start ~20 packages: illuminate/database, ramsey/uuid, nesbot/carbon, brick/math, etc.); loosen with data.
3. **Monorepo/multi-app layouts** — one `atoms.json` per Laravel app assumed; monorepos with several apps sharing Atom types need a story (probably: Atoms as an internal Composer package, which the closure walk already supports).
4. **Symfony timing** — skeleton bundle in Phase 1 as a layering test (§5.3); when does it become a supported product? Proposal: public alpha alongside platform Phase 3.
5. **WebSocket client SDKs** (JS/mobile) — resume tokens and message-ID dedupe from the platform drain protocol land in the *client* SDKs; that's a sibling plan this document deliberately excludes, but the ticket/resume contract must be co-designed with §5.1.
6. **`laravel/atoms` naming** — the `laravel/` vendor prefix implies first-party Laravel stewardship; if this is Atoms-the-company's package, `atoms/laravel` keeps the door open for `atoms/symfony` to be a peer, not an afterthought. Branding call, needed before beta.
---

## 11. Phased delivery (mapped to the product's implementation path)

**Phase 1 (private beta):** `atoms/core` with frozen-enough ABI; `atoms/client`; `atoms/laravel`; CLI with `init/make/validate/build/deploy/rollback/local`; closure-walk build with scoper; PHPStan boundary rules + error catalog; migrations; `AtomHarness` + `Atoms::fake()`; GitHub Action with OIDC; `ai:install` with the four skills; **internal Symfony skeleton as a layering test**.

**Phase 2:** `atoms diff` + skew detection + call-site collector; `atoms tunnel`; per-PR environments; MCP server; secrets management GA; illuminate-database bridge polish; Doctrine DBAL bridge draft.

**Phase 3 (GA):** `atoms/symfony` public alpha; ephemeral-environment GA; contract-ordering enforcement in the deploy action; docs/onboarding; error-catalog completeness audit against real beta failure data.

The single most important Phase 1 discipline: **nothing ships in `atoms/laravel` that could live in `atoms/client` or `atoms/core`.** Every exception to that rule is a line item in the eventual Symfony adapter's bill — and, worse, a place where the framework boundary and the *security* boundary stop coinciding.
