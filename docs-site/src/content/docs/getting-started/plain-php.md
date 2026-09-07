---
title: Plain PHP quickstart
description: Use the plain-PHP example for a framework-free Atoms host.
---

There is no adapter package for plain PHP the way there is for Laravel and Symfony; you wire up `atoms/client` yourself. [`examples/plain-php/`](https://github.com/AtomsPHP/atoms/tree/main/examples/plain-php) is a complete, conformance-tested  example - use it as a starting point.

Deployment is unchanged by the absence of an adapter: `vendor/bin/atoms` is the
same binary the framework wrappers shell out to, and it resolves configuration
the same way. With no framework loading a dotenv file for you, the only inlets
for a local token or callback URL are the environment you run the command in
and [`.env.atoms.<environment>`](/guides/configuration/#envatomsenvironment)
beside `atoms.json`. See [Configuration](/guides/configuration/) and
[Deploy](/guides/deploy/).
