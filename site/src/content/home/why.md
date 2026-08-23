---
title: Why you'd reach for it
quads:
  - title: Wired into your app, both directions
    text: >-
      Your controllers call an Atom's methods the way they'd call any
      object's, and an Atom talks back the same way — it can invoke methods in
      your app and queue jobs on your app's queue. The whole bridge between
      monolith and Durable Object comes built, and it speaks PHP at both ends.
  - title: Real-time, without leaving PHP
    text: >-
      Multiplayer, presence, live dashboards — the features that usually mean
      bolting a Node service or a hosted pub/sub onto your monolith. An Atom
      holds the sockets and the state in one place, in the language your team
      already writes.
  - title: State that can't race
    text: >-
      Some entities are fights waiting to happen — a seat map, an auction, a
      shared cart. Give each one an Atom and consistency comes built in: one
      object, one call at a time, its own SQLite database.
  - title: Scale that runs itself
    text: >-
      Every entity gets its own object with its own database, so a thousand
      rooms are a thousand small databases, each carrying only its own load.
      Idle Atoms hibernate, and Cloudflare handles placement and wake-up for
      you.
---
