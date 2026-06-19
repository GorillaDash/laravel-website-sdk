<?php

declare(strict_types=1);

namespace GorillaDash\WebsiteSdk;

/**
 * Immutable connection settings for a single GorillaDash website.
 *
 * Built from package config, but can be cloned with per-call overrides via
 * {@see Connection::with()} so a single host can serve multiple websites.
 */
final readonly class Connection
{
    public function __construct(
        public string $baseUri,
        public ?string $clientId,
        public ?string $clientSecret,
        public int $cacheTtl = 60,
        public ?string $cacheStore = null,
        public string $cachePrefix = 'gd_website:',
        public int $staleRetentionMultiplier = 100,
        public int $tokenSkew = 60,
        public int $httpTimeout = 10,
    ) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromConfig(array $config): self
    {
        return new self(
            baseUri: rtrim((string) ($config['base_uri'] ?? ''), '/'),
            clientId: $config['client_id'] ?? null,
            clientSecret: $config['client_secret'] ?? null,
            cacheTtl: (int) ($config['cache_ttl'] ?? 60),
            cacheStore: $config['cache_store'] ?? null,
            cachePrefix: (string) ($config['cache_prefix'] ?? 'gd_website:'),
            staleRetentionMultiplier: (int) ($config['stale_retention_multiplier'] ?? 100),
            tokenSkew: (int) ($config['token_skew'] ?? 60),
            httpTimeout: (int) ($config['http_timeout'] ?? 10),
        );
    }

    /**
     * Clone this connection with a subset of values overridden.
     *
     * @param  array<string, mixed>  $overrides
     */
    public function with(array $overrides): self
    {
        return new self(
            baseUri: isset($overrides['base_uri']) ? rtrim((string) $overrides['base_uri'], '/') : $this->baseUri,
            clientId: $overrides['client_id'] ?? $this->clientId,
            clientSecret: $overrides['client_secret'] ?? $this->clientSecret,
            cacheTtl: isset($overrides['cache_ttl']) ? (int) $overrides['cache_ttl'] : $this->cacheTtl,
            cacheStore: array_key_exists('cache_store', $overrides) ? $overrides['cache_store'] : $this->cacheStore,
            cachePrefix: isset($overrides['cache_prefix']) ? (string) $overrides['cache_prefix'] : $this->cachePrefix,
            staleRetentionMultiplier: isset($overrides['stale_retention_multiplier'])
                ? (int) $overrides['stale_retention_multiplier']
                : $this->staleRetentionMultiplier,
            tokenSkew: isset($overrides['token_skew']) ? (int) $overrides['token_skew'] : $this->tokenSkew,
            httpTimeout: isset($overrides['http_timeout']) ? (int) $overrides['http_timeout'] : $this->httpTimeout,
        );
    }

    /**
     * Stable fingerprint of the credentials, used to namespace cache keys so two
     * different websites never collide on the same store.
     */
    public function fingerprint(): string
    {
        return substr(sha1($this->baseUri.'|'.(string) $this->clientId), 0, 12);
    }
}
