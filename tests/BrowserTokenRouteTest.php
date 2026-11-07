<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;

function fakeBrowserTokenExchange(string ...$tokens): void
{
    $sequence = Http::sequence();

    foreach ($tokens as $token) {
        $sequence->push(['status' => 201, 'success' => true, 'data' => ['access_token' => $token, 'expires_in' => 3600]], 201);
    }

    Http::fake([
        'gd.test/oauth/token' => Http::response(['access_token' => 'full-1', 'expires_in' => 1296000]),
        'gd.test/oauth/website-browser-token' => $sequence,
    ]);
}

it('does not register the browser token route by default', function () {
    Http::fake();

    $this->getJson('/gorilla-dash/browser-token')->assertNotFound();

    Http::assertNothingSent();
});

describe('when a site turns the browser token route on', function () {
    beforeEach(function () {
        $this->withPackageRoutes(['website-sdk.register_browser_token_route' => true]);
    });

    it('answers the browser token and its lifetime, never stored by a cache', function () {
        fakeBrowserTokenExchange('browser-1');

        $response = $this->getJson('/gorilla-dash/browser-token')
            ->assertOk()
            ->assertExactJson(['access_token' => 'browser-1', 'expires_in' => 3600]);

        expect($response->headers->get('Cache-Control'))->toBe('no-store, private')
            ->and($response->headers->getCookies())->toBe([]);
    });

    it('serves the cached token until refresh=1 asks for a new one, once a minute', function () {
        fakeBrowserTokenExchange('browser-1', 'browser-2', 'browser-3');

        $this->getJson('/gorilla-dash/browser-token')->assertJsonPath('access_token', 'browser-1');
        $this->getJson('/gorilla-dash/browser-token')->assertJsonPath('access_token', 'browser-1');
        $this->getJson('/gorilla-dash/browser-token?refresh=1')->assertJsonPath('access_token', 'browser-2');
        $this->getJson('/gorilla-dash/browser-token?refresh=1')->assertJsonPath('access_token', 'browser-2');

        $this->travel(61)->seconds();

        $this->getJson('/gorilla-dash/browser-token?refresh=1')->assertJsonPath('access_token', 'browser-3');
    });

    it('answers 503, never stored, when no browser token can be had', function () {
        Http::fake([
            'gd.test/oauth/token' => Http::response(['access_token' => 'full-1', 'expires_in' => 1296000]),
            'gd.test/oauth/website-browser-token' => Http::response(['message' => 'Server Error'], 500),
        ]);

        $response = $this->getJson('/gorilla-dash/browser-token')
            ->assertStatus(503)
            ->assertJsonMissingPath('access_token');

        expect($response->headers->get('Cache-Control'))->toContain('no-store');
    });

    it('rate limits the route per IP address', function () {
        $this->withPackageRoutes([
            'website-sdk.register_browser_token_route' => true,
            'website-sdk.browser_token_rate_limit' => 2,
        ]);
        fakeBrowserTokenExchange('browser-1');

        $this->getJson('/gorilla-dash/browser-token')->assertOk();
        $this->getJson('/gorilla-dash/browser-token')->assertOk();
        $this->getJson('/gorilla-dash/browser-token')->assertTooManyRequests();
        $this->getJson('/gorilla-dash/browser-token', ['REMOTE_ADDR' => '203.0.113.9'])->assertOk();
    });

    it('serves the route on the configured path', function () {
        $this->withPackageRoutes([
            'website-sdk.register_browser_token_route' => true,
            'website-sdk.browser_token_path' => 'api/gd-token',
        ]);
        fakeBrowserTokenExchange('browser-1');

        $this->getJson('/api/gd-token')->assertOk()->assertJsonPath('access_token', 'browser-1');
    });
});
