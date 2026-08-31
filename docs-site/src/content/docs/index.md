---
title: Overview
description: What an Atom is, where it runs, and how these docs are arranged.
---

An Atom is a PHP object with its own SQLite database. Your application calls it like a local object, and Cloudflare gives it a durable identity, one-at-a-time execution, and a place to sleep between requests.

```php
namespace App\Atoms;

use Atoms\Atom;

class GameRoom extends Atom
{
    public function join(string $player): int
    {
        $this->db()->execute(
            'INSERT INTO players (name) VALUES (?)',
            [$player],
        );

        return (int) $this->db()
            ->query('SELECT COUNT(*) AS count FROM players')[0]['count'];
    }
}
```

```php
$count = Atoms::get(GameRoom::class, 'room-42')->join('ada');
```

The class runs as PHP 8.3 compiled to WebAssembly inside a SQLite-backed Cloudflare Durable Object, where every Atom id gets its own storage and its own serialized turns. You deploy that Worker into your own Cloudflare account and it runs there, under your credentials and your billing.

## What the framework gives you

State lives beside the object in transactional SQLite and survives idle eviction, so an Atom picks up where it left off. The programming model stays ordinary PHP: methods, typed values, migrations, tests, framework adapters, and stable `ATOMS-E###` error codes. The CLI drives a pinned Wrangler install to build and deploy, which keeps the toolchain reproducible on a laptop and in CI alike.

## Where to go next

Start with [Install](/getting-started/install/) for requirements, packages, and the Worker runtime, then follow the quickstart for the application you already have: [Laravel](/getting-started/laravel/), [Symfony](/getting-started/symfony/), or [plain PHP](/getting-started/plain-php/).

For the model behind the API, [the two worlds](/concepts/two-worlds/) explains which code runs inside an Atom and which runs in your application, and [lifecycle and persistence](/concepts/lifecycle/) covers turns, storage, and eviction. When you are ready to ship, [deploy](/guides/deploy/) and [rollback](/guides/rollback/) describe the release path.
