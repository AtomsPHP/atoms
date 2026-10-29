# Laravel example

This is a small Laravel application with one `GameRoom` Atom. Each room id is
a serialized Cloudflare Durable Object with its own SQLite database; the HTTP
route calls it through the normal Laravel facade.

The application is deliberately ordinary Laravel. Atom source lives under
`app/Atoms`, the adapter registers the signed callback route, and the Atoms CLI
builds and deploys only Atom-side code.

## Install

```sh
composer install
cp .env.example .env
php artisan key:generate
php artisan atoms:install
npm exec --yes --package=@atomsphp/runtime-cloudflare@0.7.0 -- \
  atoms-runtime-cloudflare init atoms-worker
(cd atoms-worker && npm ci)
git add atoms-worker
```

The Worker directory is part of your repository from here on; this example
does not ship one, because it is a copy of the runtime this monorepo already
holds under `cloudflare/worker`, so run the scaffold command above before
deploying. Fill in the Cloudflare account id and callback URL in `atoms.json`.
Set the deployed Worker URL separately as the application's `ATOMS_ENDPOINT`.
Authenticate with Cloudflare — set `CLOUDFLARE_API_TOKEN` in
`.env.atoms.production` beside `atoms.json`, or use the `wrangler login`
session you already have — then deploy:

```sh
vendor/bin/atoms deploy --env production
```

Point `ATOMS_ENDPOINT` at the resulting Worker URL and start Laravel:

```sh
php artisan serve
curl -X POST http://127.0.0.1:8000/api/rooms/room-7/players/player-4
```

Calling the route again returns `visits: 2`, demonstrating that the Atom's
SQLite state survived the request boundary.

## Run callbacks locally

Set `ATOMS_ENDPOINT=http://127.0.0.1:8787` in `.env`. Start the Worker first,
then Laravel in another terminal:

```sh
vendor/bin/atoms dev --env production --callback-url http://127.0.0.1:8000/atoms/callback
```

```sh
PHP_CLI_SERVER_WORKERS=4 php artisan serve --host=127.0.0.1 --port=8000 --no-reload
```

The `production` name selects this example's existing configuration; `dev`
still runs only locally. Once both processes are ready, request:

```sh
curl --fail http://127.0.0.1:8000/api/callback-check
```

The response's `application` field contains `config('app.name')`, obtained by
`GameRoom::appLabel()` calling the application-side `GameRoom\Methods`.
This exercises application HTTP → Atom → application callback. The original
request occupies one PHP worker while the callback needs another. Tinker and
the in-process harness do not reproduce that dependency.

`--no-reload` prevents Laravel from falling back to a single worker; restart
Laravel after changing `.env`. Native Windows cannot fork PHP CLI server
workers. Use WSL2 for both processes, or Nginx with a PHP-FPM pool allowing at
least two simultaneous requests. See the [Laravel quickstart](https://docs.atomsphp.dev/getting-started/laravel/#run-it-locally)
for platform and callback-address details.

## Test without Cloudflare

```sh
composer test
```

The feature test replaces the outbound Worker call with `Atoms::fake()`. The
monorepo also runs the real `GameRoom` against `AtomHarness`, applying the SQL
migration and proving that repeated calls persist state.

## Deploy from GitHub Actions

Copy `.github/workflows/deploy-atoms.yml` to the root workflow directory of
your application and add `CLOUDFLARE_API_TOKEN` and `CLOUDFLARE_ACCOUNT_ID` as
repository secrets, which is what the example reads. The example pins the
immutable `AtomsPHP/atoms/action@v0.7.0` release. See
`docs/cloudflare-toolchain.md` §Getting a token for which permissions the
token needs, and where to keep it on a workstation.
