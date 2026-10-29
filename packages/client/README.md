# atoms/client

The framework-independent, monolith-side client for Atoms. It provides typed
Atom proxies, the signed callback kernel, manifest loading, retries, and the
shared host-adapter contracts used by the Laravel and Symfony integrations.

```sh
composer require atoms/client:^0.8
```

Applications normally install a framework adapter. Use this package directly
when integrating another framework or a plain PHP application; the normative
adapter contract and examples are in the
[Atoms documentation](https://docs.atomsphp.dev).

## Callback nonce storage

Pass a shared `NonceStore` to `CallbackKernelFactory::create()` before accepting
callbacks. The factory rejects authenticated callbacks with `ATOMS-E111` until
a store is configured. `RedisNonceStore::fromClient()` accepts PhpRedis,
RedisCluster, or Predis clients. Laravel and Symfony applications can configure
shared storage through their adapters.

`InMemoryNonceStore` is available for explicit local development and tests.
It loses records when its instance ends and can evict nonces before their
signed timestamps expire. See [Callbacks](https://docs.atomsphp.dev/guides/callbacks/)
for backend setup and retention requirements.

## Development and support

This package is developed in the
[Atoms monorepo](https://github.com/AtomsPHP/atoms). Its standalone repository
is a read-only distribution mirror; report issues and send pull requests to
the monorepo. Licensed under the [MIT License](LICENSE).
