---
title: How it works
stations:
  - label: your repo
    text: >-
      Atom classes live in your app. `atoms build` extracts them into a bundle
      — deterministically, without ever executing your code.
  - label: atoms deploy
    text: >-
      The CLI drives the Wrangler already pinned in your project. Your
      Cloudflare credentials go to Wrangler and nowhere else.
  - label: your cloudflare account
    text: >-
      One Durable Object per Atom id: PHP in WebAssembly, SQLite stored
      durably beside it, WebSockets and timers handled by the platform.
note: >-
  Local development is `atoms dev` — the same runtime on your machine, no
  Cloudflare account required.
---
Your Atom classes stay in your repository, next to the rest of your app. A
build step extracts them into a bundle, and a deploy ships that bundle to a
Worker in your Cloudflare account, where each Atom id gets its own Durable
Object running PHP 8.3 compiled to WebAssembly — with its SQLite database
stored durably beside it.
