<?php

declare(strict_types=1);

use GorillaDash\WebsiteSdk\WebsiteClient;
use Illuminate\Support\Facades\Http;

function fakeGd(): void
{
    Http::fake([
        'gd.test/oauth/token' => Http::response(['access_token' => 'tok-1', 'expires_in' => 3600]),
        'gd.test/graphql' => Http::response(['data' => ['websiteInfo' => ['id' => '1']]]),
    ]);
}

function graphqlCallCount(): int
{
    return collect(Http::recorded())
        ->filter(fn ($pair) => str_ends_with($pair[0]->url(), '/graphql'))
        ->count();
}

it('blocks and fetches on a cache miss', function () {
    fakeGd();

    $result = app(WebsiteClient::class)->graphqlWithMeta('{ websiteInfo { id } }');

    expect($result['status'])->toBe('miss')
        ->and($result['data'])->toBe(['websiteInfo' => ['id' => '1']])
        ->and(graphqlCallCount())->toBe(1);
});

it('serves a fresh cache hit without calling the API again', function () {
    fakeGd();
    $client = app(WebsiteClient::class);

    $client->graphqlWithMeta('{ websiteInfo { id } }');
    $second = $client->graphqlWithMeta('{ websiteInfo { id } }');

    expect($second['status'])->toBe('fresh')
        ->and(graphqlCallCount())->toBe(1);
});

it('serves stale immediately and refreshes once after the response', function () {
    fakeGd();
    // ttl 0 => the stored value is stale on the very next read.
    $client = app(WebsiteClient::class)->connection(['cache_ttl' => 0]);

    $client->graphqlWithMeta('{ websiteInfo { id } }'); // miss -> stores
    $stale = $client->graphqlWithMeta('{ websiteInfo { id } }'); // stale -> defers refresh
    $client->graphqlWithMeta('{ websiteInfo { id } }'); // lock held -> no second refresh deferred

    expect($stale['status'])->toBe('stale');
    // Refresh has not run yet (deferred to after the response).
    expect(graphqlCallCount())->toBe(1);

    // Simulate the response terminating.
    app()->terminate();

    // Exactly one background refresh ran despite two stale reads (lock guard).
    expect(graphqlCallCount())->toBe(2);
});
