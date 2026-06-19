<?php

declare(strict_types=1);

namespace GorillaDash\WebsiteSdk\Facades;

use GorillaDash\WebsiteSdk\WebsiteClient;
use Illuminate\Support\Facades\Facade;

/**
 * @method static \GorillaDash\WebsiteSdk\WebsiteClient connection(array $overrides)
 * @method static array graphql(\GraphQL\Query|\GraphQL\QueryBuilder\QueryBuilderInterface $query, array $variables = [], ?int $ttl = null)
 * @method static array graphqlWithMeta(string|\GraphQL\Query|\GraphQL\QueryBuilder\QueryBuilderInterface $query, array $variables = [], ?int $ttl = null)
 * @method static bool ping()
 * @method static void flush()
 *
 * @see WebsiteClient
 */
class GorillaDash extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return WebsiteClient::class;
    }
}
