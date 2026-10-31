# atoms/laravel

The supported Laravel adapter for Atoms. It registers the Atoms client,
facade, callback route and queue bridge, testing fake, Artisan installers, and
thin wrappers around the `atoms` command-line tool. Supports Laravel 11, 12,
and 13 on PHP 8.3 or later.

```sh
composer require atoms/laravel:^0.9
php artisan atoms:install
```

Continue with the [Laravel quickstart](https://docs.atomsphp.dev/getting-started/laravel/)
or the tested `examples/laravel/` application in the monorepo.

## Callback nonce storage

Callbacks need a configured nonce store. Choose Redis or database storage and
set a stable namespace for this application and environment:

```dotenv
ATOMS_CALLBACK_NONCE_DRIVER=redis
ATOMS_CALLBACK_NONCE_REDIS_CONNECTION=default
ATOMS_CALLBACK_NONCE_NAMESPACE=my-app:production
```

The Redis connection comes from Laravel's `config/database.php`. Use PhpRedis
or install `predis/predis`. Keep nonce keys on a connection whose data is not
removed by application cache flushes or Redis eviction.

For database storage:

```dotenv
ATOMS_CALLBACK_NONCE_DRIVER=database
ATOMS_CALLBACK_NONCE_NAMESPACE=my-app:production
```

Run `php artisan migrate`. The package registers the `atoms_callback_nonces`
migration automatically when the database driver is selected. Set
`ATOMS_CALLBACK_NONCE_DB_CONNECTION` to choose a named Laravel connection;
otherwise the store uses the default database. Atoms opens its own connection
to that database so application rollbacks cannot remove nonce records.
SQLite requires a persistent file shared by every callback worker on the host.

Schedule `php artisan atoms:nonces:prune` to remove expired database records.
Redis expires its records automatically. Keep the database driver selected
when rolling back its migration.

Retention defaults to twice `atoms.callback_timestamp_window`, plus one second
and a clock allowance of 60 seconds. Set `ATOMS_CALLBACK_NONCE_RETENTION_SECONDS`
to retain records longer, or `ATOMS_CALLBACK_NONCE_CLOCK_ALLOWANCE_SECONDS` to
change the allowance. A shorter retention is rejected.

Without a configured driver, authenticated callbacks fail before application
code runs. `memory` is an explicit option for local tests that retain the same
store instance. Applications can still bind their own
`Atoms\Client\Callback\NonceStore` implementation.

## Development and support

This package is developed in the
[Atoms monorepo](https://github.com/AtomsPHP/atoms). Its standalone repository
is a read-only distribution mirror; report issues and send pull requests to
the monorepo. Licensed under the [MIT License](LICENSE).
