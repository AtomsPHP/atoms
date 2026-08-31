---
title: Why use Atoms?
quads:
  - title: Offload state
    text: >-
      Some applications don't need to store all state centrally. Each Atom class has its own database schema, and each instance has its own persistent SQLite database.
  - title: Scoped real-time interactions
    text: >-
      In many applications, real-time traffic belongs to one *thing* in your domain: a game, a chat, a team. Each of those things can be defined as an Atom that defines its own real-time interactions.
  - title: Offload compute
    text: >-
      Keep your monolith's server small by pushing compute to your Atoms. You could even have your monolith scaled to zero while your users are still interacting with Atoms!
---
