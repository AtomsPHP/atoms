---
title: Who gets a socket
caption: >-
  Ticket claims override the URL's query string, so `$params['client_id']` in
  `onConnect` is the identity your server asserted — the browser can't forge
  it.
---
A browser gets a socket only with a short-lived ticket, minted by your app in
a normal authenticated route — and the ticket's job ends at the handshake.
After that, messages flow straight between browser and Atom; your app is back
in the loop only if the connection drops.
