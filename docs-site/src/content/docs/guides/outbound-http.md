---
title: Outbound HTTP
description: Call a trusted HTTPS API from an Atom and script the response in a test.
---

Add trusted HTTPS origins to the top-level `vars` in your Worker project's
`wrangler.jsonc`. The CLI carries these settings into dev and deploy.

```json
{
  "vars": {
    "ATOMS_HTTP_ALLOWLIST": ["https://api.example.com"]
  }
}
```

The default list is empty. Each entry authorizes every path on that exact
scheme, DNS hostname and effective port. Choose services whose operators and
DNS you trust. Use a separate entry for each origin. Wildcards, IP literals,
local names, credentials, paths, query strings and fragments are invalid
in this list.

Inside an Atom method, read the status, headers and raw response bytes:

```php
public function serviceStatus(): int
{
    $response = $this->http()->request('GET', 'https://api.example.com/status');

    return $response->status;
}
```

Pass request headers as a map of names to strings or lists of strings. Pass
raw body bytes as the fourth argument. GET and HEAD require a null body.
POST, PUT, PATCH, DELETE and OPTIONS also work. The Worker starts with fresh
headers and accepts explicit third-party Authorization. It rejects routing,
hop-by-hop, Cloudflare and Atoms headers. Cookies have to be supplied on
each request. HTTP statuses including redirects and errors are returned.
Inspect `$response->headers['location'][0]` to handle a redirect yourself.
Response header names are lowercase. Set-Cookie lines are separate list
entries and other repeated headers may be combined. A bodyless response has
`$response->body === ''`.

Finish SQLite transactions before calling HTTP. The Atom waits through the
last byte while holding its turn. HTTP and `app()` share a turn deadline.
A shorter HTTP timeout leaves the remaining turn budget available. An
upstream service may have processed a write before a timeout or disconnect.
Atoms issues one Fetch call per attempt. The underlying transport can resend
a request after a connection failure. Use the service's idempotency mechanism
for writes that must take effect once. See [runtime limits](/reference/limits/).

In an offline test, script exchanges before boot. Requests match the next
method and URL in order. You can also supply an exception as the result.

```php
use Atoms\Http\HttpResponse;
use Atoms\Testing\AtomHarness;

$harness = AtomHarness::for(StatusAtom::class, 'status-1')
    ->withHttp('GET', 'https://api.example.com/status', new HttpResponse(204));

self::assertSame(204, $harness->invoke('serviceStatus'));
self::assertCount(1, $harness->httpRequests());
```
