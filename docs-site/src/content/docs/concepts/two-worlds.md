---
title: The two worlds
description: Keep Atom code separate from framework code.
---

Atoms spans two runtimes with different capabilities.

## Atom-side: inside the Atom

Atom-side code is bundled into a WebAssembly module and runs inside a Durable Object. It includes:

- classes extending `Atoms\Atom`;
- helper classes in an Atom's `support/` directory;
- shared, JSON-safe data transfer objects;
- append-only SQL or PHP migrations;
- dependencies explicitly approved in `atoms-composer.json`.

An Atom's `support/` holds helpers that ship with the Atom: an Eloquent model bound to the Atom's database, a value object, a service. They follow the same import rules as the Atom class, and your application never loads them.

An Atom can use its own database, configuration, timers, WebSockets, and the `app()`/`dispatch()` callback seam. It cannot use your Laravel container, Doctrine entity manager, filesystem, sessions, or arbitrary framework services.

## App-side: inside your application

App-side code runs in your normal Laravel, Symfony, or vanilla PHP application. A `Methods` class paired with an Atom defines entrypoints where the Atom can call into your app using the `app()` method. An `AtomJob` handles asynchronous `dispatch()` calls. These may use your database, container, mailer, queue, and other host services.

```text
Application (App-side)        ──   invoke  ──▶  Atom (Atom-side)
Application (App-side)        ◀─    app()  ───  Atom (Atom-side)
Application queue (App-side)  ◀─ dispatch() ──  Atom (Atom-side)
```

## The boundary

Values crossing the boundary are JSON-compatible scalars, lists, maps, enums, and shared DTOs. Native PHP serialization is never used. Build validation and `atoms/phpstan-rules` catch unsupported types before deployment.

For more detail, read [`docs/two-worlds.md`](https://github.com/AtomsPHP/atoms/blob/main/docs/two-worlds.md) and [`docs/conventions.md`](https://github.com/AtomsPHP/atoms/blob/main/docs/conventions.md).
