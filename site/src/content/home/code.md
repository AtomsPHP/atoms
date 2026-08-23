---
title: This is an Atom
caption: >-
  That's the Laravel facade. Symfony and plain PHP use the same client without
  it — inject `AtomsClient` and call `get()` the same way.
annotations:
  - method: join()
    kind: app
    anchor: a-join
    text: >-
      Your app calls this like any method. The seat check and the insert share
      a transaction, and because the Atom takes one call at a time, the count
      it read is still true when it writes.
  - method: onConnect()
    kind: cli
    anchor: a-onconnect
    text: >-
      A player's browser opened its WebSocket. The connection gets tied to
      their seat, and the current board goes straight back down the wire.
  - method: onMessage()
    kind: cli
    anchor: a-onmessage
    text: >-
      A move arrives over the socket. The UPDATE's WHERE clause is the referee
      — it matches only a piece the mover owns — and a move that landed is
      broadcast to everyone watching.
---
You write an ordinary PHP class and give it a database schema. You create one
Atom per entity — a game room, a document, a tenant — each addressable by id,
each handling one call at a time on data that lives in the same object.
