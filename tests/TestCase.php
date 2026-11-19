<?php

namespace BeeCoded\EFactura\Tests;

use BeeCoded\EFactura\EfacturaServiceProvider;
use BeeCoded\EFactura\Facades\EFactura;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Orchestra\Testbench\TestCase as Orchestra;
use Spatie\LaravelData\LaravelDataServiceProvider;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // No test may reach ANAF: an unfaked request (or one a partial Http::fake() pattern
        // misses) throws instead. Testbench rebuilds the app per test, so set it every time.
        Http::preventStrayRequests();

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        // Safety net: on Postgres, never let a test hang on a lock. A cross-connection
        // race test can leave one connection waiting on another's still-uncommitted row;
        // fail the statement fast instead of hanging the whole CI job.
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("SET lock_timeout = '10s'");
            DB::statement("SET statement_timeout = '60s'");
        }
    }

    protected function getPackageProviders($app): array
    {
        return [
            // The SDK's DTOs are spatie/laravel-data objects, and methods like
            // toArray() read that package's config. It is auto-discovered in a real
            // app (the SDK requires spatie/laravel-data), so registering it here
            // mirrors production - without it the config is null and toArray() throws.
            LaravelDataServiceProvider::class,
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
        // DB_CONNECTION=mariadb or mysql runs it against that engine the same way.
        // Any engine but sqlite needs a database whose name ends in _test: RefreshDatabase
        // drops every table, so a developer's DB_DATABASE must never reach it.
        $connection = match (env('DB_CONNECTION')) {
            'pgsql' => [
                'driver' => 'pgsql',
                'host' => env('DB_HOST', '127.0.0.1'),
                'port' => env('DB_PORT', '5432'),
                'database' => env('DB_DATABASE', 'efactura_test'),
                'username' => env('DB_USERNAME', 'postgres'),
                'password' => env('DB_PASSWORD', 'postgres'),
                'prefix' => '',
            ],
            'mariadb', 'mysql' => [
                'driver' => env('DB_CONNECTION'),
                'host' => env('DB_HOST', '127.0.0.1'),
                'port' => env('DB_PORT', '3306'),
                'database' => env('DB_DATABASE', 'efactura_test'),
                'username' => env('DB_USERNAME', 'root'),
                'password' => env('DB_PASSWORD', ''),
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix' => '',
            ],
            default => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                // Enforce foreign keys on sqlite too (off by default), so a test that
                // creates a dangling reference — e.g. an upload pointing at a token id
                // that does not exist — fails here rather than only on Postgres, where
                // the constraint is always enforced.
                'foreign_key_constraints' => true,
            ],
        };

        if ($connection['driver'] !== 'sqlite' && !str_ends_with((string) $connection['database'], '_test')) {
            throw new \RuntimeException("Refusing to run the suite against '{$connection['database']}': RefreshDatabase drops every table. Use a database whose name ends in _test.");
        }

        // A shared cache store (CACHE_STORE=redis, with REDIS_DB/REDIS_CACHE_DB on a dedicated
        // index) keeps its keys between tests: a per-test prefix isolates them without flushing it.
        if (env('CACHE_STORE', 'array') !== 'array') {
            $app['config']->set('cache.prefix', env('CACHE_PREFIX', 'efactura_test_').Str::random(10).':');
        }

        // Pinned over env: a developer's EFACTURA_SANDBOX=false or real OAuth client must never
        // point the suite at production ANAF or hand a real client secret to a test request.
        $app['config']->set('efactura-sdk.sandbox', true);
        $app['config']->set('efactura-sdk.oauth', [
            'client_id' => 'test-client-id',
            'client_secret' => 'test-client-secret',
            'redirect_uri' => 'https://app.test/efactura/callback',
        ]);

        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', $connection);

        $app['config']->set('efactura.enabled', true);
        $app['config']->set('efactura.features.upload_invoices', true);
        $app['config']->set('efactura.features.download_received', true);
        $app['config']->set('efactura.features.sync_messages', true);
        $app['config']->set('efactura.storage.disk', 'local');
        $app['config']->set('efactura.storage.path', 'efactura-test');
        $app['config']->set('efactura.queue', null);
        $app['config']->set('efactura.connection', null);
        $app['config']->set('efactura.routes.enabled', true);
        $app['config']->set('efactura.routes.prefix', 'efactura');
        $app['config']->set('efactura.routes.middleware', ['web']);
        $app['config']->set('efactura.routes.success_redirect', '/success');
        $app['config']->set('efactura.routes.error_redirect', '/error');
    }

    /**
     * Create the TestInvoice table once per fresh schema, before RefreshDatabase opens its
     * transaction: MySQL/MariaDB commit that transaction on any DDL, so a table created in
     * a test left the test outside it (and its savepoints failing).
     */
    protected function defineDatabaseMigrationsAfterDatabaseRefreshed(): void
    {
        $this->app['db']->connection()->getSchemaBuilder()->create('test_invoices', function ($table) {
            $table->id();
            $table->string('number');
            $table->string('cui');
            $table->decimal('total', 10, 2)->nullable();
        });
    }
}
