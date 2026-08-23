---
title: Browsers connect to the same object
caption: 'The URL comes from `Atoms::wsUrl()`, ticket included.'
---
An Atom can hold WebSocket connections itself. Every browser watching
`room-42` is connected to the one object that owns room 42's state, so when it
calls `broadcast()`, everyone sees the change — the sockets and the data live
in the same place, so they always agree.

Your app mints a short-lived ticket and hands the browser a URL; the browser
just opens it.
