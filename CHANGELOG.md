# Changelog

All notable changes to `gorilladash/laravel-website-sdk`. The package is pre-1.0, so a minor
release (0.5 → 0.6) may break things and a site on `^0.5` does not pick it up until its
`composer.json` asks for `^0.6`.

## 0.6.0 — unreleased

### Breaking

- **The GraphQL proxy route (`POST /graphql`) is off by default.** It sends the full website
  token on behalf of anyone who can reach the site, and was registered on every site that
  installed the package. A site that uses it sets `GD_WEBSITE_GRAPHQL_ROUTE=true`. A site that
  published `config/website-sdk.php` keeps what that file says: if it reads
  `env('GD_WEBSITE_GRAPHQL_ROUTE', true)`, the route stays on until that default is changed
  to `false`.

### Added

- **Browser tokens.** `GorillaDash::browserToken()` and `TokenManager::getBrowserToken()`
  return a read-only token (scope `website:read`, public queries only) for anything the
  browser sees: page HTML, Inertia props, a browser Apollo client. `browserTokenWithExpiry()` /
  `getBrowserTokenWithExpiry()` also return the seconds it has left. The website token stays on
  the server, for server rendering and `mutate()`.
  - Exchanged at `{base_uri}/oauth/website-browser-token` (never `write_base_uri`), cached
    under its own key until it has ten minutes left, and shared between concurrent requests
    through a cache lock.
  - A 401 or 404 from the exchange means the website token was refused: it is forgotten, a new
    one minted and the exchange retried once (at most one such re-mint a minute per site).
  - `forceRefresh: true`, for when Gorilla Dash refused the last browser token, is honoured at
    most once a minute per site; otherwise the cached token is returned.
- **Optional browser-token route** for sites without Inertia: `GET /gorilla-dash/browser-token`
  answers `{ access_token, expires_in }` with `Cache-Control: no-store, private`;
  `?refresh=1` forces a refresh. Off unless `GD_WEBSITE_BROWSER_TOKEN_ROUTE=true`; rate limited
  per IP address (`GD_WEBSITE_BROWSER_TOKEN_RATE_LIMIT`, default 30 a minute). Path set with
  `GD_WEBSITE_BROWSER_TOKEN_PATH`.

### Security

- **The GraphQL proxy runs queries only.** A document that defines a mutation or a
  subscription is refused with `403` before anything is sent, and a body without a well-formed
  query with `400`, so a site that turns the proxy on cannot be used to submit enquiries,
  reviews or orders with its website token.
- **The GraphQL proxy is rate limited per IP address**, 60 requests a minute by default
  (`GD_WEBSITE_GRAPHQL_RATE_LIMIT`, `0` for no limit).

### Upgrading from 0.5

1. Require `gorilladash/laravel-website-sdk:^0.6`.
2. If the site calls its own `/graphql` (or `GD_WEBSITE_GRAPHQL_PATH`) proxy, set
   `GD_WEBSITE_GRAPHQL_ROUTE=true`. If it does not, and it published
   `config/website-sdk.php`, change `env('GD_WEBSITE_GRAPHQL_ROUTE', true)` to `false` there.
3. Anything that puts the website token in front of the browser, such as an Inertia
   `graphqlToken` prop built from `TokenManager::getToken()`, switches to
   `getBrowserToken()`. See *Browser tokens* in the README.

## 0.5.0 — 2026-10-14

- `mutate()` is sent to `write_base_uri` (`GD_WEBSITE_WRITE_BASE_URI`) when one is set.

## 0.4.0 — 2026-08-25

- `php artisan gd:check` shows which website the credentials resolve to and whether the cache
  answers repeat reads.
- Repeat reads within a process are answered from memory; the default freshness window is 300
  seconds.

## 0.3.0 — 2026-08-24

- `mutate()` runs mutations straight through the transport, uncached.
- Guzzle and PSR-7 updated past open advisories; CI tests PHP 8.3 to 8.5.

## 0.2.0 — 2026-06-19

- `graphql()` accepts `mghoneimy/php-graphql-client` `Query` objects.
- The provider uses `spatie/laravel-package-tools`, and the package registers the `POST`
  GraphQL endpoint.

## 0.1.1 — 2026-06-19

- `max_stale_age` ceiling, cache `flush()` and the cache-clear webhook.
