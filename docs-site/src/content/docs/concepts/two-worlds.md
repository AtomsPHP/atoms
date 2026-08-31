---
title: The two worlds
description: Keep durable Atom code separate from framework code that stays in your application.
---

Atoms spans two runtimes with different capabilities.

## Atom-side: inside the Atom

Atom-side code is bundled into PHP 8.3 WebAssembly and run inside a Durable Object. It includes:

- classes extending `Atoms\Atom`;
- helper classes in an Atom's `support/` directory;
- shared, JSON-safe data transfer objects;
- append-only SQL or PHP migrations;
- dependencies explicitly approved in `atoms-composer.json`.

An Atom's `support/` directory sits beside its `migrations/` — `app/Atoms/GameRoom/support/ScoreBoard.php` — and holds Atom-side helpers that ship with the Atom without being Atoms themselves: an Eloquent model bound to the Atom's database, a value object, a pure service. They follow the same import rules as the Atom class, and your application never loads them.

An Atom can use its own database, configuration, timers, WebSockets, and the `app()`/`dispatch()` callback seam. It cannot use your Laravel container, Doctrine entity manager, filesystem, sessions, or arbitrary framework services.

## App-side: inside your application

App-side code runs in your normal Laravel, Symfony, or plain-PHP process. A `Methods` class paired with an Atom handles synchronous `app()` calls. An `AtomJob` handles asynchronous `dispatch()` calls. These may use your database, container, mailer, queue, and other host services.

```text
Application (App-side)  ── invoke ──▶  Atom (Atom-side)
Application (App-side)  ◀─ app() ───  Atom (Atom-side)
Application queue       ◀─ dispatch()  Atom (Atom-side)
```

## The boundary

Values crossing the boundary are JSON-compatible scalars, lists, maps, enums, and shared DTOs. Native PHP serialization is never used. Build validation and `atoms/phpstan-rules` catch unsupported types before deployment.

The practical rule: if code needs a framework service, it is App-side. If it owns the entity’s durable state and invariants, it is Atom-side.

For the full normative classification and package-layer rules, read [`docs/two-worlds.md`](https://github.com/AtomsPHP/atoms/blob/main/docs/two-worlds.md) and [`docs/conventions.md`](https://github.com/AtomsPHP/atoms/blob/main/docs/conventions.md).
