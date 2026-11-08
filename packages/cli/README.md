# atoms/cli

The command-line tool for creating, validating, building, running, deploying,
inspecting, and rolling back Atoms applications. Builds are deterministic and
do not execute customer code; Cloudflare operations use the Wrangler binary
already installed in your committed Worker directory (`atoms-worker/`).

```sh
composer require --dev atoms/cli:^0.9
vendor/bin/atoms --help
```

Start with the [installation guide](https://docs.atomsphp.dev/getting-started/install/).

## Atom PHP targets

`atoms build` and `atoms validate` read `php` from `atoms.json`: `8.3`
(default, runtime 8.3.32) or `8.5` (TypePHP runtime 8.5.9). Both produce or
validate portable source; builds never execute customer code.

`atoms dev --compiler typephp` and `atoms deploy --compiler typephp` select
PHP 8.5 for their build and stage the experimental TypePHP backend. For
`deploy --bundle`, first build with `"php": "8.5"`; staging refuses an archive
whose target differs. The default compiler is `php-wasm`.

The CLI and monolith keep their PHP 8.3 baseline. Only the separate TypePHP
compiler process needs its upstream host PHP version (`>=8.4 <8.6`). See the
[setup guide](../../cloudflare/docs/typephp.md) for installed toolchain pins,
intentional language differences, and remaining conformance failures.

## Development and support

This package is developed in the
[Atoms monorepo](https://github.com/AtomsPHP/atoms). Its standalone repository
is a read-only distribution mirror; report issues and send pull requests to
the monorepo. Licensed under the [MIT License](LICENSE).
