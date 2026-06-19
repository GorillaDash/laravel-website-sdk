<?php

declare(strict_types=1);

use GorillaDash\WebsiteSdk\Http\Controllers\ClearCacheController;
use GorillaDash\WebsiteSdk\Http\Controllers\GraphQlController;
use Illuminate\Support\Facades\Route;

/*
| Default GraphQL endpoint. Proxies a POST { query, variables } body through the
| SWR cache and returns the full envelope (data + cache metadata).
*/
if (config('website-sdk.register_graphql_route', true)) {
    Route::post(
        config('website-sdk.graphql_path', 'graphql'),
        GraphQlController::class,
    )->name('gd-website.graphql');
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
