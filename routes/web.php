<?php

declare(strict_types=1);

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
