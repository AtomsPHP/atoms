<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Environment
    |--------------------------------------------------------------------------
    |
    | Environment name (e.g. production, staging) selecting which Worker
    | endpoint/credentials apply. Purely descriptive here; endpoint/api_key
    | below are the values actually used for this app instance.
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
    | API key
    |--------------------------------------------------------------------------
    |
    | Must match the Worker's ATOMS_APP_KEY. Leave ATOMS_API_KEY unset (null)
    | when the Worker runs with ATOMS_APP_KEY unset — its bearer check is off
    | entirely then, and the client sends no Authorization header. Setting it to
    | an EMPTY string is not that posture: it is a misconfiguration, and
    | AtomsConfig throws rather than shipping "Authorization: Bearer ".
    |
    */

    'api_key' => env('ATOMS_API_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Timeout / retries
    |--------------------------------------------------------------------------
    */

    'timeout' => (float) env('ATOMS_TIMEOUT', 10.0),

    'max_attempts' => (int) env('ATOMS_MAX_ATTEMPTS', 3),

    /*
    |--------------------------------------------------------------------------
    | Platform public key
    |--------------------------------------------------------------------------
    |
    | Ed25519 public key (base64, or raw 32 bytes) used to verify inbound
    | callback signatures. Required for the callback route to function.
    |
    */

    'platform_public_key' => env('ATOMS_PLATFORM_PUBLIC_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Callback route
    |--------------------------------------------------------------------------
    |
    | The inbound callback route is auto-registered by AtomsServiceProvider.
    | It is intentionally NOT part of the "web" middleware group by default
    | (no session, no CSRF) — it is authenticated by Ed25519 signature instead
    | (see docs/conventions.md "Callback signing"). Add middleware here only if
    | your deployment needs it (e.g. rate limiting).
    |
    */

    'callback' => [
        'path' => '/atoms/callback',
        'middleware' => [],
    ],

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
