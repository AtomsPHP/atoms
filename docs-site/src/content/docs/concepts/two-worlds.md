---
title: The two worlds
description: Keep Atom code separate from framework code.
---

Atoms spans two runtimes with different capabilities.

## Atom-side: inside the Atom

Atoms bundles your PHP source for a WebAssembly PHP interpreter running inside a Durable Object. It includes:

- classes extending `Atoms\Atom`;
- helper classes in an Atom's `Support/` directory;
- shared, JSON-safe data transfer objects;
- append-only SQL or PHP migrations;
- dependencies explicitly approved in `atoms-composer.json`.

`Support/` holds helpers that follow the Atom's import rules. They run in the Worker in production and in `AtomHarness` during tests.

Match the directory's case to its namespace, such as `GameRoom/Support/ScoreBoard.php` and `App\Atoms\GameRoom\Support`. Rename lowercase `support/` directories to `Support/` so the build and Composer can find them. Use an intermediate name for a case-only Git rename.

An Atom can use its own database, configuration, timers, and WebSockets, and it can [call back into your application](/guides/callbacks/) with `app()` and `dispatch()`. It cannot use your Laravel container, Doctrine entity manager, filesystem, sessions, or any other service from your framework.

## App-side: inside your application

App-side code runs in your normal Laravel, Symfony, or vanilla PHP application. A `Methods` class paired with an Atom defines entrypoints where the Atom can call into your app using the `app()` method. An `AtomJob` handles asynchronous `dispatch()` calls. These may use your database, container, mailer, queue, and other host services.

```text
Application (App-side)        ──   invoke  ──▶  Atom (Atom-side)
Application (App-side)        ◀─    app()  ───  Atom (Atom-side)
Application queue (App-side)  ◀─ dispatch() ──  Atom (Atom-side)
```

## The boundary

Values crossing the boundary are JSON-compatible scalars, lists, maps, enums, and shared DTOs. Objects such as Eloquent models or closures can't cross it; convert them to one of those types first. Build validation and `atoms/phpstan-rules` catch unsupported types before deployment.

For more detail, read [`docs/two-worlds.md`](https://github.com/AtomsPHP/atoms/blob/main/docs/two-worlds.md) and [`docs/conventions.md`](https://github.com/AtomsPHP/atoms/blob/main/docs/conventions.md).
