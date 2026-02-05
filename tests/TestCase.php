<?php

namespace BeeCoded\EFactura\Tests;

use BeeCoded\EFactura\EfacturaServiceProvider;
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
            EfacturaServiceProvider::class,
        ];
    }

    protected function getPackageAliases($app): array
    {
        return [
            'EFactura' => \BeeCoded\EFactura\Facades\EFactura::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

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
