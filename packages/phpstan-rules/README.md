# atoms/phpstan-rules

PHPStan rules for the Atoms boundary. They reject framework leakage,
non-serializable boundary types, adapter layering violations, and code that
depends on a guest clock which does not advance during a deployed turn.

```sh
composer require --dev atoms/phpstan-rules:^0.4
```

Include the shipped rules in `phpstan.neon`:

```neon
includes:
    - vendor/atoms/phpstan-rules/rules.neon
```

See the [limits guide](https://docs.atomsphp.dev/reference/limits/) for the
frozen-clock rules and their ATOMS-E101/E102 diagnostics.

## Development and support

This package is developed in the
[Atoms monorepo](https://github.com/AtomsPHP/atoms). Its standalone repository
is a read-only distribution mirror; report issues and send pull requests to
the monorepo. Licensed under the [MIT License](LICENSE).
