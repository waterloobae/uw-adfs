<?php

namespace WaterlooBae\UwAdfs;

use Illuminate\Support\ServiceProvider;
use WaterlooBae\UwAdfs\Services\AdfsService;
use WaterlooBae\UwAdfs\Services\ProxyService;
use WaterlooBae\UwAdfs\Services\AccessControlService;

class UwAdfsServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/uw-adfs.php',
            'uw-adfs'
        );

        $this->app->singleton('uw-adfs', function ($app) {
            return new AdfsService($app['config']['uw-adfs']);
        });

        $this->app->singleton(AdfsService::class, function ($app) {
            return $app['uw-adfs'];
        });

        // Register ProxyService
        $this->app->singleton(ProxyService::class, function ($app) {
            return new ProxyService($app->make(AdfsService::class));
        });

        // Register AccessControlService (used by ProxyService)
        $this->app->bind(AccessControlService::class, function ($app) {
            return new AccessControlService(config('uw-adfs.access_control', []));
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/uw-adfs.php' => config_path('uw-adfs.php'),
            ], 'uw-adfs-config');

            $this->publishes([
                __DIR__ . '/../dev.xml' => storage_path('app/saml/dev.xml'),
                __DIR__ . '/../prod.xml' => storage_path('app/saml/prod.xml'),
            ], 'uw-adfs-metadata');

            // Register console commands
            $this->commands([
                \WaterlooBae\UwAdfs\Console\Commands\RefreshMetadataCommand::class,
            ]);
        }

        $this->loadRoutesFrom(__DIR__ . '/../routes/web.php');

        // Register middleware
        $router = $this->app['router'];
        $router->aliasMiddleware('adfs.auth', \WaterlooBae\UwAdfs\Http\Middleware\AdfsAuthenticated::class);
        $router->aliasMiddleware('adfs.group', \WaterlooBae\UwAdfs\Http\Middleware\AdfsGroup::class);
        $router->aliasMiddleware('adfs.no-cache', \WaterlooBae\UwAdfs\Http\Middleware\NoCache::class);
    }
}