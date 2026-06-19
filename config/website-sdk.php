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
    | Website public key
    |--------------------------------------------------------------------------
    |
    | The GorillaDash Website "public key". Not used for GraphQL auth — it
    | authenticates the cache-clear webhook below, so GorillaDash can flush this
    | site's cache when content changes.
    |
    */
    'public_key' => env('GD_WEBSITE_PUBLIC_KEY'),

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
    | Maximum stale age (seconds)
    |--------------------------------------------------------------------------
    |
    | A hard ceiling on how old cached data may be served. Past this age the
    | cache is no longer served stale — the request blocks and fetches fresh
    | (falling back to the stale copy only if that fetch fails). Set to 0/null
    | to disable (pure stale-while-revalidate). Default: 1 day.
    |
    */
    'max_stale_age' => (int) env('GD_WEBSITE_MAX_STALE_AGE', 86400),

    /*
    |--------------------------------------------------------------------------
    | Cache-clear webhook
    |--------------------------------------------------------------------------
    |
    | When enabled, the package registers a route that flushes this site's cache
    | when called with ?key={public_key}. Point GorillaDash's "Cache Clear URL"
    | at {your-site}/{clear_cache_path}?key={public_key}.
    |
    */
    'register_clear_cache_route' => (bool) env('GD_WEBSITE_CLEAR_CACHE_ROUTE', true),
    'clear_cache_path' => env('GD_WEBSITE_CLEAR_CACHE_PATH', 'gorilla-dash/clear-cache'),

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
