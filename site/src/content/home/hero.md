---
title_html: 'Persistent <em>PHP objects</em>, inside Cloudflare <em class="o">Durable Objects</em>.'
ctas:
  - { label: 'Get started', href: 'https://github.com/AtomsPHP/atoms#install', style: solid }
  - { label: 'GitHub', href: 'https://github.com/AtomsPHP/atoms', style: ghost }
---
An Atom is a long-lived PHP object running inside a Cloudflare Durable Object. Your PHP app calls it like a normal object - but each Atom controls its own SQLite database and talks directly with clients over websockets.
