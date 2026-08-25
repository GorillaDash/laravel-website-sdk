<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;

it('names the website the credentials resolve to', function () {
    Http::fake([
        'gd.test/oauth/token' => Http::response(['access_token' => 'tok-1', 'expires_in' => 3600]),
        'gd.test/graphql' => Http::response(['data' => ['websiteInfo' => [
            'id' => '169',
            'name' => 'Black Optix Tint Australia',
            'url' => 'https://bot-au.example',
        ]]]),
    ]);

    // The failure this exists to catch: credentials for the wrong website
    // authenticate fine and return empty data, so the site looks merely empty.
    $this->artisan('gd:check')
        ->expectsOutputToContain('Black Optix Tint Australia')
        ->expectsOutputToContain('Check the website above is the one this site should serve.')
        ->assertSuccessful();
});

it('reports a healthy cache when a repeat read does not hit the API', function () {
    Http::fake([
        'gd.test/oauth/token' => Http::response(['access_token' => 'tok-1', 'expires_in' => 3600]),
        'gd.test/graphql' => Http::response(['data' => ['websiteInfo' => ['id' => '1', 'name' => 'Acme', 'url' => 'https://acme.test']]]),
    ]);

    $this->artisan('gd:check')
        ->expectsOutputToContain('Cache is working')
        ->assertSuccessful();
});

it('fails when credentials are missing rather than pretending to work', function () {
    config(['website-sdk.client_id' => null, 'website-sdk.client_secret' => null]);

    $this->artisan('gd:check')
        ->expectsOutputToContain('Credentials are not configured')
        ->assertFailed();
});

it('fails loudly when the API cannot be reached', function () {
    Http::fake([
        'gd.test/oauth/token' => Http::response(['access_token' => 'tok-1', 'expires_in' => 3600]),
        'gd.test/graphql' => Http::response(['errors' => [['message' => 'Unauthenticated.']]]),
    ]);

    $this->artisan('gd:check')
        ->expectsOutputToContain('Unauthenticated.')
        ->assertFailed();
});
