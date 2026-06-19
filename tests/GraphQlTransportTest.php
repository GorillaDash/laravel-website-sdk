<?php

declare(strict_types=1);

use GorillaDash\WebsiteSdk\Exceptions\GdRequestException;
use GorillaDash\WebsiteSdk\WebsiteClient;
use Illuminate\Support\Facades\Http;

it('raises a typed exception on GraphQL errors', function () {
    Http::fake([
        'gd.test/oauth/token' => Http::response(['access_token' => 'tok-1', 'expires_in' => 3600]),
        'gd.test/graphql' => Http::response(['errors' => [['message' => 'Field not found']]]),
    ]);

    app(WebsiteClient::class)->info('id');
})->throws(GdRequestException::class, 'Field not found');

it('retries once with a fresh token after a 401', function () {
    Http::fake([
        'gd.test/oauth/token' => Http::sequence()
            ->push(['access_token' => 'tok-1', 'expires_in' => 3600])
            ->push(['access_token' => 'tok-2', 'expires_in' => 3600]),
        'gd.test/graphql' => Http::sequence()
            ->push(['message' => 'Unauthenticated'], 401)
            ->push(['data' => ['websiteInfo' => ['id' => '1']]], 200),
    ]);

    $data = app(WebsiteClient::class)->info('id');

    expect($data)->toBe(['websiteInfo' => ['id' => '1']]);

    // Second GraphQL call carried the freshly re-exchanged token.
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/graphql')
        && $request->hasHeader('Authorization', 'Bearer tok-2'));
});

it('uses the correct registered query name for each helper', function (string $method, array $args, string $expectedQueryName) {
    Http::fake([
        'gd.test/oauth/token' => Http::response(['access_token' => 'tok-1', 'expires_in' => 3600]),
        'gd.test/graphql' => Http::response(['data' => []]),
    ]);

    app(WebsiteClient::class)->{$method}(...$args);

    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/graphql')
        && str_contains($request['query'], $expectedQueryName));
})->with([
    'info' => ['info', ['name'], 'websiteInfo'],
    'page' => ['page', ['home', 'name slug'], 'websitePage'],
    'pages' => ['pages', ['name slug'], 'websitePages'],
    'sections' => ['sections', ['name'], 'websiteSections'],
    'menu' => ['menu', ['Main Menu', 'name menu_json'], 'websiteMenu'],
    'menus' => ['menus', ['name menu_json'], 'menus'],
    'faqs' => ['faqs', ['slug question'], 'websiteFaq'],
    'faqCategories' => ['faqCategories', ['name'], 'websiteFaqCategory'],
]);

it('builds a parameterised query for helpers with arguments', function () {
    Http::fake([
        'gd.test/oauth/token' => Http::response(['access_token' => 'tok-1', 'expires_in' => 3600]),
        'gd.test/graphql' => Http::response(['data' => ['websitePage' => ['slug' => 'home']]]),
    ]);

    app(WebsiteClient::class)->page('home', 'slug name', ['locale' => 'en']);

    Http::assertSent(function ($request) {
        return str_ends_with($request->url(), '/graphql')
            && str_contains($request['query'], 'websitePage(slug: $slug, locale: $locale)')
            && (array) $request['variables'] === ['slug' => 'home', 'locale' => 'en'];
    });
});
