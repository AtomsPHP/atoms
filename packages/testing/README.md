# atoms/testing

Test an Atom locally against a real temporary SQLite database without a
network connection or Cloudflare account. `AtomHarness` exercises migrations,
lifecycle hooks, serialization, `app()`, jobs, broadcasts, WebSockets, and
timers using the same `atoms/core` contracts as the deployed runtime.

```sh
composer require --dev atoms/testing:^0.5
```

See the [Atoms documentation](https://docs.atomsphp.dev) for local testing
patterns and framework-specific fakes.

## Development and support

This package is developed in the
[Atoms monorepo](https://github.com/AtomsPHP/atoms). Its standalone repository
is a read-only distribution mirror; report issues and send pull requests to
the monorepo. Licensed under the [MIT License](LICENSE).
