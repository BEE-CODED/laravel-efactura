<?php

use BeeCoded\EFactura\Enums\UploadStatus;
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
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
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
        expect($job->retryUntil())->toBeInstanceOf(\DateTime::class);
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

        expect($retryUntil)->toBeInstanceOf(\DateTime::class);
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

        $fakeQueueJob = Mockery::mock(\Illuminate\Contracts\Queue\Job::class);
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

        $fakeQueueJob = Mockery::mock(\Illuminate\Contracts\Queue\Job::class);
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
                    'errors' => ['Rate limit exceeded'],
                ]);
            });
        $uploadService->shouldReceive('resetForRateLimit')
            ->with(Mockery::on(fn ($arg) => $arg->id === $upload->id))
            ->once()
            ->andReturn(true);

        $rateLimiter = Mockery::mock(RateLimiter::class);
        $rateLimiter->shouldReceive('isEnabled')->andReturn(false);

        $fakeQueueJob = Mockery::mock(\Illuminate\Contracts\Queue\Job::class);
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
                    'errors' => ['Rate limit exceeded'],
                ]);
            });
        $uploadService->shouldReceive('resetForRateLimit')
            ->once()
            ->andReturn(false); // Another process already transitioned

        $rateLimiter = Mockery::mock(RateLimiter::class);
        $rateLimiter->shouldReceive('isEnabled')->andReturn(false);

        $fakeQueueJob = Mockery::mock(\Illuminate\Contracts\Queue\Job::class);
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
        $uploadService->shouldNotReceive('resetForRateLimit');

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
                    'errors' => ['XML validation error'],
                ]);
            });
        $uploadService->shouldNotReceive('resetForRateLimit');

        $rateLimiter = Mockery::mock(RateLimiter::class);
        $rateLimiter->shouldReceive('isEnabled')->andReturn(false);

        $job = new ProcessSingleUpload($upload);
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

        Illuminate\Support\Facades\Log::shouldReceive('error')
            ->once()
            ->with('EFactura: Upload job failed permanently', Mockery::on(fn ($ctx) => $ctx['upload_id'] === $upload->id
                && $ctx['error'] === 'Something went wrong'));

        $job->failed(new \RuntimeException('Something went wrong'));
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
