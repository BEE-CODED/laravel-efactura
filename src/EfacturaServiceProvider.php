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

use BeeCoded\EFactura\Services\CachedAnafLookup;
use BeeCoded\EFactura\Services\DownloadService;
use BeeCoded\EFactura\Services\MessageSyncService;
use BeeCoded\EFactura\Services\RetryingAnafDetailsClient;
use BeeCoded\EFactura\Services\TokenService;
use BeeCoded\EFactura\Services\UploadService;
use BeeCoded\EFacturaSdk\Contracts\AnafDetailsClientInterface;
use BeeCoded\EFacturaSdk\Services\ApiClients\AnafDetailsClient;
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
        $this->app->singleton(EfacturaManager::class);

        // Verdicts share one cache key whatever the client, so the container's must be strict.
        $this->app->bind(CachedAnafLookup::class, fn () => new CachedAnafLookup(new AnafDetailsClient(strict: true, tries: 1, timeoutSeconds: 2)));

        // Wrap the SDK's ANAF lookup client with synchronous retry on the SDK's
        // company-lookup limit. extend() decorates whatever the SDK provider bound, so the app
        // injects the resilient client transparently via the SDK interface.
        $this->app->extend(AnafDetailsClientInterface::class, function (AnafDetailsClientInterface $inner) {
            return new RetryingAnafDetailsClient(
                $inner,
                (int) config('efactura.anaf_lookup.retry_attempts', 5),
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

            $migrations = $this->migrations();

            $this->publishes(
                array_combine($migrations, array_map(fn (string $path) => database_path('migrations/'.basename($path)), $migrations)),
                'efactura-migrations',
            );

            $this->publishes([
                __DIR__.'/../resources/lang' => $this->app->langPath('vendor/efactura'),
            ], 'efactura-translations');

            $this->loadMigrationsFrom($migrations);

            $this->commands([
                Console\Commands\EfacturaUploadCommand::class,
                Console\Commands\EfacturaStatusCommand::class,
                Console\Commands\EfacturaSyncCommand::class,
                Console\Commands\EfacturaAuthCommand::class,
                Console\Commands\EfacturaReconcileCommand::class,
            ]);
        }

        $this->registerRoutes();
    }

    /**
     * The package migrations: only the token ones when `efactura.pipeline_tables` is false.
     *
     * @return list<string>
     */
    protected function migrations(): array
    {
        $directory = __DIR__.'/../database/migrations';

        if (config('efactura.pipeline_tables', true)) {
            return glob($directory.'/*.php') ?: [];
        }

        return array_map(fn (string $name) => "{$directory}/{$name}.php", [
            '2024_01_01_000001_create_efactura_tokens_table',
            '2024_01_01_000004_encrypt_efactura_token_credentials',
            '2024_01_01_000009_make_efactura_token_expiry_an_instant',
        ]);
    }

    protected function registerRoutes(): void
    {
        if (!config('efactura.routes.enabled', true)) {
            return;
        }

        $this->app['router']
            ->prefix(config('efactura.routes.prefix', 'efactura'))
            ->middleware(config('efactura.routes.middleware', ['web', 'auth']))
            ->group(__DIR__.'/../routes/efactura.php');
    }
}
