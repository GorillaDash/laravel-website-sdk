<?php

declare(strict_types=1);

namespace GorillaDash\WebsiteSdk;

use GorillaDash\WebsiteSdk\Support\AfterResponseRefresher;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class WebsiteSdkServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/website-sdk.php', 'website-sdk');

        $this->app->singleton(AfterResponseRefresher::class, fn ($app) => new AfterResponseRefresher($app));

        $this->app->singleton(WebsiteClient::class, fn ($app) => new WebsiteClient(
            Connection::fromConfig($app['config']->get('website-sdk')),
            $app->make(CacheFactory::class),
            $app->make(HttpFactory::class),
            $app->make(AfterResponseRefresher::class),
        ));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/website-sdk.php' => $this->app->configPath('website-sdk.php'),
            ], 'website-sdk-config');
        }

        $this->registerClearCacheRoute();
    }

    /**
     * Register the cache-clear webhook. GorillaDash calls
     * {site}/{clear_cache_path}?key={public_key} when content changes; a matching
     * public key flushes this site's cached content.
     */
    private function registerClearCacheRoute(): void
    {
        if (! $this->app['config']->get('website-sdk.register_clear_cache_route', true)) {
            return;
        }

        Route::match(
            ['get', 'post'],
            $this->app['config']->get('website-sdk.clear_cache_path', 'gorilla-dash/clear-cache'),
            function () {
                $configured = (string) config('website-sdk.public_key');
                $provided = (string) request('key');

                abort_if($configured === '' || ! hash_equals($configured, $provided), 404);

                $this->app->make(WebsiteClient::class)->flush();

                return response()->json(['cleared' => true]);
            },
        )->name('gd-website.clear-cache');
    }
}
