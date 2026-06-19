<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;

it('serves graphql over the default POST endpoint with the cache envelope', function () {
    Http::fake([
        'gd.test/oauth/token' => Http::response(['access_token' => 'tok-1', 'expires_in' => 3600]),
        'gd.test/graphql' => Http::response(['data' => ['websiteInfo' => ['name' => 'Acme']]]),
    ]);

    $this->postJson('/graphql', ['query' => '{ websiteInfo { name } }'])
        ->assertOk()
        ->assertJson([
            'data' => ['websiteInfo' => ['name' => 'Acme']],
            'status' => 'miss',
        ]);
});

it('passes variables through to the transport', function () {
    Http::fake([
        'gd.test/oauth/token' => Http::response(['access_token' => 'tok-1', 'expires_in' => 3600]),
        'gd.test/graphql' => Http::response(['data' => ['websitePage' => ['name' => 'About']]]),
    ]);

    $this->postJson('/graphql', [
        'query' => 'query ($slug: String) { websitePage(slug: $slug) { name } }',
        'variables' => ['slug' => 'about-us'],
    ])->assertOk()->assertJson(['data' => ['websitePage' => ['name' => 'About']]]);

    Http::assertSent(fn ($request) => $request->url() === 'https://gd.test/graphql'
        && (array) $request['variables'] === ['slug' => 'about-us']);
});
