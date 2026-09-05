# atoms/core

The frozen runtime API shared by Atoms applications and the Cloudflare
runtime. It contains the `Atom` programming model, serialization algebra,
SQLite helpers, migrations, WebSocket and timer interfaces, and the canonical
error catalog. It has no framework dependency and supports PHP 8.3 and later.

```sh
composer require atoms/core:^0.6
```

Most applications should install `atoms/laravel` or `atoms/symfony`, which
pull this package in. See the [Atoms documentation](https://docs.atomsphp.dev)
for the programming model and compatibility limits.

## Development and support

This package is developed in the
[Atoms monorepo](https://github.com/AtomsPHP/atoms). Its standalone repository
is a read-only distribution mirror; report issues and send pull requests to
the monorepo. Licensed under the [MIT License](LICENSE).
