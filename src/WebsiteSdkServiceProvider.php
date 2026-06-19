<?php

declare(strict_types=1);

namespace GorillaDash\WebsiteSdk;

use GorillaDash\WebsiteSdk\Support\AfterResponseRefresher;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Http\Client\Factory as HttpFactory;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class WebsiteSdkServiceProvider extends PackageServiceProvider
{
    /**
     * Wire the package up the spatie/laravel-package-tools way: the config file
     * (config/website-sdk.php, published with tag "website-sdk-config") and the
     * cache-clear webhook route (routes/web.php) are registered declaratively.
     */
    public function configurePackage(Package $package): void
    {
        $package
            ->name('website-sdk')
            ->hasConfigFile()
            ->hasRoute('web');
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(AfterResponseRefresher::class, fn ($app) => new AfterResponseRefresher($app));

        $this->app->singleton(WebsiteClient::class, fn ($app) => new WebsiteClient(
            Connection::fromConfig($app['config']->get('website-sdk')),
            $app->make(CacheFactory::class),
            $app->make(HttpFactory::class),
            $app->make(AfterResponseRefresher::class),
        ));
    }
}
