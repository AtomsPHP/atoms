# Laravel example

This is a small Laravel application with one `GameRoom` Atom. Each room id is
a serialized Cloudflare Durable Object with its own SQLite database; the HTTP
route calls it through the normal Laravel facade.

The application is deliberately ordinary Laravel. Atom source lives under
`app/Atoms`, the adapter registers the signed callback route, and the Atoms CLI
builds and deploys only World A code.

## Install

```sh
composer install
cp .env.example .env
php artisan key:generate
php artisan atoms:install
npm exec --yes --package=@atomsphp/runtime-cloudflare@0.1.0 -- \
  atoms-runtime-cloudflare init .atoms/worker
```

Fill in the Cloudflare account id, Worker endpoint and callback URL in
`atoms.json`. Export `CLOUDFLARE_API_TOKEN`, then deploy:

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

## Test without Cloudflare

```sh
composer test
```

The feature test replaces the outbound Worker call with `Atoms::fake()`. The
monorepo also runs the real `GameRoom` against `AtomHarness`, applying the SQL
migration and proving that repeated calls persist state.

## Deploy from GitHub Actions

Copy `.github/workflows/deploy-atoms.yml` to the root workflow directory of
your application and add `CLOUDFLARE_API_TOKEN` and
`CLOUDFLARE_ACCOUNT_ID` as repository secrets. The example pins the immutable
`AtomsPHP/atoms/action@v0.1.0` release.
