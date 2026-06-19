<?php

declare(strict_types=1);

use GorillaDash\WebsiteSdk\Exceptions\GdRequestException;
use GorillaDash\WebsiteSdk\WebsiteClient;
use GraphQL\Query;
use Illuminate\Support\Facades\Http;

it('exchanges credentials once and reuses the cached token', function () {
    Http::fake([
        'gd.test/oauth/token' => Http::response(['access_token' => 'tok-1', 'expires_in' => 3600]),
        'gd.test/graphql' => Http::response(['data' => ['websiteInfo' => ['id' => '1']]]),
    ]);

    $client = app(WebsiteClient::class);
    $query = (new Query('websiteInfo'))->setSelectionSet(['id']);

    $client->graphql($query);
    // Second query forces a fresh GraphQL call by busting cache freshness (ttl 0).
    $client->connection(['cache_ttl' => 0])->graphql($query);

    // Token endpoint hit exactly once despite multiple GraphQL requests.
    $tokenCalls = collect(Http::recorded())
        ->filter(fn ($pair) => str_contains($pair[0]->url(), '/oauth/token'))
        ->count();

    expect($tokenCalls)->toBe(1);
});

it('throws when credentials are missing', function () {
    config()->set('gd-website.client_id', null);
    config()->set('gd-website.client_secret', null);
    app()->forgetInstance(WebsiteClient::class);

    Http::fake();

    app(WebsiteClient::class)->graphql((new Query('websiteInfo'))->setSelectionSet(['id']));
})->throws(GdRequestException::class);

it('sends the client_credentials grant to the token endpoint', function () {
    Http::fake([
        'gd.test/oauth/token' => Http::response(['access_token' => 'tok-1', 'expires_in' => 3600]),
        'gd.test/graphql' => Http::response(['data' => []]),
    ]);

    app(WebsiteClient::class)->graphql((new Query('websiteInfo'))->setSelectionSet(['id']));

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/oauth/token')
            && $request['grant_type'] === 'client_credentials'
            && $request['client_id'] === 'client-123'
            && $request['client_secret'] === 'secret-abc';
    });
});
