<?php

declare(strict_types=1);

namespace GorillaDash\WebsiteSdk\Cache;

use Closure;
use GorillaDash\WebsiteSdk\Connection;
use GorillaDash\WebsiteSdk\Support\AfterResponseRefresher;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository as CacheRepository;

/**
 * Stale-while-revalidate cache.
 *
 * Three states for every key:
 *   1. Miss          — block, fetch, store, return.
 *   2. Fresh hit     — age < ttl: return cached, no fetch.
 *   3. Stale hit     — age >= ttl: return cached immediately AND schedule a
 *                      background refresh (after the response is sent).
 *
 * Cached payloads are retained far longer than the freshness window so stale
 * data is always available to serve while a refresh runs. Staleness is derived
 * from a stored `cached_at` timestamp, never from cache eviction.
 */
class SwrCache
{
    public function __construct(
        private readonly Connection $connection,
        private readonly CacheRepository $cache,
        private readonly AfterResponseRefresher $refresher,
    ) {}

    /**
     * @param  array<string, mixed>  $payloadKey  Stable data used to build the cache key.
     * @param  Closure():array<string, mixed>  $fetcher  Performs the live API call.
     * @return array{data: array<string, mixed>, cached_at: int, age: int, status: string}
     */
    public function remember(array $payloadKey, Closure $fetcher, ?int $ttl = null): array
    {
        $ttl ??= $this->connection->cacheTtl;
        $key = $this->key($payloadKey);
        $envelope = $this->cache->get($key);

        // 1. Miss — block and fetch.
        if (! $this->isValidEnvelope($envelope)) {
            return $this->fetchAndStore($key, $fetcher, status: 'miss');
        }

        $age = $this->now() - (int) $envelope['cached_at'];

        // 2. Fresh hit — serve without touching the API.
        if ($age < $ttl) {
            return $envelope + ['age' => $age, 'status' => 'fresh'];
        }

        // 3. Stale hit — serve cached now, refresh in the background.
        $this->scheduleRefresh($key, $fetcher);

        return $envelope + ['age' => $age, 'status' => 'stale'];
    }

    /**
     * @param  Closure():array<string, mixed>  $fetcher
     * @return array{data: array<string, mixed>, cached_at: int, age: int, status: string}
     */
    private function fetchAndStore(string $key, Closure $fetcher, string $status): array
    {
        $data = $fetcher();
        $envelope = ['data' => $data, 'cached_at' => $this->now()];
        $this->cache->put($key, $envelope, $this->retentionSeconds());

        return $envelope + ['age' => 0, 'status' => $status];
    }

    /**
     * Defer a refresh to after the response, guarded by a short lock so only one
     * process refreshes a given key at a time (stampede protection).
     *
     * @param  Closure():array<string, mixed>  $fetcher
     */
    private function scheduleRefresh(string $key, Closure $fetcher): void
    {
        $store = $this->cache->getStore();

        // Without lock support we still refresh — just without stampede guarding.
        if (! $store instanceof LockProvider) {
            $this->refresher->defer(fn () => $this->fetchAndStore($key, $fetcher, status: 'refresh'));

            return;
        }

        $lock = $store->lock($key.':refresh', 30);

        // Another request is already refreshing this key — skip.
        if (! $lock->get()) {
            return;
        }

        $this->refresher->defer(function () use ($key, $fetcher, $lock) {
            try {
                $this->fetchAndStore($key, $fetcher, status: 'refresh');
            } finally {
                $lock->release();
            }
        });
    }

    /**
     * @param  mixed  $envelope
     */
    private function isValidEnvelope($envelope): bool
    {
        return is_array($envelope)
            && array_key_exists('data', $envelope)
            && array_key_exists('cached_at', $envelope);
    }

    private function retentionSeconds(): int
    {
        $multiplier = $this->connection->staleRetentionMultiplier;

        // 0 = retain forever.
        return $multiplier <= 0 ? 315360000 : max($this->connection->cacheTtl, 1) * $multiplier;
    }

    /**
     * @param  array<string, mixed>  $payloadKey
     */
    private function key(array $payloadKey): string
    {
        return $this->connection->cachePrefix
            .'data:'.$this->connection->fingerprint().':'
            .sha1(json_encode($payloadKey, JSON_THROW_ON_ERROR));
    }

    private function now(): int
    {
        return time();
    }
}
