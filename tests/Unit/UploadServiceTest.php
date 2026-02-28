<?php

use BeeCoded\EFactura\Enums\UploadStatus;
use BeeCoded\EFactura\Events\InvoiceFailed;
use BeeCoded\EFactura\Events\InvoiceUploaded;
use BeeCoded\EFactura\Models\EfacturaToken;
use BeeCoded\EFactura\Models\EfacturaUpload;
use BeeCoded\EFactura\Services\TokenService;
use BeeCoded\EFactura\Services\UploadService;
use BeeCoded\EFactura\Tests\Fixtures\TestInvoice;
use BeeCoded\EFacturaSdk\Facades\UblBuilder;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

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
        'access_token' => 'test_access',
        'refresh_token' => 'test_refresh',
        'expires_at' => now()->addHour(),
        'is_active' => true,
    ]);

    $this->invoice = TestInvoice::create([
        'number' => 'INV-001',
        'cui' => '12345678',
        'total' => 100,
    ]);

    $this->tokenService = Mockery::mock(TokenService::class);
    $this->uploadService = new UploadService($this->tokenService);
});

describe('UploadService', function () {
    describe('queueUpload', function () {
        it('creates pending upload for valid model', function () {
            $this->tokenService->shouldReceive('getToken')
                ->with('12345678')
                ->once()
                ->andReturn($this->token);

            $upload = $this->uploadService->queueUpload($this->invoice);

            expect($upload)->toBeInstanceOf(EfacturaUpload::class)
                ->and($upload->status)->toBe(UploadStatus::Pending)
                ->and($upload->efactura_token_id)->toBe($this->token->id)
                ->and($upload->uploadable_type)->toBe(TestInvoice::class)
                ->and($upload->uploadable_id)->toBe($this->invoice->id)
                ->and($upload->standard)->toBe('UBL')
                ->and($upload->is_extern)->toBeFalse()
                ->and($upload->is_self_billed)->toBeFalse()
                ->and($upload->is_b2c)->toBeFalse();
        });

        it('creates upload with custom options', function () {
            $this->tokenService->shouldReceive('getToken')
                ->with('12345678')
                ->once()
                ->andReturn($this->token);

            $options = [
                'standard' => 'CII',
                'extern' => true,
                'self_billed' => true,
            ];

            $upload = $this->uploadService->queueUpload($this->invoice, $options);

            expect($upload->standard)->toBe('CII')
                ->and($upload->is_extern)->toBeTrue()
                ->and($upload->is_self_billed)->toBeTrue();
        });

        it('throws exception when no token found', function () {
            $this->tokenService->shouldReceive('getToken')
                ->with('12345678')
                ->once()
                ->andReturn(null);

            expect(fn () => $this->uploadService->queueUpload($this->invoice))
                ->toThrow(\RuntimeException::class, 'No active token found for CUI: 12345678');
        });
    });

    describe('queueB2CUpload', function () {
        it('creates B2C upload', function () {
            $this->tokenService->shouldReceive('getToken')
                ->with('12345678')
                ->once()
                ->andReturn($this->token);

            $upload = $this->uploadService->queueB2CUpload($this->invoice);

            expect($upload->is_b2c)->toBeTrue()
                ->and($upload->standard)->toBe('UBL')
                ->and($upload->status)->toBe(UploadStatus::Pending);
        });

        it('throws exception when no token found', function () {
            $this->tokenService->shouldReceive('getToken')
                ->with('12345678')
                ->once()
                ->andReturn(null);

            expect(fn () => $this->uploadService->queueB2CUpload($this->invoice))
                ->toThrow(\RuntimeException::class, 'No active token found for CUI: 12345678');
        });
    });

    describe('processUpload', function () {
        beforeEach(function () {
            $this->upload = EfacturaUpload::create([
                'efactura_token_id' => $this->token->id,
                'uploadable_type' => TestInvoice::class,
                'uploadable_id' => $this->invoice->id,
                'status' => UploadStatus::Pending,
                'standard' => 'UBL',
            ]);
        });

        it('skips non-pending uploads', function () {
            $this->upload->update(['status' => UploadStatus::Processing]);

            // Should not call any mocks
            $this->uploadService->processUpload($this->upload);

            // Verify status unchanged
            expect($this->upload->fresh()->status)->toBe(UploadStatus::Processing);
        });

        it('fails upload when token is missing', function () {
            Event::fake([InvoiceFailed::class]);

            // Create upload without token relationship
            $orphanUpload = EfacturaUpload::create([
                'efactura_token_id' => 999, // Non-existent token
                'uploadable_type' => TestInvoice::class,
                'uploadable_id' => $this->invoice->id,
                'status' => UploadStatus::Pending,
                'standard' => 'UBL',
            ]);

            $this->uploadService->processUpload($orphanUpload);

            $orphanUpload->refresh();
            expect($orphanUpload->status)->toBe(UploadStatus::Failed)
                ->and($orphanUpload->errors)->toContain('No associated token found for upload');

            Event::assertDispatched(InvoiceFailed::class);
        });

        it('processes upload successfully', function () {
            Event::fake([InvoiceUploaded::class]);
            Storage::fake('local');

            // Use swap to completely replace the facade
            UblBuilder::swap(new class
            {
                public function generateInvoiceXml($data): string
                {
                    return '<Invoice>test</Invoice>';
                }
            });

            $mockResponse = new class
            {
                public function isSuccessful(): bool
                {
                    return true;
                }

                public string $indexIncarcare = 'INDEX123';
            };

            $this->tokenService->shouldReceive('executeWithClient')
                ->once()
                ->andReturn($mockResponse);

            $this->uploadService->processUpload($this->upload);

            $this->upload->refresh();
            expect($this->upload->status)->toBe(UploadStatus::Processing)
                ->and($this->upload->upload_index)->toBe('INDEX123')
                ->and($this->upload->uploaded_at)->not->toBeNull()
                ->and($this->upload->xml_path)->not->toBeNull();

            Event::assertDispatched(InvoiceUploaded::class);
        });

        it('handles upload failure response', function () {
            Event::fake([InvoiceFailed::class]);
            Storage::fake('local');

            UblBuilder::swap(new class
            {
                public function generateInvoiceXml($data): string
                {
                    return '<Invoice>test</Invoice>';
                }
            });

            $mockResponse = new class
            {
                public function isSuccessful(): bool
                {
                    return false;
                }

                public array $errors = ['Validation error', 'Another error'];
            };

            $this->tokenService->shouldReceive('executeWithClient')
                ->once()
                ->andReturn($mockResponse);

            $this->uploadService->processUpload($this->upload);

            $this->upload->refresh();
            expect($this->upload->status)->toBe(UploadStatus::Failed)
                ->and($this->upload->errors)->toBe(['Validation error', 'Another error']);

            Event::assertDispatched(InvoiceFailed::class);
        });

        it('handles exceptions during upload', function () {
            Event::fake([InvoiceFailed::class]);
            Storage::fake('local');

            UblBuilder::swap(new class
            {
                public function generateInvoiceXml($data): string
                {
                    return '<Invoice>test</Invoice>';
                }
            });

            $this->tokenService->shouldReceive('executeWithClient')
                ->once()
                ->andThrow(new \Exception('API Error'));

            $this->uploadService->processUpload($this->upload);

            $this->upload->refresh();
            expect($this->upload->status)->toBe(UploadStatus::Failed)
                ->and($this->upload->errors)->toContain('API Error');

            Event::assertDispatched(InvoiceFailed::class);
        });

        it('handles missing uploadable model', function () {
            Event::fake([InvoiceFailed::class]);

            // Delete the invoice
            $this->invoice->delete();

            $this->uploadService->processUpload($this->upload);

            $this->upload->refresh();
            expect($this->upload->status)->toBe(UploadStatus::Failed)
                ->and($this->upload->errors)->toContain('Uploadable model must implement EFacturaUploadableInterface');

            Event::assertDispatched(InvoiceFailed::class);
        });
    });

    describe('markUploadAs methods', function () {
        beforeEach(function () {
            $this->upload = EfacturaUpload::create([
                'efactura_token_id' => $this->token->id,
                'uploadable_type' => TestInvoice::class,
                'uploadable_id' => $this->invoice->id,
                'status' => UploadStatus::Pending,
                'standard' => 'UBL',
            ]);
        });

        it('marks upload as uploading', function () {
            $this->uploadService->markUploadAsUploading($this->upload);

            expect($this->upload->fresh()->status)->toBe(UploadStatus::Uploading);
        });

        it('marks upload as processing', function () {
            $this->uploadService->markUploadAsProcessing($this->upload, 'INDEX456');

            $upload = $this->upload->fresh();
            expect($upload->status)->toBe(UploadStatus::Processing)
                ->and($upload->upload_index)->toBe('INDEX456')
                ->and($upload->uploaded_at)->not->toBeNull();
        });

        it('marks upload as completed', function () {
            $this->uploadService->markUploadAsCompleted($this->upload, 'DOWNLOAD789');

            $upload = $this->upload->fresh();
            expect($upload->status)->toBe(UploadStatus::Completed)
                ->and($upload->download_id)->toBe('DOWNLOAD789')
                ->and($upload->processed_at)->not->toBeNull();
        });

        it('marks upload as failed', function () {
            $errors = ['Error 1', 'Error 2'];
            $this->uploadService->markUploadAsFailed($this->upload, $errors);

            $upload = $this->upload->fresh();
            expect($upload->status)->toBe(UploadStatus::Failed)
                ->and($upload->errors)->toBe($errors)
                ->and($upload->processed_at)->not->toBeNull();
        });
    });

    describe('resetForRateLimit', function () {
        beforeEach(function () {
            $this->upload = EfacturaUpload::create([
                'efactura_token_id' => $this->token->id,
                'uploadable_type' => TestInvoice::class,
                'uploadable_id' => $this->invoice->id,
                'status' => UploadStatus::Failed,
                'standard' => 'UBL',
                'errors' => ['Rate limit exceeded'],
                'processed_at' => now(),
            ]);
        });

        it('resets a failed upload back to pending', function () {
            $result = $this->uploadService->resetForRateLimit($this->upload);

            expect($result)->toBeTrue();

            $upload = $this->upload->fresh();
            expect($upload->status)->toBe(UploadStatus::Pending)
                ->and($upload->errors)->toBeNull()
                ->and($upload->processed_at)->toBeNull();
        });

        it('returns false when upload is no longer in failed state', function () {
            $this->upload->update(['status' => UploadStatus::Processing]);

            $result = $this->uploadService->resetForRateLimit($this->upload);

            expect($result)->toBeFalse();
            expect($this->upload->fresh()->status)->toBe(UploadStatus::Processing);
        });

        it('returns false for pending uploads', function () {
            $this->upload->update(['status' => UploadStatus::Pending]);

            $result = $this->uploadService->resetForRateLimit($this->upload);

            expect($result)->toBeFalse();
        });

        it('returns false for completed uploads', function () {
            $this->upload->update(['status' => UploadStatus::Completed]);

            $result = $this->uploadService->resetForRateLimit($this->upload);

            expect($result)->toBeFalse();
        });
    });

    describe('processPendingUploads', function () {
        it('processes all pending uploads when no CUI specified', function () {
            Storage::fake('local');
            Event::fake();

            UblBuilder::swap(new class
            {
                public function generateInvoiceXml($data): string
                {
                    return '<Invoice/>';
                }
            });

            $mockResponse = new class
            {
                public function isSuccessful(): bool
                {
                    return true;
                }

                public string $indexIncarcare = 'INDEX';
            };

            $this->tokenService->shouldReceive('getToken')->andReturn($this->token);
            $this->tokenService->shouldReceive('executeWithClient')->andReturn($mockResponse);

            // Create multiple pending uploads
            $upload1 = EfacturaUpload::create([
                'efactura_token_id' => $this->token->id,
                'uploadable_type' => TestInvoice::class,
                'uploadable_id' => $this->invoice->id,
                'status' => UploadStatus::Pending,
                'standard' => 'UBL',
            ]);

            $invoice2 = TestInvoice::create(['number' => 'INV-002', 'cui' => '12345678']);
            $upload2 = EfacturaUpload::create([
                'efactura_token_id' => $this->token->id,
                'uploadable_type' => TestInvoice::class,
                'uploadable_id' => $invoice2->id,
                'status' => UploadStatus::Pending,
                'standard' => 'UBL',
            ]);

            $this->uploadService->processPendingUploads();

            expect($upload1->fresh()->status)->toBe(UploadStatus::Processing);
            expect($upload2->fresh()->status)->toBe(UploadStatus::Processing);
        });

        it('filters by CUI when specified', function () {
            $this->tokenService->shouldReceive('getToken')
                ->with('12345678')
                ->once()
                ->andReturn($this->token);

            // No pending uploads for this token, so nothing should process
            $this->uploadService->processPendingUploads('12345678');

            // Just verify no exception thrown
            expect(true)->toBeTrue();
        });

        it('returns early when CUI token not found', function () {
            $this->tokenService->shouldReceive('getToken')
                ->with('99999999')
                ->once()
                ->andReturn(null);

            // Should not throw, just log and return
            $this->uploadService->processPendingUploads('99999999');

            expect(true)->toBeTrue();
        });
    });

    describe('generateXml', function () {
        it('generates XML from model', function () {
            UblBuilder::swap(new class
            {
                public function generateInvoiceXml($data): string
                {
                    return '<Invoice>Generated XML</Invoice>';
                }
            });

            $xml = $this->uploadService->generateXml($this->invoice);

            expect($xml)->toBe('<Invoice>Generated XML</Invoice>');
        });
    });
});
