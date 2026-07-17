<?php

use BeeCoded\EFactura\Enums\FailureReason;
use BeeCoded\EFactura\Enums\UploadStatus;
use BeeCoded\EFactura\Events\InvoiceFailed;
use BeeCoded\EFactura\Events\InvoiceProcessed;
use BeeCoded\EFactura\Events\InvoiceReceived;
use BeeCoded\EFactura\Models\EfacturaMessage;
use BeeCoded\EFactura\Models\EfacturaToken;
use BeeCoded\EFactura\Models\EfacturaUpload;
use BeeCoded\EFactura\Services\DownloadService;
use BeeCoded\EFactura\Services\TokenService;
use BeeCoded\EFactura\Services\UploadService;
use BeeCoded\EFacturaSdk\Data\Response\DownloadResponseData;
use BeeCoded\EFacturaSdk\Data\Response\StatusResponseData;
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

    $this->tokenService = Mockery::mock(TokenService::class);
    $this->uploadService = Mockery::mock(UploadService::class);
    $this->downloadService = new DownloadService($this->tokenService, $this->uploadService);
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

            // Should not call any token service methods
            $this->downloadService->checkStatus($upload);

            expect(true)->toBeTrue();
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

            // Should not call any token service methods
            $this->downloadService->checkStatus($upload);

            expect(true)->toBeTrue();
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

            // Should not throw, just log and return
            $this->downloadService->checkStatus($upload);

            expect(true)->toBeTrue();
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
                    'id_descarcare' => 'DOWNLOAD456',
                ]));

            $this->uploadService->shouldReceive('markUploadAsCompleted')
                ->once()
                ->with($upload, 'DOWNLOAD456');

            $this->downloadService->checkStatus($upload);
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
                    'id_descarcare' => 'ERROR_DL_123',
                    'Errors' => ['Processing error'],
                ]));

            // ANAF processed the document and rejected it: a terminal validation
            // verdict, which must be recorded as such so the retry job never
            // re-submits it.
            $this->uploadService->shouldReceive('markUploadAsFailed')
                ->once()
                ->with($upload, ['Processing error'], 'ERROR_DL_123', FailureReason::Validation);

            $this->downloadService->checkStatus($upload);

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

            $this->uploadService->shouldReceive('markUploadAsFailed')
                ->once()
                ->withArgs(fn ($u, $errors, $downloadId, $reason) => $u->is($upload) && $reason === FailureReason::Indeterminate);

            $this->downloadService->checkStatus($upload);

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

            // Should not throw
            $this->downloadService->checkStatus($upload);

            // Status unchanged
            expect($upload->fresh()->status)->toBe(UploadStatus::Processing);
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

            // Should not call any token service methods
            $this->downloadService->downloadResponse($upload);

            expect(true)->toBeTrue();
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

            // Should not call any token service methods
            $this->downloadService->downloadResponse($upload);

            expect(true)->toBeTrue();
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

            // Should not throw
            $this->downloadService->downloadResponse($upload);

            expect(true)->toBeTrue();
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
                ->andReturn(new DownloadResponseData(content: 'ZIP CONTENT', contentType: 'application/zip'));

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
            $this->downloadService->downloadResponse($upload);

            // No response_path set
            expect($upload->fresh()->response_path)->toBeNull();
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

            // Should not call any token service methods
            $this->downloadService->downloadMessage($message);

            expect(true)->toBeTrue();
        });

        it('skips messages without download_id', function () {
            $message = EfacturaMessage::create([
                'efactura_token_id' => $this->token->id,
                'message_id' => 'MSG123',
                'type' => 'received',
                'download_id' => null,
                'is_downloaded' => false,
            ]);

            // Should not call any token service methods
            $this->downloadService->downloadMessage($message);

            expect(true)->toBeTrue();
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

            // Should not throw
            $this->downloadService->downloadMessage($message);

            expect(true)->toBeTrue();
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
                ->andReturn(new DownloadResponseData(content: 'MESSAGE CONTENT', contentType: 'application/zip'));

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
            $this->downloadService->downloadMessage($message);

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

            $this->downloadService->checkAllStatuses();

            expect(true)->toBeTrue();
        });

        it('filters by CUI when specified', function () {
            $this->tokenService->shouldReceive('getToken')
                ->with('12345678')
                ->once()
                ->andReturn($this->token);

            $this->downloadService->checkAllStatuses('12345678');

            expect(true)->toBeTrue();
        });

        it('returns early when CUI token not found', function () {
            $this->tokenService->shouldReceive('getToken')
                ->with('99999999')
                ->once()
                ->andReturn(null);

            $this->downloadService->checkAllStatuses('99999999');

            expect(true)->toBeTrue();
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
            ]);

            $this->tokenService->shouldReceive('executeWithClient')
                ->once()
                ->andReturn(new DownloadResponseData(content: 'ZIP', contentType: 'application/zip'));

            $this->downloadService->downloadAllResponses();

            expect($upload->fresh()->response_path)->not->toBeNull();
        });

        it('filters by CUI when specified', function () {
            $this->tokenService->shouldReceive('getToken')
                ->with('12345678')
                ->once()
                ->andReturn($this->token);

            $this->downloadService->downloadAllResponses('12345678');

            expect(true)->toBeTrue();
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
                ->andReturn(new DownloadResponseData(content: 'CONTENT', contentType: 'application/zip'));

            $this->downloadService->downloadAllReceivedInvoices();

            expect($message->fresh()->is_downloaded)->toBeTrue();
        });

        it('filters by CUI when specified', function () {
            $this->tokenService->shouldReceive('getToken')
                ->with('12345678')
                ->once()
                ->andReturn($this->token);

            $this->downloadService->downloadAllReceivedInvoices('12345678');

            expect(true)->toBeTrue();
        });
    });
});
