<?php

declare(strict_types=1);

namespace GorillaDash\WebsiteSdk\Cache;

use Closure;
use GorillaDash\WebsiteSdk\Connection;
use GorillaDash\WebsiteSdk\Support\AfterResponseRefresher;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Throwable;

/**
 * Stale-while-revalidate cache.
 *
 * States for every key:
 *   1. Miss              — block, fetch, store, return.
 *   2. Fresh hit         — age < ttl: return cached, no fetch.
 *   3. Stale hit         — ttl <= age < max_stale_age: return cached immediately
 *                          AND schedule a background refresh.
 *   4. Beyond max age    — age >= max_stale_age: block and fetch fresh (falling
 *                          back to the stale copy only if that fetch fails).
 *
 * Cached payloads are retained well past the freshness window (and at least as
 * long as max_stale_age) so stale data is available to serve while a refresh
 * runs. Staleness is derived from a stored `cached_at` timestamp, never from
 * cache eviction.
 *
 * All keys are namespaced by a per-site version token, so {@see flush()} can
 * invalidate every cached query at once on any cache store (no tags required).
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

        // 4. Beyond the hard ceiling — force a blocking refresh, but degrade to
        // the stale copy if the live fetch fails (an outage shouldn't 500).
        $maxStale = $this->connection->maxStaleAge;
        if ($maxStale > 0 && $age >= $maxStale) {
            try {
                return $this->fetchAndStore($key, $fetcher, status: 'miss');
            } catch (Throwable) {
                return $envelope + ['age' => $age, 'status' => 'stale'];
            }
        }

        // 3. Stale hit — serve cached now, refresh in the background.
        $this->scheduleRefresh($key, $fetcher);

        return $envelope + ['age' => $age, 'status' => 'stale'];
    }

    /**
     * Invalidate every cached query for this site by bumping the version token.
     * Old keys become unreachable immediately and expire on their own. Does not
     * touch the cached bearer token.
     */
    public function flush(): void
    {
        $this->cache->forever($this->versionKey(), $this->version() + 1);
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

    private function isValidEnvelope(mixed $envelope): bool
    {
        return is_array($envelope)
            && array_key_exists('data', $envelope)
            && array_key_exists('cached_at', $envelope);
    }

    private function retentionSeconds(): int
    {
        $multiplier = $this->connection->staleRetentionMultiplier;
        $base = $multiplier <= 0 ? 315360000 : max($this->connection->cacheTtl, 1) * $multiplier;

        // The entry must outlive max_stale_age so it can be force-refreshed (and
        // used as a fallback). Keep generous headroom beyond the ceiling.
        $maxStale = $this->connection->maxStaleAge;

        return $maxStale > 0 ? max($base, $maxStale * 2) : $base;
    }

    /**
     * @param  array<string, mixed>  $payloadKey
     *
     * @throws \JsonException
     */
    private function key(array $payloadKey): string
    {
        return $this->connection->cachePrefix
            .'data:'.$this->connection->fingerprint()
            .':v'.$this->version().':'
            .sha1(json_encode($payloadKey, JSON_THROW_ON_ERROR));
    }

    private function version(): int
    {
        return (int) $this->cache->get($this->versionKey(), 1);
    }

    private function versionKey(): string
    {
        return $this->connection->cachePrefix.'ver:'.$this->connection->fingerprint();
    }

    private function now(): int
    {
        return now()->timestamp;
    }
}
