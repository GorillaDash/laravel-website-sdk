<?php

declare(strict_types=1);

namespace GorillaDash\WebsiteSdk;

use GorillaDash\WebsiteSdk\Cache\SwrCache;
use GorillaDash\WebsiteSdk\Support\AfterResponseRefresher;
use GraphQL\Query;
use GraphQL\QueryBuilder\QueryBuilderInterface;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Client\Factory as HttpFactory;

/**
 * Public entry point. Runs GraphQL queries against GorillaDash through the
 * stale-while-revalidate cache so SSR pages render instantly.
 *
 * Convenience helpers (info/page/sections/menu/faqs) take an explicit field
 * selection so callers control exactly what GorillaDash returns — the package
 * stays decoupled from the remote schema.
 */
class WebsiteClient
{
    public function __construct(
        private readonly Connection $connection,
        private readonly CacheFactory $cacheFactory,
        private readonly HttpFactory $http,
        private readonly AfterResponseRefresher $refresher,
    ) {}

    /**
     * Derive a client bound to a different connection (e.g. another website's
     * credentials, or a custom TTL) for the duration of a call chain.
     *
     * @param  array<string, mixed>  $overrides
     */
    public function connection(array $overrides): self
    {
        return new self($this->connection->with($overrides), $this->cacheFactory, $this->http, $this->refresher);
    }

    /**
     * Run an `mghoneimy/php-graphql-client` query — either a {@see Query} or a
     * {@see QueryBuilderInterface} — and return its `data` payload (SWR cached).
     *
     * For a raw query string, use {@see graphqlWithMeta()} instead.
     *
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     */
    public function graphql(Query|QueryBuilderInterface $query, array $variables = [], ?int $ttl = null): array
    {
        return $this->graphqlWithMeta($query, $variables, $ttl)['data'];
    }

    /**
     * As {@see graphql()} but accepts a raw query string too, and returns the
     * full cache envelope — `status` (miss|fresh|stale) and `age` in seconds —
     * useful for debugging and for surfacing data freshness in the UI.
     *
     * @param  array<string, mixed>  $variables
     * @return array{data: array<string, mixed>, cached_at: int, age: int, status: string}
     */
    public function graphqlWithMeta(string|Query|QueryBuilderInterface $query, array $variables = [], ?int $ttl = null): array
    {
        $query = $this->normalizeQuery($query);
        $transport = $this->makeTransport();

        return $this->makeCache()->remember(
            ['query' => $query, 'variables' => $variables],
            fn () => $transport->query($query, $variables),
            $ttl,
        );
    }

    /**
     * Exchange credentials for a token and run a trivial query. Returns true on
     * success; lets exceptions bubble so callers can show the real error.
     */
    public function ping(): bool
    {
        $this->makeTransport()->query('{ websiteInfo { name } }');

        return true;
    }

    /**
     * Invalidate all cached content for this site (e.g. from a GorillaDash
     * cache-clear webhook). Cheap and store-agnostic.
     */
    public function flush(): void
    {
        $this->makeCache()->flush();
    }

    /**
     * Reduce any accepted query form to the wire string. A query string is the
     * stable cache key and the only thing the transport sends, so we resolve
     * builders/objects up-front — before the value is hashed for the cache.
     */
    private function normalizeQuery(string|Query|QueryBuilderInterface $query): string
    {
        if ($query instanceof QueryBuilderInterface) {
            $query = $query->getQuery();
        }

        return (string) $query;
    }

    private function makeCache(): SwrCache
    {
        return new SwrCache($this->connection, $this->store(), $this->refresher);
    }

    private function makeTransport(): GraphQlTransport
    {
        $tokenManager = new TokenManager($this->connection, $this->store(), $this->http);

        return new GraphQlTransport($this->connection, $tokenManager, $this->http);
    }

    private function store(): Repository
    {
        return $this->cacheFactory->store($this->connection->cacheStore);
    }
}
