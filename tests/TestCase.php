<?php

namespace BeeCoded\EFactura\Tests;

use BeeCoded\EFactura\EfacturaServiceProvider;
use BeeCoded\EFactura\Facades\EFactura;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }

    protected function getPackageProviders($app): array
    {
        return [
            // The SDK's DTOs are spatie/laravel-data objects, and methods like
            // toArray() read that package's config. It is auto-discovered in a real
            // app (the SDK requires spatie/laravel-data), so registering it here
            // mirrors production - without it the config is null and toArray() throws.
            \Spatie\LaravelData\LaravelDataServiceProvider::class,
            // The SDK provider binds AnafDetailsClientInterface; auto-discovered in
            // a real app. Registered here so the wrapper's decorating extend() has a
            // concrete to wrap (mirrors production wiring).
            \BeeCoded\EFacturaSdk\EFacturaServiceProvider::class,
            EfacturaServiceProvider::class,
        ];
    }

    protected function getPackageAliases($app): array
    {
        return [
            'EFactura' => EFactura::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        // Defaults to in-memory sqlite — the whole suite locally and the main CI
        // matrix. Set DB_CONNECTION=pgsql (with the usual DB_* env) to run against
        // Postgres, which the CI Postgres job does so the transaction poisoning
        // only Postgres exhibits is actually exercised. sqlite :memory: cannot: it
        // never poisons, and a second connection to it is a separate empty
        // database, so the create/constraint race tests skip there.
        $connection = env('DB_CONNECTION') === 'pgsql'
            ? [
                'driver' => 'pgsql',
                'host' => env('DB_HOST', '127.0.0.1'),
                'port' => env('DB_PORT', '5432'),
                'database' => env('DB_DATABASE', 'efactura_test'),
                'username' => env('DB_USERNAME', 'postgres'),
                'password' => env('DB_PASSWORD', 'postgres'),
                'prefix' => '',
            ]
            : [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ];

        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', $connection);

        $app['config']->set('efactura.enabled', true);
        $app['config']->set('efactura.features.upload_invoices', true);
        $app['config']->set('efactura.features.download_received', true);
        $app['config']->set('efactura.features.sync_messages', true);
        $app['config']->set('efactura.storage.disk', 'local');
        $app['config']->set('efactura.storage.path', 'efactura-test');
        $app['config']->set('efactura.queue', null);
        $app['config']->set('efactura.routes.enabled', true);
        $app['config']->set('efactura.routes.prefix', 'efactura');
        $app['config']->set('efactura.routes.middleware', ['web']);
        $app['config']->set('efactura.routes.success_redirect', '/success');
        $app['config']->set('efactura.routes.error_redirect', '/error');
    }

    protected function createTestInvoicesTable(): void
    {
        $this->app['db']->connection()->getSchemaBuilder()->create('test_invoices', function ($table) {
            $table->id();
            $table->string('number');
            $table->string('cui');
            $table->decimal('total', 10, 2)->default(100);
        });
    }
}
