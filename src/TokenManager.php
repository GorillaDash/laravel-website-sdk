<?php

declare(strict_types=1);

namespace GorillaDash\WebsiteSdk;

use GorillaDash\WebsiteSdk\Exceptions\GdRequestException;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\Client\Factory as HttpFactory;
use Throwable;

/**
 * Obtains and caches the short-lived bearer token used to authenticate against
 * the GorillaDash website GraphQL API.
 *
 * The token is exchanged via the OAuth2 client_credentials grant at
 * {base_uri}/oauth/token and cached until shortly before it expires.
 */
class TokenManager
{
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

    private function cacheKey(): string
    {
        return $this->connection->cachePrefix.'token:'.$this->connection->fingerprint();
    }
}
