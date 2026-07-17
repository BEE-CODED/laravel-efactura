<?php

use BeeCoded\EFactura\Enums\UploadStatus;
use BeeCoded\EFactura\Models\EfacturaToken;
use BeeCoded\EFactura\Models\EfacturaUpload;
use BeeCoded\EFactura\Services\DownloadService;
use BeeCoded\EFactura\Services\MessageSyncService;
use BeeCoded\EFactura\Services\TokenService;
use BeeCoded\EFactura\Services\UploadService;

beforeEach(function () {
    $this->token = EfacturaToken::create([
        'cui' => '12345678',
        'access_token' => 'test',
        'refresh_token' => 'test',
        'expires_at' => now()->addHour(),
        'is_active' => true,
    ]);
});

describe('EfacturaUploadCommand', function () {
    it('fails when upload feature is disabled', function () {
        config(['efactura.enabled' => true, 'efactura.features.upload_invoices' => false]);

        $this->artisan('efactura:upload')
            ->expectsOutput('e-Factura upload feature is disabled.')
            ->assertExitCode(1);
    });

    it('fails when efactura is disabled', function () {
        config(['efactura.enabled' => false, 'efactura.features.upload_invoices' => true]);

        $this->artisan('efactura:upload')
            ->expectsOutput('e-Factura upload feature is disabled.')
            ->assertExitCode(1);
    });

    /**
     * The command now QUEUES uploads instead of uploading them inline: the inline path
     * has no rate-limit pre-flight and no release/retry, so a backlog past ANAF's
     * quota used to be marked permanently Failed en masse.
     */
    it('queues all uploads when no CUI specified', function () {
        config(['efactura.enabled' => true, 'efactura.features.upload_invoices' => true]);

        $uploadService = Mockery::mock(UploadService::class);
        $uploadService->shouldReceive('dispatchPendingUploads')
            ->once()
            ->with(null)
            ->andReturn(2);

        $this->app->instance(UploadService::class, $uploadService);

        $this->artisan('efactura:upload')
            ->expectsOutputToContain('Queued 2 pending upload(s)')
            ->assertExitCode(0);
    });

    it('still supports inline processing behind --sync', function () {
        config(['efactura.enabled' => true, 'efactura.features.upload_invoices' => true]);

        $uploadService = Mockery::mock(UploadService::class);
        $uploadService->shouldReceive('processPendingUploads')
            ->once()
            ->with(null)
            ->andReturn(3);
        $uploadService->shouldNotReceive('dispatchPendingUploads');

        $this->app->instance(UploadService::class, $uploadService);

        $this->artisan('efactura:upload', ['--sync' => true])
            ->expectsOutputToContain('Processed 3 upload(s).')
            ->assertExitCode(0);
    });

    it('queues uploads for specific CUI', function () {
        config(['efactura.enabled' => true, 'efactura.features.upload_invoices' => true]);

        $uploadService = Mockery::mock(UploadService::class);
        $uploadService->shouldReceive('dispatchPendingUploads')
            ->once()
            ->with('12345678')
            ->andReturn(1);

        $this->app->instance(UploadService::class, $uploadService);

        $this->artisan('efactura:upload', ['--cui' => '12345678'])
            ->assertExitCode(0);
    });

    it('strips RO prefix from CUI', function () {
        config(['efactura.enabled' => true, 'efactura.features.upload_invoices' => true]);

        $uploadService = Mockery::mock(UploadService::class);
        $uploadService->shouldReceive('dispatchPendingUploads')
            ->once()
            ->with('12345678')
            ->andReturn(0);

        $this->app->instance(UploadService::class, $uploadService);

        $this->artisan('efactura:upload', ['--cui' => 'RO12345678'])
            ->assertExitCode(0);
    });
});

describe('EfacturaStatusCommand', function () {
    it('fails when upload feature is disabled', function () {
        config(['efactura.enabled' => true, 'efactura.features.upload_invoices' => false]);

        $this->artisan('efactura:status')
            ->expectsOutput('e-Factura upload feature is disabled.')
            ->assertExitCode(1);
    });

    it('fails when upload not found', function () {
        config(['efactura.enabled' => true, 'efactura.features.upload_invoices' => true]);

        $this->artisan('efactura:status', ['--upload' => '999'])
            ->expectsOutput('Upload #999 not found.')
            ->assertExitCode(1);
    });

    it('checks status for specific upload', function () {
        config(['efactura.enabled' => true, 'efactura.features.upload_invoices' => true]);

        $upload = EfacturaUpload::create([
            'efactura_token_id' => $this->token->id,
            'uploadable_type' => 'App\\Models\\Invoice',
            'uploadable_id' => 1,
            'status' => UploadStatus::Processing,
            'upload_index' => 'IDX123',
            'standard' => 'UBL',
        ]);

        $downloadService = Mockery::mock(DownloadService::class);
        $downloadService->shouldReceive('checkStatus')
            ->once()
            ->with(Mockery::type(EfacturaUpload::class));

        $this->app->instance(DownloadService::class, $downloadService);

        $this->artisan('efactura:status', ['--upload' => $upload->id])
            ->expectsOutputToContain("Checking status for upload #{$upload->id}")
            ->assertExitCode(0);
    });

    it('downloads response if download_id exists', function () {
        config(['efactura.enabled' => true, 'efactura.features.upload_invoices' => true]);

        $upload = EfacturaUpload::create([
            'efactura_token_id' => $this->token->id,
            'uploadable_type' => 'App\\Models\\Invoice',
            'uploadable_id' => 1,
            'status' => UploadStatus::Completed,
            'download_id' => 'DL123',
            'standard' => 'UBL',
        ]);

        $downloadService = Mockery::mock(DownloadService::class);
        $downloadService->shouldReceive('checkStatus')->once();
        $downloadService->shouldReceive('downloadResponse')
            ->once()
            ->with(Mockery::type(EfacturaUpload::class));

        $this->app->instance(DownloadService::class, $downloadService);

        $this->artisan('efactura:status', ['--upload' => $upload->id])
            ->expectsOutput('Downloading response...')
            ->assertExitCode(0);
    });

    it('checks all statuses when no upload specified', function () {
        config(['efactura.enabled' => true, 'efactura.features.upload_invoices' => true]);

        $downloadService = Mockery::mock(DownloadService::class);
        $downloadService->shouldReceive('checkAllStatuses')
            ->once()
            ->with(null);
        $downloadService->shouldReceive('downloadAllResponses')
            ->once()
            ->with(null);

        $this->app->instance(DownloadService::class, $downloadService);

        $this->artisan('efactura:status')
            ->expectsOutput('Checking all upload statuses...')
            ->expectsOutput('Downloading pending responses...')
            ->assertExitCode(0);
    });

    it('filters by CUI when specified', function () {
        config(['efactura.enabled' => true, 'efactura.features.upload_invoices' => true]);

        $downloadService = Mockery::mock(DownloadService::class);
        $downloadService->shouldReceive('checkAllStatuses')
            ->once()
            ->with('12345678');
        $downloadService->shouldReceive('downloadAllResponses')
            ->once()
            ->with('12345678');

        $this->app->instance(DownloadService::class, $downloadService);

        $this->artisan('efactura:status', ['--cui' => '12345678'])
            ->assertExitCode(0);
    });
});

describe('EfacturaSyncCommand', function () {
    it('fails when sync feature is disabled', function () {
        config(['efactura.enabled' => true, 'efactura.features.sync_messages' => false]);

        $this->artisan('efactura:sync')
            ->expectsOutput('e-Factura sync feature is disabled.')
            ->assertExitCode(1);
    });

    it('syncs all messages when no CUI specified', function () {
        config(['efactura.enabled' => true, 'efactura.features.sync_messages' => true]);

        $messageSyncService = Mockery::mock(MessageSyncService::class);
        $messageSyncService->shouldReceive('syncAllMessages')->once();

        $downloadService = Mockery::mock(DownloadService::class);
        $tokenService = Mockery::mock(TokenService::class);

        $this->app->instance(MessageSyncService::class, $messageSyncService);
        $this->app->instance(DownloadService::class, $downloadService);
        $this->app->instance(TokenService::class, $tokenService);

        $this->artisan('efactura:sync')
            ->expectsOutput('Syncing messages from ANAF...')
            ->expectsOutput('Done.')
            ->assertExitCode(0);
    });

    it('syncs messages for specific CUI', function () {
        config(['efactura.enabled' => true, 'efactura.features.sync_messages' => true]);

        $messageSyncService = Mockery::mock(MessageSyncService::class);
        $messageSyncService->shouldReceive('syncMessages')
            ->once()
            ->with(Mockery::type(EfacturaToken::class));

        $tokenService = Mockery::mock(TokenService::class);
        $tokenService->shouldReceive('getToken')
            ->with('12345678')
            ->once()
            ->andReturn($this->token);

        $downloadService = Mockery::mock(DownloadService::class);

        $this->app->instance(MessageSyncService::class, $messageSyncService);
        $this->app->instance(TokenService::class, $tokenService);
        $this->app->instance(DownloadService::class, $downloadService);

        $this->artisan('efactura:sync', ['--cui' => '12345678'])
            ->assertExitCode(0);
    });

    it('fails when CUI token not found', function () {
        config(['efactura.enabled' => true, 'efactura.features.sync_messages' => true]);

        $messageSyncService = Mockery::mock(MessageSyncService::class);
        $tokenService = Mockery::mock(TokenService::class);
        $tokenService->shouldReceive('getToken')
            ->with('99999999')
            ->once()
            ->andReturn(null);

        $downloadService = Mockery::mock(DownloadService::class);

        $this->app->instance(MessageSyncService::class, $messageSyncService);
        $this->app->instance(TokenService::class, $tokenService);
        $this->app->instance(DownloadService::class, $downloadService);

        $this->artisan('efactura:sync', ['--cui' => '99999999'])
            ->expectsOutput('No active token found for CUI: 99999999')
            ->assertExitCode(1);
    });

    it('downloads invoices when --download flag is set', function () {
        config([
            'efactura.enabled' => true,
            'efactura.features.sync_messages' => true,
            'efactura.features.download_received' => true,
        ]);

        $messageSyncService = Mockery::mock(MessageSyncService::class);
        $messageSyncService->shouldReceive('syncAllMessages')->once();

        $downloadService = Mockery::mock(DownloadService::class);
        $downloadService->shouldReceive('downloadAllReceivedInvoices')
            ->once()
            ->with(null);

        $tokenService = Mockery::mock(TokenService::class);

        $this->app->instance(MessageSyncService::class, $messageSyncService);
        $this->app->instance(DownloadService::class, $downloadService);
        $this->app->instance(TokenService::class, $tokenService);

        $this->artisan('efactura:sync', ['--download' => true])
            ->expectsOutput('Downloading received invoices...')
            ->assertExitCode(0);
    });
});

describe('EfacturaAuthCommand', function () {
    it('shows existing token info and asks for confirmation', function () {
        $tokenService = Mockery::mock(TokenService::class);
        $this->app->instance(TokenService::class, $tokenService);

        $this->artisan('efactura:auth', ['cui' => '12345678'])
            ->expectsOutput('A token already exists for CUI: 12345678')
            ->expectsOutput('Status: Active')
            ->expectsConfirmation('Do you want to generate a new authorization URL anyway?', 'no')
            ->assertExitCode(0);
    });

    it('generates URL for new CUI', function () {
        // No existing token
        $this->token->delete();

        $tokenService = Mockery::mock(TokenService::class);
        $tokenService->shouldReceive('getAuthorizationUrl')
            ->with('99999999')
            ->once()
            ->andReturn('https://auth.example.com/oauth');

        $this->app->instance(TokenService::class, $tokenService);

        $this->artisan('efactura:auth', ['cui' => '99999999'])
            ->expectsOutput('Authorization URL for CUI: 99999999')
            ->expectsOutput('https://auth.example.com/oauth')
            ->assertExitCode(0);
    });

    it('strips RO prefix from CUI', function () {
        // No existing token - use different CUI
        $tokenService = Mockery::mock(TokenService::class);
        $tokenService->shouldReceive('getAuthorizationUrl')
            ->with('88888888')
            ->once()
            ->andReturn('https://auth.example.com');

        $this->app->instance(TokenService::class, $tokenService);

        $this->artisan('efactura:auth', ['cui' => 'RO88888888'])
            ->assertExitCode(0);
    });
});
