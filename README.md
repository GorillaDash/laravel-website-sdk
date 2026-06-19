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

The SDK is distributed from a private/VCS repository. Add the source, then require it:

```jsonc
// composer.json
"repositories": [
    { "type": "vcs", "url": "git@github.com:gorilladash/laravel-website-sdk.git" }
]
```

```bash
composer require gorilladash/laravel-website-sdk
php artisan vendor:publish --tag=website-sdk-config   # optional
```

The service provider and the `GorillaDash` facade are auto-discovered.

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
| `cache_ttl` | `GD_WEBSITE_CACHE_TTL` | `60` | Freshness window (seconds). |
| `cache_store` | `GD_WEBSITE_CACHE_STORE` | app default | Laravel cache store to use. |
| `cache_prefix` | `GD_WEBSITE_CACHE_PREFIX` | `gd_website:` | Cache key prefix. |
| `stale_retention_multiplier` | `GD_WEBSITE_STALE_RETENTION` | `100` | Stale data kept for `cache_ttl × this` (0 = forever). |
| `token_skew` | `GD_WEBSITE_TOKEN_SKEW` | `60` | Re-exchange the token this many seconds early. |
| `http_timeout` | `GD_WEBSITE_HTTP_TIMEOUT` | `10` | Per-request timeout (seconds). |

## Usage

```php
use GorillaDash\WebsiteSdk\Facades\GorillaDash;

// Raw query — returns the GraphQL `data` array (SWR cached):
$data = GorillaDash::graphql('{ websiteInfo { name url } }');

// With variables:
$data = GorillaDash::graphql(
    'query ($slug: String) { websitePage(slug: $slug) { name body } }',
    ['slug' => 'about-us'],
);

// With cache metadata (status: miss | fresh | stale, age in seconds):
$result = GorillaDash::graphqlWithMeta('{ websiteInfo { name } }');
// => ['data' => [...], 'cached_at' => 1718..., 'age' => 0, 'status' => 'miss']

// Per-call overrides (different credentials, or a custom TTL):
$data = GorillaDash::connection(['cache_ttl' => 300])->info('name url');

// Verify credentials (throws GdRequestException on failure):
GorillaDash::ping();
```

### Convenience helpers

Each helper takes an explicit field selection (and optional args + TTL). For any query not
listed (products, tribes, reviews, articles, …) use `graphql()`.

```php
GorillaDash::info('name url');
GorillaDash::page('about-us', 'name slug body contents { name type value }', ['locale' => 'en']);
GorillaDash::pages('name slug show_in_menu');
GorillaDash::sections('name contents { name type value }');
GorillaDash::menu('Main Menu', 'name menu_json');
GorillaDash::menus('name menu_json');
GorillaDash::faqs('slug question answer');
GorillaDash::faqCategories('name sort');
```

## Error handling

Transport, auth and GraphQL errors throw `GorillaDash\WebsiteSdk\Exceptions\GdRequestException`:

```php
use GorillaDash\WebsiteSdk\Exceptions\GdRequestException;

try {
    $info = GorillaDash::info('name url');
} catch (GdRequestException $e) {
    $info = null; // render a fallback; $e->graphqlErrors holds GraphQL error details
}
```

## Inertia SSR client sites

Fetch in the controller and pass the data as Inertia props — the cache keeps it fast and SSR
renders it into the initial HTML:

```php
return Inertia::render('Home', [
    'info' => GorillaDash::info('name url'),
    'menu' => GorillaDash::menu('Main Menu', 'name menu_json'),
]);
```

## Testing

Fake the HTTP layer with Laravel's `Http::fake()` — no real API calls:

```php
Http::fake([
    'api.gorilladash.com/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
    'api.gorilladash.com/graphql' => Http::response(['data' => ['websiteInfo' => ['name' => 'Acme']]]),
]);

expect(GorillaDash::info('name'))->toBe(['websiteInfo' => ['name' => 'Acme']]);
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
