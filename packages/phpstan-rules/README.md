# atoms/phpstan-rules

PHPStan rules for the Atoms boundary. They reject framework leakage,
non-serializable boundary types, adapter layering violations, and code that
depends on a guest clock which does not advance during a deployed turn.

```sh
composer require --dev atoms/phpstan-rules:^0.11
```

Include the shipped rules in `phpstan.neon`:

```neon
includes:
    - vendor/atoms/phpstan-rules/rules.neon
```

Run `vendor/bin/atoms validate` to check syntax, built-ins and extensions
against the Worker selected in `atoms.json`. Run PHPStan for type analysis and
the Atoms boundary rules. PHPStan uses one PHP target per analysis, including
host-side Methods, Jobs and shared files. When the host and Worker use different
versions, a separate PHPStan run can analyse Atom and Support files at the guest
version. Analyse shared code at both versions.

See the [limits guide](https://docs.atomsphp.dev/reference/limits/) for the
frozen-clock rules and their ATOMS-E101/E102 diagnostics.

## Development and support

This package is developed in the
[Atoms monorepo](https://github.com/AtomsPHP/atoms). Its standalone repository
is a read-only distribution mirror; report issues and send pull requests to
the monorepo. Licensed under the [MIT License](LICENSE).
