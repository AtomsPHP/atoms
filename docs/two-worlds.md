# The Two-Worlds Model

Every design decision in Atoms flows from one fact: **the customer's repository contains code for two different runtimes**, and the file layout makes it impossible to be confused about which is which.

## Directory Layout

```
app/Atoms/
├── GameRoom.php                    ← WORLD A: ships to platform, runs on Amp runtime
├── GameRoom/
│   ├── Methods.php                 ← WORLD B: stays in monolith, full Laravel access
│   └── migrations/
│       ├── 001_create_events.sql   ← WORLD A: ships, runs at activation
│       └── 002_add_round_index.sql
├── Shared/
│   └── PlayerSnapshot.php          ← BOTH: DTO crossing the RPC boundary
├── Jobs/
│   └── RecordGameResult.php        ← WORLD B: handle() runs in monolith;
│                                      constructor signature is the contract
├── SomeAtomType/
│   └── migrations/
│       └── 001_schema.sql          ← WORLD A: append-only, never edit once shipped
└── ...
atoms.json                          ← toolchain anchor
atoms-composer.json                 ← World A dependencies only
```

## Worlds at a Glance

| Where | Code | Executes in | Import | Behavior |
|-------|------|-------------|--------|----------|
| **Atom class** (e.g., `GameRoom.php`) | World A | Platform Atoms runtime | `atoms/core`, `atoms-composer.json` approved packages, `Shared/` | `$this->db()`, `$this->dispatch()`, `$this->app()`, WebSocket handlers |
| **Methods class** (e.g., `GameRoom/Methods.php`) | World B | Your Laravel/Symfony app | Full app access | `$this->app()->someMethod()` receives calls from Atoms |
| **Shared DTOs** (e.g., `Shared/PlayerSnapshot.php`) | BOTH | Platform runtime + your app | `atoms/core` + stdlib only | Data crossing the RPC boundary |
| **AtomJob** (e.g., `Jobs/RecordGameResult.php`) | World B | Your app's queue/job system | Full app access | Dispatched via `$this->dispatch()` from Atoms |
| **Migrations** (e.g., `GameRoom/migrations/001_*.sql`) | World A | Platform Atoms runtime at activation | SQL + no app context | Append-only, schema changes for the Atom's SQLite database |

## The Rule of Thumb

**If it extends `Atom`, it leaves. If it extends `AtomMethods` or `AtomJob`, it stays. If it's in `Shared/`, it does both — so it must be pure data.**

This one sentence is the entire mental model:

- Anything under an Atom class's namespace that extends `Atom` ships to the platform.
- Methods classes and Jobs extend base classes that stay in your app (World B).
- `Shared/` classes cross the boundary, so they carry no framework dependencies.

## Shared/ — Pure Data Zone

Developers will immediately want types that cross the boundary — `$this->app()->getPlayer()` returning a generic `array` is beneath the DX bar. `Shared/` is the sanctioned mechanism:

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

Methods classes return these; Atoms receive them typed; the serializer hydrates on both sides. **Shared classes are subject to the strictest rules:**

- `atoms/core` + stdlib only
- No behavior beyond accessors/factories (no I/O, no side effects)
- Public readonly promoted properties only
- Nesting of other Payload DTOs is allowed

An escape-hatch attribute `#[SharedWithAtoms]` marks classes outside `Shared/` for teams with existing DTO layers — the same rules apply wherever the class sits.

## Migrations — Append-Only

Each Atom type may ship ordered migrations (`app/Atoms/GameRoom/migrations/001_*.sql` or PHP migration classes for data transforms). The platform stores the applied version in SQLite's `user_version` pragma and applies pending migrations at activation, before the first turn.

**Migrations are append-only once shipped:**

- Editing a migration that's already deployed causes a build error (`ATOMS-E050`).
- To fix a broken migration, append a corrective one.
- The manifest records the head version; rollback of a *code* deploy does not roll back schema.
- Migrations must be backward-compatible one version (expand/contract pattern).

Activation-time cost gets a budget: a migration exceeding it (default 250ms) trips a warning at build time.

## Packages Map to Worlds

| Package | Executes in | Purpose |
|---------|-------------|---------|
| `atoms/core` | **Both** | Runtime ABI, serialization, migrations, error catalog. Framework-free. |
| `atoms/client` | World B (monolith) | Stub proxies, RPC transport, callback kernel, manifest loader. PSR-7/15/17/18 contracts. |
| `atoms/laravel` | World B (monolith) | Service provider, `Atoms` facade, queue bridge, Artisan wrappers, callback route registration. |
| `atoms/symfony` | World B (monolith) | Bundle, DI extension, callback controller, Messenger bridge, console commands. |
| `atoms/testing` | Dev-time | `AtomHarness` (in-process Atom against temp SQLite), fake `app()` proxy, dispatcher recorder, WebSocket test client. |
| `atoms/phpstan-rules` | Dev-time | Boundary enforcement: serialization rules, call-site collector, World A symbol restrictions. |
| `atoms/cli` | Dev-time | Standalone `atoms` binary: `init`, `make`, `validate`, `build`, `deploy`, `rollback`, `local`, `ai:install`. |

**Critical constraint:** Nothing in `atoms/laravel` may leak into `atoms/client`. Everything an Atom can touch at runtime lives in `atoms/core`. This is not speculative — the platform runtime has no Laravel loaded, so any Laravel symbol an Atom references is a bug today.

## Loading an Atom's Dependency Closure

When you `atoms build`:

1. The CLI enumerates all Atom classes, Methods classes, Shared DTOs, and migrations under the Atoms path.
2. For each Atom class, it computes the transitive closure: parent classes, interfaces, traits, attributes, type hints, constants, `new`/static-call/`instanceof` targets.
3. Every symbol in the closure is classified:
   - `atoms/core` → provided by the runtime, not bundled
   - Atoms path / `Shared/` → bundled
   - Package in `atoms-composer.json` → bundled with PHP-Scoper (namespace-prefixed to avoid collisions)
   - Framework code, facades, global helpers → **hard error** (with the fix from the error catalog)
4. The manifest records what shipped and what the runtime must provide.

This is how the boundary is enforced: statically, at build time, before anything is deployed. `atoms validate` runs stages 1–3 in seconds — that's your fast CI entry point.
