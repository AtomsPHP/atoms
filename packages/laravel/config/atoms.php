<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Environment
    |--------------------------------------------------------------------------
    |
    | Environment name (e.g. production, staging) selecting which Worker
    | endpoint/credentials apply. Purely descriptive here; endpoint and
    | shared_secret below are the values actually used for this app instance.
    |
    */

    'environment' => env('ATOMS_ENVIRONMENT', 'production'),

    /*
    |--------------------------------------------------------------------------
    | Endpoint
    |--------------------------------------------------------------------------
    |
    | Base URL of YOUR deployed Atoms Worker, e.g.
    | https://atoms.<your-subdomain>.workers.dev, a custom route, or
    | http://127.0.0.1:8787 for `wrangler dev`. There is deliberately no
    | default: Atoms is self-hosted in your own Cloudflare account, so any
    | built-in host would be wrong. Set ATOMS_ENDPOINT.
    |
    */

    'endpoint' => env('ATOMS_ENDPOINT', ''),

    /*
    |--------------------------------------------------------------------------
    | Shared secret
    |--------------------------------------------------------------------------
    |
    | The root of the app <-> Worker boundary: base64 of 32 random bytes
    | (`openssl rand -base64 32`), configured identically here and on the
    | Worker (`wrangler secret put ATOMS_SHARED_SECRET`). Required.
    |
    | NEVER send this value anywhere. Requests carry a bearer derived from it
    | by HKDF-SHA256, and inbound callbacks are verified with a second key
    | derived the same way. `atoms token` prints the derived bearer for
    | hand-issued requests (e.g. curl).
    |
    */

    'shared_secret' => env('ATOMS_SHARED_SECRET'),

    /*
    |--------------------------------------------------------------------------
    | Shared secret (previous)
    |--------------------------------------------------------------------------
    |
    | Rotation overlap. While set, a callback signed under the previous secret
    | still verifies, so both sides can adopt a new secret without downtime.
    | Same format as ATOMS_SHARED_SECRET. Outbound requests always use the
    | bearer derived from the current secret. Unset it once every instance on
    | both sides holds the new value.
    |
    */

    'shared_secret_previous' => env('ATOMS_SHARED_SECRET_PREVIOUS'),

    /*
    |--------------------------------------------------------------------------
    | Timeout / retries
    |--------------------------------------------------------------------------
    */

    'timeout' => (float) env('ATOMS_TIMEOUT', 10.0),

    'max_attempts' => (int) env('ATOMS_MAX_ATTEMPTS', 3),

    /*
    |--------------------------------------------------------------------------
    | Callback route
    |--------------------------------------------------------------------------
    |
    | The inbound callback route is auto-registered by AtomsServiceProvider.
    | It is intentionally NOT part of the "web" middleware group by default
    | (no session, no CSRF) — it is authenticated by an HMAC-SHA256 signature
    | instead (see docs/conventions.md "Callback signing"). Add middleware here
    | only if your deployment needs it (e.g. rate limiting).
    |
    */

    'callback' => [
        'path' => env('ATOMS_CALLBACK_PATH', '/atoms/callback'),
        'middleware' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Callback timestamp window
    |--------------------------------------------------------------------------
    |
    | Seconds of clock skew tolerated between the signed X-Atoms-Timestamp
    | header and this app's own clock before a callback request is rejected
    | as a replay (see docs/conventions.md "Callback signing").
    |
    */

    'callback_timestamp_window' => (int) env('ATOMS_CALLBACK_TIMESTAMP_WINDOW', 300),

    /*
    |--------------------------------------------------------------------------
    | Manifest path
    |--------------------------------------------------------------------------
    |
    | Relative to the application base path, resolved lazily (not at config-load
    | time) so it works the same in the app and in tests with a different base
    | path. Absent/missing file is not an error — the client and callback
    | resolver both degrade gracefully with no manifest loaded.
    |
    */

    'manifest_path' => env('ATOMS_MANIFEST_PATH', '.atoms/build/manifest.json'),

    /*
    |--------------------------------------------------------------------------
    | HTTP client override
    |--------------------------------------------------------------------------
    |
    | Container binding id to resolve as the PSR-18 client. Leave null to use
    | an existing Psr\Http\Client\ClientInterface binding if one exists, or a
    | plain new GuzzleHttp\Client() otherwise.
    |
    */

    'http_client' => null,

];
