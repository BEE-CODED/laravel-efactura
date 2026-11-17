<?php

use BeeCoded\EFactura\Enums\FailureReason;
use BeeCoded\EFactura\Enums\StatusCheckOutcome;
use BeeCoded\EFactura\Enums\UploadStatus;
use BeeCoded\EFactura\Events\InvoiceFailed;
use BeeCoded\EFactura\Events\InvoiceProcessed;
use BeeCoded\EFactura\Events\InvoiceReceived;
use BeeCoded\EFactura\Models\EfacturaMessage;
use BeeCoded\EFactura\Models\EfacturaToken;
use BeeCoded\EFactura\Models\EfacturaUpload;
use BeeCoded\EFactura\Services\DownloadService;
use BeeCoded\EFactura\Services\TokenService;
use BeeCoded\EFacturaSdk\Data\Response\DownloadResponseData;
use BeeCoded\EFacturaSdk\Data\Response\StatusResponseData;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->token = EfacturaToken::create([
        'cui' => '12345678',
        'access_token' => 'test_access',
        'refresh_token' => 'test_refresh',
        'expires_at' => now()->addHour(),
        'is_active' => true,
    ]);

    // Partial, so DownloadService reads failures through the real isGrantFailure().
    $this->tokenService = Mockery::mock(TokenService::class)->makePartial();
    // No grant is in its refresh cooldown unless a test says so.
    $this->tokenService->shouldReceive('refreshCooldownSeconds')->andReturn(0)->byDefault();
    $this->downloadService = new DownloadService($this->tokenService);
});

describe('DownloadService', function () {
    describe('checkStatus', function () {
        it('skips upload without upload_index', function () {
            $upload = EfacturaUpload::create([
                'efactura_token_id' => $this->token->id,
                'uploadable_type' => 'App\\Models\\Invoice',
                'uploadable_id' => 1,
                'status' => UploadStatus::Processing,
                'upload_index' => null,
                'standard' => 'UBL',
            ]);

            expect($this->downloadService->checkStatus($upload))->toBe(StatusCheckOutcome::NotPollable);
        });

        it('skips terminal status uploads', function () {
            $upload = EfacturaUpload::create([
                'efactura_token_id' => $this->token->id,
                'uploadable_type' => 'App\\Models\\Invoice',
                'uploadable_id' => 1,
                'status' => UploadStatus::Completed,
                'upload_index' => 'INDEX123',
                'standard' => 'UBL',
            ]);

            expect($this->downloadService->checkStatus($upload))->toBe(StatusCheckOutcome::NotPollable);
        });

        it('skips upload with missing token', function () {
            // token_id is NOT NULL behind a cascade FK, so a genuinely orphaned upload
            // cannot exist. Create a valid row, then null the loaded relation to exercise
            // the defensive `if (! $upload->token)` guard without inserting dangling data.
            $upload = EfacturaUpload::create([
                'efactura_token_id' => $this->token->id,
                'uploadable_type' => 'App\\Models\\Invoice',
                'uploadable_id' => 1,
                'status' => UploadStatus::Processing,
                'upload_index' => 'INDEX123',
                'standard' => 'UBL',
            ]);
            $upload->setRelation('token', null);

            expect($this->downloadService->checkStatus($upload))->toBe(StatusCheckOutcome::NotPollable);
        });

        it('marks upload as completed when ready', function () {
            $upload = EfacturaUpload::create([
                'efactura_token_id' => $this->token->id,
                'uploadable_type' => 'App\\Models\\Invoice',
                'uploadable_id' => 1,
                'status' => UploadStatus::Processing,
                'upload_index' => 'INDEX123',
                'standard' => 'UBL',
            ]);

            $this->tokenService->shouldReceive('executeWithClient')
                ->once()
                ->andReturn(StatusResponseData::fromAnafResponse([
                    'stare' => 'ok',
                    'id_descarcare' => '3001130456',
                ]));

            $this->downloadService->checkStatus($upload);

            expect($upload->fresh()->status)->toBe(UploadStatus::Completed)
                ->and($upload->fresh()->download_id)->toBe('3001130456');
        });

        it('marks upload as failed when processing failed', function () {
            Event::fake([InvoiceFailed::class]);

            $upload = EfacturaUpload::create([
                'efactura_token_id' => $this->token->id,
                'uploadable_type' => 'App\\Models\\Invoice',
                'uploadable_id' => 1,
                'status' => UploadStatus::Processing,
                'upload_index' => 'INDEX123',
                'standard' => 'UBL',
            ]);

            $this->tokenService->shouldReceive('executeWithClient')
                ->once()
                ->andReturn(StatusResponseData::fromAnafResponse([
                    'stare' => 'nok',
                    'id_descarcare' => '3001130123',
                    'Errors' => ['Processing error'],
                ]));

            $this->downloadService->checkStatus($upload);

            // ANAF processed the document and rejected it: a terminal validation
            // verdict, which must be recorded as such so the retry job never
            // re-submits it.
            expect($upload->fresh()->status)->toBe(UploadStatus::Failed)
                ->and($upload->fresh()->failure_reason)->toBe(FailureReason::Validation)
                ->and($upload->fresh()->errors)->toBe(['Processing error'])
                ->and($upload->fresh()->download_id)->toBe('3001130123');

            Event::assertDispatched(InvoiceFailed::class);
        });

        /**
         * Uses the REAL StatusResponseData. The hand-rolled anonymous fake this
         * replaces declared only isReady()/isFailed() — no isInProgress(), which the
         * SDK's DTO has always had. Once checkStatus() started asking isInProgress(),
         * the fake raised an undefined-method Error that the service's catch swallowed,
         * leaving the row Processing and passing this test for entirely the wrong
         * reason: it would have gone on "proving" the in-progress no-op long after the
         * in-progress branch stopped being reached at all.
         */
        it('does nothing when still in progress', function () {
            $upload = EfacturaUpload::create([
                'efactura_token_id' => $this->token->id,
                'uploadable_type' => 'App\\Models\\Invoice',
                'uploadable_id' => 1,
                'status' => UploadStatus::Processing,
                'upload_index' => 'INDEX123',
                'standard' => 'UBL',
            ]);

            $this->tokenService->shouldReceive('executeWithClient')
                ->once()
                ->andReturn(StatusResponseData::fromAnafResponse(['stare' => 'in prelucrare']));

            // Should not mark anything
            $this->downloadService->checkStatus($upload);

            expect($upload->fresh()->status)->toBe(UploadStatus::Processing);
            expect($upload->fresh()->failure_reason)->toBeNull();
        });

        /**
         * ANAF answers an unknown index_incarcare with 200 + {"eroare"}, which parses
         * to stare = null: isReady(), isFailed() and isInProgress() are all false.
         */
        it('parks an upload whose status ANAF does not recognise', function () {
            Event::fake([InvoiceFailed::class]);

            $upload = EfacturaUpload::create([
                'efactura_token_id' => $this->token->id,
                'uploadable_type' => 'App\\Models\\Invoice',
                'uploadable_id' => 1,
                'status' => UploadStatus::Processing,
                'upload_index' => 'INDEX123',
                'standard' => 'UBL',
            ]);

            $this->tokenService->shouldReceive('executeWithClient')
                ->once()
                ->andReturn(StatusResponseData::fromAnafResponse(['eroare' => 'Nu exista niciun upload']));

            $this->downloadService->checkStatus($upload);

            expect($upload->fresh()->status)->toBe(UploadStatus::Failed)
                ->and($upload->fresh()->failure_reason)->toBe(FailureReason::Indeterminate);

            Event::assertDispatched(InvoiceFailed::class);
        });

        it('handles exceptions gracefully', function () {
            $upload = EfacturaUpload::create([
                'efactura_token_id' => $this->token->id,
                'uploadable_type' => 'App\\Models\\Invoice',
                'uploadable_id' => 1,
                'status' => UploadStatus::Processing,
                'upload_index' => 'INDEX123',
                'standard' => 'UBL',
            ]);

            $this->tokenService->shouldReceive('executeWithClient')
                ->once()
                ->andThrow(new Exception('API Error'));

            // Should not throw, and a failure before the request says nothing about the row.
            expect($this->downloadService->checkStatus($upload))->toBe(StatusCheckOutcome::Failed);

            expect($upload->fresh()->status)->toBe(UploadStatus::Processing)
                ->and($upload->fresh()->status_failures)->toBe(0);
        });
    });

    describe('downloadResponse', function () {
        it('skips upload without download_id', function () {
            $upload = EfacturaUpload::create([
                'efactura_token_id' => $this->token->id,
                'uploadable_type' => 'App\\Models\\Invoice',
                'uploadable_id' => 1,
                'status' => UploadStatus::Completed,
                'download_id' => null,
                'standard' => 'UBL',
            ]);

            $this->tokenService->shouldNotReceive('executeWithClient');

            $this->downloadService->downloadResponse($upload);

            expect($upload->fresh()->response_attempts)->toBe(0);
        });

        it('skips upload with existing response_path', function () {
            $upload = EfacturaUpload::create([
                'efactura_token_id' => $this->token->id,
                'uploadable_type' => 'App\\Models\\Invoice',
                'uploadable_id' => 1,
                'status' => UploadStatus::Completed,
                'download_id' => 'DL123',
                'response_path' => 'existing/path.zip',
                'standard' => 'UBL',
            ]);

            $this->tokenService->shouldNotReceive('executeWithClient');

            $this->downloadService->downloadResponse($upload);

            expect($upload->fresh()->response_attempts)->toBe(0);
        });

        it('skips upload with missing token', function () {
            // See the checkStatus case above: a real orphaned upload cannot exist, so
            // create a valid row and null the loaded relation to hit the token guard.
            $upload = EfacturaUpload::create([
                'efactura_token_id' => $this->token->id,
                'uploadable_type' => 'App\\Models\\Invoice',
                'uploadable_id' => 1,
                'status' => UploadStatus::Completed,
                'download_id' => 'DL123',
                'standard' => 'UBL',
            ]);
            $upload->setRelation('token', null);

            Log::spy();
            $this->downloadService->downloadResponse($upload);
            Log::shouldHaveReceived('error')->with('EFactura: Upload has no associated token', ['upload_id' => $upload->id])->once();
        });

        it('downloads and stores response', function () {
            Event::fake([InvoiceProcessed::class]);
            Storage::fake('local');

            $upload = EfacturaUpload::create([
                'efactura_token_id' => $this->token->id,
                'uploadable_type' => 'App\\Models\\Invoice',
                'uploadable_id' => 1,
                'status' => UploadStatus::Completed,
                'download_id' => 'DL123',
                'standard' => 'UBL',
            ]);

            $this->tokenService->shouldReceive('executeWithClient')
                ->once()
                ->andReturn(new DownloadResponseData(content: responseZip(), contentType: 'application/zip'));

            $this->downloadService->downloadResponse($upload);

            $upload->refresh();
            expect($upload->response_path)->not->toBeNull()
                ->and($upload->response_path)->toContain('responses');

            Event::assertDispatched(InvoiceProcessed::class);
        });

        it('handles exceptions gracefully', function () {
            $upload = EfacturaUpload::create([
                'efactura_token_id' => $this->token->id,
                'uploadable_type' => 'App\\Models\\Invoice',
                'uploadable_id' => 1,
                'status' => UploadStatus::Completed,
                'download_id' => 'DL123',
                'standard' => 'UBL',
            ]);

            $this->tokenService->shouldReceive('executeWithClient')
                ->once()
                ->andThrow(new Exception('Download failed'));

            // Should not throw
            expect($this->downloadService->downloadResponse($upload))->toBeFalse();

            // No response_path set, and no failure recorded against it
            expect($upload->fresh()->response_path)->toBeNull()
                ->and($upload->fresh()->response_failed_at)->toBeNull();
        });
    });

    describe('downloadMessage', function () {
        it('skips already downloaded messages', function () {
            $message = EfacturaMessage::create([
                'efactura_token_id' => $this->token->id,
                'message_id' => 'MSG123',
                'type' => 'received',
                'download_id' => 'DL123',
                'is_downloaded' => true,
            ]);

            $this->tokenService->shouldNotReceive('executeWithClient');

            $this->downloadService->downloadMessage($message);

            expect($message->fresh()->download_attempts)->toBe(0);
        });

        it('skips messages without download_id', function () {
            $message = EfacturaMessage::create([
                'efactura_token_id' => $this->token->id,
                'message_id' => 'MSG123',
                'type' => 'received',
                'download_id' => null,
                'is_downloaded' => false,
            ]);

            $this->tokenService->shouldNotReceive('executeWithClient');

            $this->downloadService->downloadMessage($message);

            expect($message->fresh()->download_attempts)->toBe(0);
        });

        it('skips messages with missing token', function () {
            // See the checkStatus case above: a real orphaned message cannot exist, so
            // create a valid row and null the loaded relation to hit the token guard.
            $message = EfacturaMessage::create([
                'efactura_token_id' => $this->token->id,
                'message_id' => 'MSG123',
                'type' => 'received',
                'download_id' => 'DL123',
                'is_downloaded' => false,
            ]);
            $message->setRelation('token', null);

            Log::spy();
            $this->downloadService->downloadMessage($message);
            Log::shouldHaveReceived('error')->with('EFactura: Message has no associated token', ['id' => $message->id, 'message_id' => 'MSG123'])->once();
        });

        it('downloads message and fires event', function () {
            Event::fake([InvoiceReceived::class]);
            Storage::fake('local');

            $message = EfacturaMessage::create([
                'efactura_token_id' => $this->token->id,
                'message_id' => 'MSG123',
                'type' => 'received',
                'download_id' => 'DL123',
                'is_downloaded' => false,
            ]);

            $this->tokenService->shouldReceive('executeWithClient')
                ->once()
                ->andReturn(new DownloadResponseData(content: responseZip(), contentType: 'application/zip'));

            $this->downloadService->downloadMessage($message);

            $message->refresh();
            expect($message->is_downloaded)->toBeTrue()
                ->and($message->xml_path)->not->toBeNull();

            Event::assertDispatched(InvoiceReceived::class);
        });

        it('handles exceptions gracefully', function () {
            $message = EfacturaMessage::create([
                'efactura_token_id' => $this->token->id,
                'message_id' => 'MSG123',
                'type' => 'received',
                'download_id' => 'DL123',
                'is_downloaded' => false,
            ]);

            $this->tokenService->shouldReceive('executeWithClient')
                ->once()
                ->andThrow(new Exception('Download failed'));

            // Should not throw
            expect($this->downloadService->downloadMessage($message))->toBeFalse();

            // Still not downloaded
            expect($message->fresh()->is_downloaded)->toBeFalse();
        });
    });

    describe('markMessageAsDownloaded', function () {
        it('marks message as downloaded with path', function () {
            $message = EfacturaMessage::create([
                'efactura_token_id' => $this->token->id,
                'message_id' => 'MSG123',
                'type' => 'received',
                'is_downloaded' => false,
            ]);

            $this->downloadService->markMessageAsDownloaded($message, '/path/to/file.zip');

            $message->refresh();
            expect($message->is_downloaded)->toBeTrue()
                ->and($message->xml_path)->toBe('/path/to/file.zip');
        });

        it('marks message as downloaded without path', function () {
            $message = EfacturaMessage::create([
                'efactura_token_id' => $this->token->id,
                'message_id' => 'MSG456',
                'type' => 'received',
                'is_downloaded' => false,
            ]);

            $this->downloadService->markMessageAsDownloaded($message);

            $message->refresh();
            expect($message->is_downloaded)->toBeTrue()
                ->and($message->xml_path)->toBeNull();
        });
    });

    describe('checkAllStatuses', function () {
        it('processes all uploads needing status check', function () {
            // Create uploads needing check
            $upload = EfacturaUpload::create([
                'efactura_token_id' => $this->token->id,
                'uploadable_type' => 'App\\Models\\Invoice',
                'uploadable_id' => 1,
                'status' => UploadStatus::Processing,
                'upload_index' => 'INDEX1',
                'standard' => 'UBL',
            ]);

            $this->tokenService->shouldReceive('executeWithClient')
                ->once()
                ->andReturn(StatusResponseData::fromAnafResponse(['stare' => 'in prelucrare']));

            expect($this->downloadService->checkAllStatuses($this->token))->toBeTrue();
        });
    });

    describe('downloadAllResponses', function () {
        it('processes all uploads needing response download', function () {
            Storage::fake('local');
            Event::fake();

            $upload = EfacturaUpload::create([
                'efactura_token_id' => $this->token->id,
                'uploadable_type' => 'App\\Models\\Invoice',
                'uploadable_id' => 1,
                'status' => UploadStatus::Completed,
                'download_id' => 'DL123',
                'response_path' => null,
                'standard' => 'UBL',
                'processed_at' => now(),
            ]);

            $this->tokenService->shouldReceive('executeWithClient')
                ->once()
                ->andReturn(new DownloadResponseData(content: responseZip(), contentType: 'application/zip'));

            expect($this->downloadService->downloadAllResponses($this->token))->toBeTrue();

            expect($upload->fresh()->response_path)->not->toBeNull();
        });
    });

    describe('downloadAllReceivedInvoices', function () {
        it('downloads all pending received messages', function () {
            Storage::fake('local');
            Event::fake();

            $message = EfacturaMessage::create([
                'efactura_token_id' => $this->token->id,
                'message_id' => 'MSG789',
                'type' => 'received',
                'download_id' => 'DL789',
                'is_downloaded' => false,
            ]);

            $this->tokenService->shouldReceive('executeWithClient')
                ->once()
                ->andReturn(new DownloadResponseData(content: responseZip(), contentType: 'application/zip'));

            expect($this->downloadService->downloadAllReceivedInvoices($this->token))->toBeTrue();

            expect($message->fresh()->is_downloaded)->toBeTrue();
        });
    });
});
