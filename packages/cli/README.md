# atoms/cli

The command-line tool for creating, validating, building, running, deploying,
inspecting, and rolling back Atoms applications. Builds are deterministic and
do not execute customer code; Cloudflare operations use the Wrangler binary
already installed in your committed Worker directory (`atoms-worker/`).

```sh
composer require --dev atoms/cli:^0.5
vendor/bin/atoms --help
```

Start with the [installation guide](https://docs.atomsphp.dev/getting-started/install/).

## Development and support

This package is developed in the
[Atoms monorepo](https://github.com/AtomsPHP/atoms). Its standalone repository
is a read-only distribution mirror; report issues and send pull requests to
the monorepo. Licensed under the [MIT License](LICENSE).
