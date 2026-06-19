<?php

declare(strict_types=1);

namespace GorillaDash\WebsiteSdk;

use GorillaDash\WebsiteSdk\Support\AfterResponseRefresher;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Http\Client\Factory as HttpFactory;
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
    }
}
