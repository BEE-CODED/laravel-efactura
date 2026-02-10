<?php

/*
 * This file is part of the Laravel e-Factura package.
 *
 * (c) BEE-CODED <dev.ttl@beecoded.ro>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace BeeCoded\EFactura;

use BeeCoded\EFactura\Services\DownloadService;
use BeeCoded\EFactura\Services\MessageSyncService;
use BeeCoded\EFactura\Services\TokenService;
use BeeCoded\EFactura\Services\UploadService;
use Illuminate\Support\ServiceProvider;

class EfacturaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/efactura.php', 'efactura');

        $this->app->singleton(TokenService::class);
        $this->app->singleton(UploadService::class);
        $this->app->singleton(DownloadService::class);
        $this->app->singleton(MessageSyncService::class);

        $this->app->singleton(EfacturaManager::class, function ($app) {
            return new EfacturaManager(
                $app->make(TokenService::class),
                $app->make(UploadService::class),
                $app->make(DownloadService::class),
                $app->make(MessageSyncService::class),
            );
        });
    }

    public function boot(): void
    {
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'efactura');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/efactura.php' => config_path('efactura.php'),
            ], 'efactura-config');

            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'efactura-migrations');

            $this->publishes([
                __DIR__.'/../resources/lang' => $this->app->langPath('vendor/efactura'),
            ], 'efactura-translations');

            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

            $this->commands([
                Console\Commands\EfacturaUploadCommand::class,
                Console\Commands\EfacturaStatusCommand::class,
                Console\Commands\EfacturaSyncCommand::class,
                Console\Commands\EfacturaAuthCommand::class,
            ]);
        }

        $this->registerRoutes();
    }

    protected function registerRoutes(): void
    {
        if (!config('efactura.routes.enabled', true)) {
            return;
        }

        $this->app['router']
            ->prefix(config('efactura.routes.prefix', 'efactura'))
            ->middleware(config('efactura.routes.middleware', ['web']))
            ->group(__DIR__.'/../routes/efactura.php');
    }
}
