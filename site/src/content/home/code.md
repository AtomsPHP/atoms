---
title: This is an Atom
caption: >-
  Fetch or create an Atom from your monolith
annotations:
  - method: join()
    kind: app
    anchor: a-join
    text: >-
      Write any method and call it from your PHP monolith. In this example, the main app adds a new player to a game.
  - method: onConnect()
    kind: cli
    anchor: a-onconnect
    text: >-
      Called when a client makes a WebSocket connection.
  - method: onMessage()
    kind: cli
    anchor: a-onmessage
    text: >-
      Called when a message arrives from a connected client.
---
An Atom is defined as a normal PHP class. It has its own database schema: each Atom instance has its own state separate from your app. An Atom can communicate with your app and directly with clients. Use an Atom to represent a game lobby, a document, a chat room, and more.
