# GorillaDash Website SDK

[![Latest Version](https://img.shields.io/badge/packagist-gorilladash%2Flaravel--website--sdk-blue)](https://packagist.org)
[![License: MIT](https://img.shields.io/badge/license-MIT-green)](LICENSE)

A Laravel package that connects a client website to the **GorillaDash website GraphQL API**
and serves its content through a **stale-while-revalidate (SWR) cache** — so server-rendered
pages return **instantly** from cache while fresh data is fetched from the API in the
background. The browser never waits on an API call.

> **Live demo:** [website-sdk-demo.gorilladash.com](https://website-sdk-demo.gorilladash.com)
> — a small Inertia + SSR site that lets you enter credentials, run any of the website
> GraphQL queries, and watch the cache behaviour (and timings) live.

---

## Why

Client websites built on GorillaDash need to render marketing/content pages fast. Calling the
GraphQL API on every request adds latency and a hard dependency on the API being up. This SDK
puts a cache in front of the API with **stale-while-revalidate** semantics:

| Cache state | Behaviour |
|-------------|-----------|
| **No cache** | Block, fetch from the API, store, return. |
| **Younger than TTL** (default 60s) | Serve cached. **No API call.** |
| **Older than TTL** | Serve cached **instantly**, and refresh from the API **in the background** (after the response is sent — no queue worker needed). The next request gets the newer data. |

Stale data is retained well past the TTL so there is always something to serve, and background
refreshes are guarded by a per-key lock to avoid stampedes.

---

## Requirements

- PHP 8.3+
- Laravel 11, 12 or 13

## Installation

```bash
composer require gorilladash/laravel-website-sdk
php artisan vendor:publish --tag=website-sdk-config   # optional
```

The service provider and the `GorillaDash` facade are auto-discovered.

The provider is built on [spatie/laravel-package-tools](https://github.com/spatie/laravel-package-tools):
it extends `PackageServiceProvider` and wires everything declaratively in `configurePackage()` —
the config file (published under the `website-sdk-config` tag) and the cache-clear webhook route
(`routes/web.php`). Service bindings are registered in the `packageRegistered()` lifecycle hook.

## Credentials

From the GorillaDash **website API settings** you get three values. Map them as follows — the
public key is **not** used for GraphQL auth:

```dotenv
GD_WEBSITE_BASE_URI=https://api.gorilladash.com
GD_WEBSITE_CLIENT_ID=your-website-id        # the website API "ID"
GD_WEBSITE_CLIENT_SECRET=your-access-token   # the "API Access Token"
GD_WEBSITE_CACHE_TTL=60
```

The SDK exchanges these for a bearer token at `{base_uri}/oauth/token`
(OAuth2 client-credentials grant) and caches the token automatically.

## Configuration

All options live in `config/website-sdk.php` and are env-driven:

| Option | Env var | Default | Description |
|--------|---------|---------|-------------|
| `base_uri` | `GD_WEBSITE_BASE_URI` | `https://api.gorilladash.com` | API base URL (token + GraphQL). |
| `client_id` | `GD_WEBSITE_CLIENT_ID` | — | Passport OAuth client id (website "ID"). |
| `client_secret` | `GD_WEBSITE_CLIENT_SECRET` | — | Passport client secret ("API Access Token"). |
| `public_key` | `GD_WEBSITE_PUBLIC_KEY` | — | Website public key — authenticates the cache-clear webhook (not GraphQL). |
| `cache_ttl` | `GD_WEBSITE_CACHE_TTL` | `60` | Freshness window (seconds). |
| `cache_store` | `GD_WEBSITE_CACHE_STORE` | app default | Laravel cache store to use. |
| `cache_prefix` | `GD_WEBSITE_CACHE_PREFIX` | `gd_website:` | Cache key prefix. |
| `stale_retention_multiplier` | `GD_WEBSITE_STALE_RETENTION` | `100` | Stale data kept for `cache_ttl × this` (0 = forever). |
| `max_stale_age` | `GD_WEBSITE_MAX_STALE_AGE` | `86400` | Hard ceiling on stale age (seconds); past it, block and refetch. 0 = disabled. |
| `register_graphql_route` | `GD_WEBSITE_GRAPHQL_ROUTE` | `true` | Expose the `POST` GraphQL endpoint. |
| `graphql_path` | `GD_WEBSITE_GRAPHQL_PATH` | `graphql` | Path for the GraphQL endpoint. |
| `register_clear_cache_route` | `GD_WEBSITE_CLEAR_CACHE_ROUTE` | `true` | Register the cache-clear webhook. |
| `clear_cache_path` | `GD_WEBSITE_CLEAR_CACHE_PATH` | `gorilla-dash/clear-cache` | Path for the cache-clear webhook. |
| `token_skew` | `GD_WEBSITE_TOKEN_SKEW` | `60` | Re-exchange the token this many seconds early. |
| `http_timeout` | `GD_WEBSITE_HTTP_TIMEOUT` | `10` | Per-request timeout (seconds). |

## Usage

Queries are built with [`mghoneimy/php-graphql-client`](https://github.com/mghoneimy/php-graphql-client)
(Packagist: `gmostafa/php-graphql-client`, a dependency of this package). `graphql()` takes a
`Query` (or `QueryBuilderInterface`) object; `graphqlWithMeta()` additionally accepts a raw
query string.

```php
use GorillaDash\WebsiteSdk\Facades\GorillaDash;
use GraphQL\Query;
use GraphQL\RawObject;
use GraphQL\Variable;

// Pass a Query object — returns the GraphQL `data` array (SWR cached):
$query = (new Query('websiteInfo'))->setSelectionSet(['name', 'url']);
$data = GorillaDash::graphql($query);

// With variables — declare them on the query, pass values as the second arg:
$query = (new Query('websitePage'))
    ->setVariables([new Variable('slug', 'String', true)])
    ->setArguments(['slug' => new RawObject('$slug')])
    ->setSelectionSet(['name', 'body']);
$data = GorillaDash::graphql($query, ['slug' => 'about-us']);

// graphqlWithMeta() also accepts a raw query string, and returns the full cache
// envelope (status: miss | fresh | stale, age in seconds):
$result = GorillaDash::graphqlWithMeta('{ websiteInfo { name } }');
// => ['data' => [...], 'cached_at' => 1718..., 'age' => 0, 'status' => 'miss']

// Per-call overrides (different credentials, or a custom TTL):
$data = GorillaDash::connection(['cache_ttl' => 300])->graphql($query);

// Verify credentials (throws GdRequestException on failure):
GorillaDash::ping();
```

## HTTP endpoints

The package registers two routes (both can be disabled or repathed via config — see the table above):

### `POST /graphql`

A ready-to-use GraphQL endpoint that proxies a `{ query, variables }` body through the SWR
cache and returns the full envelope (`data` + cache metadata). Useful for client-side / SSR
fetching without writing a controller:

```bash
curl -X POST https://your-site.test/graphql \
  -H 'Content-Type: application/json' \
  -d '{"query":"{ websiteInfo { name url } }"}'
# => { "data": { "websiteInfo": {...} }, "cached_at": 1718..., "age": 0, "status": "miss" }
```

### Cache-clear webhook

GorillaDash calls this when content changes, to flush this site's cache. Point GorillaDash's
**"Cache Clear URL"** at `{your-site}/gorilla-dash/clear-cache?key={public_key}`; a request whose
`key` matches `public_key` flushes the cache (any other request gets a `404`).

## Error handling

Transport, auth and GraphQL errors throw `GorillaDash\WebsiteSdk\Exceptions\GdRequestException`:

```php
use GorillaDash\WebsiteSdk\Exceptions\GdRequestException;
use GraphQL\Query;

try {
    $info = GorillaDash::graphql((new Query('websiteInfo'))->setSelectionSet(['name', 'url']));
} catch (GdRequestException $e) {
    $info = null; // render a fallback; $e->graphqlErrors holds GraphQL error details
}
```

## Inertia SSR client sites

Fetch in the controller and pass the data as Inertia props — the cache keeps it fast and SSR
renders it into the initial HTML:

```php
use GraphQL\Query;

return Inertia::render('Home', [
    'info' => GorillaDash::graphql(
        (new Query('websiteInfo'))->setSelectionSet(['name', 'url'])
    ),
    'menu' => GorillaDash::graphql(
        (new Query('websiteMenu'))
            ->setArguments(['name' => 'Main Menu'])
            ->setSelectionSet(['name', 'menu_json'])
    ),
]);
```

## Testing

Fake the HTTP layer with Laravel's `Http::fake()` — no real API calls:

```php
use GraphQL\Query;

Http::fake([
    'api.gorilladash.com/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
    'api.gorilladash.com/graphql' => Http::response(['data' => ['websiteInfo' => ['name' => 'Acme']]]),
]);

expect(GorillaDash::graphql((new Query('websiteInfo'))->setSelectionSet(['name'])))
    ->toBe(['websiteInfo' => ['name' => 'Acme']]);
```

Run the package test suite:

```bash
composer install
vendor/bin/pest
```

## How it works

```
Your app ──> GorillaDash facade ──> SwrCache ──> (cache hit? serve)
                                       │
                                       └─ miss/stale ─> TokenManager ─> /oauth/token (cached bearer)
                                                         └─> GraphQlTransport ─> POST /graphql
```

- `TokenManager` — exchanges client-credentials for a bearer token and caches it until just
  before expiry.
- `GraphQlTransport` — sends queries with the bearer token; retries once on a 401 with a fresh token.
- `SwrCache` — the stale-while-revalidate logic; schedules background refreshes via the
  application's `terminating` callbacks (runs after the response is sent).

## License

MIT — see [LICENSE](LICENSE).
