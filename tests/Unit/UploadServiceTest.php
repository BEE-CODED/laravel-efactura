<?php

use BeeCoded\EFactura\Enums\FailureReason;
use BeeCoded\EFactura\Enums\UploadStatus;
use BeeCoded\EFactura\Events\InvoiceFailed;
use BeeCoded\EFactura\Events\InvoiceUploaded;
use BeeCoded\EFactura\Models\EfacturaToken;
use BeeCoded\EFactura\Models\EfacturaUpload;
use BeeCoded\EFactura\Services\TokenService;
use BeeCoded\EFactura\Services\UploadService;
use BeeCoded\EFactura\Tests\Fixtures\TestInvoice;
use BeeCoded\EFacturaSdk\Data\Response\UploadResponseData;
use BeeCoded\EFacturaSdk\Exceptions\AuthenticationException;
use BeeCoded\EFacturaSdk\Facades\UblBuilder;
use BeeCoded\EFacturaSdk\Services\ApiClients\EFacturaClient;
use BeeCoded\EFacturaSdk\Services\RateLimiter;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
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
    // No grant is in its refresh cooldown unless a test says so.
    $this->tokenService->shouldReceive('refreshCooldownSeconds')->andReturn(0)->byDefault();
    $this->tokenService->shouldReceive('isGrantFailure')->passthru()->byDefault();
    $this->uploadService = new UploadService($this->tokenService, app(RateLimiter::class));
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
                ->toThrow(RuntimeException::class, 'No active token found for CUI: 12345678');
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

        it('stores the requested standard', function () {
            $this->tokenService->shouldReceive('getToken')
                ->with('12345678')
                ->once()
                ->andReturn($this->token);

            $upload = $this->uploadService->queueB2CUpload($this->invoice, ['standard' => 'CN']);

            expect($upload->is_b2c)->toBeTrue()
                ->and($upload->standard)->toBe('CN');
        });

        it('throws exception when no token found', function () {
            $this->tokenService->shouldReceive('getToken')
                ->with('12345678')
                ->once()
                ->andReturn(null);

            expect(fn () => $this->uploadService->queueB2CUpload($this->invoice))
                ->toThrow(RuntimeException::class, 'No active token found for CUI: 12345678');
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

            // Its own model: an upload row is now unique per uploadable, and the
            // beforeEach already registered one for $this->invoice.
            $orphanInvoice = TestInvoice::create([
                'number' => 'INV-ORPHAN',
                'cui' => '12345678',
                'total' => 100,
            ]);

            // token_id is NOT NULL behind a cascade FK, so a genuinely orphaned upload
            // cannot exist. Create a valid row, then null the loaded relation to exercise
            // processUpload()'s `if (! $upload->token)` guard without dangling data.
            $orphanUpload = EfacturaUpload::create([
                'efactura_token_id' => $this->token->id,
                'uploadable_type' => TestInvoice::class,
                'uploadable_id' => $orphanInvoice->id,
                'status' => UploadStatus::Pending,
                'standard' => 'UBL',
            ]);
            $orphanUpload->setRelation('token', null);

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

            $mockResponse = UploadResponseData::fromAnafResponse(['ExecutionStatus' => '0', 'index_incarcare' => '5001234']);

            $this->tokenService->shouldReceive('executeWithClient')
                ->once()
                ->andReturn($mockResponse);

            $this->uploadService->processUpload($this->upload);

            $this->upload->refresh();
            expect($this->upload->status)->toBe(UploadStatus::Processing)
                ->and($this->upload->upload_index)->toBe('5001234')
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

            $mockResponse = UploadResponseData::fromAnafResponse([
                'ExecutionStatus' => '1',
                'Errors' => ['Validation error', 'Another error'],
            ]);

            $this->tokenService->shouldReceive('executeWithClient')
                ->once()
                ->andReturn($mockResponse);

            $this->uploadService->processUpload($this->upload);

            $this->upload->refresh();
            expect($this->upload->status)->toBe(UploadStatus::Failed)
                ->and($this->upload->errors)->toBe(['Validation error', 'Another error']);

            Event::assertDispatched(InvoiceFailed::class);
        });

        /**
         * An unrecognised throwable from the transmission phase may have been raised
         * AFTER ANAF accepted the document — we cannot tell. It is parked as
         * Indeterminate (never auto-resubmitted) rather than either retried blindly
         * (double-filing risk) or written off as a validation failure.
         *
         * The mock must actually INVOKE the operation: "during transmission" is precisely
         * the state of having handed the document to a live client. A mock that throws
         * without ever calling the closure describes the opposite case (see below) and
         * cannot tell the two apart.
         */
        it('parks an unrecognised exception during upload as indeterminate', function () {
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
                ->andReturnUsing(fn ($token, $operation) => $operation(new class
                {
                    public function uploadDocument($xml, $options)
                    {
                        throw new Exception('API Error');
                    }

                    public function uploadB2CDocument($xml, $options)
                    {
                        throw new Exception('API Error');
                    }
                }));

            $this->uploadService->processUpload($this->upload);

            $this->upload->refresh();
            // Not an SDK exception: its message may hold anything, so the row names its class.
            expect($this->upload->status)->toBe(UploadStatus::Failed)
                ->and($this->upload->failure_reason)->toBe(FailureReason::Indeterminate)
                ->and($this->upload->errors)->toBe([
                    __('efactura::messages.upload_outcome_no_answer'),
                    __('efactura::messages.local_failure', ['class' => 'Exception']),
                ]);

            Event::assertDispatched(InvoiceFailed::class);
        });

        /**
         * The other side of that line. An unrecognised throwable raised BEFORE the client
         * was ever handed the document (reading an unmigrated token throws DecryptException;
         * TokenService throws RuntimeException for a deactivated token) proves the document
         * never entered ANAF's pipeline. Writing those off as Indeterminate — "never
         * auto-resubmitted, requires human reconciliation" — condemned every upload filed
         * during a deploy window to manual reconciliation, and the migration landing
         * afterwards did not unpark them.
         */
        it('leaves the upload pending after an unrecognised exception raised before transmission', function () {
            Event::fake([InvoiceFailed::class]);
            Storage::fake('local');

            UblBuilder::swap(new class
            {
                public function generateInvoiceXml($data): string
                {
                    return '<Invoice>test</Invoice>';
                }
            });

            // Throws WITHOUT invoking the operation: the client never got the document.
            $this->tokenService->shouldReceive('executeWithClient')
                ->once()
                ->andThrow(new RuntimeException('Token for CUI 12345678 has been deactivated'));

            $this->uploadService->processUpload($this->upload);

            $this->upload->refresh();
            expect($this->upload->status)->toBe(UploadStatus::Pending)
                ->and($this->upload->failure_reason)->toBeNull()
                ->and($this->upload->status_failures)->toBe(0);

            // Not a terminal outcome: the pipeline will re-drive it.
            Event::assertNotDispatched(InvoiceFailed::class);
        });

        /**
         * An auth blip provably never reaches ANAF's filing pipeline, so it must stay
         * pending instead of permanently failing a legal filing. This is the exact
         * shape that turned the (now-fixed) token deadlock into permanent data loss.
         */
        it('leaves the upload pending and uncounted after an authentication failure', function () {
            Event::fake([InvoiceFailed::class]);
            Storage::fake('local');

            UblBuilder::swap(new class
            {
                public function generateInvoiceXml($data): string
                {
                    return '<Invoice>test</Invoice>';
                }
            });

            // Thrown by the client the operation already holds: the SDK's in-call refresh.
            $client = Mockery::mock(EFacturaClient::class);
            $client->shouldReceive('uploadDocument')->once()->andThrow(new AuthenticationException('Token refresh lock timeout'));
            $this->tokenService->shouldReceive('executeWithClient')
                ->once()
                ->andReturnUsing(fn ($token, Closure $operation) => $operation($client));

            $this->uploadService->processUpload($this->upload);

            $this->upload->refresh();
            expect($this->upload->status)->toBe(UploadStatus::Pending)
                ->and($this->upload->failure_reason)->toBeNull()
                ->and($this->upload->status_failures)->toBe(0)
                ->and($this->upload->errors)->toBe(['Token refresh lock timeout']);

            // Not a terminal outcome: the pipeline will re-drive it.
            Event::assertNotDispatched(InvoiceFailed::class);
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
