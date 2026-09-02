# atoms/laravel

The supported Laravel adapter for Atoms. It registers the Atoms client,
facade, callback route and queue bridge, testing fake, Artisan installers, and
thin wrappers around the `atoms` command-line tool.

```sh
composer require atoms/laravel:^0.4
php artisan atoms:install
```

Continue with the [Laravel quickstart](https://docs.atomsphp.dev/getting-started/laravel/)
or the tested `examples/laravel/` application in the monorepo.

## Development and support

This package is developed in the
[Atoms monorepo](https://github.com/AtomsPHP/atoms). Its standalone repository
is a read-only distribution mirror; report issues and send pull requests to
the monorepo. Licensed under the [MIT License](LICENSE).
