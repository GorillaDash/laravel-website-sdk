<?php

declare(strict_types=1);

namespace GorillaDash\WebsiteSdk;

use Closure;
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
 * Both are cached until shortly before they expire.
 */
class TokenManager
{
    /** A browser token is handed out only while it has at least this many seconds left. */
    private const BROWSER_TOKEN_MIN_REMAINING = 600;

    /** At most one forced browser-token refresh, and one recovery re-mint, per site in this many seconds. */
    private const BROWSER_TOKEN_GATE_SECONDS = 60;

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
        $key = $this->cacheKey();

        if (! $forceRefresh) {
            $cached = $this->cache->get($key);
            if (is_string($cached) && $cached !== '') {
                return $cached;
            }
        }

        return $this->exchange($key);
    }

    /**
     * Forget the cached token (used after the API rejects it with a 401).
     */
    public function forget(): void
    {
        $this->cache->forget($this->cacheKey());
    }

    /**
     * Return a read-only browser token, exchanging the full token for one when
     * none with at least ten minutes left is cached.
     *
     * @param  bool  $forceRefresh  The browser was refused the last token. Honoured
     *                              at most once a minute per site; otherwise the
     *                              cached token is returned.
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

        if ($forceRefresh && ! $this->cache->add($key.':forced', true, self::BROWSER_TOKEN_GATE_SECONDS)) {
            $forceRefresh = false;
        }

        if (! $forceRefresh && ($cached = $this->cachedBrowserToken($key)) !== null) {
            return $cached;
        }

        return $this->withExchangeLock($key, function () use ($key, $forceRefresh): array {
            // Another request may have finished an exchange while this one waited.
            if (! $forceRefresh && ($cached = $this->cachedBrowserToken($key)) !== null) {
                return $cached;
            }

            return $this->exchangeBrowserToken($key);
        });
    }

    private function exchange(string $key): string
    {
        if (blank($this->connection->clientId) || blank($this->connection->clientSecret)) {
            throw new GdRequestException('GorillaDash client_id / client_secret are not configured.');
        }

        try {
            $response = $this->http
                ->timeout($this->connection->httpTimeout)
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
     * Exchange the full token for a browser token and cache it.
     *
     * A 401 or 404 means the full token was refused (revoked, or the API Access
     * Token was regenerated), so a new one is minted and the exchange tried once
     * more. The re-mint is gated to once a minute per site, so a host that never
     * accepts the exchange cannot turn every request into a new full token.
     *
     * @return array{access_token: string, expires_in: int}
     */
    private function exchangeBrowserToken(string $key): array
    {
        $response = $this->requestBrowserToken($this->getToken());

        if (in_array($response->status(), [401, 404], true)
            && $this->cache->add($key.':remint', true, self::BROWSER_TOKEN_GATE_SECONDS)) {
            $this->forget();
            $response = $this->requestBrowserToken($this->getToken(forceRefresh: true));
        }

        if ($response->failed()) {
            $message = "Browser token exchange failed with HTTP {$response->status()}.";

            if ($response->status() === 404 && parse_url($this->connection->baseUri, PHP_URL_HOST) !== 'graphql.gorilladash.com') {
                $message .= " base_uri is {$this->connection->baseUri}; in production only graphql.gorilladash.com serves the exchange.";
            }

            throw new GdRequestException($message);
        }

        $token = $response->json('data.access_token') ?? $response->json('access_token');
        $expiresIn = $response->json('data.expires_in') ?? $response->json('expires_in');

        if (! is_string($token) || $token === '' || ! is_numeric($expiresIn) || (int) $expiresIn <= 0) {
            throw new GdRequestException('Browser token endpoint did not return an access_token and expires_in.');
        }

        $expiresIn = (int) $expiresIn;

        // A token that arrives with less than the minimum left is still used, for
        // at most a minute, so a short lifetime cannot make every request exchange.
        $ttl = max($expiresIn - self::BROWSER_TOKEN_MIN_REMAINING, min(60, $expiresIn));

        $this->cache->put($key, [
            'access_token' => $token,
            'expires_at' => now()->timestamp + $expiresIn,
        ], $ttl);

        return ['access_token' => $token, 'expires_in' => $expiresIn];
    }

    private function requestBrowserToken(string $fullToken): Response
    {
        try {
            return $this->http
                ->timeout($this->connection->httpTimeout)
                ->withToken($fullToken)
                ->acceptJson()
                ->post($this->connection->baseUri.'/oauth/website-browser-token');
        } catch (Throwable $exception) {
            throw new GdRequestException('Failed to reach the GorillaDash browser token endpoint.', previous: $exception);
        }
    }

    /**
     * @return array{access_token: string, expires_in: int}|null
     */
    private function cachedBrowserToken(string $key): ?array
    {
        $cached = $this->cache->get($key);

        if (! is_array($cached) || ! is_string($cached['access_token'] ?? null) || $cached['access_token'] === '') {
            return null;
        }

        $expiresIn = (int) ($cached['expires_at'] ?? 0) - now()->timestamp;

        return $expiresIn > 0 ? ['access_token' => $cached['access_token'], 'expires_in' => $expiresIn] : null;
    }

    /**
     * Run a browser-token exchange under a cache lock, so concurrent requests
     * share one exchange. Stores without locks, and a wait that times out, run
     * it unguarded rather than fail the request.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    private function withExchangeLock(string $key, Closure $callback): mixed
    {
        $store = $this->cache->getStore();

        if (! $store instanceof LockProvider) {
            return $callback();
        }

        // Long enough for four requests: a mint, an exchange, a re-mint and a second exchange.
        $timeout = max(1, $this->connection->httpTimeout);
        $lock = $store->lock($key.':exchange', $timeout * 4);

        try {
            return $lock->block($timeout * 2, $callback);
        } catch (LockTimeoutException) {
            return $callback();
        }
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
