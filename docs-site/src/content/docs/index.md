---
title: Overview
description: What an Atom is, where it runs, and how these docs are arranged.
---

An Atom is a long-running PHP object with its own database that communicates directly to browsers over WebSockets. Its code lives in your PHP app, but it is deployed to Cloudflare and runs inside a [Durable Object](https://developers.cloudflare.com/durable-objects/).

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

## What the framework gives you

Durable Objects are normally written in TypeScript, but Atoms lets you write them in ordinary PHP alongside your monolith code. At deploy time, your code gets bundled into a WebAssembly module which runs inside of a Durable Object.

## Where to go next

Start with [Install](/getting-started/install/) for requirements, packages, and the Worker runtime, then follow the quickstart for your framework of choice: [Laravel](/getting-started/laravel/), [Symfony](/getting-started/symfony/), or [plain PHP](/getting-started/plain-php/).

Atoms has some core concepts you should know about. [The two worlds](/concepts/two-worlds/) explains which code runs inside an Atom and which runs in your application, and [lifecycle and persistence](/concepts/lifecycle/) covers turns, storage, and eviction.

When you are ready to ship, start with [Configuration](/guides/configuration/) for the files and environments a project has, then [Deploy](/guides/deploy/) for the deploy itself, [Secrets and authentication](/guides/secrets/) for the shared secret both sides need, and [Rollback](/guides/rollback/) for moving a Worker back.
