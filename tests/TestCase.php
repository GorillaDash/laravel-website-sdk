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
        $app['config']->set('website-sdk.cache_ttl', 60);
    }
}
