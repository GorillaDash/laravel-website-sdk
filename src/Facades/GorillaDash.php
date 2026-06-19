<?php

declare(strict_types=1);

namespace GorillaDash\WebsiteSdk\Facades;

use GorillaDash\WebsiteSdk\WebsiteClient;
use Illuminate\Support\Facades\Facade;

/**
 * @method static \GorillaDash\WebsiteSdk\WebsiteClient connection(array $overrides)
 * @method static array graphql(string $query, array $variables = [], ?int $ttl = null)
 * @method static array graphqlWithMeta(string $query, array $variables = [], ?int $ttl = null)
 * @method static bool ping()
 * @method static array info(string $fields = 'name url', ?int $ttl = null)
 * @method static array page(string $slug, string $fields, array $args = [], ?int $ttl = null)
 * @method static array pages(string $fields, array $args = [], ?int $ttl = null)
 * @method static array sections(string $fields, array $args = [], ?int $ttl = null)
 * @method static array menu(string $name, string $fields, array $args = [], ?int $ttl = null)
 * @method static array menus(string $fields, array $args = [], ?int $ttl = null)
 * @method static array faqs(string $fields, array $args = [], ?int $ttl = null)
 * @method static array faqCategories(string $fields, array $args = [], ?int $ttl = null)
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
