---
title: How it works
stations:
  - label: your repo
    text: >-
      Atoms classes are defined in your PHP app. `atoms build` extracts them into a bundle.
  - label: atoms deploy
    text: >-
      Run `atoms deploy` (locally or in CI) to push your bundle to Cloudflare.
  - label: your cloudflare account
    text: >-
      A Durable Object is created for an Atom instance as soon as your app calls a method on it or a client tries to connect.
note: >-
  Work locally using `atoms dev`, which runs your Atoms on Cloudflare's open-source workerd runtime.
---
Write your Atoms alongside the rest of your PHP app - but deploy them to Durable Objects.
