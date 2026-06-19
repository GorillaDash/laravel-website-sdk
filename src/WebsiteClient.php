<?php

declare(strict_types=1);

namespace GorillaDash\WebsiteSdk;

use GorillaDash\WebsiteSdk\Cache\SwrCache;
use GorillaDash\WebsiteSdk\Support\AfterResponseRefresher;
use GorillaDash\WebsiteSdk\Support\QueryBuilder;
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
     * Run an arbitrary GraphQL query and return its `data` payload (SWR cached).
     *
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     */
    public function graphql(string $query, array $variables = [], ?int $ttl = null): array
    {
        return $this->graphqlWithMeta($query, $variables, $ttl)['data'];
    }

    /**
     * As {@see graphql()} but returns the full cache envelope, including
     * `status` (miss|fresh|stale) and `age` in seconds — useful for debugging
     * and for surfacing data freshness in the UI.
     *
     * @param  array<string, mixed>  $variables
     * @return array{data: array<string, mixed>, cached_at: int, age: int, status: string}
     */
    public function graphqlWithMeta(string $query, array $variables = [], ?int $ttl = null): array
    {
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
     * websiteInfo — the current authenticated website. (No `id` field exists on
     * this type.)
     *
     * @return array<string, mixed>
     */
    public function info(string $fields = 'name url', ?int $ttl = null): array
    {
        return $this->graphql("{ websiteInfo { {$fields} } }", [], $ttl);
    }

    /**
     * websitePage — a single page by slug.
     *
     * @param  array<string, mixed>  $args  Extra GraphQL args (locale, tribe_slug, template).
     * @return array<string, mixed>
     */
    public function page(string $slug, string $fields, array $args = [], ?int $ttl = null): array
    {
        return $this->run('websitePage', $fields, ['slug' => $slug] + $args, $ttl);
    }

    /**
     * websitePages — list of pages.
     *
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public function pages(string $fields, array $args = [], ?int $ttl = null): array
    {
        return $this->run('websitePages', $fields, $args, $ttl);
    }

    /**
     * websiteSections — sections (and their content/media).
     *
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public function sections(string $fields, array $args = [], ?int $ttl = null): array
    {
        return $this->run('websiteSections', $fields, $args, $ttl);
    }

    /**
     * websiteMenu — a single menu by name.
     *
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public function menu(string $name, string $fields, array $args = [], ?int $ttl = null): array
    {
        return $this->run('websiteMenu', $fields, ['name' => $name] + $args, $ttl);
    }

    /**
     * menus — all website menus (each carries a `menu_json` structure).
     *
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public function menus(string $fields, array $args = [], ?int $ttl = null): array
    {
        return $this->run('menus', $fields, $args, $ttl);
    }

    /**
     * websiteFaq — FAQ entries.
     *
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public function faqs(string $fields, array $args = [], ?int $ttl = null): array
    {
        return $this->run('websiteFaq', $fields, $args, $ttl);
    }

    /**
     * websiteFaqCategory — FAQ categories.
     *
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public function faqCategories(string $fields, array $args = [], ?int $ttl = null): array
    {
        return $this->run('websiteFaqCategory', $fields, $args, $ttl);
    }

    /**
     * Build (via {@see QueryBuilder}) and run a named query with an explicit
     * field selection and arguments.
     *
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function run(string $queryName, string $fields, array $args, ?int $ttl): array
    {
        $built = QueryBuilder::build($queryName, $fields, $args);

        return $this->graphql($built['query'], $built['variables'], $ttl);
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
