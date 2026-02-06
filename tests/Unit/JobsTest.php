<?php

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
use BeeCoded\EFactura\Services\TokenService;
use BeeCoded\EFactura\Services\UploadService;
use Illuminate\Contracts\Queue\ShouldQueue;

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

        $uploadService = Mockery::mock(UploadService::class);
        $uploadService->shouldNotReceive('processPendingUploads');

        $job = new ProcessPendingUploads;
        $job->handle($uploadService);
    });

    it('does nothing when upload_invoices feature is disabled', function () {
        config(['efactura.enabled' => true, 'efactura.features.upload_invoices' => false]);

        $uploadService = Mockery::mock(UploadService::class);
        $uploadService->shouldNotReceive('processPendingUploads');

        $job = new ProcessPendingUploads;
        $job->handle($uploadService);
    });

    it('calls processPendingUploads when enabled', function () {
        config(['efactura.enabled' => true, 'efactura.features.upload_invoices' => true]);

        $uploadService = Mockery::mock(UploadService::class);
        $uploadService->shouldReceive('processPendingUploads')
            ->once()
            ->with(null);

        $job = new ProcessPendingUploads;
        $job->handle($uploadService);
    });

    it('passes CUI to processPendingUploads', function () {
        config(['efactura.enabled' => true, 'efactura.features.upload_invoices' => true]);

        $uploadService = Mockery::mock(UploadService::class);
        $uploadService->shouldReceive('processPendingUploads')
            ->once()
            ->with('12345678');

        $job = new ProcessPendingUploads('12345678');
        $job->handle($uploadService);
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
            'status' => 'pending',
            'standard' => 'UBL',
        ]);

        $uploadService = Mockery::mock(UploadService::class);
        $uploadService->shouldNotReceive('processUpload');

        $job = new ProcessSingleUpload($upload);
        $job->handle($uploadService);
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

        $job = new ProcessSingleUpload($upload);
        $job->handle($uploadService);
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

        $job = new ProcessSingleUpload($upload);
        $job->handle($uploadService);
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
