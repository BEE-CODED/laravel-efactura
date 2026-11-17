<?php

use BeeCoded\EFactura\Enums\StatusCheckOutcome;
use BeeCoded\EFactura\Jobs\CheckSingleUploadStatus;
use BeeCoded\EFactura\Jobs\CheckUploadStatuses;
use BeeCoded\EFactura\Jobs\DownloadReceivedInvoices;
use BeeCoded\EFactura\Jobs\DownloadResponses;
use BeeCoded\EFactura\Jobs\ProcessPendingUploads;
use BeeCoded\EFactura\Jobs\ProcessSingleUpload;
use BeeCoded\EFactura\Jobs\SyncMessages;
use BeeCoded\EFactura\Models\EfacturaToken;
use BeeCoded\EFactura\Models\EfacturaUpload;
use BeeCoded\EFactura\Services\DownloadService;
use BeeCoded\EFactura\Services\MessageSyncService;
use BeeCoded\EFactura\Services\UploadService;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->token = EfacturaToken::create([
        'cui' => '12345678',
        'access_token' => 'test',
        'refresh_token' => 'test',
        'expires_at' => now()->addHour(),
        'is_active' => true,
    ]);
});

/**
 * Binds a mock of a job's service that expects its run for the test's token, or none.
 */
function expectRun(string $service, string $method, mixed $returns = null): void
{
    $mock = Mockery::mock($service);
    if ($returns === null) {
        $mock->shouldNotReceive($method);
    } else {
        $mock->shouldReceive($method)->once()
            ->with(Mockery::on(fn ($token) => $token instanceof EfacturaToken && $token->is(test()->token)))
            ->andReturn($returns);
    }
    app()->instance($service, $mock);
}

dataset('company batch jobs', [
    'status checks' => [CheckUploadStatuses::class, DownloadService::class, 'checkAllStatuses', true, 'upload_invoices'],
    'response downloads' => [DownloadResponses::class, DownloadService::class, 'downloadAllResponses', true, 'upload_invoices'],
    'received invoices' => [DownloadReceivedInvoices::class, DownloadService::class, 'downloadAllReceivedInvoices', true, 'download_received'],
    'message sync' => [SyncMessages::class, MessageSyncService::class, 'syncMessages', 0, 'sync_messages'],
    'pending uploads' => [ProcessPendingUploads::class, UploadService::class, 'dispatchPendingUploads', 0, 'upload_invoices'],
]);

describe('the company batch jobs', function () {
    it('are unique per company until processing, queued with the configured settings', function (string $job) {
        $instance = new $job('12345678');

        expect($instance)->toBeInstanceOf(ShouldQueue::class)
            ->toBeInstanceOf(ShouldBeUniqueUntilProcessing::class)
            ->and($instance->tries)->toBe(3)
            ->and($instance->timeout)->toBe(120)
            ->and($instance->backoff)->toBe([60, 180, 300])
            ->and($instance->maxExceptions)->toBe(3)
            ->and($instance->uniqueFor)->toBe(3600)
            ->and($instance->uniqueId())->toBe('12345678')
            ->and((new $job)->uniqueId())->toBe('all');
    })->with('company batch jobs');

    it('pass the company\'s token to their service', function (string $job, string $service, string $method, bool|int $returns) {
        expectRun($service, $method, $returns);

        app()->call([new $job('12345678'), 'handle']);
    })->with('company batch jobs');

    it('do nothing while e-Factura or their feature is off', function (string $job, string $service, string $method, bool|int $returns, string $feature) {
        Queue::fake();
        expectRun($service, $method);

        config(['efactura.enabled' => false]);
        app()->call([new $job('12345678'), 'handle']);
        app()->call([new $job, 'handle']);

        config(['efactura.enabled' => true, "efactura.features.{$feature}" => false]);
        app()->call([new $job('12345678'), 'handle']);
        app()->call([new $job, 'handle']);

        Queue::assertNothingPushed();
    })->with('company batch jobs');

    it('log once and do nothing for a company that is unknown, deactivated or paused', function (string $job, string $service, string $method, bool|int $returns, string $feature, Closure $company) {
        Http::fake();
        Log::spy();
        expectRun($service, $method);

        $cui = $company($this->token);
        app()->call([new $job($cui), 'handle']);

        Log::shouldHaveReceived('warning')->once()->with('EFactura: no usable token for CUI', ['cui' => $cui, 'job' => class_basename($job)]);
        Http::assertNothingSent();
    })->with('company batch jobs')->with([
        'unknown' => fn () => '14399840',
        'deactivated' => function (EfacturaToken $token) {
            $token->update(['is_active' => false]);

            return '12345678';
        },
        'paused' => function (EfacturaToken $token) {
            refuseGrant($token);

            return '12345678';
        },
    ]);
});

describe('ProcessPendingUploads Job', function () {
    it('fans out to each company with a due upload, whose job queues its uploads', function () {
        Queue::fake();

        $upload = EfacturaUpload::create([
            'efactura_token_id' => $this->token->id,
            'uploadable_type' => 'App\\Models\\Invoice',
            'uploadable_id' => 1,
            'status' => 'pending',
            'standard' => 'UBL',
        ]);

        app()->call([new ProcessPendingUploads, 'handle']);
        Queue::assertPushed(ProcessPendingUploads::class, fn (ProcessPendingUploads $job) => $job->cui === '12345678');
        Queue::assertNotPushed(ProcessSingleUpload::class);

        app()->call([new ProcessPendingUploads('12345678'), 'handle']);
        Queue::assertPushed(ProcessSingleUpload::class, fn ($j) => $j->upload->id === $upload->id);
    });

    it('queues only its own company\'s uploads', function () {
        Queue::fake();
        $other = EfacturaToken::create([
            'cui' => '87654321',
            'access_token' => 'other',
            'refresh_token' => 'other',
            'expires_at' => now()->addHour(),
            'is_active' => true,
        ]);
        $mine = EfacturaUpload::create([
            'efactura_token_id' => $this->token->id,
            'uploadable_type' => 'App\\Models\\Invoice',
            'uploadable_id' => 1,
            'status' => 'pending',
        ]);
        EfacturaUpload::create([
            'efactura_token_id' => $other->id,
            'uploadable_type' => 'App\\Models\\Invoice',
            'uploadable_id' => 2,
            'status' => 'pending',
        ]);

        app()->call([new ProcessPendingUploads('12345678'), 'handle']);

        Queue::assertPushed(ProcessSingleUpload::class, 1);
        Queue::assertPushed(ProcessSingleUpload::class, fn ($j) => $j->upload->is($mine));
    });
});

describe('ProcessSingleUpload Job', function () {
    it('implements ShouldQueue', function () {
        $upload = EfacturaUpload::create([
            'efactura_token_id' => $this->token->id,
            'uploadable_type' => 'App\\Models\\Invoice',
            'uploadable_id' => 1,
            'status' => 'pending',
            'standard' => 'UBL',
        ]);

        expect(new ProcessSingleUpload($upload))->toBeInstanceOf(ShouldQueue::class);
    });

    // One try: the row's next_poll_at, not the queue, decides when it runs again.
    it('has correct queue configuration', function () {
        $upload = EfacturaUpload::create([
            'efactura_token_id' => $this->token->id,
            'uploadable_type' => 'App\\Models\\Invoice',
            'uploadable_id' => 1,
            'status' => 'pending',
            'standard' => 'UBL',
        ]);

        $job = new ProcessSingleUpload($upload, '2026-11-16 12:05:00');

        expect($job->tries)->toBe(1)
            ->and($job->timeout)->toBe(120)
            ->and($job->lease)->toBe('2026-11-16 12:05:00');
    });

    it('does nothing when efactura is disabled', function () {
        config(['efactura.enabled' => false]);

        $upload = EfacturaUpload::create([
            'efactura_token_id' => $this->token->id,
            'uploadable_type' => 'App\\Models\\Invoice',
            'uploadable_id' => 1,
            'status' => 'pending',
            'standard' => 'UBL',
        ]);

        $uploadService = Mockery::mock(UploadService::class);
        $uploadService->shouldNotReceive('processUpload');

        (new ProcessSingleUpload($upload))->handle($uploadService);
    });

    it('does nothing when upload_invoices feature is disabled', function () {
        config(['efactura.enabled' => true, 'efactura.features.upload_invoices' => false]);

        $upload = EfacturaUpload::create([
            'efactura_token_id' => $this->token->id,
            'uploadable_type' => 'App\\Models\\Invoice',
            'uploadable_id' => 1,
            'status' => 'pending',
            'standard' => 'UBL',
        ]);

        $uploadService = Mockery::mock(UploadService::class);
        $uploadService->shouldNotReceive('processUpload');

        (new ProcessSingleUpload($upload))->handle($uploadService);
    });

    it('calls processUpload with the upload and its lease when enabled', function () {
        config(['efactura.enabled' => true, 'efactura.features.upload_invoices' => true]);

        $upload = EfacturaUpload::create([
            'efactura_token_id' => $this->token->id,
            'uploadable_type' => 'App\\Models\\Invoice',
            'uploadable_id' => 1,
            'status' => 'pending',
            'standard' => 'UBL',
        ]);

        $uploadService = Mockery::mock(UploadService::class);
        $uploadService->shouldReceive('processUpload')
            ->once()
            ->with(Mockery::on(fn ($arg) => $arg->id === $upload->id), '2026-11-16 12:05:00');

        (new ProcessSingleUpload($upload, '2026-11-16 12:05:00'))->handle($uploadService);
    });

    it('hands a failure to the upload service with its lease', function () {
        $upload = EfacturaUpload::create([
            'efactura_token_id' => $this->token->id,
            'uploadable_type' => 'App\\Models\\Invoice',
            'uploadable_id' => 1,
            'status' => 'pending',
            'standard' => 'UBL',
        ]);
        $failure = new RuntimeException('Something went wrong');

        $uploadService = Mockery::mock(UploadService::class);
        $uploadService->shouldReceive('jobFailed')->once()
            ->with(Mockery::on(fn ($arg) => $arg->is($upload)), '2026-11-16 12:05:00', $failure);
        $this->instance(UploadService::class, $uploadService);

        (new ProcessSingleUpload($upload, '2026-11-16 12:05:00'))->failed($failure);
    });
});

// Each job's only model is its own upload: once it is gone there is nothing to do.
it('drops a single-upload job whose upload was deleted with its token', function (string $class) {
    $upload = EfacturaUpload::create([
        'efactura_token_id' => $this->token->id,
        'uploadable_type' => 'App\\Models\\Invoice',
        'uploadable_id' => 1,
        'status' => 'pending',
        'standard' => 'UBL',
    ]);
    $job = new $class($upload);
    $this->token->delete();
    Event::fake([JobFailed::class]);

    expect(fn () => Queue::connection('sync')->push($job))->not->toThrow(Throwable::class);

    Event::assertNotDispatched(JobFailed::class);
})->with([ProcessSingleUpload::class, CheckSingleUploadStatus::class]);

describe('CheckSingleUploadStatus Job', function () {
    it('implements ShouldQueue', function () {
        $upload = EfacturaUpload::create([
            'efactura_token_id' => $this->token->id,
            'uploadable_type' => 'App\\Models\\Invoice',
            'uploadable_id' => 1,
            'status' => 'processing',
            'standard' => 'UBL',
            'upload_index' => 'INDEX123',
        ]);

        expect(new CheckSingleUploadStatus($upload))->toBeInstanceOf(ShouldQueue::class);
    });

    it('has correct queue configuration', function () {
        $upload = EfacturaUpload::create([
            'efactura_token_id' => $this->token->id,
            'uploadable_type' => 'App\\Models\\Invoice',
            'uploadable_id' => 1,
            'status' => 'processing',
            'standard' => 'UBL',
            'upload_index' => 'INDEX123',
        ]);

        $job = new CheckSingleUploadStatus($upload);

        expect($job->tries)->toBe(3);
        expect($job->timeout)->toBe(120);
        expect($job->backoff)->toBe([60, 180, 300]);
        expect($job->maxExceptions)->toBe(3);
    });

    it('does nothing when efactura is disabled', function () {
        config(['efactura.enabled' => false]);

        $upload = EfacturaUpload::create([
            'efactura_token_id' => $this->token->id,
            'uploadable_type' => 'App\\Models\\Invoice',
            'uploadable_id' => 1,
            'status' => 'processing',
            'standard' => 'UBL',
            'upload_index' => 'INDEX123',
        ]);

        $downloadService = Mockery::mock(DownloadService::class);
        $downloadService->shouldNotReceive('checkStatus');

        $job = new CheckSingleUploadStatus($upload);
        $job->handle($downloadService);
    });

    it('does nothing when upload_invoices feature is disabled', function () {
        config(['efactura.enabled' => true, 'efactura.features.upload_invoices' => false]);

        $upload = EfacturaUpload::create([
            'efactura_token_id' => $this->token->id,
            'uploadable_type' => 'App\\Models\\Invoice',
            'uploadable_id' => 1,
            'status' => 'processing',
            'standard' => 'UBL',
            'upload_index' => 'INDEX123',
        ]);

        $downloadService = Mockery::mock(DownloadService::class);
        $downloadService->shouldNotReceive('checkStatus');

        $job = new CheckSingleUploadStatus($upload);
        $job->handle($downloadService);
    });

    it('calls checkStatus with the upload when enabled', function () {
        config(['efactura.enabled' => true, 'efactura.features.upload_invoices' => true]);

        $upload = EfacturaUpload::create([
            'efactura_token_id' => $this->token->id,
            'uploadable_type' => 'App\\Models\\Invoice',
            'uploadable_id' => 1,
            'status' => 'processing',
            'standard' => 'UBL',
            'upload_index' => 'INDEX123',
        ]);

        $downloadService = Mockery::mock(DownloadService::class);
        $downloadService->shouldReceive('checkStatus')
            ->once()
            ->with(Mockery::on(fn ($arg) => $arg->id === $upload->id))
            ->andReturn(StatusCheckOutcome::Checked);

        $job = new CheckSingleUploadStatus($upload);
        $job->handle($downloadService);
    });
});

describe('Job Queue Configuration', function () {
    it('uses configured queue from config', function () {
        config(['efactura.queue' => 'custom-queue']);

        $job = new ProcessPendingUploads;
        expect($job->queue)->toBe('custom-queue');
    });

    it('uses default queue when config is null', function () {
        config(['efactura.queue' => null]);

        $job = new ProcessPendingUploads;
        expect($job->queue)->toBeNull();
    });
});
