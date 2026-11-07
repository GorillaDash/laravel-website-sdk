<?php

declare(strict_types=1);

use GorillaDash\WebsiteSdk\Http\Controllers\BrowserTokenController;
use GorillaDash\WebsiteSdk\Http\Controllers\ClearCacheController;
use GorillaDash\WebsiteSdk\Http\Controllers\GraphQlController;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Route;

/*
| These routes are loaded outside the `web` middleware group: no session, no
| cookies, no CSRF. Each one that answers the public carries its own guard.
*/

/*
| Optional GraphQL proxy (off by default). Runs a POST { query, variables } body
| through the SWR cache with the full website token, so it refuses anything but
| a query, and is rate limited per IP address.
*/
if (config('website-sdk.register_graphql_route', false)) {
    $graphqlRoute = Route::post(
        config('website-sdk.graphql_path', 'graphql'),
        GraphQlController::class,
    )->name('gd-website.graphql');

    if (($limit = (int) config('website-sdk.graphql_rate_limit', 60)) > 0) {
        $graphqlRoute->middleware(ThrottleRequests::with($limit, 1, 'gd-website-graphql'));
    }
}

/*
| Optional browser-token endpoint (off by default). Answers a read-only token for
| browser code; ?refresh=1 asks for a new one after Gorilla Dash refused it.
*/
if (config('website-sdk.register_browser_token_route', false)) {
    $browserTokenRoute = Route::get(
        config('website-sdk.browser_token_path', 'gorilla-dash/browser-token'),
        BrowserTokenController::class,
    )->name('gd-website.browser-token');

    if (($limit = (int) config('website-sdk.browser_token_rate_limit', 30)) > 0) {
        $browserTokenRoute->middleware(ThrottleRequests::with($limit, 1, 'gd-website-browser-token'));
    }
}

/*
| Cache-clear webhook. GorillaDash calls
| {site}/{clear_cache_path}?key={public_key} when content changes; a matching
| public key flushes this site's cached content.
*/
if (config('website-sdk.register_clear_cache_route', true)) {
    Route::match(
        ['get', 'post'],
        config('website-sdk.clear_cache_path', 'gorilla-dash/clear-cache'),
        ClearCacheController::class,
    )->name('gd-website.clear-cache');
}
