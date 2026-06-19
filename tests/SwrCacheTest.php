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

it('flush invalidates cached content so the next read refetches', function () {
    fakeGd();
    $client = app(WebsiteClient::class);

    $client->graphqlWithMeta('{ websiteInfo { id } }'); // miss -> stores
    expect($client->graphqlWithMeta('{ websiteInfo { id } }')['status'])->toBe('fresh');
    expect(graphqlCallCount())->toBe(1);

    $client->flush();

    // Version bumped -> previous key unreachable -> miss again.
    expect($client->graphqlWithMeta('{ websiteInfo { id } }')['status'])->toBe('miss');
    expect(graphqlCallCount())->toBe(2);
});

it('forces a blocking refetch once past max_stale_age', function () {
    Http::fake([
        'gd.test/oauth/token' => Http::response(['access_token' => 'tok-1', 'expires_in' => 3600]),
        'gd.test/graphql' => Http::sequence()
            ->push(['data' => ['websiteInfo' => ['name' => 'Old']]])
            ->push(['data' => ['websiteInfo' => ['name' => 'New']]]),
    ]);

    $client = app(WebsiteClient::class)->connection(['cache_ttl' => 60, 'max_stale_age' => 100]);

    expect($client->graphqlWithMeta('{ websiteInfo { name } }')['data']['websiteInfo']['name'])->toBe('Old');

    $this->travel(120)->seconds();

    $result = $client->graphqlWithMeta('{ websiteInfo { name } }');
    expect($result['status'])->toBe('miss')
        ->and($result['data']['websiteInfo']['name'])->toBe('New')
        ->and(graphqlCallCount())->toBe(2);
});

it('falls back to stale when the forced refetch fails', function () {
    Http::fake([
        'gd.test/oauth/token' => Http::response(['access_token' => 'tok-1', 'expires_in' => 3600]),
        'gd.test/graphql' => Http::sequence()
            ->push(['data' => ['websiteInfo' => ['name' => 'Old']]])
            ->push(['message' => 'boom'], 500),
    ]);

    $client = app(WebsiteClient::class)->connection(['cache_ttl' => 60, 'max_stale_age' => 100]);

    $client->graphqlWithMeta('{ websiteInfo { name } }'); // miss -> 'Old'
    $this->travel(120)->seconds();

    $result = $client->graphqlWithMeta('{ websiteInfo { name } }');
    expect($result['status'])->toBe('stale')
        ->and($result['data']['websiteInfo']['name'])->toBe('Old');
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
