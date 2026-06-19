<?php

declare(strict_types=1);

use GorillaDash\WebsiteSdk\Exceptions\GdRequestException;
use GorillaDash\WebsiteSdk\WebsiteClient;
use GraphQL\Query;
use GraphQL\RawObject;
use GraphQL\Variable;
use Illuminate\Support\Facades\Http;

it('raises a typed exception on GraphQL errors', function () {
    Http::fake([
        'gd.test/oauth/token' => Http::response(['access_token' => 'tok-1', 'expires_in' => 3600]),
        'gd.test/graphql' => Http::response(['errors' => [['message' => 'Field not found']]]),
    ]);

    $query = (new Query('websiteInfo'))->setSelectionSet(['id']);

    app(WebsiteClient::class)->graphql($query);
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

    $query = (new Query('websiteInfo'))->setSelectionSet(['id']);

    $data = app(WebsiteClient::class)->graphql($query);

    expect($data)->toBe(['websiteInfo' => ['id' => '1']]);

    // Second GraphQL call carried the freshly re-exchanged token.
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/graphql')
        && $request->hasHeader('Authorization', 'Bearer tok-2'));
});

it('accepts an mghoneimy query object and forwards its variables', function () {
    Http::fake([
        'gd.test/oauth/token' => Http::response(['access_token' => 'tok-1', 'expires_in' => 3600]),
        'gd.test/graphql' => Http::response(['data' => ['websitePage' => ['slug' => 'home']]]),
    ]);

    $query = (new Query('websitePage'))
        ->setVariables([new Variable('slug', 'String', true)])
        ->setArguments(['slug' => new RawObject('$slug')])
        ->setSelectionSet(['slug', 'name']);

    app(WebsiteClient::class)->graphql($query, ['slug' => 'home']);

    Http::assertSent(function ($request) {
        return str_ends_with($request->url(), '/graphql')
            && str_contains($request['query'], 'websitePage(slug: $slug)')
            && (array) $request['variables'] === ['slug' => 'home'];
    });
});
