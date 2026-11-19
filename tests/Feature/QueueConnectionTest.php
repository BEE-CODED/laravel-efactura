<?php

/*
 * This file is part of the Laravel e-Factura package.
 *
 * (c) BEE-CODED <dev.ttl@beecoded.ro>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

use BeeCoded\EFactura\Enums\StatusCheckOutcome;
use BeeCoded\EFactura\Enums\UploadStatus;
use BeeCoded\EFactura\Jobs\CheckSingleUploadStatus;
use BeeCoded\EFactura\Jobs\CheckUploadStatuses;
use BeeCoded\EFactura\Jobs\ProcessPendingUploads;
use BeeCoded\EFactura\Jobs\ProcessSingleUpload;
use BeeCoded\EFactura\Jobs\SweepStaleUploads;
use BeeCoded\EFactura\Jobs\SyncMessages;
use BeeCoded\EFactura\Models\EfacturaToken;
use BeeCoded\EFactura\Models\EfacturaUpload;
use BeeCoded\EFactura\Services\DownloadService;
use BeeCoded\EFactura\Tests\Fixtures\TestInvoice;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Queue;

/**
 * efactura.connection (EFACTURA_QUEUE_CONNECTION) puts every job the package dispatches on
 * one queue connection, so an app whose default connection has a large retry_after can give
 * the package jobs the retry_after they need. Null or blank keeps the app's default.
 */
beforeEach(function () {
    config(['queue.connections.efactura-jobs' => ['driver' => 'database', 'table' => 'jobs', 'queue' => 'efactura-default', 'retry_after' => 180]]);

    $this->token = EfacturaToken::create([
        'cui' => '12345678',
        'access_token' => 'test',
        'refresh_token' => 'test',
        'expires_at' => now()->addHour(),
        'is_active' => true,
    ]);

    $invoice = TestInvoice::create(['number' => 'INV-1', 'cui' => '12345678', 'total' => 100]);

    $this->upload = EfacturaUpload::create([
        'efactura_token_id' => $this->token->id,
        'uploadable_type' => TestInvoice::class,
        'uploadable_id' => $invoice->id,
        'status' => UploadStatus::Pending,
        'standard' => 'UBL',
    ]);
});

dataset('connection settings', [
    'configured' => ['efactura-jobs', 'efactura-jobs'],
    'unset (the app default)' => [null, null],
    'blank (the app default)' => ['', null],
]);

it('queues the jobs efactura:upload dispatches on the configured connection', function (?string $setting, ?string $expected) {
    config(['efactura.connection' => $setting, 'efactura.queue' => 'efactura']);
    Queue::fake();

    $this->artisan('efactura:upload')->assertExitCode(0);

    $pushed = Queue::pushed(ProcessSingleUpload::class);
    expect($pushed)->toHaveCount(1)
        ->and($pushed->first()->connection)->toBe($expected)
        ->and($pushed->first()->queue)->toBe('efactura');
})->with('connection settings');

it('queues the per-company jobs a batch job fans out on the configured connection', function (?string $setting, ?string $expected) {
    config(['efactura.connection' => $setting, 'efactura.queue' => 'efactura']);
    Queue::fake();

    app()->call([new ProcessPendingUploads, 'handle']);
    app()->call([new SyncMessages, 'handle']);

    foreach ([ProcessPendingUploads::class, SyncMessages::class] as $job) {
        $pushed = Queue::pushed($job);
        expect($pushed)->toHaveCount(1)
            ->and($pushed->first()->cui)->toBe('12345678')
            ->and($pushed->first()->connection)->toBe($expected)
            ->and($pushed->first()->queue)->toBe('efactura');
    }
})->with('connection settings');

it('queues scheduled jobs on the configured connection', function (?string $setting, ?string $expected) {
    config(['efactura.connection' => $setting, 'efactura.queue' => 'efactura']);
    Queue::fake();

    $schedule = app(Schedule::class);
    $schedule->job(new CheckUploadStatuses)->everyMinute();
    $schedule->job(new SweepStaleUploads)->everyMinute();

    $this->artisan('schedule:run')->assertExitCode(0);

    foreach ([CheckUploadStatuses::class, SweepStaleUploads::class] as $job) {
        $pushed = Queue::pushed($job);
        expect($pushed)->toHaveCount(1)
            ->and($pushed->first()->connection)->toBe($expected)
            ->and($pushed->first()->queue)->toBe('efactura');
    }
})->with('connection settings');

it('queues an on-demand status check on the configured connection', function (?string $setting, ?string $expected) {
    config(['efactura.connection' => $setting, 'efactura.queue' => 'efactura']);
    Queue::fake();

    CheckSingleUploadStatus::dispatch($this->upload);

    $pushed = Queue::pushed(CheckSingleUploadStatus::class);
    expect($pushed)->toHaveCount(1)
        ->and($pushed->first()->connection)->toBe($expected)
        ->and($pushed->first()->queue)->toBe('efactura');
})->with('connection settings');

it('still runs a dispatchSync() inline when a connection is configured', function () {
    config(['efactura.connection' => 'efactura-jobs']);
    $this->mock(DownloadService::class)->shouldReceive('checkStatus')->once()->andReturn(StatusCheckOutcome::Checked);

    CheckSingleUploadStatus::dispatchSync($this->upload);
});

it('names the configured connection and its queue in the efactura:upload hint', function () {
    config(['efactura.connection' => 'efactura-jobs', 'efactura.queue' => null]);
    Queue::fake();

    $this->artisan('efactura:upload')
        ->expectsOutputToContain('running on the "efactura-default" queue of the "efactura-jobs" connection.')
        ->assertExitCode(0);
});
