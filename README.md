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
| **Younger than TTL** (default 300s) | Serve cached. **No API call.** |
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
GD_WEBSITE_BASE_URI=https://graphql.gorilladash.com       # reads (the default)
GD_WEBSITE_WRITE_BASE_URI=https://api.gorilladash.com     # mutate() — see Mutations
GD_WEBSITE_CLIENT_ID=your-website-id        # the website API "ID"
GD_WEBSITE_CLIENT_SECRET=your-access-token   # the "API Access Token"
GD_WEBSITE_CACHE_TTL=60
```

The SDK exchanges these for a bearer token at `{base_uri}/oauth/token`
(OAuth2 client-credentials grant) and caches the token automatically.

### Two tokens: one for your server, one for the browser

Gorilla Dash accepts two kinds of bearer token, and only one of them may reach a browser.

| Token | Get it with | Use it for |
|-------|-------------|------------|
| **Website token** (full) | Handled for you by `graphql()` and `mutate()` | Server rendering and everything with a side effect: enquiries, forms, reviews, sign-ins, orders. It can run every query and mutation, so it never leaves your server. |
| **Browser token** (read-only) | `GorillaDash::browserToken()` | Anything the browser sees: page HTML, Inertia props, an Apollo client in the browser. It can run public queries only; mutations and per-visitor fields are refused. |

Anyone can copy a token out of a page's source. A copied browser token can read what your
website already shows, for at most an hour; a copied website token could submit enquiries,
reviews and orders as your website. See [Browser tokens](#browser-tokens).

## Configuration

All options live in `config/website-sdk.php` and are env-driven:

| Option | Env var | Default | Description |
|--------|---------|---------|-------------|
| `base_uri` | `GD_WEBSITE_BASE_URI` | `https://graphql.gorilladash.com` | API base URL for reads (token + GraphQL). |
| `write_base_uri` | `GD_WEBSITE_WRITE_BASE_URI` | — (`base_uri`) | API base URL for `mutate()`. Set `https://api.gorilladash.com` when `base_uri` is the default — see [Mutations](#mutations). |
| `client_id` | `GD_WEBSITE_CLIENT_ID` | — | Passport OAuth client id (website "ID"). |
| `client_secret` | `GD_WEBSITE_CLIENT_SECRET` | — | Passport client secret ("API Access Token"). |
| `public_key` | `GD_WEBSITE_PUBLIC_KEY` | — | Website public key — authenticates the cache-clear webhook (not GraphQL). |
| `cache_ttl` | `GD_WEBSITE_CACHE_TTL` | `300` | Freshness window (seconds). |
| `cache_store` | `GD_WEBSITE_CACHE_STORE` | app default | Laravel cache store to use. |
| `cache_prefix` | `GD_WEBSITE_CACHE_PREFIX` | `gd_website:` | Cache key prefix. |
| `stale_retention_multiplier` | `GD_WEBSITE_STALE_RETENTION` | `100` | Stale data kept for `cache_ttl × this` (0 = forever). |
| `max_stale_age` | `GD_WEBSITE_MAX_STALE_AGE` | `86400` | Hard ceiling on stale age (seconds); past it, block and refetch. 0 = disabled. |
| `register_graphql_route` | `GD_WEBSITE_GRAPHQL_ROUTE` | `false` | Expose the `POST` GraphQL proxy (queries only). Off by default since 0.6.0. |
| `graphql_path` | `GD_WEBSITE_GRAPHQL_PATH` | `graphql` | Path for the GraphQL proxy. |
| `graphql_rate_limit` | `GD_WEBSITE_GRAPHQL_RATE_LIMIT` | `60` | GraphQL proxy requests a minute per IP address. 0 = no limit. |
| `register_browser_token_route` | `GD_WEBSITE_BROWSER_TOKEN_ROUTE` | `false` | Expose the `GET` browser-token endpoint. |
| `browser_token_path` | `GD_WEBSITE_BROWSER_TOKEN_PATH` | `gorilla-dash/browser-token` | Path for the browser-token endpoint. |
| `browser_token_rate_limit` | `GD_WEBSITE_BROWSER_TOKEN_RATE_LIMIT` | `30` | Browser-token requests a minute per IP address. 0 = no limit. |
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

### Mutations

Anything with a side effect — a form submission, a booking — goes through `mutate()`, never
`graphql()`. It takes a raw string or a `Mutation` object and returns the `data` payload:

```php
use GraphQL\Mutation;

$mutation = (new Mutation('submitEnquiry'))
    ->setVariables([new Variable('email', 'String', true)])
    ->setArguments(['email' => new RawObject('$email')]);

$data = GorillaDash::mutate($mutation, ['email' => 'mary@example.test']);
```

Two things make it different from `graphql()`:

- **It is never cached.** `graphql()` answers a repeat of the same query and variables from
  the cache, and re-sends it in the background once it is stale. For a submission that means
  the second one is never sent, and a later refresh files it again. `mutate()` sends every
  call.
- **It goes to `write_base_uri`** (falling back to `base_uri`). The default read host,
  `graphql.gorilladash.com`, is GorillaDash's website API on Cloud Run behind a CDN that is
  purged on publish. It accepts a mutation and cannot finish it: `submitEnquiry` saves the
  enquiry, then times out reaching the queue the notification emails go to, so the caller
  gets a timeout and nobody hears about the lead. A site reading from that host sets
  `GD_WEBSITE_WRITE_BASE_URI=https://api.gorilladash.com`. The same OAuth client works on
  both hosts, and each is sent a token it issued.

`php artisan gd:check` prints both hosts.

### Browser tokens

`GorillaDash::browserToken()` returns a read-only token for this website, to hand to the
browser. The SDK exchanges the website token for it at `{base_uri}/oauth/website-browser-token`
and keeps it until it expires, asking for a new one once it has less than ten minutes left. A
token lasts an hour, so while the exchange works Gorilla Dash is asked about once every fifty
minutes, not on every page view; while it fails, it is asked at most once every 30 seconds
(see below). Pages rendered at the same moment share one exchange (a cache lock), which
matters because the exchange accepts at most 60 requests a minute from each website.

```php
$token = GorillaDash::browserToken();
// => 'eyJ0eXAiOiJKV1Qi...'

GorillaDash::browserTokenWithExpiry();
// => ['access_token' => 'eyJ0eXAiOiJKV1Qi...', 'expires_in' => 3312]
```

The same methods are on `TokenManager` (`getBrowserToken()`, `getBrowserTokenWithExpiry()`)
for code that already injects it.

Things worth knowing:

- **A slow or failing Gorilla Dash does not hold up your pages.** The browser token is read
  on every page render, so:
  - the exchange gives up after 3 seconds (2 to connect), and a website token minted on the
    way after 5 (2 to connect), whatever `http_timeout` says;
  - a page that finds another request exchanging gets the previous token straight away while
    it is still valid, and waits (up to 5 seconds) only when there is no valid token at all;
  - a failed exchange is reported once, and for the next 30 seconds nothing is exchanged, a
    forced refresh included. Meanwhile the previous token is returned while it is still
    valid, and `GdRequestException` is thrown only when there is none. Wrap the call in
    `rescue()` if a page should render without it.
- **The exchange always goes to `base_uri`**, never to `write_base_uri`. Only
  `graphql.gorilladash.com`, the default `base_uri`, serves it; a site whose `base_uri` points
  at another host gets a `GdRequestException` naming the host, at most once every 30 seconds,
  and no new website tokens are minted for it.
- **Only a read-only token is handed out.** The answer must carry `data.scope` =
  `website:read`; anything else is treated as a failed exchange.
- **Pass `forceRefresh: true` when Gorilla Dash refused the last browser token** (the API
  Access Token was regenerated, or the token was revoked). It is honoured at most once a
  minute per site, so a visitor cannot use it to spend the exchange budget; within that
  minute the cached token is returned.
- **A refused website token is recovered once.** When the exchange answers 401 (or 404 from
  `graphql.gorilladash.com`), the SDK forgets the cached website token, mints a new one and
  tries the exchange again. If that is refused too, the exchange has failed, as above.
- **A browser token can only run public queries.** Run mutations with `mutate()` and anything
  that needs the full token with `graphql()`, on your server.

#### Inertia sites

Share the browser token as a prop and point the browser's GraphQL client at `base_uri`. If the
client asks for that prop alone with a partial reload after Gorilla Dash refuses a token, as
the client-site starter's Apollo client does, that request is the moment to force a new one:

```php
// app/Http/Middleware/HandleInertiaRequests.php
public function share(Request $request): array
{
    return [
        ...parent::share($request),
        'graphqlURL' => config('website-sdk.base_uri'),
        // Read-only, so it is safe in the page. Never share the website token here.
        'graphqlToken' => fn () => rescue(
            fn () => GorillaDash::browserToken(
                forceRefresh: $request->header('X-Inertia-Partial-Data') === 'graphqlToken',
            ),
            null,
            report: false,
        ),
    ];
}
```

If your server-side renderer runs the same GraphQL client with this prop, its queries are
read-only too, and Gorilla Dash applies the browser token's limits to them. Fetch anything
that needs the full token in the controller with `GorillaDash::graphql()` and pass it as a prop.

#### Sites without Inertia

Write the token into the page, and turn on the package's browser-token endpoint so browser code
can ask for a new one without a page load:

```dotenv
GD_WEBSITE_BROWSER_TOKEN_ROUTE=true
```

```blade
<script>window.websiteAccessToken = @json(GorillaDash::browserToken())</script>
```

See [`GET /gorilla-dash/browser-token`](#get-gorilla-dashbrowser-token-off-by-default) below.

## HTTP endpoints

The package can register three routes. Only the cache-clear webhook is on by default; each can
be turned on or off and repathed via config (see the table above). They are loaded outside the
`web` middleware group, so they carry no session, cookies or CSRF check.

### `POST /graphql` (off by default)

A GraphQL proxy that runs a `{ query, variables }` body through the SWR cache and returns the
full envelope (`data` + cache metadata):

```bash
curl -X POST https://your-site.test/graphql \
  -H 'Content-Type: application/json' \
  -d '{"query":"{ websiteInfo { name url } }"}'
# => { "data": { "websiteInfo": {...} }, "cached_at": 1718..., "age": 0, "status": "miss" }
```

It answers anyone who can reach your site, so:

- **It is off unless you set `GD_WEBSITE_GRAPHQL_ROUTE=true`.** Before 0.6.0 it was on by
  default. A site that published `config/website-sdk.php` keeps whatever that file says:
  `env('GD_WEBSITE_GRAPHQL_ROUTE', true)` there leaves it on, so change the default to `false`
  if you do not use it.
- **It runs queries only.** A document that defines a mutation or a subscription is refused
  with `403` and a GraphQL-style `errors` body before anything is sent; a body without a
  well-formed query gets `400`. Send mutations from your server with `mutate()`.
- **It runs them with the read-only browser token**, so it can read only what the website
  shows the public, with the browser token's limits. Its answers are cached apart from your
  server's own `graphql()` reads, which keep the full token. Gorilla Dash sees your
  server, not the visitor, as the caller, so any per-visitor limit it applies counts every
  visitor of the proxy together.
- **It is rate limited per IP address**, 60 requests a minute by default
  (`GD_WEBSITE_GRAPHQL_RATE_LIMIT`, `0` for no limit). See the note on visitor addresses
  below.

Browser code that queries Gorilla Dash is usually better served by a
[browser token](#browser-tokens) sent straight to `base_uri`.

### `GET /gorilla-dash/browser-token` (off by default)

Turned on with `GD_WEBSITE_BROWSER_TOKEN_ROUTE=true`. Answers a read-only browser token and the
seconds it has left, with `Cache-Control: no-store, private` so no browser or shared cache keeps
it:

```bash
curl https://your-site.test/gorilla-dash/browser-token
# => { "access_token": "eyJ0eXAiOiJKV1Qi...", "expires_in": 3312 }
```

Add `?refresh=1` when Gorilla Dash refused the last token; the forced refresh is honoured at
most once a minute per site, and the cached token is returned otherwise. When no token can be
had it answers `503` with a JSON `message`. It is rate limited per IP address, 30 requests a
minute by default (`GD_WEBSITE_BROWSER_TOKEN_RATE_LIMIT`).

> **Per-IP limits need the visitor's address.** Both limits above count requests per IP
> address as Laravel sees it. Behind a load balancer, a CDN or Cloudflare, that is the
> balancer's address unless the site trusts the proxy (`trustProxies`) or reads the visitor
> address the CDN passes on (for example `CF-Connecting-IP`). Without that, every visitor
> shares one counter and the per-IP limit becomes a limit for the whole site.

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
renders it into the initial HTML. A token for the browser's own GraphQL client is the
[browser token](#inertia-sites), never the website token:

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

- `TokenManager` — exchanges client-credentials for the website token and caches it until just
  before expiry; exchanges that for the read-only browser token (`getBrowserToken()`) and
  caches it until it has ten minutes left.
- `GraphQlTransport` — sends queries with the bearer token; retries once on a 401 with a fresh
  token, and once on a 404 to the website token (how Gorilla Dash refuses a revoked or expired
  one). A mint on a 404 is tried at most once a minute per host, and not for an hour after a
  new token was refused too.
- `SwrCache` — the stale-while-revalidate logic; schedules background refreshes via the
  application's `terminating` callbacks (runs after the response is sent).
- `mutate()` skips `SwrCache` and sends straight through `GraphQlTransport`, on a connection
  aimed at `write_base_uri` (`Connection::forWrites()`).

## License

MIT — see [LICENSE](LICENSE).
