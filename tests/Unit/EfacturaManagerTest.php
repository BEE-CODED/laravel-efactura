<?php

use BeeCoded\EFactura\EfacturaManager;
use BeeCoded\EFactura\Enums\UploadStatus;
use BeeCoded\EFactura\Models\EfacturaToken;
use BeeCoded\EFactura\Models\EfacturaUpload;
use BeeCoded\EFactura\Services\DownloadService;
use BeeCoded\EFactura\Services\MessageSyncService;
use BeeCoded\EFactura\Services\TokenService;
use BeeCoded\EFactura\Services\UploadService;
use BeeCoded\EFactura\Tests\Fixtures\TestInvoice;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    // Create test_invoices table
    if (!Schema::hasTable('test_invoices')) {
        Schema::create('test_invoices', function ($table) {
            $table->id();
            $table->string('number');
            $table->string('cui');
            $table->decimal('total', 10, 2)->nullable();
        });
    }

    $this->token = EfacturaToken::create([
        'cui' => '12345678',
        'access_token' => 'test',
        'refresh_token' => 'test',
        'expires_at' => now()->addHour(),
        'is_active' => true,
    ]);

    $this->tokenService = Mockery::mock(TokenService::class);
    $this->uploadService = Mockery::mock(UploadService::class);
    $this->downloadService = Mockery::mock(DownloadService::class);
    $this->messageSyncService = Mockery::mock(MessageSyncService::class);

    $this->manager = new EfacturaManager(
        $this->tokenService,
        $this->uploadService,
        $this->downloadService,
        $this->messageSyncService,
    );
});

describe('EfacturaManager', function () {
    describe('Token Management', function () {
        it('delegates getToken to TokenService', function () {
            $this->tokenService->shouldReceive('getToken')
                ->with('12345678')
                ->once()
                ->andReturn($this->token);

            $result = $this->manager->getToken('12345678');

            expect($result)->toBe($this->token);
        });

        it('delegates getAuthorizationUrl to TokenService', function () {
            $this->tokenService->shouldReceive('getAuthorizationUrl')
                ->with('12345678')
                ->once()
                ->andReturn('https://auth.example.com');

            $result = $this->manager->getAuthorizationUrl('12345678');

            expect($result)->toBe('https://auth.example.com');
        });

        it('delegates client creation to TokenService', function () {
            $mockClient = Mockery::mock(\BeeCoded\EFacturaSdk\Services\ApiClients\EFacturaClient::class);

            $this->tokenService->shouldReceive('createClientForCui')
                ->with('12345678')
                ->once()
                ->andReturn($mockClient);

            $result = $this->manager->client('12345678');

            expect($result)->toBe($mockClient);
        });
    });

    describe('Upload Operations', function () {
        it('delegates queueUpload to UploadService', function () {
            $invoice = TestInvoice::create([
                'number' => 'INV-001',
                'cui' => '12345678',
                'total' => 100,
            ]);

            $upload = new EfacturaUpload([
                'id' => 1,
                'status' => UploadStatus::Pending,
            ]);

            $this->uploadService->shouldReceive('queueUpload')
                ->with($invoice, null)
                ->once()
                ->andReturn($upload);

            $result = $this->manager->queueUpload($invoice);

            expect($result)->toBe($upload);
        });

        it('delegates queueUpload with options to UploadService', function () {
            $invoice = TestInvoice::create([
                'number' => 'INV-002',
                'cui' => '12345678',
                'total' => 200,
            ]);

            $options = ['is_extern' => true];
            $upload = new EfacturaUpload(['id' => 2]);

            $this->uploadService->shouldReceive('queueUpload')
                ->with($invoice, $options)
                ->once()
                ->andReturn($upload);

            $result = $this->manager->queueUpload($invoice, $options);

            expect($result)->toBe($upload);
        });

        it('delegates queueB2CUpload to UploadService', function () {
            $invoice = TestInvoice::create([
                'number' => 'INV-003',
                'cui' => '12345678',
                'total' => 300,
            ]);

            $upload = new EfacturaUpload(['id' => 3, 'is_b2c' => true]);

            $this->uploadService->shouldReceive('queueB2CUpload')
                ->with($invoice)
                ->once()
                ->andReturn($upload);

            $result = $this->manager->queueB2CUpload($invoice);

            expect($result)->toBe($upload);
        });

        it('delegates processUpload to UploadService', function () {
            $upload = EfacturaUpload::create([
                'efactura_token_id' => $this->token->id,
                'uploadable_type' => 'App\\Models\\Invoice',
                'uploadable_id' => 1,
                'status' => UploadStatus::Pending,
                'standard' => 'UBL',
            ]);

            $this->uploadService->shouldReceive('processUpload')
                ->with($upload)
                ->once();

            $this->manager->processUpload($upload);
        });
    });

    describe('Service Access', function () {
        it('returns TokenService instance', function () {
            expect($this->manager->tokenService())->toBe($this->tokenService);
        });

        it('returns UploadService instance', function () {
            expect($this->manager->uploadService())->toBe($this->uploadService);
        });

        it('returns DownloadService instance', function () {
            expect($this->manager->downloadService())->toBe($this->downloadService);
        });

        it('returns MessageSyncService instance', function () {
            expect($this->manager->messageSyncService())->toBe($this->messageSyncService);
        });
    });
});
