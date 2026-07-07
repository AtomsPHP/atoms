# AGENTS.md

Guidance for coding agents working in this repository.

## What this repo is

The **Atoms framework monorepo** — everything that lives in a customer's
codebase for the Atoms platform: the SDK packages (`atoms/core`,
`atoms/client`, `atoms/laravel`, `atoms/symfony`), the dev/CI toolchain
(`atoms/cli`, `atoms/phpstan-rules`, `atoms/testing`), the deploy GitHub
Action, and the generated agent skills. The platform itself (control plane,
runtime) lives in separate repositories; its contracts are mirrored here
under `docs/platform/`.

Read before non-trivial work, in this order:

1. `docs/conventions.md` — cross-package contracts, the frozen `atoms/core`
   ABI, error-catalog rules, layering. **Normative.** If code and this file
   disagree, the code is wrong.
2. `docs/integration-plan.md` — the design rationale (why seven packages, the
   two-worlds model, extraction pipeline).
3. `docs/platform/api-contract.md` — the frozen platform HTTP contract v1.

## Hard rules

- **`atoms/core` is the runtime ABI.** Its public surface is wire-protocol
  grade: never change an existing signature, only add. It depends on nothing
  framework-ish (psr/* interfaces only). It must run on PHP 8.3.
- **Layering is the product.** core ← client ← {laravel, symfony};
  core ← {testing, phpstan-rules, cli}. The Symfony bundle must never need
  `atoms/laravel`; the CLI and testing packages must never need
  `atoms/client`. Nothing ships in `atoms/laravel` that could live in
  `atoms/client` or `atoms/core`.
- **The error catalog is the single source of truth.**
  `packages/core/resources/errors.json` — every user-facing failure in every
  package carries its `ATOMS-E###` code and catalog fix line. Append-only;
  never renumber; keep the `ErrorCode` enum in sync (a core test enforces it).
- **No native `serialize()`/`unserialize()`** anywhere in the codebase.
  Boundary data moves as JSON through `Atoms\Serialization\Serializer`.
- **Builds are pure functions of the repo.** `atoms build` must be
  deterministic (byte-identical bundles from identical trees) and must never
  execute customer code.
- Tests never hit the network.

## Common commands

```sh
composer install                     # one root install for all packages
composer test                        # all test suites
composer test -- --testsuite=core    # one package's suite
composer stan                        # phpstan across all packages/*/src
packages/cli/bin/atoms               # run the CLI from source
```

## Layout

`packages/{core,client,laravel,symfony,testing,phpstan-rules,cli}` — see
`docs/conventions.md` for namespaces and dependency rules. Root
`composer.json` wires everything via path repositories; package versions are
pinned `0.1.0` and managed by release tooling — don't hand-edit them.
