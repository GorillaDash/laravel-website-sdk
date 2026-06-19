<?php

declare(strict_types=1);

use GorillaDash\WebsiteSdk\WebsiteClient;
use Illuminate\Support\Facades\Http;

it('clears the cache via the webhook with a valid key', function () {
    Http::fake([
        'gd.test/oauth/token' => Http::response(['access_token' => 'tok-1', 'expires_in' => 3600]),
        'gd.test/graphql' => Http::response(['data' => ['websiteInfo' => ['name' => 'Acme']]]),
    ]);

    $client = app(WebsiteClient::class);
    $client->graphqlWithMeta('{ websiteInfo { name } }'); // miss -> cached
    expect($client->graphqlWithMeta('{ websiteInfo { name } }')['status'])->toBe('fresh');

    $this->get('/gorilla-dash/clear-cache?key=pub-123')
        ->assertOk()
        ->assertJson(['cleared' => true]);

    // Version bumped -> next read is a miss again.
    expect($client->graphqlWithMeta('{ websiteInfo { name } }')['status'])->toBe('miss');
});

it('rejects the webhook without the correct key', function () {
    $this->get('/gorilla-dash/clear-cache')->assertNotFound();
    $this->get('/gorilla-dash/clear-cache?key=wrong')->assertNotFound();
});
