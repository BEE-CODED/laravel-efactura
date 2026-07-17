<?php

use BeeCoded\EFactura\Enums\FailureReason;
use BeeCoded\EFactura\Enums\UploadStatus;
use BeeCoded\EFactura\Events\InvoiceFailed;
use BeeCoded\EFactura\Jobs\CheckSingleUploadStatus;
use BeeCoded\EFactura\Jobs\CheckUploadStatuses;
use BeeCoded\EFactura\Jobs\DownloadReceivedInvoices;
use BeeCoded\EFactura\Jobs\DownloadResponses;
use BeeCoded\EFactura\Jobs\ProcessPendingUploads;
use BeeCoded\EFactura\Jobs\ProcessSingleUpload;
use BeeCoded\EFactura\Jobs\RetryRateLimitedUploads;
use BeeCoded\EFactura\Jobs\SyncMessages;
use BeeCoded\EFactura\Models\EfacturaToken;
use BeeCoded\EFactura\Models\EfacturaUpload;
use BeeCoded\EFactura\Services\DownloadService;
use BeeCoded\EFactura\Services\MessageSyncService;
use BeeCoded\EFactura\Services\TokenService;
use BeeCoded\EFactura\Services\UploadService;
use BeeCoded\EFacturaSdk\Services\RateLimiter;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
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

describe('ProcessPendingUploads Job', function () {
    it('implements ShouldQueue', function () {
        expect(new ProcessPendingUploads)->toBeInstanceOf(ShouldQueue::class);
    });

    it('has correct queue configuration', function () {
        $job = new ProcessPendingUploads;

        expect($job->tries)->toBe(3);
        expect($job->timeout)->toBe(120);
        expect($job->backoff)->toBe([60, 180, 300]);
        expect($job->maxExceptions)->toBe(3);
    });

    it('accepts optional CUI parameter', function () {
        $job = new ProcessPendingUploads('12345678');

        expect($job->cui)->toBe('12345678');
    });

    it('does nothing when efactura is disabled', function () {
        config(['efactura.enabled' => false]);

        Queue::fake();

        $tokenService = Mockery::mock(TokenService::class);
        $tokenService->shouldNotReceive('getToken');

        $job = new ProcessPendingUploads;
        $job->handle($tokenService);

        Queue::assertNothingPushed();
    });

    it('does nothing when upload_invoices feature is disabled', function () {
        config(['efactura.enabled' => true, 'efactura.features.upload_invoices' => false]);

        Queue::fake();

        $tokenService = Mockery::mock(TokenService::class);
        $tokenService->shouldNotReceive('getToken');

        $job = new ProcessPendingUploads;
        $job->handle($tokenService);

        Queue::assertNothingPushed();
    });

    it('dispatches ProcessSingleUpload for each pending upload when enabled', function () {
        config(['efactura.enabled' => true, 'efactura.features.upload_invoices' => true]);

        Queue::fake();

        $upload = EfacturaUpload::create([
            'efactura_token_id' => $this->token->id,
            'uploadable_type' => 'App\\Models\\Invoice',
            'uploadable_id' => 1,
            'status' => 'pending',
            'standard' => 'UBL',
        ]);

        $tokenService = Mockery::mock(TokenService::class);

        $job = new ProcessPendingUploads;
        $job->handle($tokenService);

        Queue::assertPushed(ProcessSingleUpload::class, fn ($j) => $j->upload->id === $upload->id);
    });

    it('filters by CUI when specified', function () {
        config(['efactura.enabled' => true, 'efactura.features.upload_invoices' => true]);

        Queue::fake();

        $tokenService = Mockery::mock(TokenService::class);
        $tokenService->shouldReceive('getToken')
            ->with('12345678')
            ->once()
            ->andReturn($this->token);

        $job = new ProcessPendingUploads('12345678');
        $job->handle($tokenService);
    });

    it('logs warning and returns when CUI token not found', function () {
        config(['efactura.enabled' => true, 'efactura.features.upload_invoices' => true]);

        Queue::fake();

        $tokenService = Mockery::mock(TokenService::class);
        $tokenService->shouldReceive('getToken')
            ->with('99999999')
            ->once()
            ->andReturn(null);

        $job = new ProcessPendingUploads('99999999');
        $job->handle($tokenService);

        Queue::assertNothingPushed();
    });
});

describe('CheckUploadStatuses Job', function () {
    it('implements ShouldQueue', function () {
        expect(new CheckUploadStatuses)->toBeInstanceOf(ShouldQueue::class);
    });

    it('has correct queue configuration', function () {
        $job = new CheckUploadStatuses;

        expect($job->tries)->toBe(3);
        expect($job->timeout)->toBe(120);
    });

    it('does nothing when upload_invoices feature is disabled', function () {
        config(['efactura.enabled' => true, 'efactura.features.upload_invoices' => false]);

        $downloadService = Mockery::mock(DownloadService::class);
        $downloadService->shouldNotReceive('checkAllStatuses');

        $job = new CheckUploadStatuses;
        $job->handle($downloadService);
    });

    it('calls checkAllStatuses when enabled', function () {
        config(['efactura.enabled' => true, 'efactura.features.upload_invoices' => true]);

        $downloadService = Mockery::mock(DownloadService::class);
        $downloadService->shouldReceive('checkAllStatuses')
            ->once()
            ->with(null);

        $job = new CheckUploadStatuses;
        $job->handle($downloadService);
    });

    it('passes CUI to checkAllStatuses', function () {
        config(['efactura.enabled' => true, 'efactura.features.upload_invoices' => true]);

        $downloadService = Mockery::mock(DownloadService::class);
        $downloadService->shouldReceive('checkAllStatuses')
            ->once()
            ->with('12345678');

        $job = new CheckUploadStatuses('12345678');
        $job->handle($downloadService);
    });
});

describe('DownloadResponses Job', function () {
    it('implements ShouldQueue', function () {
        expect(new DownloadResponses)->toBeInstanceOf(ShouldQueue::class);
    });

    it('has correct queue configuration', function () {
        $job = new DownloadResponses;

        expect($job->tries)->toBe(3);
        expect($job->timeout)->toBe(120);
    });

    it('does nothing when upload_invoices feature is disabled', function () {
        config(['efactura.enabled' => true, 'efactura.features.upload_invoices' => false]);

        $downloadService = Mockery::mock(DownloadService::class);
        $downloadService->shouldNotReceive('downloadAllResponses');

        $job = new DownloadResponses;
        $job->handle($downloadService);
    });

    it('calls downloadAllResponses when enabled', function () {
        config(['efactura.enabled' => true, 'efactura.features.upload_invoices' => true]);

        $downloadService = Mockery::mock(DownloadService::class);
        $downloadService->shouldReceive('downloadAllResponses')
            ->once()
            ->with(null);

        $job = new DownloadResponses;
        $job->handle($downloadService);
    });

    it('passes CUI to downloadAllResponses', function () {
        config(['efactura.enabled' => true, 'efactura.features.upload_invoices' => true]);

        $downloadService = Mockery::mock(DownloadService::class);
        $downloadService->shouldReceive('downloadAllResponses')
            ->once()
            ->with('12345678');

        $job = new DownloadResponses('12345678');
        $job->handle($downloadService);
    });
});

describe('DownloadReceivedInvoices Job', function () {
    it('implements ShouldQueue', function () {
        expect(new DownloadReceivedInvoices)->toBeInstanceOf(ShouldQueue::class);
    });

    it('has correct queue configuration', function () {
        $job = new DownloadReceivedInvoices;

        expect($job->tries)->toBe(3);
        expect($job->timeout)->toBe(120);
    });

    it('does nothing when download_received feature is disabled', function () {
        config(['efactura.enabled' => true, 'efactura.features.download_received' => false]);

        $downloadService = Mockery::mock(DownloadService::class);
        $downloadService->shouldNotReceive('downloadAllReceivedInvoices');

        $job = new DownloadReceivedInvoices;
        $job->handle($downloadService);
    });

    it('calls downloadAllReceivedInvoices when enabled', function () {
        config(['efactura.enabled' => true, 'efactura.features.download_received' => true]);

        $downloadService = Mockery::mock(DownloadService::class);
        $downloadService->shouldReceive('downloadAllReceivedInvoices')
            ->once()
            ->with(null);

        $job = new DownloadReceivedInvoices;
        $job->handle($downloadService);
    });

    it('passes CUI to downloadAllReceivedInvoices', function () {
        config(['efactura.enabled' => true, 'efactura.features.download_received' => true]);

        $downloadService = Mockery::mock(DownloadService::class);
        $downloadService->shouldReceive('downloadAllReceivedInvoices')
            ->once()
            ->with('12345678');

        $job = new DownloadReceivedInvoices('12345678');
        $job->handle($downloadService);
    });
});

describe('SyncMessages Job', function () {
    it('implements ShouldQueue', function () {
        expect(new SyncMessages)->toBeInstanceOf(ShouldQueue::class);
    });

    it('has correct queue configuration', function () {
        $job = new SyncMessages;

        expect($job->tries)->toBe(3);
        expect($job->timeout)->toBe(120);
    });

    it('does nothing when sync_messages feature is disabled', function () {
        config(['efactura.enabled' => true, 'efactura.features.sync_messages' => false]);

        $messageSyncService = Mockery::mock(MessageSyncService::class);
        $tokenService = Mockery::mock(TokenService::class);

        $messageSyncService->shouldNotReceive('syncAllMessages');
        $messageSyncService->shouldNotReceive('syncMessages');

        $job = new SyncMessages;
        $job->handle($messageSyncService, $tokenService);
    });

    it('calls syncAllMessages when no CUI specified', function () {
        config(['efactura.enabled' => true, 'efactura.features.sync_messages' => true]);

        $messageSyncService = Mockery::mock(MessageSyncService::class);
        $tokenService = Mockery::mock(TokenService::class);

        $messageSyncService->shouldReceive('syncAllMessages')->once();

        $job = new SyncMessages;
        $job->handle($messageSyncService, $tokenService);
    });

    it('calls syncMessages for specific CUI when token exists', function () {
        config(['efactura.enabled' => true, 'efactura.features.sync_messages' => true]);

        $messageSyncService = Mockery::mock(MessageSyncService::class);
        $tokenService = Mockery::mock(TokenService::class);

        $tokenService->shouldReceive('getToken')
            ->with('12345678')
            ->once()
            ->andReturn($this->token);

        $messageSyncService->shouldReceive('syncMessages')
            ->with($this->token)
            ->once();

        $job = new SyncMessages('12345678');
        $job->handle($messageSyncService, $tokenService);
    });

    it('logs warning and returns when CUI token not found', function () {
        config(['efactura.enabled' => true, 'efactura.features.sync_messages' => true]);

        $messageSyncService = Mockery::mock(MessageSyncService::class);
        $tokenService = Mockery::mock(TokenService::class);

        $tokenService->shouldReceive('getToken')
            ->with('99999999')
            ->once()
            ->andReturn(null);

        $messageSyncService->shouldNotReceive('syncMessages');
        $messageSyncService->shouldNotReceive('syncAllMessages');

        $job = new SyncMessages('99999999');
        $job->handle($messageSyncService, $tokenService);
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

    it('has correct queue configuration', function () {
        $upload = EfacturaUpload::create([
            'efactura_token_id' => $this->token->id,
            'uploadable_type' => 'App\\Models\\Invoice',
            'uploadable_id' => 1,
            'status' => 'pending',
            'standard' => 'UBL',
        ]);

        $job = new ProcessSingleUpload($upload);

        expect($job->timeout)->toBe(120);
        expect($job->maxExceptions)->toBe(3);
        expect($job->retryUntil())->toBeInstanceOf(DateTime::class);
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

        $rateLimiter = Mockery::mock(RateLimiter::class);

        $job = new ProcessSingleUpload($upload);
        $job->handle($uploadService, $rateLimiter);
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

        $rateLimiter = Mockery::mock(RateLimiter::class);

        $job = new ProcessSingleUpload($upload);
        $job->handle($uploadService, $rateLimiter);
    });

    it('calls processUpload with the upload when enabled', function () {
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
            ->with(Mockery::on(fn ($arg) => $arg->id === $upload->id));

        $rateLimiter = Mockery::mock(RateLimiter::class);
        $rateLimiter->shouldReceive('isEnabled')->andReturn(false);

        $job = new ProcessSingleUpload($upload);
        $job->handle($uploadService, $rateLimiter);
    });
});

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
            ->with(Mockery::on(fn ($arg) => $arg->id === $upload->id));

        $job = new CheckSingleUploadStatus($upload);
        $job->handle($downloadService);
    });
});

describe('ProcessSingleUpload Rate Limiting', function () {
    it('uses retryUntil with configured retry window', function () {
        config(['efactura.rate_limit.retry_window_hours' => 12]);

        $upload = EfacturaUpload::create([
            'efactura_token_id' => $this->token->id,
            'uploadable_type' => 'App\\Models\\Invoice',
            'uploadable_id' => 1,
            'status' => 'pending',
            'standard' => 'UBL',
        ]);

        $job = new ProcessSingleUpload($upload);
        $retryUntil = $job->retryUntil();

        expect($retryUntil)->toBeInstanceOf(DateTime::class);
        // Should be approximately 12 hours from now
        $diffHours = (int) round((now()->diffInMinutes($retryUntil)) / 60);
        expect($diffHours)->toBe(12);
    });

    it('releases job when rate limit quota is exhausted', function () {
        config(['efactura.enabled' => true, 'efactura.features.upload_invoices' => true]);

        $upload = EfacturaUpload::create([
            'efactura_token_id' => $this->token->id,
            'uploadable_type' => 'App\\Models\\Invoice',
            'uploadable_id' => 1,
            'status' => 'pending',
            'standard' => 'UBL',
        ]);

        $uploadService = Mockery::mock(UploadService::class);
        $uploadService->shouldNotReceive('processUpload');

        $rateLimiter = Mockery::mock(RateLimiter::class);
        $rateLimiter->shouldReceive('isEnabled')->andReturn(true);
        $rateLimiter->shouldReceive('getRemainingQuota')
            ->with('global')
            ->andReturn(['remaining' => 0, 'resetsIn' => 30]);

        $fakeQueueJob = Mockery::mock(Job::class);
        $fakeQueueJob->shouldReceive('release')->with(30)->once();

        $job = new ProcessSingleUpload($upload);
        $job->setJob($fakeQueueJob);

        $job->handle($uploadService, $rateLimiter);
    });

    it('uses minimum 10 second delay when resetsIn is less', function () {
        config(['efactura.enabled' => true, 'efactura.features.upload_invoices' => true]);

        $upload = EfacturaUpload::create([
            'efactura_token_id' => $this->token->id,
            'uploadable_type' => 'App\\Models\\Invoice',
            'uploadable_id' => 1,
            'status' => 'pending',
            'standard' => 'UBL',
        ]);

        $uploadService = Mockery::mock(UploadService::class);
        $uploadService->shouldNotReceive('processUpload');

        $rateLimiter = Mockery::mock(RateLimiter::class);
        $rateLimiter->shouldReceive('isEnabled')->andReturn(true);
        $rateLimiter->shouldReceive('getRemainingQuota')
            ->with('global')
            ->andReturn(['remaining' => 0, 'resetsIn' => 3]);

        $fakeQueueJob = Mockery::mock(Job::class);
        $fakeQueueJob->shouldReceive('release')->with(10)->once();

        $job = new ProcessSingleUpload($upload);
        $job->setJob($fakeQueueJob);

        $job->handle($uploadService, $rateLimiter);
    });

    it('skips rate limit check when rate limiter is disabled', function () {
        config(['efactura.enabled' => true, 'efactura.features.upload_invoices' => true]);

        $upload = EfacturaUpload::create([
            'efactura_token_id' => $this->token->id,
            'uploadable_type' => 'App\\Models\\Invoice',
            'uploadable_id' => 1,
            'status' => 'pending',
            'standard' => 'UBL',
        ]);

        $uploadService = Mockery::mock(UploadService::class);
        $uploadService->shouldReceive('processUpload')->once();

        $rateLimiter = Mockery::mock(RateLimiter::class);
        $rateLimiter->shouldReceive('isEnabled')->andReturn(false);
        $rateLimiter->shouldNotReceive('getRemainingQuota');

        $job = new ProcessSingleUpload($upload);
        $job->handle($uploadService, $rateLimiter);
    });

    it('proceeds when rate limit has remaining quota', function () {
        config(['efactura.enabled' => true, 'efactura.features.upload_invoices' => true]);

        $upload = EfacturaUpload::create([
            'efactura_token_id' => $this->token->id,
            'uploadable_type' => 'App\\Models\\Invoice',
            'uploadable_id' => 1,
            'status' => 'pending',
            'standard' => 'UBL',
        ]);

        $uploadService = Mockery::mock(UploadService::class);
        $uploadService->shouldReceive('processUpload')->once();

        $rateLimiter = Mockery::mock(RateLimiter::class);
        $rateLimiter->shouldReceive('isEnabled')->andReturn(true);
        $rateLimiter->shouldReceive('getRemainingQuota')
            ->with('global')
            ->andReturn(['remaining' => 100, 'resetsIn' => 45]);

        $job = new ProcessSingleUpload($upload);
        $job->handle($uploadService, $rateLimiter);
    });

    it('resets upload and releases job on rate limit error after processing', function () {
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
            ->andReturnUsing(function () use ($upload) {
                // Simulate the upload failing with a rate limit error
                $upload->update([
                    'status' => UploadStatus::Failed,
                    'failure_reason' => FailureReason::RateLimited,
                    'errors' => ['Rate limit exceeded'],
                ]);
            });
        $uploadService->shouldReceive('resetForRetry')
            ->with(Mockery::on(fn ($arg) => $arg->id === $upload->id))
            ->once()
            ->andReturn(true);

        $rateLimiter = Mockery::mock(RateLimiter::class);
        $rateLimiter->shouldReceive('isEnabled')->andReturn(false);

        $fakeQueueJob = Mockery::mock(Job::class);
        $fakeQueueJob->shouldReceive('release')->with(60)->once();

        $job = new ProcessSingleUpload($upload);
        $job->setJob($fakeQueueJob);

        $job->handle($uploadService, $rateLimiter);
    });

    it('does not release job when rate limit reset fails due to concurrent transition', function () {
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
            ->andReturnUsing(function () use ($upload) {
                $upload->update([
                    'status' => UploadStatus::Failed,
                    'failure_reason' => FailureReason::RateLimited,
                    'errors' => ['Rate limit exceeded'],
                ]);
            });
        $uploadService->shouldReceive('resetForRetry')
            ->once()
            ->andReturn(false); // Another process already transitioned

        $rateLimiter = Mockery::mock(RateLimiter::class);
        $rateLimiter->shouldReceive('isEnabled')->andReturn(false);

        $fakeQueueJob = Mockery::mock(Job::class);
        $fakeQueueJob->shouldNotReceive('release');

        $job = new ProcessSingleUpload($upload);
        $job->setJob($fakeQueueJob);

        $job->handle($uploadService, $rateLimiter);
    });

    it('does not attempt reset when upload did not fail', function () {
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
            ->andReturnUsing(function () use ($upload) {
                // Simulate successful processing
                $upload->update(['status' => UploadStatus::Processing]);
            });
        $uploadService->shouldNotReceive('resetForRetry');

        $rateLimiter = Mockery::mock(RateLimiter::class);
        $rateLimiter->shouldReceive('isEnabled')->andReturn(false);

        $job = new ProcessSingleUpload($upload);
        $job->handle($uploadService, $rateLimiter);
    });

    it('does not attempt reset when error is not rate limit related', function () {
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
            ->andReturnUsing(function () use ($upload) {
                $upload->update([
                    'status' => UploadStatus::Failed,
                    'failure_reason' => FailureReason::Validation,
                    'errors' => ['XML validation error'],
                ]);
            });
        $uploadService->shouldNotReceive('resetForRetry');

        $rateLimiter = Mockery::mock(RateLimiter::class);
        $rateLimiter->shouldReceive('isEnabled')->andReturn(false);

        $job = new ProcessSingleUpload($upload);
        $job->handle($uploadService, $rateLimiter);
    });
});

describe('ProcessSingleUpload transient retry', function () {
    /**
     * A transient failure (auth blip) provably never reached ANAF's pipeline, so the
     * job must re-drive it rather than let a legal filing die permanently. This is the
     * shape that turned the token deadlock into permanent data loss.
     */
    it('resets and releases a transiently-failed upload', function () {
        config([
            'efactura.enabled' => true,
            'efactura.features.upload_invoices' => true,
            'efactura.jobs.transient_retry_delay_seconds' => 30,
        ]);

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
            ->andReturnUsing(function () use ($upload) {
                $upload->update([
                    'status' => UploadStatus::Failed,
                    'failure_reason' => FailureReason::Transient,
                    'errors' => ['Token refresh lock timeout'],
                ]);
            });
        $uploadService->shouldReceive('resetForRetry')->once()->andReturn(true);

        $rateLimiter = Mockery::mock(RateLimiter::class);
        $rateLimiter->shouldReceive('isEnabled')->andReturn(false);

        $fakeQueueJob = Mockery::mock(Job::class);
        $fakeQueueJob->shouldReceive('attempts')->andReturn(1);
        $fakeQueueJob->shouldReceive('release')->with(30)->once();

        $job = new ProcessSingleUpload($upload);
        $job->setJob($fakeQueueJob);
        $job->handle($uploadService, $rateLimiter);
    });

    /**
     * A durable fault (revoked token answering 401 forever) must not release every few
     * seconds for the whole 24h retry window. Once the cap is hit the failure is genuinely
     * terminal, so InvoiceFailed fires exactly then.
     *
     * The cap is only real if the row it leaves behind is NON-RETRYABLE.
     *
     * giveUpOnTransientFailure() fired InvoiceFailed and returned without touching
     * failure_reason, so the row stayed Failed/transient — exactly what
     * RetryRateLimitedUploads::retryableQuery() selects (Failed + rate_limited|transient).
     * The scheduled job then reset it to Pending with a fresh attempts counter and a fresh
     * 24h window, so the job "gave up" every ~10 minutes for retry_max_age_days and fired
     * a false InvoiceFailed each time — the exact contract violation InvoiceRateLimited
     * was created to fix, reintroduced through a different door.
     */
    it('gives up, announces a terminal failure, and leaves the row non-retryable', function () {
        config([
            'efactura.enabled' => true,
            'efactura.features.upload_invoices' => true,
            'efactura.jobs.max_transient_attempts' => 5,
        ]);

        Event::fake([InvoiceFailed::class]);
        Queue::fake();

        $upload = EfacturaUpload::create([
            'efactura_token_id' => $this->token->id,
            'uploadable_type' => 'App\\Models\\Invoice',
            'uploadable_id' => 1,
            'status' => 'pending',
            'standard' => 'UBL',
        ]);

        // A REAL UploadService: the give-up transition itself is what is under test,
        // so mocking it away would prove nothing.
        $uploadService = Mockery::mock(
            UploadService::class.'[processUpload]',
            [app(TokenService::class)]
        );
        $uploadService->shouldReceive('processUpload')
            ->once()
            ->andReturnUsing(function () use ($upload) {
                $upload->update([
                    'status' => UploadStatus::Failed,
                    'failure_reason' => FailureReason::Transient,
                    'errors' => ['Token refresh lock timeout'],
                ]);
            });

        $rateLimiter = Mockery::mock(RateLimiter::class);
        $rateLimiter->shouldReceive('isEnabled')->andReturn(false);

        $fakeQueueJob = Mockery::mock(Job::class);
        $fakeQueueJob->shouldReceive('attempts')->andReturn(5);
        $fakeQueueJob->shouldNotReceive('release');

        $job = new ProcessSingleUpload($upload);
        $job->setJob($fakeQueueJob);
        $job->handle($uploadService, $rateLimiter);

        $upload->refresh();

        expect($upload->status)->toBe(UploadStatus::Failed)
            ->and($upload->failure_reason->isRetryable())->toBeFalse()
            // Nothing was transmitted, so it must not demand human reconciliation.
            ->and($upload->failure_reason->needsReconciliation())->toBeFalse();

        // The pipeline really has given up, so this is a genuine terminal failure.
        Event::assertDispatched(InvoiceFailed::class);

        // The gating layer: the scheduled batch job that actually did the resurrecting.
        (new RetryRateLimitedUploads)->handle();

        expect($upload->fresh()->status)->toBe(UploadStatus::Failed);
        Queue::assertNothingPushed();
    });

    /**
     * The double-filing guard at the job layer: an indeterminate upload must never be
     * re-driven, no matter how the job got here.
     */
    it('never re-drives an indeterminate upload', function () {
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
            ->andReturnUsing(function () use ($upload) {
                $upload->update([
                    'status' => UploadStatus::Failed,
                    'failure_reason' => FailureReason::Indeterminate,
                    'errors' => ['Connection lost mid-POST'],
                ]);
            });
        $uploadService->shouldNotReceive('resetForRetry');

        $rateLimiter = Mockery::mock(RateLimiter::class);
        $rateLimiter->shouldReceive('isEnabled')->andReturn(false);

        $fakeQueueJob = Mockery::mock(Job::class);
        $fakeQueueJob->shouldNotReceive('release');

        $job = new ProcessSingleUpload($upload);
        $job->setJob($fakeQueueJob);
        $job->handle($uploadService, $rateLimiter);
    });
});

describe('ProcessSingleUpload Failed Handler', function () {
    it('logs error when job fails permanently', function () {
        $upload = EfacturaUpload::create([
            'efactura_token_id' => $this->token->id,
            'uploadable_type' => 'App\\Models\\Invoice',
            'uploadable_id' => 1,
            'status' => 'pending',
            'standard' => 'UBL',
        ]);

        $job = new ProcessSingleUpload($upload);

        Log::shouldReceive('error')
            ->once()
            ->with('EFactura: Upload job failed permanently', Mockery::on(fn ($ctx) => $ctx['upload_id'] === $upload->id
                && $ctx['error'] === 'Something went wrong'));

        // The row is Pending, so failed() also abandons it (see the retry-deadline tests
        // in StrandedUploadTest) — which logs a warning of its own.
        Log::shouldReceive('warning')->zeroOrMoreTimes();

        $job->failed(new RuntimeException('Something went wrong'));
    });
});

describe('RetryRateLimitedUploads Job', function () {
    it('implements ShouldQueue and ShouldBeUniqueUntilProcessing', function () {
        $job = new RetryRateLimitedUploads;

        expect($job)->toBeInstanceOf(ShouldQueue::class);
        expect($job)->toBeInstanceOf(ShouldBeUniqueUntilProcessing::class);
    });

    it('has correct queue configuration', function () {
        $job = new RetryRateLimitedUploads;

        expect($job->timeout)->toBe(120);
        expect($job->maxExceptions)->toBe(3);
        expect($job->uniqueFor)->toBe(600);
    });

    it('does nothing when efactura is disabled', function () {
        config(['efactura.enabled' => false]);

        Queue::fake();

        $job = new RetryRateLimitedUploads;
        $job->handle();

        Queue::assertNothingPushed();
    });

    it('does nothing when upload_invoices feature is disabled', function () {
        config(['efactura.enabled' => true, 'efactura.features.upload_invoices' => false]);

        Queue::fake();

        $job = new RetryRateLimitedUploads;
        $job->handle();

        Queue::assertNothingPushed();
    });

    it('does nothing when no rate-limited uploads exist', function () {
        config(['efactura.enabled' => true, 'efactura.features.upload_invoices' => true]);

        Queue::fake();

        $job = new RetryRateLimitedUploads;
        $job->handle();

        Queue::assertNothingPushed();
    });

    it('resets rate-limited uploads to pending and dispatches ProcessSingleUpload', function () {
        config(['efactura.enabled' => true, 'efactura.features.upload_invoices' => true]);

        Queue::fake();

        $upload = EfacturaUpload::create([
            'efactura_token_id' => $this->token->id,
            'uploadable_type' => 'App\\Models\\Invoice',
            'uploadable_id' => 1,
            'status' => UploadStatus::Failed,
            'failure_reason' => FailureReason::RateLimited,
            'standard' => 'UBL',
            'errors' => ['Rate limit exceeded'],
        ]);

        $job = new RetryRateLimitedUploads;
        $job->handle();

        expect($upload->fresh()->status)->toBe(UploadStatus::Pending);
        expect($upload->fresh()->errors)->toBeNull();

        Queue::assertPushed(ProcessSingleUpload::class, fn ($j) => $j->upload->id === $upload->id);
    });

    it('ignores failed uploads without rate limit errors', function () {
        config(['efactura.enabled' => true, 'efactura.features.upload_invoices' => true]);

        Queue::fake();

        $upload = EfacturaUpload::create([
            'efactura_token_id' => $this->token->id,
            'uploadable_type' => 'App\\Models\\Invoice',
            'uploadable_id' => 1,
            'status' => UploadStatus::Failed,
            'failure_reason' => FailureReason::Validation,
            'standard' => 'UBL',
            'errors' => ['XML validation error'],
        ]);

        $job = new RetryRateLimitedUploads;
        $job->handle();

        expect($upload->fresh()->status)->toBe(UploadStatus::Failed);
        Queue::assertNothingPushed();
    });

    it('ignores uploads older than max age', function () {
        config([
            'efactura.enabled' => true,
            'efactura.features.upload_invoices' => true,
            'efactura.rate_limit.retry_max_age_days' => 7,
        ]);

        Queue::fake();

        $upload = EfacturaUpload::create([
            'efactura_token_id' => $this->token->id,
            'uploadable_type' => 'App\\Models\\Invoice',
            'uploadable_id' => 1,
            'status' => UploadStatus::Failed,
            'failure_reason' => FailureReason::RateLimited,
            'standard' => 'UBL',
            'errors' => ['Rate limit exceeded'],
        ]);

        // Backdate the upload beyond max age
        EfacturaUpload::where('id', $upload->id)->update(['created_at' => now()->subDays(8)]);

        $job = new RetryRateLimitedUploads;
        $job->handle();

        expect($upload->fresh()->status)->toBe(UploadStatus::Failed);
        Queue::assertNothingPushed();
    });

    it('respects batch size limit', function () {
        config([
            'efactura.enabled' => true,
            'efactura.features.upload_invoices' => true,
            'efactura.rate_limit.retry_batch_size' => 2,
        ]);

        Queue::fake();

        // Create 3 rate-limited uploads
        for ($i = 1; $i <= 3; $i++) {
            EfacturaUpload::create([
                'efactura_token_id' => $this->token->id,
                'uploadable_type' => 'App\\Models\\Invoice',
                'uploadable_id' => $i,
                'status' => UploadStatus::Failed,
                'failure_reason' => FailureReason::RateLimited,
                'standard' => 'UBL',
                'errors' => ['Rate limit exceeded'],
            ]);
        }

        $job = new RetryRateLimitedUploads;
        $job->handle();

        // Only 2 should be dispatched (batch size limit)
        Queue::assertPushed(ProcessSingleUpload::class, 2);
    });
});

describe('Batch jobs discard themselves when stale', function () {
    it('CheckUploadStatuses skips checkAllStatuses when stale', function () {
        config(['efactura.enabled' => true, 'efactura.features.upload_invoices' => true, 'efactura.jobs.max_staleness_seconds' => 120]);

        $downloadService = Mockery::mock(DownloadService::class);
        $downloadService->shouldNotReceive('checkAllStatuses');

        $job = new CheckUploadStatuses;
        $job->enqueuedAt = now()->subSeconds(200)->getTimestamp();
        $job->handle($downloadService);
    });

    it('DownloadResponses skips downloadAllResponses when stale', function () {
        config(['efactura.enabled' => true, 'efactura.features.upload_invoices' => true, 'efactura.jobs.max_staleness_seconds' => 120]);

        $downloadService = Mockery::mock(DownloadService::class);
        $downloadService->shouldNotReceive('downloadAllResponses');

        $job = new DownloadResponses;
        $job->enqueuedAt = now()->subSeconds(200)->getTimestamp();
        $job->handle($downloadService);
    });

    it('ProcessPendingUploads skips work when stale', function () {
        config(['efactura.enabled' => true, 'efactura.features.upload_invoices' => true, 'efactura.jobs.max_staleness_seconds' => 120]);

        Queue::fake();

        $tokenService = Mockery::mock(TokenService::class);

        $job = new ProcessPendingUploads;
        $job->enqueuedAt = now()->subSeconds(200)->getTimestamp();
        $job->handle($tokenService);

        Queue::assertNothingPushed();
    });

    it('CheckUploadStatuses still runs when fresh', function () {
        config(['efactura.enabled' => true, 'efactura.features.upload_invoices' => true, 'efactura.jobs.max_staleness_seconds' => 120]);

        $downloadService = Mockery::mock(DownloadService::class);
        $downloadService->shouldReceive('checkAllStatuses')->once()->with(null);

        $job = new CheckUploadStatuses; // fresh: enqueuedAt = now
        $job->handle($downloadService);
    });
});

describe('Batch jobs are unique until processing', function () {
    it('CheckUploadStatuses implements ShouldBeUniqueUntilProcessing with configured TTL and per-CUI id', function () {
        config(['efactura.jobs.unique_for_seconds' => 3600]);

        $job = new CheckUploadStatuses('12345678');

        expect($job)->toBeInstanceOf(ShouldBeUniqueUntilProcessing::class)
            ->and($job->uniqueFor)->toBe(3600)
            ->and($job->uniqueId())->toBe('12345678');
    });

    it('CheckUploadStatuses uniqueId falls back to "all" without a CUI', function () {
        expect((new CheckUploadStatuses)->uniqueId())->toBe('all');
    });

    it('DownloadResponses implements ShouldBeUniqueUntilProcessing', function () {
        expect(new DownloadResponses)->toBeInstanceOf(ShouldBeUniqueUntilProcessing::class);
    });

    it('ProcessPendingUploads implements ShouldBeUniqueUntilProcessing', function () {
        expect(new ProcessPendingUploads)->toBeInstanceOf(ShouldBeUniqueUntilProcessing::class);
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
