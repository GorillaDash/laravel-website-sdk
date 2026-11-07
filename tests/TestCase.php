<?php

declare(strict_types=1);

namespace GorillaDash\WebsiteSdk\Tests;

use GorillaDash\WebsiteSdk\WebsiteSdkServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [WebsiteSdkServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('cache.default', 'array');
        $app['config']->set('website-sdk.base_uri', 'https://gd.test');
        $app['config']->set('website-sdk.client_id', 'client-123');
        $app['config']->set('website-sdk.client_secret', 'secret-abc');
        $app['config']->set('website-sdk.public_key', 'pub-123');
        $app['config']->set('website-sdk.cache_ttl', 60);
        // Disable the hard ceiling by default so SWR tests are deterministic;
        // the max-stale tests set it explicitly.
        $app['config']->set('website-sdk.max_stale_age', 0);
    }

    /**
     * Set package config and load the package routes again, as a site booting
     * with those values would. The routes read config once, when they load.
     *
     * @param  array<string, mixed>  $config
     */
    protected function withPackageRoutes(array $config): void
    {
        config($config);

        require __DIR__.'/../routes/web.php';

        $this->app['router']->getRoutes()->refreshNameLookups();
    }
}
