<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | GorillaDash API base URI
    |--------------------------------------------------------------------------
    |
    | The base URL of the GorillaDash instance that serves the website GraphQL
    | API and the OAuth token endpoint. No trailing slash.
    |
    */
    'base_uri' => env('GD_WEBSITE_BASE_URI', 'https://api.gorilladash.com'),

    /*
    |--------------------------------------------------------------------------
    | OAuth client credentials
    |--------------------------------------------------------------------------
    |
    | The Passport "client_credentials" client_id / client_secret tied to the
    | GorillaDash Website this site renders. Exchanged at {base_uri}/oauth/token
    | for a short-lived bearer token.
    |
    */
    'client_id' => env('GD_WEBSITE_CLIENT_ID'),
    'client_secret' => env('GD_WEBSITE_CLIENT_SECRET'),

    /*
    |--------------------------------------------------------------------------
    | Stale-while-revalidate freshness window (seconds)
    |--------------------------------------------------------------------------
    |
    | How long a cached payload is considered "fresh". Within this window the
    | cached value is served with no API call. Past it, the cached value is
    | still served immediately and a background refresh is scheduled.
    |
    */
    'cache_ttl' => (int) env('GD_WEBSITE_CACHE_TTL', 60),

    /*
    |--------------------------------------------------------------------------
    | Cache store + key prefix
    |--------------------------------------------------------------------------
    |
    | Which Laravel cache store to use (null = the app default) and the prefix
    | applied to every cache key written by this package.
    |
    */
    'cache_store' => env('GD_WEBSITE_CACHE_STORE'),
    'cache_prefix' => env('GD_WEBSITE_CACHE_PREFIX', 'gd_website:'),

    /*
    |--------------------------------------------------------------------------
    | Stale retention multiplier
    |--------------------------------------------------------------------------
    |
    | Cached payloads are retained for cache_ttl * this multiplier so stale
    | data survives well past the freshness window and can be served while a
    | background refresh runs. Set to 0 to retain forever.
    |
    */
    'stale_retention_multiplier' => (int) env('GD_WEBSITE_STALE_RETENTION', 100),

    /*
    |--------------------------------------------------------------------------
    | Token early-refresh skew (seconds)
    |--------------------------------------------------------------------------
    |
    | Re-exchange the bearer token this many seconds before it actually expires
    | to avoid using a token that lapses mid-flight.
    |
    */
    'token_skew' => (int) env('GD_WEBSITE_TOKEN_SKEW', 60),

    /*
    |--------------------------------------------------------------------------
    | HTTP timeout (seconds)
    |--------------------------------------------------------------------------
    */
    'http_timeout' => (int) env('GD_WEBSITE_HTTP_TIMEOUT', 10),
];
