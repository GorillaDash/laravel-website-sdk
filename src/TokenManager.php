<?php

declare(strict_types=1);

namespace GorillaDash\WebsiteSdk;

use GorillaDash\WebsiteSdk\Exceptions\GdRequestException;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use Throwable;

/**
 * Obtains and caches the two bearer tokens the GorillaDash website GraphQL API
 * accepts.
 *
 * The full website token is exchanged via the OAuth2 client_credentials grant
 * at {base_uri}/oauth/token. It can run everything, mutations included, so it
 * never leaves the server.
 *
 * The browser token is exchanged for the full token at
 * {base_uri}/oauth/website-browser-token. It can only run public queries, so it
 * is the one to put in a page, an Inertia prop or a browser GraphQL client.
 *
 * Both are cached until shortly before they expire. The browser token is read
 * on every page render, so Gorilla Dash being slow or down must not hold up a
 * page: its exchange has short timeouts, falls back to the previous token while
 * that is still valid, and backs off for a while after a failure.
 */
class TokenManager
{
    /** The only host that serves the browser-token exchange. */
    private const BROWSER_TOKEN_HOST = 'graphql.gorilladash.com';

    /** The only scope a token handed to the browser may carry. */
    private const BROWSER_TOKEN_SCOPE = 'website:read';

    /**
     * A browser token with less than this many seconds left is due for refresh.
     * It is still handed out, until it expires, while no newer one can be had.
     */
    private const BROWSER_TOKEN_REFRESH_BEFORE = 600;

    /** At most one forced browser-token refresh per site in this many seconds. */
    private const BROWSER_TOKEN_FORCED_REFRESH_SECONDS = 60;

    /** After a failed browser-token exchange, nothing is exchanged for this many seconds. */
    private const BROWSER_TOKEN_BACKOFF_SECONDS = 30;

    /** The browser-token exchange's timeouts, in seconds; http_timeout does not apply. */
    private const BROWSER_TOKEN_TIMEOUT = 3;

    private const BROWSER_TOKEN_CONNECT_TIMEOUT = 2;

    /** Timeouts for a full-token mint made on the browser-token path, in seconds. */
    private const BROWSER_PATH_MINT_TIMEOUT = 5;

    private const BROWSER_PATH_MINT_CONNECT_TIMEOUT = 2;

    /**
     * Longer than the slowest refresh, a mint, an exchange, a re-mint and a second
     * exchange each at its timeout, so the lock is never released mid-exchange.
     */
    private const BROWSER_TOKEN_LOCK_SECONDS = 20;

    /** How long a request with no valid browser token waits for another request's exchange. */
    private const BROWSER_TOKEN_LOCK_WAIT_SECONDS = 5;

    public function __construct(
        private readonly Connection $connection,
        private readonly CacheRepository $cache,
        private readonly HttpFactory $http,
    ) {}

    /**
     * Return a valid bearer token, exchanging a fresh one if none is cached.
     *
     * @param  bool  $forceRefresh  Bypass the cache (e.g. after a 401).
     */
    public function getToken(bool $forceRefresh = false): string
    {
        return $this->fullToken($forceRefresh, $this->connection->httpTimeout);
    }

    /**
     * Forget the cached token (used after the API rejects it with a 401).
     */
    public function forget(): void
    {
        $this->cache->forget($this->cacheKey());
    }

    /**
     * Return a read-only browser token, exchanging the full token for a new one
     * when the cached one has less than ten minutes left.
     *
     * While a refresh cannot be had (another request is exchanging, the exchange
     * failed, or it failed less than 30 seconds ago) the previous token is
     * returned as long as it is still valid. Throws GdRequestException only when
     * there is none.
     *
     * @param  bool  $forceRefresh  The browser was refused the last token. Honoured
     *                              at most once a minute per site, and not during
     *                              the back-off after a failed exchange.
     */
    public function getBrowserToken(bool $forceRefresh = false): string
    {
        return $this->getBrowserTokenWithExpiry($forceRefresh)['access_token'];
    }

    /**
     * As {@see getBrowserToken()}, with the seconds the token has left.
     *
     * @return array{access_token: string, expires_in: int}
     */
    public function getBrowserTokenWithExpiry(bool $forceRefresh = false): array
    {
        $key = $this->browserTokenKey();
        $token = $this->storedBrowserToken($key);

        if (! $forceRefresh && $this->isFresh($token)) {
            return $this->present($token);
        }

        if ($this->cache->has($key.':backoff')) {
            return $this->previousOrFail($token);
        }

        $forced = $forceRefresh
            && $this->cache->add($key.':forced', true, self::BROWSER_TOKEN_FORCED_REFRESH_SECONDS);

        if (! $forced && $this->isFresh($token)) {
            return $this->present($token);
        }

        return $this->refreshUnderLock($key, $forced);
    }

    private function fullToken(bool $forceRefresh, int $timeout, ?int $connectTimeout = null): string
    {
        $key = $this->cacheKey();

        if (! $forceRefresh) {
            $cached = $this->cache->get($key);
            if (is_string($cached) && $cached !== '') {
                return $cached;
            }
        }

        return $this->exchange($key, $timeout, $connectTimeout);
    }

    private function exchange(string $key, int $timeout, ?int $connectTimeout): string
    {
        if (blank($this->connection->clientId) || blank($this->connection->clientSecret)) {
            throw new GdRequestException('GorillaDash client_id / client_secret are not configured.');
        }

        try {
            $request = $this->http->timeout($timeout);

            if ($connectTimeout !== null) {
                $request->connectTimeout($connectTimeout);
            }

            $response = $request
                ->asForm()
                ->acceptJson()
                ->post($this->connection->baseUri.'/oauth/token', [
                    'grant_type' => 'client_credentials',
                    'client_id' => $this->connection->clientId,
                    'client_secret' => $this->connection->clientSecret,
                    'scope' => '',
                ]);
        } catch (Throwable $exception) {
            throw new GdRequestException('Failed to reach the GorillaDash token endpoint.', previous: $exception);
        }

        if ($response->failed()) {
            throw new GdRequestException("Token exchange failed with HTTP {$response->status()}.");
        }

        $token = (string) $response->json('access_token');
        $expiresIn = (int) $response->json('expires_in', 0);

        if ($token === '') {
            throw new GdRequestException('Token endpoint did not return an access_token.');
        }

        $ttl = max(1, $expiresIn - $this->connection->tokenSkew);
        $this->cache->put($key, $token, $ttl);

        return $token;
    }

    /**
     * Refresh the browser token, and store it until it expires.
     *
     * A failure starts the back-off and is reported once; the previous token is
     * returned while it is still valid, and the failure thrown otherwise.
     *
     * @return array{access_token: string, expires_in: int}
     */
    private function refresh(string $key, bool $forced): array
    {
        $previous = $this->storedBrowserToken($key);

        // Another request may have finished, or failed, an exchange while this one waited.
        if (! $forced && $this->isFresh($previous)) {
            return $this->present($previous);
        }

        if ($this->cache->has($key.':backoff')) {
            return $this->previousOrFail($previous);
        }

        try {
            $token = $this->exchangeBrowserToken();
        } catch (Throwable $exception) {
            $this->cache->put($key.':backoff', true, self::BROWSER_TOKEN_BACKOFF_SECONDS);

            $failure = $exception instanceof GdRequestException
                ? $exception
                : new GdRequestException('Browser token exchange failed.', previous: $exception);

            report($failure);

            if ($this->isValid($previous)) {
                return $this->present($previous);
            }

            throw $failure;
        }

        $this->cache->put($key, $token, $token['expires_at'] - now()->timestamp);

        return $this->present($token);
    }

    /**
     * Exchange the full token for a browser token.
     *
     * A refused full token (revoked, or the API Access Token was regenerated) is
     * forgotten, a new one minted and the exchange tried once more. A 401 always
     * means refused. A 404 does only on graphql.gorilladash.com, the one host that
     * serves the exchange; anywhere else it means the route is missing, and a new
     * full token would not help.
     *
     * @return array{access_token: string, expires_at: int, refresh_at: int}
     */
    private function exchangeBrowserToken(): array
    {
        $response = $this->requestBrowserToken($this->browserPathFullToken(forceRefresh: false));

        if ($this->fullTokenRefused($response)) {
            $this->forget();
            $response = $this->requestBrowserToken($this->browserPathFullToken(forceRefresh: true));
        }

        if ($response->failed()) {
            $message = "Browser token exchange failed with HTTP {$response->status()}.";

            if ($response->status() === 404 && ! $this->exchangeHost()) {
                $message .= " base_uri is {$this->connection->baseUri}; in production only ".self::BROWSER_TOKEN_HOST.' serves the exchange.';
            }

            throw new GdRequestException($message);
        }

        // Only the responder envelope carries a browser token. A bare access_token
        // is the shape /oauth/token answers with, i.e. a full token.
        $token = $response->json('data.access_token');
        $expiresIn = $response->json('data.expires_in');

        if (! is_string($token) || $token === '' || ! is_numeric($expiresIn) || (int) $expiresIn <= 0) {
            throw new GdRequestException('Browser token endpoint did not return data.access_token and data.expires_in.');
        }

        // This token is written into pages, so anything but the read-only scope is
        // refused rather than published.
        if ($response->json('data.scope') !== self::BROWSER_TOKEN_SCOPE) {
            throw new GdRequestException('Browser token endpoint returned a token without the website:read scope.');
        }

        $expiresIn = (int) $expiresIn;
        $now = now()->timestamp;

        return [
            'access_token' => $token,
            'expires_at' => $now + $expiresIn,
            // A token that arrives with less than ten minutes left is refreshed
            // after a minute at most, so a short lifetime cannot make every
            // request exchange.
            'refresh_at' => $now + max($expiresIn - self::BROWSER_TOKEN_REFRESH_BEFORE, min(60, $expiresIn)),
        ];
    }

    private function browserPathFullToken(bool $forceRefresh): string
    {
        return $this->fullToken($forceRefresh, self::BROWSER_PATH_MINT_TIMEOUT, self::BROWSER_PATH_MINT_CONNECT_TIMEOUT);
    }

    private function fullTokenRefused(Response $response): bool
    {
        return $response->status() === 401 || ($response->status() === 404 && $this->exchangeHost());
    }

    /** Whether base_uri is the host that serves the browser-token exchange. */
    private function exchangeHost(): bool
    {
        return parse_url($this->connection->baseUri, PHP_URL_HOST) === self::BROWSER_TOKEN_HOST;
    }

    private function requestBrowserToken(string $fullToken): Response
    {
        try {
            return $this->http
                ->timeout(self::BROWSER_TOKEN_TIMEOUT)
                ->connectTimeout(self::BROWSER_TOKEN_CONNECT_TIMEOUT)
                ->withToken($fullToken)
                ->acceptJson()
                ->post($this->connection->baseUri.'/oauth/website-browser-token');
        } catch (Throwable $exception) {
            throw new GdRequestException('Failed to reach the GorillaDash browser token endpoint.', previous: $exception);
        }
    }

    /**
     * Refresh under a cache lock, so concurrent requests share one exchange.
     *
     * A request that finds the lock taken does not wait while the previous token
     * is still valid: it returns that. With no valid token it waits a few
     * seconds for the other exchange, then refreshes on its own. Stores without
     * locks refresh unguarded.
     *
     * @return array{access_token: string, expires_in: int}
     */
    private function refreshUnderLock(string $key, bool $forced): array
    {
        $store = $this->cache->getStore();

        if (! $store instanceof LockProvider) {
            return $this->refresh($key, $forced);
        }

        $lock = $store->lock($key.':exchange', self::BROWSER_TOKEN_LOCK_SECONDS);
        $refreshed = $lock->get(fn (): array => $this->refresh($key, $forced));

        if (is_array($refreshed)) {
            return $refreshed;
        }

        $previous = $this->storedBrowserToken($key);

        if ($this->isValid($previous)) {
            return $this->present($previous);
        }

        try {
            return $lock->block(self::BROWSER_TOKEN_LOCK_WAIT_SECONDS, fn (): array => $this->refresh($key, $forced));
        } catch (LockTimeoutException) {
            return $this->refresh($key, $forced);
        }
    }

    /**
     * @return array{access_token: string, expires_at: int, refresh_at: int}|null
     */
    private function storedBrowserToken(string $key): ?array
    {
        $token = $this->cache->get($key);

        $wellFormed = is_array($token)
            && is_string($token['access_token'] ?? null) && $token['access_token'] !== ''
            && is_int($token['expires_at'] ?? null)
            && is_int($token['refresh_at'] ?? null);

        return $wellFormed ? $token : null;
    }

    /**
     * @param  array{access_token: string, expires_at: int, refresh_at: int}|null  $token
     */
    private function isFresh(?array $token): bool
    {
        return $token !== null && now()->timestamp < $token['refresh_at'];
    }

    /**
     * @param  array{access_token: string, expires_at: int, refresh_at: int}|null  $token
     */
    private function isValid(?array $token): bool
    {
        return $token !== null && now()->timestamp < $token['expires_at'];
    }

    /**
     * @param  array{access_token: string, expires_at: int, refresh_at: int}|null  $token
     * @return array{access_token: string, expires_in: int}
     */
    private function previousOrFail(?array $token): array
    {
        if ($this->isValid($token)) {
            return $this->present($token);
        }

        throw new GdRequestException(
            'No browser token: the last exchange failed less than '.self::BROWSER_TOKEN_BACKOFF_SECONDS.' seconds ago.',
        );
    }

    /**
     * @param  array{access_token: string, expires_at: int, refresh_at: int}  $token
     * @return array{access_token: string, expires_in: int}
     */
    private function present(array $token): array
    {
        return [
            'access_token' => $token['access_token'],
            'expires_in' => max(0, $token['expires_at'] - now()->timestamp),
        ];
    }

    private function cacheKey(): string
    {
        return $this->connection->cachePrefix.'token:'.$this->connection->fingerprint();
    }

    private function browserTokenKey(): string
    {
        return $this->connection->cachePrefix.'browser-token:'.$this->connection->fingerprint();
    }
}
