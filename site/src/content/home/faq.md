---
title: FAQ
---

### Where do my Atoms run?

In your own Cloudflare account. Atoms is an open-source library
that you deploy yourself. A Workers Paid plan is required due to the PHP
runtime size exceeding free tier limits.

### Which PHP versions are supported?

The `atoms/*` packages require PHP 8.3 or newer in your main app. Atoms
themselves currently run on PHP 8.3 compiled to WebAssembly inside the Durable Object.

### Which frameworks does Atoms support?

There are adapters for Laravel and Symfony. Any other PHP application can use
`atoms/client` directly - use the framework adapters for inspiration!

### Is Atoms ready for production?

Atoms is new and experimental - for now, use it at your own risk.

### Is Atoms affiliated with Cloudflare, Laravel, or Symfony?

No. Atoms is a project by Daniel Abernathy
([@dabernathy89](https://github.com/dabernathy89)) and it is neither
affiliated with nor endorsed by Cloudflare, Laravel, or Symfony.

### What's on the roadmap?

Future plans include:

- an Atoms-specific PDO driver to repair some limitations of the current SQLite bridge
- a custom WebAssembly build of PHP optimized for bundle size and cold boot time
