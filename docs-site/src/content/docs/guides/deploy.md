---
title: Deploy
description: Build an Atom bundle and deploy its Worker to your Cloudflare account.
---

You deploy Atoms to your own Cloudflare account with `vendor/bin/atoms`, which
runs the Wrangler installed in `atoms-worker/`.

## Prepare the Worker

Follow [Initialize the project](/getting-started/install/#initialize-the-project)
to set up `atoms-worker/`, and run `npm ci` inside it after every fresh
checkout.

## Authenticate with Cloudflare

Deploys authenticate with a Cloudflare API token that has permission to edit
Workers Scripts in the target account. In CI, set `CLOUDFLARE_API_TOKEN` as a
job variable from your secret store.

On your own machine, you can log in with the installed Wrangler instead:

```bash
cd atoms-worker
./node_modules/.bin/wrangler login
cd ..
```

Or put the token in the gitignored
[`.env.atoms.<environment>`](/guides/configuration/#envatomsenvironment) file
for the environment you deploy to:

```bash
# .env.atoms.production
CLOUDFLARE_API_TOKEN=…
```

## Build and deploy

Check that your host adapter is ready first; [Before you
deploy](/concepts/adapters/#before-you-deploy) lists what it must provide.
Then deploy an environment:

```bash
vendor/bin/atoms deploy --env production
```

`deploy` validates and bundles your Atom PHP source along with the packages in
[`atoms-composer.json`](/guides/configuration/#atoms-composerjson), then
deploys the Worker through Wrangler. The Worker executes the source using its
PHP interpreter.

Before deployment starts, the CLI prints the settings it will use and [where each one came
from](/guides/environments/#where-values-come-from).

To check your Atom code without deploying, run `atoms validate` (add `--json`
for JSON output). To produce a bundle for inspection or a later deploy, run
`atoms build`.

## Set the shared secret

After the first deploy, set `ATOMS_SHARED_SECRET` on the Worker. The Worker
answers only `/healthz` until it has one. [Secrets and
authentication](/guides/secrets/#the-shared-secret) shows how.

## Verify the deployment

List the Worker's versions with:

```bash
vendor/bin/atoms status --env production
```

Cloudflare [propagates code updates over time](https://developers.cloudflare.com/durable-objects/platform/known-issues/#code-updates),
so old and new versions can run at the same time. Neither `status` nor
`/healthz` confirms that every Atom has updated.

[Code updates](https://developers.cloudflare.com/durable-objects/concepts/durable-object-lifecycle/#code-updates)
can restart active Atoms. Committed SQLite data survives, but in-memory state
is lost. Calls may fail and WebSockets may disconnect during
[shutdown](https://developers.cloudflare.com/durable-objects/concepts/durable-object-lifecycle/#shutdown-behavior).
Reconnect sockets and make mutating calls safe to repeat before retrying them.

### Check protected RPC and a signed callback

For the [Laravel quickstart](/getting-started/laravel/), add these methods inside
`App\Atoms\GameRoom`. They read the tutorial's `players` table and call the
application without inserting player records:

```php
/** @return list<array<string, mixed>> */
public function players(): array
{
    return $this->db()->query('SELECT player_id, visits FROM players ORDER BY player_id');
}

public function applicationName(): string
{
    return $this->app()->applicationName();
}
```

Replace the class's `@extends` annotation with the Methods type:

```php
/** @extends Atom<\App\Atoms\GameRoom\Methods> */
class GameRoom extends Atom
```

Create the app-side Methods class:

```php title="app/Atoms/GameRoom/Methods.php"
<?php

declare(strict_types=1);

namespace App\Atoms\GameRoom;

use Atoms\AtomMethods;

final class Methods extends AtomMethods
{
    public function applicationName(): string
    {
        return (string) config('app.name');
    }
}
```

Run `vendor/bin/atoms validate` and `vendor/bin/phpstan analyse`. Deploy the
updated Atom and install the Methods class in the application that receives
callbacks. Configure the [shared nonce store](/getting-started/laravel/#configure-callback-replay-protection)
and the target environment's `callback_url`. The application and Worker must
have the same shared secret, and the Worker must require bearer authentication.
The runtime scaffold uses `ATOMS_BEARER_AUTH=required` by default.

Save this script in the Laravel application root:

```php title="atoms-smoke.php"
<?php

declare(strict_types=1);

use App\Atoms\GameRoom;
use Atoms\Laravel\Facades\Atoms;
use Illuminate\Contracts\Console\Kernel;

try {
    require __DIR__ . '/vendor/autoload.php';
    $app = require __DIR__ . '/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();

    $expectedName = (string) config('app.name');
    if ($expectedName === '') {
        throw new RuntimeException('The application name is empty.');
    }

    $room = Atoms::get(GameRoom::class, 'deployment-smoke-v1');
    if ($room->players() !== []) {
        throw new RuntimeException('The smoke-test identity has player records.');
    }
    if ($room->applicationName() !== $expectedName) {
        throw new RuntimeException('The callback returned an unexpected application name.');
    }
    if ($room->players() !== []) {
        throw new RuntimeException('The smoke-test identity changed.');
    }

    fwrite(STDOUT, "Atom read and signed callback succeeded.\n");
} catch (Throwable $error) {
    // Do not print exception details that could contain application data.
    fwrite(STDERR, "Atom smoke test failed. Check the application and Worker logs.\n");
    exit(1);
}
```

Run it using the intended application's configuration:

```bash
php atoms-smoke.php
```

The script uses the configured endpoint and shared secret. It does not need a
pasted bearer token and does not print secrets. Reserve `deployment-smoke-v1`
for this check. Its first access may create the Atom and apply migrations even
though the methods do not add domain records.

For a local check, start Atoms and Laravel with the
[documented concurrent-server setup](/getting-started/laravel/#run-it-locally)
before running the script. In a disposable copy with uncached Laravel
configuration, set `ATOMS_SHARED_SECRET` to a different valid secret and rerun
the script. It must exit nonzero. Restore the shared secret, restart the local
Worker with an unreachable callback URL, and confirm that the script again
exits nonzero. Restore the working callback URL after this check. Do not change
production secrets or callback settings to test these failure cases.

Success confirms protected RPC, activation and migrations for one identity,
and a signed callback accepted by the configured application. It does not prove
fleet-wide version propagation, production concurrency, or complete replay
protection coverage.

### Stage application and Atom releases

1. Deploy compatible Atom changes before application code that uses them.
2. Check new behavior through the application with a read-only or idempotent
   call. This checks one Atom, so keep a fallback for instances still updating.
3. Remove old behavior once callers have migrated and rollback no longer needs it.

To restore an earlier version, see [Rollback](/guides/rollback/).

## Upgrade the runtime

When you update the Atoms PHP packages, upgrade `atoms-worker/` to the same
release. For 0.9.1:

```bash
npm exec --yes --package=@atomsphp/runtime-cloudflare@0.9.1 -- \
  atoms-runtime-cloudflare upgrade atoms-worker
cd atoms-worker
npm ci
cd ..
```

`atoms dev` and `atoms deploy` need the CLI and the Worker runtime to be the
same version. `atoms init` and the version-mismatch error both print the
version to use.

| Files | What an upgrade does |
|---|---|
| `wrangler.jsonc` | Keeps your file as it is. The release notes list any change you need to make. |
| Runtime files listed in `atoms-runtime.json` | Replaces them with the release's copies and removes files the release no longer ships. Local edits are overwritten. |

## Deploy from GitHub Actions

The deploy Action installs the Worker's dependencies, builds and deploys:

```yaml
jobs:
  deploy-atoms:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: AtomsPHP/atoms/action@v0.9.1
        with:
          environment: production
          cloudflare-api-token: ${{ secrets.CLOUDFLARE_API_TOKEN }}
          cloudflare-account-id: ${{ vars.CLOUDFLARE_ACCOUNT_ID }}
          shared-secret: ${{ secrets.ATOMS_SHARED_SECRET }}
```

Use the tag or commit SHA that matches your runtime, and set `worker-directory`
if your Worker lives somewhere other than `atoms-worker/`. The [Action's
README](https://github.com/AtomsPHP/atoms/blob/v0.9.1/action/README.md)
documents every input and how to scope the API token.

The `shared-secret` input sets the Worker's shared secret after the deploy
when the Worker doesn't have one yet. Configure the same value in your
application.

To rotate the secret from CI, first prepare your application as described in
[Rotate the shared secret](/guides/secrets/#rotate-the-shared-secret). Then run
the Action with `rotate-shared-secret: true`, `shared-secret` set to the new
value and `shared-secret-previous` set to the old one. On a later run,
`retire-shared-secret-previous: true` removes the old value from the Worker.
