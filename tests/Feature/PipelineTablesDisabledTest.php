<?php

declare(strict_types=1);

use BeeCoded\EFactura\EfacturaServiceProvider;
use BeeCoded\EFactura\Exceptions\PipelineTablesDisabledException;
use BeeCoded\EFactura\Facades\EFactura;
use BeeCoded\EFactura\Jobs\CheckSingleUploadStatus;
use BeeCoded\EFactura\Jobs\ProcessSingleUpload;
use BeeCoded\EFactura\Jobs\SweepStaleUploads;
use BeeCoded\EFactura\Jobs\SyncMessages;
use BeeCoded\EFactura\Models\EfacturaMessage;
use BeeCoded\EFactura\Models\EfacturaToken;
use BeeCoded\EFactura\Models\EfacturaUpload;
use BeeCoded\EFactura\Services\DownloadService;
use BeeCoded\EFactura\Services\MessageSyncService;
use BeeCoded\EFactura\Services\UploadService;
use BeeCoded\EFactura\Tests\Fixtures\TestInvoice;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\ServiceProvider;

/**
 * `efactura.pipeline_tables` false: only the token tables exist, so the pipeline refuses
 * instead of failing on a missing table, and tokens keep working.
 */
it('loads and publishes only the token migrations', function () {
    $published = ServiceProvider::$publishes;
    $groups = ServiceProvider::$publishGroups;
    config(['efactura.pipeline_tables' => false]);
    $migrator = new Migrator($this->app['migration.repository'], $this->app['db'], $this->app['files'], $this->app['events']);
    $this->app->instance('migrator', $migrator);

    try {
        ServiceProvider::$publishes = [];
        ServiceProvider::$publishGroups = [];
        (new EfacturaServiceProvider($this->app))->boot();
        $tokenMigrations = [
            '2024_01_01_000001_create_efactura_tokens_table.php',
            '2024_01_01_000004_encrypt_efactura_token_credentials.php',
            '2024_01_01_000009_make_efactura_token_expiry_an_instant.php',
        ];
        $publish = ServiceProvider::pathsToPublish(EfacturaServiceProvider::class, 'efactura-migrations');

        expect(array_map('basename', $migrator->paths()))->toBe($tokenMigrations)
            ->and(array_map('basename', array_keys($publish)))->toBe($tokenMigrations)
            ->and(array_values($publish))->toBe(array_map(fn (string $name) => database_path('migrations/'.$name), $tokenMigrations));
    } finally {
        ServiceProvider::$publishes = $published;
        ServiceProvider::$publishGroups = $groups;
    }
});

it('refuses B2C queueing and a container-resolved service, but keeps tokens', function () {
    config(['efactura.pipeline_tables' => false]);
    $token = EfacturaToken::create(['cui' => '12345678', 'access_token' => 'a', 'refresh_token' => 'r', 'expires_at' => now()->addHour()]);
    $invoice = TestInvoice::create(['number' => 'INV-1', 'cui' => '12345678']);

    expect(fn () => EFactura::queueB2CUpload($invoice))->toThrow(PipelineTablesDisabledException::class)
        ->and(fn () => app(MessageSyncService::class)->syncMessages($token))->toThrow(PipelineTablesDisabledException::class)
        ->and(fn () => EFactura::downloadService())->toThrow(PipelineTablesDisabledException::class)
        ->and(fn () => EFactura::uploadService())->toThrow(PipelineTablesDisabledException::class)
        ->and(fn () => EFactura::messageSyncService())->toThrow(PipelineTablesDisabledException::class)
        ->and(EFactura::getToken('12345678')?->is($token))->toBeTrue()
        ->and(EFactura::getAuthorizationUrl('18547290'))->toStartWith('https://');
    Http::assertNothingSent();
});

it('refuses UploadService::queueUpload(), the upload job and efactura:upload before they touch anything', function () {
    config(['efactura.pipeline_tables' => false]);
    EfacturaToken::create(['cui' => '12345678', 'access_token' => 'a', 'refresh_token' => 'r', 'expires_at' => now()->addHour()]);
    $invoice = TestInvoice::create(['number' => 'INV-1', 'cui' => '12345678']);

    expect(fn () => app(UploadService::class)->queueUpload($invoice))->toThrow(PipelineTablesDisabledException::class);

    // Any call reaching the service fails differently, so only the job's and command's own checks pass.
    $this->mock(UploadService::class);
    expect(fn () => app()->call([new ProcessSingleUpload(new EfacturaUpload), 'handle']))->toThrow(PipelineTablesDisabledException::class)
        ->and(fn () => Artisan::call('efactura:upload'))->toThrow(PipelineTablesDisabledException::class)
        ->and(EfacturaUpload::count())->toBe(0);
});

it('refuses the scheduled jobs and the status, sync and reconcile commands before they touch anything', function (Closure $run) {
    config(['efactura.pipeline_tables' => false]);
    EfacturaToken::create(['cui' => '12345678', 'access_token' => 'a', 'refresh_token' => 'r', 'expires_at' => now()->addHour()]);
    // Any call reaching a service fails differently, so only the entry point's own check passes.
    $this->mock(DownloadService::class);
    $this->mock(MessageSyncService::class);
    $this->mock(UploadService::class);

    expect($run)->toThrow(PipelineTablesDisabledException::class);
})->with([
    // CompanyBatchJob::handle() is final: one subclass covers every batch job.
    'a per-company batch job' => [fn () => app()->call([new SyncMessages('12345678'), 'handle'])],
    'the stale-upload sweep' => [fn () => app()->call([new SweepStaleUploads, 'handle'])],
    'a single status check' => [fn () => app()->call([new CheckSingleUploadStatus(new EfacturaUpload), 'handle'])],
    'efactura:status' => [fn () => Artisan::call('efactura:status')],
    'efactura:sync' => [fn () => Artisan::call('efactura:sync')],
    'efactura:reconcile' => [fn () => Artisan::call('efactura:reconcile')],
]);

it('refuses a pipeline service method resolved from the container', function (Closure $run) {
    config(['efactura.pipeline_tables' => false]);
    Http::fake();
    $token = EfacturaToken::create(['cui' => '12345678', 'access_token' => 'a', 'refresh_token' => 'r', 'expires_at' => now()->addHour()]);

    expect(fn () => $run($token))->toThrow(PipelineTablesDisabledException::class);
    Http::assertNothingSent();
})->with([
    'checkStatus' => [fn () => app(DownloadService::class)->checkStatus(new EfacturaUpload)],
    'downloadResponse' => [fn () => app(DownloadService::class)->downloadResponse(new EfacturaUpload)],
    'downloadMessage' => [fn () => app(DownloadService::class)->downloadMessage(new EfacturaMessage)],
    'markMessageAsDownloaded' => [fn () => app(DownloadService::class)->markMessageAsDownloaded(new EfacturaMessage)],
    'checkAllStatuses' => [fn (EfacturaToken $token) => app(DownloadService::class)->checkAllStatuses($token)],
    'downloadAllResponses' => [fn (EfacturaToken $token) => app(DownloadService::class)->downloadAllResponses($token)],
    'downloadAllReceivedInvoices' => [fn (EfacturaToken $token) => app(DownloadService::class)->downloadAllReceivedInvoices($token)],
    'processUpload' => [fn () => app(UploadService::class)->processUpload(new EfacturaUpload)],
]);
