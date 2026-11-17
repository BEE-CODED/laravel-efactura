<?php

/*
 * This file is part of the Laravel e-Factura package.
 *
 * (c) BEE-CODED <dev.ttl@beecoded.ro>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

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
use BeeCoded\EFacturaSdk\Services\RateLimiter;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * DownloadService caught \Throwable and only logged. Two consequences this suite pins
 * at the HTTP layer, driving the REAL SDK client rather than a hand-built fake DTO:
 *
 *  1. A rate-limit refusal was swallowed, so a rate-limited batch walked its ENTIRE
 *     backlog throwing-and-swallowing instead of stopping.
 *  2. ANAF answers an unknown id with HTTP 200 + {"eroare": ...}. For /stareMesaj that
 *     yields stare = null — neither isReady() nor isFailed() — so the upload polled
 *     every 10 minutes FOREVER while logging nothing. For /descarcare the SDK's
 *     guardDownloadBody() throws, but the throw was swallowed and response_path stayed
 *     null, so the poisoned response was re-downloaded on EVERY scheduled run.
 */
const STATUS_URL = 'https://api.anaf.ro/test/FCTEL/rest/stareMesaj*';
const DOWNLOAD_URL = 'https://api.anaf.ro/test/FCTEL/rest/descarcare*';
// The SDK maps this stare to UploadStatusValue::NotProcessed.
const NOT_PROCESSED_STARE = 'XML cu erori nepreluat de sistem';

beforeEach(function () {
    config([
        'efactura-sdk.sandbox' => true,
        'efactura-sdk.oauth.client_id' => 'test-client-id',
        'efactura-sdk.oauth.client_secret' => 'test-client-secret',
        'efactura-sdk.oauth.redirect_uri' => 'https://app.test/efactura/callback',
        'efactura-sdk.http.retry_times' => 1,
        'efactura-sdk.http.retry_delay' => 0,
    ]);

    $this->downloadService = app(DownloadService::class);

    $this->token = EfacturaToken::create([
        'cui' => '12345678',
        'access_token' => 'ACCESS',
        'refresh_token' => 'REFRESH',
        'expires_at' => now()->addHour(),
        'is_active' => true,
    ]);
});

/**
 * ANAF ids are numeric strings and the SDK enforces it client-side ("Upload ID must
 * be a numeric string"), so a fixture like 'INDEX123' never reaches HTTP at all.
 */
function makeProcessingUpload(EfacturaToken $token): EfacturaUpload
{
    return EfacturaUpload::create([
        'efactura_token_id' => $token->id,
        'uploadable_type' => 'App\\Models\\Invoice',
        'uploadable_id' => 1,
        'status' => UploadStatus::Processing,
        'upload_index' => '5001130255',
        'standard' => 'UBL',
    ]);
}

function makeCompletedUpload(EfacturaToken $token): EfacturaUpload
{
    return EfacturaUpload::create([
        'efactura_token_id' => $token->id,
        'uploadable_type' => 'App\\Models\\Invoice',
        'uploadable_id' => 2,
        'status' => UploadStatus::Completed,
        'download_id' => '3001130059',
        'standard' => 'UBL',
        'processed_at' => now(),
    ]);
}

function makeReceivedMessage(EfacturaToken $token, string $id = '4001130001'): EfacturaMessage
{
    return EfacturaMessage::create([
        'efactura_token_id' => $token->id,
        'message_id' => $id,
        'type' => 'received',
        'download_id' => $id,
        'is_downloaded' => false,
    ]);
}

/** A response archive whose only member is encrypted, which ANAF never sends. */
function encryptedZip(): string
{
    $path = tempnam(sys_get_temp_dir(), 'efactura-zip');
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::OVERWRITE);
    $zip->addFromString('raport.xml', '<Raport/>');
    $zip->setEncryptionName('raport.xml', ZipArchive::EM_AES_256, 'secret');
    $zip->close();
    $bytes = file_get_contents($path);
    unlink($path);

    return $bytes;
}

describe('checkStatus', function () {
    it('reports ANAF rate limiting a check, without throwing or counting it', function () {
        // A real ANAF 429 arrives as ApiException(429) — the SDK's isRetryable()
        // deliberately excludes 429, so it throws rather than retrying.
        Http::fake([STATUS_URL => Http::response(
            ['eroare' => 'S-au facut deja 100 de apeluri in ultimele 24 ore'],
            429
        )]);
        $upload = makeProcessingUpload($this->token);

        expect($this->downloadService->checkStatus($upload))->toBe(StatusCheckOutcome::RateLimited)
            ->and($upload->fresh()->status_failures)->toBe(0);
    });

    it('refuses to poll an unknown upload forever in silence', function () {
        // ANAF answers an unknown index_incarcare with 200 + {"eroare"}, so `stare`
        // parses to null: isReady() and isFailed() are BOTH false and the old code
        // fell through to "still in progress, do nothing" — every 10 minutes, forever,
        // logging nothing because nothing threw. SweepStaleUploads does not rescue it
        // either: it sweeps Uploading, and this row is Processing.
        Http::fake([STATUS_URL => anafStatus(null, error: 'Nu exista niciun upload cu indexul=5001130255')]);

        $upload = makeProcessingUpload($this->token);

        $this->downloadService->checkStatus($upload);

        $upload->refresh();

        expect($upload->status)->not->toBe(UploadStatus::Processing);
        expect($upload->failure_reason)->toBe(FailureReason::Indeterminate);
    });

    it('still does nothing while ANAF is genuinely processing', function () {
        // The ONLY legitimate no-op. Must survive the fix.
        Http::fake([STATUS_URL => anafStatus('in prelucrare')]);

        $upload = makeProcessingUpload($this->token);

        $this->downloadService->checkStatus($upload);

        expect($upload->fresh()->status)->toBe(UploadStatus::Processing);
        expect($upload->fresh()->failure_reason)->toBeNull();
    });

    it('completes an upload ANAF reports as ok', function () {
        Http::fake([STATUS_URL => anafStatus('ok', '3001130059')]);

        $upload = makeProcessingUpload($this->token);

        $this->downloadService->checkStatus($upload);

        expect($upload->fresh()->status)->toBe(UploadStatus::Completed);
    });

    it('fails an upload ANAF did not take in, without a download or retry', function () {
        Event::fake([InvoiceFailed::class]);
        Http::fake([STATUS_URL => anafStatus(NOT_PROCESSED_STARE)]);

        $upload = makeProcessingUpload($this->token);

        $this->downloadService->checkStatus($upload);

        $upload->refresh();
        expect($upload->status)->toBe(UploadStatus::Failed)
            ->and($upload->failure_reason)->toBe(FailureReason::Validation)
            ->and($upload->failure_reason->isRetryable())->toBeFalse()
            ->and($upload->download_id)->toBeNull()
            ->and($upload->errors)->not->toBeEmpty();
        Event::assertDispatched(InvoiceFailed::class, fn (InvoiceFailed $e) => $e->errors !== []);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/descarcare'));
    });

    it('records no answer for the previous index on a row sent again meanwhile', function (Closure $answer) {
        Event::fake([InvoiceFailed::class]);
        $upload = makeProcessingUpload($this->token);
        Http::fake([STATUS_URL => function () use ($upload, $answer) {
            // Failed, re-queued and sent again under a new index while ANAF answered.
            EfacturaUpload::whereKey($upload->id)->update(['upload_index' => '5001130256', 'next_poll_at' => null]);

            return $answer();
        }]);

        $this->downloadService->checkStatus($upload);

        $upload->refresh();
        expect([$upload->status, $upload->upload_index, $upload->failure_reason, $upload->download_id, $upload->next_poll_at])
            ->toBe([UploadStatus::Processing, '5001130256', null, null, null]);
        Event::assertNotDispatched(InvoiceFailed::class);
    })->with([
        'nok' => [fn () => anafStatus('nok', '3001130059')],
        'ok' => [fn () => anafStatus('ok', '3001130059')],
        'in prelucrare' => [fn () => anafStatus('in prelucrare')],
    ]);
});

describe('downloadResponse', function () {
    it('reports ANAF rate limiting a download, without throwing or recording a failure', function () {
        Http::fake([DOWNLOAD_URL => Http::response(
            ['eroare' => 'S-au facut deja 100 de apeluri in ultimele 24 ore'],
            429
        )]);
        $upload = makeCompletedUpload($this->token);

        expect($this->downloadService->downloadResponse($upload))->toBeFalse()
            ->and($upload->fresh()->response_failed_at)->toBeNull();
    });

    it('records a poisoned download loudly instead of swallowing it', function () {
        // The SDK's guardDownloadBody() throws ApiException on a 2xx body that is not
        // a ZIP. Swallowing it into a log line left response_path null, so
        // needsResponseDownload() re-selected this row on EVERY scheduled
        // DownloadResponses run, forever, with nothing recorded against the upload.
        Storage::fake('local');

        Http::fake([DOWNLOAD_URL => Http::response(
            ['eroare' => 'Nu exista niciun mesaj cu id-ul=3001130059'],
            200,
            ['Content-Type' => 'application/json']
        )]);

        $upload = makeCompletedUpload($this->token);

        $this->downloadService->downloadResponse($upload);

        $upload->refresh();

        // The invoice itself was accepted by ANAF — the upload must stay Completed.
        expect($upload->status)->toBe(UploadStatus::Completed);
        // But the permanent failure must be recorded rather than silently retried.
        expect($upload->errors)->not->toBeNull();
        expect($upload->response_failed_at)->not->toBeNull();
        expect($upload->response_path)->toBeNull();
    });

    it('stores a real ZIP body', function () {
        Storage::fake('local');

        Http::fake([DOWNLOAD_URL => Http::response(
            responseZip(),
            200,
            ['Content-Type' => 'application/zip']
        )]);

        $upload = makeCompletedUpload($this->token);

        $this->downloadService->downloadResponse($upload);

        expect($upload->fresh()->response_path)->not->toBeNull();
    });

    /**
     * put() returning false was ignored, so response_path pointed at a file that was
     * never written. A storage problem is ours, not ANAF's: it is not recorded as a failed
     * download either.
     */
    it('records neither a path nor a failure when the file cannot be stored', function () {
        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('put')->andReturn(false);
        Storage::set('local', $disk);

        Http::fake([DOWNLOAD_URL => Http::response(responseZip(), 200, ['Content-Type' => 'application/zip'])]);

        $upload = makeCompletedUpload($this->token);

        $this->downloadService->downloadResponse($upload);

        expect($upload->fresh()->response_path)->toBeNull()
            ->and($upload->fresh()->response_failed_at)->toBeNull();
    });

    it('does not mark a received invoice downloaded when it cannot be stored', function () {
        Event::fake([InvoiceReceived::class]);
        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('put')->andReturn(false);
        Storage::set('local', $disk);
        Http::fake([DOWNLOAD_URL => Http::response(responseZip(), 200, ['Content-Type' => 'application/zip'])]);

        $message = EfacturaMessage::create([
            'efactura_token_id' => $this->token->id,
            'message_id' => '4001130001',
            'type' => 'received',
            'download_id' => '4001130001',
            'is_downloaded' => false,
        ]);

        $this->downloadService->downloadMessage($message);

        $message->refresh();
        expect($message->is_downloaded)->toBeFalse()
            ->and($message->xml_path)->toBeNull();
        Event::assertNotDispatched(InvoiceReceived::class);
    });
});

/**
 * Every read claims its row by writing its next try before it asks ANAF, so whatever the
 * outcome, the next run does not spend the row's daily quota again before that slot.
 */
describe('a read that fails is not repeated before its slot', function () {
    it('waits out a failed download instead of reading it again on every run', function (string $kind) {
        config(['efactura-sdk.http.retry_times' => 3, 'efactura-sdk.http.retry_delay' => 0]);
        Storage::fake('local');
        $this->freezeSecond();
        // Still valid after the hour this test travels.
        $this->token->update(['expires_at' => now()->addDay()]);
        Http::fake([DOWNLOAD_URL => Http::sequence()
            ->push('Service Unavailable', 503)
            ->push('Service Unavailable', 503)
            ->push('Service Unavailable', 503)
            ->push(responseZip(), 200, ['Content-Type' => 'application/zip'])]);
        [$row, $run, $attempts, $due, $stored] = match ($kind) {
            'response' => [makeCompletedUpload($this->token), fn () => $this->downloadService->downloadAllResponses($this->token), 'response_attempts', 'next_poll_at', 'response_path'],
            'received invoice' => [makeReceivedMessage($this->token), fn () => $this->downloadService->downloadAllReceivedInvoices($this->token), 'download_attempts', 'next_download_at', 'xml_path'],
        };

        // The row is reached a few seconds into its scheduled run; the retry still lands on
        // the run an hour later, not the one after it.
        $tick = now()->getTimestamp();
        $this->travel(10)->seconds();
        $run();
        Http::assertSentCount(3);
        expect($row->fresh()->{$attempts})->toBe(1)
            ->and($row->fresh()->{$due}?->getTimestamp())->toBe($tick + 10 + 3600 - 60);

        $run();
        Http::assertSentCount(3);

        $this->travelTo(Carbon\Carbon::createFromTimestamp($tick + 3601, config('app.timezone')));
        $run();
        expect($row->fresh()->{$stored})->not->toBeNull()
            ->and($row->fresh()->{$attempts})->toBe(2);
        Http::assertSentCount(4);
    })->with(['response', 'received invoice']);
});

/** ANAF keeps an archive downloadable for 60 days; past that no run asks for it. */
describe('the 60-day horizon', function () {
    it('selects responses and received invoices for download only within 60 days', function () {
        $old = makeCompletedUpload($this->token);
        $old->update(['processed_at' => now()->subDays(61)]);
        $recent = EfacturaUpload::create([
            'efactura_token_id' => $this->token->id,
            'uploadable_type' => 'App\\Models\\Invoice',
            'uploadable_id' => 3,
            'status' => UploadStatus::Completed,
            'download_id' => '3001130060',
            'standard' => 'UBL',
            'processed_at' => now()->subDays(59),
        ]);
        $oldMessage = makeReceivedMessage($this->token, '4001130001');
        $recentMessage = makeReceivedMessage($this->token, '4001130002');
        EfacturaMessage::whereKey($oldMessage->id)->update(['created_at' => now()->subDays(61)]);
        EfacturaMessage::whereKey($recentMessage->id)->update(['created_at' => now()->subDays(59)]);

        expect(EfacturaUpload::needsResponseDownload()->pluck('id')->all())->toBe([$recent->id])
            ->and(EfacturaMessage::needsDownload()->pluck('id')->all())->toBe([$recentMessage->id]);
    });
});

describe('overlapping runs on one upload', function () {
    /**
     * Two runs that loaded the same Processing row each recorded ANAF's verdict and each
     * announced it, and the event carried no errors.
     */
    it('announces a rejection once, with ANAF\'s errors', function () {
        Event::fake([InvoiceFailed::class]);
        Http::fake([STATUS_URL => anafStatus('nok', '3001130059')]);

        $first = makeProcessingUpload($this->token);
        $second = EfacturaUpload::find($first->id);

        $this->downloadService->checkStatus($first);
        $this->downloadService->checkStatus($second);

        expect($first->fresh()->failure_reason)->toBe(FailureReason::Validation);
        Event::assertDispatchedTimes(InvoiceFailed::class, 1);
        Event::assertDispatched(InvoiceFailed::class, fn (InvoiceFailed $e) => $e->errors !== []);
    });

    it('keeps the first stored response and announces it once', function () {
        Event::fake([InvoiceProcessed::class]);
        Storage::fake('local');
        Http::fake([DOWNLOAD_URL => Http::response(responseZip(), 200, ['Content-Type' => 'application/zip'])]);

        $first = makeCompletedUpload($this->token);
        $second = EfacturaUpload::find($first->id);

        $this->downloadService->downloadResponse($first);
        $path = $first->fresh()->response_path;
        // A later second, so the overlapping run would write a different file name.
        $this->travel(2)->seconds();
        $this->downloadService->downloadResponse($second);

        expect($path)->not->toBeNull()
            ->and($first->fresh()->response_path)->toBe($path);
        Event::assertDispatchedTimes(InvoiceProcessed::class, 1);
    });

    // In the same second both runs write one file name: the loser must not delete it.
    it('leaves only the stored response file on disk', function (int $secondsApart) {
        Storage::fake('local');
        Http::fake([DOWNLOAD_URL => Http::response(responseZip(), 200, ['Content-Type' => 'application/zip'])]);

        $first = makeCompletedUpload($this->token);
        $second = EfacturaUpload::find($first->id);

        $this->downloadService->downloadResponse($first);
        $this->travel($secondsApart)->seconds();
        $this->downloadService->downloadResponse($second);

        expect(Storage::disk('local')->allFiles())->toBe([$first->fresh()->response_path]);
    })->with(['seconds apart' => [2], 'same second' => [0]]);

    it('announces a received invoice once and leaves only its stored file on disk', function (int $secondsApart) {
        Event::fake([InvoiceReceived::class]);
        Storage::fake('local');
        Http::fake([DOWNLOAD_URL => Http::response(responseZip(), 200, ['Content-Type' => 'application/zip'])]);

        $first = EfacturaMessage::create([
            'efactura_token_id' => $this->token->id,
            'message_id' => '4001130001',
            'type' => 'received',
            'download_id' => '4001130001',
            'is_downloaded' => false,
        ]);
        $second = EfacturaMessage::find($first->id);

        $this->downloadService->downloadMessage($first);
        $path = $first->fresh()->xml_path;
        $this->travel($secondsApart)->seconds();
        $this->downloadService->downloadMessage($second);

        Event::assertDispatchedTimes(InvoiceReceived::class, 1);
        expect($path)->not->toBeNull()
            ->and($first->fresh()->xml_path)->toBe($path)
            ->and(Storage::disk('local')->allFiles())->toBe([$path]);
    })->with(['seconds apart' => [2], 'same second' => [0]]);

    it('does not park an upload another run already completed', function () {
        Event::fake([InvoiceFailed::class]);
        Http::fake([STATUS_URL => Http::sequence()
            ->pushResponse(anafStatus('ok', '3001130059'))
            ->pushResponse(anafStatus(null, error: 'Nu exista niciun upload cu indexul=5001130255'))]);

        $first = makeProcessingUpload($this->token);
        $second = EfacturaUpload::find($first->id);

        $this->downloadService->checkStatus($first);
        $this->downloadService->checkStatus($second);

        $upload = $first->fresh();
        expect($upload->status)->toBe(UploadStatus::Completed)
            ->and($upload->failure_reason)->toBeNull()
            ->and($upload->download_id)->toBe('3001130059');
        Event::assertNotDispatched(InvoiceFailed::class);
    });

    // A re-queue clears download_id and response_path, so the old report could land on the new attempt.
    it('does not attach an old error report to a row re-queued while it downloaded', function () {
        Event::fake([InvoiceProcessed::class]);
        Storage::fake('local');

        $upload = makeCompletedUpload($this->token);
        $upload->update(['status' => UploadStatus::Failed, 'failure_reason' => FailureReason::Validation]);

        Http::fake([DOWNLOAD_URL => function () use ($upload) {
            // Meanwhile: re-queued, re-sent, and completed under a new download id.
            EfacturaUpload::whereKey($upload->id)->update([
                'status' => UploadStatus::Completed,
                'failure_reason' => null,
                'download_id' => '3001130060',
                'response_path' => null,
                'next_poll_at' => null,
            ]);

            return Http::response(responseZip(), 200, ['Content-Type' => 'application/zip']);
        }]);

        $this->downloadService->downloadResponse($upload);

        expect($upload->fresh()->response_path)->toBeNull()
            ->and(EfacturaUpload::needsResponseDownload()->pluck('id')->all())->toContain($upload->id)
            ->and(Storage::disk('local')->allFiles())->toBe([]);
        Event::assertNotDispatched(InvoiceProcessed::class);
    });

    it('does not mark a response another run stored as failed', function () {
        Http::fake([DOWNLOAD_URL => Http::response(['eroare' => 'Nu exista niciun mesaj cu id-ul=3001130059'], 200, ['Content-Type' => 'application/json'])]);
        $upload = makeCompletedUpload($this->token);
        // The other run stored it after this one loaded the row.
        EfacturaUpload::whereKey($upload->id)->update(['response_path' => 'efactura/responses/12345678/x.zip']);

        $downloaded = $this->downloadService->downloadResponse($upload);

        Http::assertNothingSent();
        $upload->refresh();
        expect([$upload->response_attempts, $upload->response_failed_at, $upload->errors])->toBe([0, null, null])
            ->and($downloaded)->toBeFalse();
    });

    it('removes the file it stored when recording it fails', function (string $kind) {
        Storage::fake('local');
        Http::fake([DOWNLOAD_URL => Http::response(responseZip(), 200, ['Content-Type' => 'application/zip'])]);
        [$row, $download, $columns, $path] = match ($kind) {
            'response' => [$upload = makeCompletedUpload($this->token), fn () => $this->downloadService->downloadResponse($upload), ['response_path', 'response_verification'], 'response_path'],
            'received invoice' => [$message = makeReceivedMessage($this->token), fn () => $this->downloadService->downloadMessage($message), ['is_downloaded', 'xml_path'], 'xml_path'],
        };
        DB::beforeExecuting(function (string $query) use ($columns) {
            if (str_starts_with($query, 'update') && str_contains($query, $columns[0]) && str_contains($query, $columns[1])) {
                throw new RuntimeException('database write failed');
            }
        });

        $download();

        expect(Storage::disk('local')->allFiles())->toBe([])
            ->and($row->fresh()->{$path})->toBeNull();
    })->with(['response', 'received invoice']);

    it('does not count an old permanent download failure against a row re-queued meanwhile', function () {
        $upload = makeCompletedUpload($this->token);
        $upload->update(['status' => UploadStatus::Failed, 'failure_reason' => FailureReason::Validation]);

        Http::fake([DOWNLOAD_URL => function () use ($upload) {
            EfacturaUpload::whereKey($upload->id)->update([
                'status' => UploadStatus::Pending,
                'failure_reason' => null,
                'errors' => null,
                'download_id' => null,
                'response_attempts' => 0,
            ]);

            return Http::response(['eroare' => 'Forbidden'], 403);
        }]);

        $this->downloadService->downloadResponse($upload);

        $upload->refresh();
        expect($upload->response_attempts)->toBe(0)
            ->and($upload->errors)->toBeNull()
            ->and($upload->response_failed_at)->toBeNull();
    });
});

/**
 * Topic L5: no status poll can loop forever, and none records a false verdict.
 */
describe('status answers without a download id', function () {
    it('completes an ok without a download id, with a note, and never downloads a response for it', function () {
        Http::fake([
            STATUS_URL => anafStatus('ok'),
            DOWNLOAD_URL => Http::response(responseZip(), 200, ['Content-Type' => 'application/zip']),
        ]);

        $upload = makeProcessingUpload($this->token);

        $this->downloadService->checkAllStatuses($this->token);

        $upload->refresh();
        expect($upload->status)->toBe(UploadStatus::Completed)
            ->and($upload->download_id)->toBeNull()
            ->and($upload->failure_reason)->toBeNull()
            ->and($upload->errors)->toBe([__('efactura::messages.completed_without_download_id')]);

        $this->downloadService->downloadAllResponses($this->token);

        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/descarcare'));
        expect($upload->fresh()->response_path)->toBeNull();
    });

    it('parks an error answer without a download id as indeterminate, keeping the index and ANAF\'s errors', function (Closure $answer, ?string $anafError) {
        Event::fake([InvoiceFailed::class]);
        Http::fake([STATUS_URL => $answer()]);

        $upload = makeProcessingUpload($this->token);

        $this->downloadService->checkStatus($upload);

        $upload->refresh();
        expect($upload->status)->toBe(UploadStatus::Failed)
            ->and($upload->failure_reason)->toBe(FailureReason::Indeterminate)
            ->and($upload->upload_index)->toBe('5001130255')
            ->and($upload->errors)->toBe([__('efactura::messages.status_nok_without_download_id', ['index' => '5001130255']), ...($anafError === null ? [] : [$anafError])]);
        Event::assertDispatched(InvoiceFailed::class);
    })->with([
        // SDK 4.0 reads an <Errors/>-only header as no stare with ANAF's errors (hasAnafError()).
        'XML Errors header' => [fn () => anafStatus(null, error: 'Nu aveti dreptul de inteorgare pentru id_incarcare= 5001130255'), 'Nu aveti dreptul de inteorgare pentru id_incarcare= 5001130255'],
        'XML nok' => [fn () => anafStatus('nok'), null],
        // The SDK's fallback when the answer is not XML.
        'JSON eroare' => [fn () => Http::response(['eroare' => 'Nu exista niciun upload cu indexul=5001130255']), 'Nu exista niciun upload cu indexul=5001130255'],
    ]);

    it('keeps a download id ANAF named beside its error for whoever reconciles the parked upload', function () {
        Http::fake([STATUS_URL => Http::response(['eroare' => 'Nu aveti dreptul de inteorgare', 'id_descarcare' => '3001'])]);

        $upload = makeProcessingUpload($this->token);
        $this->downloadService->checkStatus($upload);

        expect($upload->refresh()->failure_reason)->toBe(FailureReason::Indeterminate)
            ->and($upload->download_id)->toBe('3001');
    });

    /**
     * ANAF's download ids are digits. Any other id cannot be downloaded, so it is no verdict
     * on the document: as if ANAF had named none.
     */
    it('reads a download id that is not digits as none', function (Closure $answer, UploadStatus $status, ?FailureReason $reason) {
        Event::fake([InvoiceFailed::class]);
        Http::fake([STATUS_URL => $answer()]);

        $upload = makeProcessingUpload($this->token);

        $this->downloadService->checkStatus($upload);

        $upload->refresh();
        expect([$upload->status, $upload->failure_reason, $upload->download_id])->toBe([$status, $reason, null]);
    })->with([
        'ok' => [fn () => anafStatus('ok', 'DL999'), UploadStatus::Completed, null],
        'nok' => [fn () => anafStatus('nok', 'DL999'), UploadStatus::Failed, FailureReason::Indeterminate],
        'error answer' => [fn () => Http::response(['eroare' => 'Nu aveti dreptul', 'id_descarcare' => 'DL999']), UploadStatus::Failed, FailureReason::Indeterminate],
        'unrecognised status' => [fn () => anafStatus('necunoscut', 'DL999'), UploadStatus::Failed, FailureReason::Indeterminate],
    ]);
});

describe('permanent status-check failures', function () {
    it('parks an upload after DownloadService::MAX_STATUS_FAILURES refusals, not before', function () {
        Event::fake([InvoiceFailed::class]);
        Http::fake([STATUS_URL => Http::response(['eroare' => 'Bad request'], 400)]);

        $upload = makeProcessingUpload($this->token);

        for ($run = 1; $run < DownloadService::MAX_STATUS_FAILURES; $run++) {
            $this->downloadService->checkStatus($upload->refresh());
        }

        $upload->refresh();
        expect($upload->status)->toBe(UploadStatus::Processing)
            ->and($upload->status_failures)->toBe(DownloadService::MAX_STATUS_FAILURES - 1);
        Event::assertNotDispatched(InvoiceFailed::class);

        $this->downloadService->checkStatus($upload->refresh());

        $upload->refresh();
        expect($upload->status)->toBe(UploadStatus::Failed)
            ->and($upload->failure_reason)->toBe(FailureReason::Indeterminate)
            ->and($upload->upload_index)->toBe('5001130255')
            ->and($upload->errors[0])->toBe(__('efactura::messages.status_poll_failures_exhausted', ['index' => '5001130255', 'count' => DownloadService::MAX_STATUS_FAILURES]));
        Event::assertDispatchedTimes(InvoiceFailed::class, 1);

        // A parked row leaves the polling query.
        $this->travel(1)->day();
        $this->downloadService->checkAllStatuses($this->token);
        Http::assertSentCount(DownloadService::MAX_STATUS_FAILURES);
    });

    it('resets a failure count another run added while this read was in flight', function () {
        Http::fake([STATUS_URL => anafStatus('in prelucrare')]);
        $upload = makeProcessingUpload($this->token);
        EfacturaUpload::whereKey($upload->id)->update(['status_failures' => 1]);

        $this->downloadService->checkStatus($upload);

        expect($upload->fresh()->status_failures)->toBe(0);
    });

    it('never parks an upload for ANAF or transport outages', function (int $status) {
        Http::fake([STATUS_URL => Http::response('Service Unavailable', $status)]);

        $upload = makeProcessingUpload($this->token);

        for ($run = 1; $run <= DownloadService::MAX_STATUS_FAILURES + 1; $run++) {
            $this->downloadService->checkStatus($upload->refresh());
        }

        $upload->refresh();
        expect($upload->status)->toBe(UploadStatus::Processing)
            ->and($upload->failure_reason)->toBeNull()
            ->and($upload->status_failures)->toBe(0);
    })->with([503, 408]);

    it('parks an upload whose stored index the SDK refuses', function () {
        Http::fake();

        $upload = makeProcessingUpload($this->token);
        $upload->update(['upload_index' => 'INDEX123']);

        for ($run = 1; $run < DownloadService::MAX_STATUS_FAILURES; $run++) {
            $this->downloadService->checkStatus($upload->refresh());
        }
        expect($upload->refresh()->status)->toBe(UploadStatus::Processing);

        $this->downloadService->checkStatus($upload->refresh());

        $upload->refresh();
        expect($upload->status)->toBe(UploadStatus::Failed)
            ->and($upload->failure_reason)->toBe(FailureReason::Indeterminate)
            ->and($upload->upload_index)->toBe('INDEX123');
        Http::assertNothingSent();
    });

    it('resets the count on any successful read', function () {
        Http::fake([STATUS_URL => Http::sequence()
            ->push(['eroare' => 'Bad request'], 400)
            ->push(['eroare' => 'Bad request'], 400)
            ->pushResponse(anafStatus('in prelucrare'))
            ->push(['eroare' => 'Bad request'], 400)
            ->push(['eroare' => 'Bad request'], 400),
        ]);

        $upload = makeProcessingUpload($this->token);

        for ($run = 1; $run <= 3; $run++) {
            $this->downloadService->checkStatus($upload->refresh());
        }
        expect($upload->refresh()->status_failures)->toBe(0);

        for ($run = 1; $run <= 2; $run++) {
            $this->downloadService->checkStatus($upload->refresh());
        }

        $upload->refresh();
        expect($upload->status)->toBe(UploadStatus::Processing)
            ->and($upload->status_failures)->toBe(2);
    });

    it('leaves the count of a row sent again meanwhile', function (Closure $answer) {
        $upload = makeProcessingUpload($this->token);
        $upload->update(['status_failures' => 1]);
        Http::fake([STATUS_URL => function () use ($upload, $answer) {
            // Sent again under a new index, with a count of its own, while ANAF answered.
            EfacturaUpload::whereKey($upload->id)->update(['upload_index' => '5001130256', 'status_failures' => 1]);

            return $answer();
        }]);

        $this->downloadService->checkStatus($upload->refresh());

        $upload->refresh();
        expect([$upload->status, $upload->upload_index, $upload->failure_reason, $upload->status_failures])
            ->toBe([UploadStatus::Processing, '5001130256', null, 1]);
    })->with([
        'a permanent failure' => [fn () => Http::response(['eroare' => 'Bad request'], 400)],
        'an answer' => [fn () => anafStatus('in prelucrare')],
    ]);
});

describe('permanent download failures', function () {
    it('records ANAF refusing a response download, and reads it again only at its next slot', function () {
        Storage::fake('local');
        Http::fake([DOWNLOAD_URL => Http::response(['eroare' => 'Forbidden'], 403)]);

        $upload = makeCompletedUpload($this->token);

        $this->downloadService->downloadAllResponses($this->token);
        $this->downloadService->downloadAllResponses($this->token);

        $upload->refresh();
        expect($upload->response_failed_at)->not->toBeNull()
            ->and($upload->response_path)->toBeNull()
            ->and($upload->response_attempts)->toBe(1);
        Http::assertSentCount(1);
    });

    it('records ANAF refusing a response archive', function (Closure $answer) {
        Storage::fake('local');
        Http::fake([DOWNLOAD_URL => $answer()]);

        $upload = makeCompletedUpload($this->token);

        $this->downloadService->downloadResponse($upload);

        expect($upload->fresh()->response_failed_at)->not->toBeNull()
            ->and($upload->fresh()->response_path)->toBeNull();
    })->with([
        'a JSON error with 200' => [fn () => Http::response(['eroare' => 'Nu exista niciun mesaj cu id-ul=3001130059'], 200, ['Content-Type' => 'application/json'])],
        'an encrypted archive' => [fn () => Http::response(encryptedZip(), 200, ['Content-Type' => 'application/zip'])],
    ]);

    it('does not record a 5xx response download as a failure', function () {
        Storage::fake('local');
        Http::fake([DOWNLOAD_URL => Http::response('Service Unavailable', 503)]);

        $upload = makeCompletedUpload($this->token);

        $this->downloadService->downloadResponse($upload);

        expect($upload->refresh()->response_failed_at)->toBeNull();
    });

    it('reads a received invoice ANAF refused again only at its next slot', function () {
        Storage::fake('local');
        Http::fake([DOWNLOAD_URL => Http::response(['eroare' => 'Not found'], 404)]);

        $message = makeReceivedMessage($this->token);

        $this->downloadService->downloadAllReceivedInvoices($this->token);
        $this->downloadService->downloadAllReceivedInvoices($this->token);

        expect($message->refresh()->download_attempts)->toBe(1)
            ->and($message->is_downloaded)->toBeFalse();
        Http::assertSentCount(1);
    });
});

/**
 * Only ANAF refusing the row is recorded against it. Our own database, cache or
 * configuration failing after the request says nothing about the row (#64).
 */
describe('a local failure after the request', function () {
    beforeEach(function () {
        config(['cache.limiter' => 'missing']);
        app()->forgetInstance(Illuminate\Cache\RateLimiter::class);
        Illuminate\Support\Facades\RateLimiter::clearResolvedInstance(Illuminate\Cache\RateLimiter::class);
        Http::fake();
    });

    it('is not counted as ANAF refusing a status read', function () {
        $upload = makeProcessingUpload($this->token);

        expect($this->downloadService->checkStatus($upload))->toBe(StatusCheckOutcome::Failed)
            ->and($upload->fresh()->status_failures)->toBe(0);
        Http::assertNothingSent();
    });

    it('is not recorded as a failed response download', function () {
        $upload = makeCompletedUpload($this->token);
        $upload->update(['errors' => ['an earlier note']]);

        $this->downloadService->downloadResponse($upload);

        $upload->refresh();
        expect([$upload->response_failed_at, $upload->errors, $upload->response_attempts])->toBe([null, ['an earlier note'], 1]);
        Http::assertNothingSent();
    });
});

/**
 * A refused grant, a deactivated or unreadable company, or a pause applies to every row of
 * the company: the run stops at the first, records nothing against any row and claims no
 * other one.
 */
describe('a failure of the whole grant', function () {
    it('stops the status run without counting or claiming another row', function (Closure $breakIt, int $requests, bool $claimsFirst) {
        Http::fake([STATUS_URL => Http::response(['eroare' => 'Unauthorized'], 401)]);
        $first = makeProcessingUpload($this->token);
        $second = EfacturaUpload::create([
            'efactura_token_id' => $this->token->id,
            'uploadable_type' => 'App\\Models\\Invoice',
            'uploadable_id' => 3,
            'status' => UploadStatus::Processing,
            'upload_index' => '5001130256',
            'standard' => 'UBL',
        ]);
        $breakIt($this->token);

        expect($this->downloadService->checkAllStatuses($this->token))->toBeFalse();

        Http::assertSentCount($requests);
        expect([$first->fresh()->status_failures, $second->fresh()->status_failures])->toBe([0, 0])
            ->and($second->fresh()->next_poll_at)->toBeNull()
            ->and($first->fresh()->next_poll_at !== null)->toBe($claimsFirst);
    })->with([
        'ANAF refuses the request with 401' => [fn (EfacturaToken $token) => null, 1, true],
        'a deactivated token' => [fn (EfacturaToken $token) => $token->update(['is_active' => false]), 0, true],
        'a stored CUI that is not canonical' => [fn (EfacturaToken $token) => EfacturaToken::whereKey($token->id)->update(['cui' => '012345678']), 0, true],
        'a paused grant' => [fn (EfacturaToken $token) => refuseGrant($token), 0, false],
    ]);

    it('reports a refused request as a failed check', function () {
        Http::fake([STATUS_URL => Http::response(['eroare' => 'Unauthorized'], 401)]);

        expect($this->downloadService->checkStatus(makeProcessingUpload($this->token)))->toBe(StatusCheckOutcome::Failed);
    });
});

/**
 * Topic L5d: the SDK's per-message limits (status per upload index, download per
 * download id) block only that row, so the batch carries on; a global limit still
 * stops it. Driven through the real RateLimiter, exhausted before the run.
 */
describe('SDK rate limits in a batch', function () {
    function exhaustRateLimit(array $limits, Closure $exhaust): void
    {
        config(collect($limits)->mapWithKeys(fn ($limit, $key) => ["efactura-sdk.rate_limits.{$key}" => $limit])->all());
        app()->forgetInstance(RateLimiter::class);
        $exhaust(app(RateLimiter::class));
    }

    it('skips an upload at its per-message status limit and still polls the rest', function () {
        Http::fake([STATUS_URL => anafStatus('ok', '3001130059')]);

        $limited = makeProcessingUpload($this->token);
        $next = EfacturaUpload::create([
            'efactura_token_id' => $this->token->id,
            'uploadable_type' => 'App\\Models\\Invoice',
            'uploadable_id' => 3,
            'status' => UploadStatus::Processing,
            'upload_index' => '5001130256',
            'standard' => 'UBL',
        ]);

        exhaustRateLimit(['status_per_day_message' => 1], fn (RateLimiter $limiter) => $limiter->checkStatusQuery('5001130255'));

        $this->downloadService->checkAllStatuses($this->token);

        expect($limited->refresh()->status)->toBe(UploadStatus::Processing)
            ->and($limited->status_failures)->toBe(0)
            ->and($next->refresh()->status)->toBe(UploadStatus::Completed);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'id_incarcare=5001130256'));
    });

    it('makes efactura:status --upload report a skipped upload instead of "Done."', function () {
        // descarcare is faked too, so a limiter regression that lets the status call through
        // fails the assertions below instead of following up with an unfaked download.
        Storage::fake('local');
        Http::fake([
            STATUS_URL => anafStatus('ok', '3001130059'),
            DOWNLOAD_URL => Http::response(responseZip(), 200, ['Content-Type' => 'application/zip']),
        ]);
        $upload = makeProcessingUpload($this->token);

        exhaustRateLimit(['status_per_day_message' => 1], fn (RateLimiter $limiter) => $limiter->checkStatusQuery('5001130255'));

        $this->artisan('efactura:status', ['--upload' => $upload->id])
            ->expectsOutputToContain('ANAF rate limit reached, try again later')
            ->doesntExpectOutput('Done.')
            ->assertExitCode(1);

        expect($upload->refresh()->status)->toBe(UploadStatus::Processing);
        Http::assertNothingSent();
    });

    it('makes efactura:status --upload say ANAF rate limits it at the global limit', function () {
        Http::fake([STATUS_URL => anafStatus('ok', '3001130059')]);
        $upload = makeProcessingUpload($this->token);

        exhaustRateLimit(['global_per_minute' => 1], fn (RateLimiter $limiter) => $limiter->checkGlobal());

        $this->artisan('efactura:status', ['--upload' => $upload->id])
            ->expectsOutputToContain('ANAF rate limit reached, try again later')
            ->assertExitCode(1);

        Http::assertNothingSent();
    });

    it('makes a bulk efactura:status say it stopped for a company at the global limit', function () {
        Http::fake([STATUS_URL => anafStatus('ok', '3001130059')]);
        makeProcessingUpload($this->token);

        exhaustRateLimit(['global_per_minute' => 1], fn (RateLimiter $limiter) => $limiter->checkGlobal());

        $this->artisan('efactura:status', ['--cui' => '12345678'])
            ->expectsOutputToContain('Stopped for 12345678')
            ->assertExitCode(1);

        Http::assertNothingSent();
    });

    it('makes a bulk efactura:status carry on past a paused company and still exit 1', function () {
        Http::fake([STATUS_URL => anafStatus('in prelucrare')]);
        $healthy = EfacturaToken::create([
            'cui' => '87654321',
            'access_token' => 'ACCESS_H',
            'refresh_token' => 'REFRESH_H',
            'expires_at' => now()->addHour(),
            'is_active' => true,
        ]);
        makeProcessingUpload($this->token);
        $checked = EfacturaUpload::create([
            'efactura_token_id' => $healthy->id,
            'uploadable_type' => 'App\\Models\\Invoice',
            'uploadable_id' => 2,
            'status' => UploadStatus::Processing,
            'upload_index' => '5001130256',
            'standard' => 'UBL',
        ]);
        refuseGrant($this->token);

        $this->artisan('efactura:status')
            ->expectsOutputToContain('Stopped for 12345678 (rate limit or token unavailable); see the log.')
            ->assertExitCode(1);

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'id_incarcare=5001130256'));
        expect($checked->fresh()->next_poll_at)->not->toBeNull();
    });

    it('reports the global limit on a single check as a rate limit', function () {
        Http::fake([STATUS_URL => anafStatus('ok', '3001130059')]);
        $upload = makeProcessingUpload($this->token);

        exhaustRateLimit(['global_per_minute' => 1], fn (RateLimiter $limiter) => $limiter->checkGlobal());

        expect($this->downloadService->checkStatus($upload))->toBe(StatusCheckOutcome::RateLimited);
        Http::assertNothingSent();
    });

    it('makes efactura:status --upload report a failed check instead of "Done."', function () {
        Http::fake([STATUS_URL => Http::response('Server Error', 500)]);
        $upload = makeProcessingUpload($this->token);

        $this->artisan('efactura:status', ['--upload' => $upload->id])
            ->expectsOutputToContain('failed; see the log')
            ->doesntExpectOutput('Done.')
            ->assertExitCode(1);
    });

    it('makes efactura:status --upload say when the response download was skipped', function () {
        Storage::fake('local');
        Http::fake([
            STATUS_URL => anafStatus('ok', '3001130059'),
            DOWNLOAD_URL => Http::response(responseZip(), 200, ['Content-Type' => 'application/zip']),
        ]);
        $upload = makeProcessingUpload($this->token);

        exhaustRateLimit(['download_per_day_message' => 1], fn (RateLimiter $limiter) => $limiter->checkDownload('3001130059'));

        $this->artisan('efactura:status', ['--upload' => $upload->id])
            ->expectsOutputToContain('was not downloaded')
            ->assertExitCode(0);

        expect($upload->refresh()->response_path)->toBeNull();
    });

    it('still prints "Done." from efactura:status --upload when ANAF answered', function () {
        Http::fake([STATUS_URL => anafStatus('in prelucrare')]);
        $upload = makeProcessingUpload($this->token);

        $this->artisan('efactura:status', ['--upload' => $upload->id])
            ->expectsOutput('Done.')
            ->assertExitCode(0);
    });

    it('skips a response and a received invoice at their per-message download limit', function () {
        Storage::fake('local');
        Http::fake([DOWNLOAD_URL => Http::response(responseZip(), 200, ['Content-Type' => 'application/zip'])]);

        $limitedUpload = makeCompletedUpload($this->token);
        $nextUpload = EfacturaUpload::create([
            'efactura_token_id' => $this->token->id,
            'uploadable_type' => 'App\\Models\\Invoice',
            'uploadable_id' => 3,
            'status' => UploadStatus::Completed,
            'download_id' => '3001130060',
            'standard' => 'UBL',
            'processed_at' => now(),
        ]);
        $message = fn (string $id) => EfacturaMessage::create([
            'efactura_token_id' => $this->token->id,
            'message_id' => $id,
            'type' => 'received',
            'download_id' => $id,
            'is_downloaded' => false,
        ]);
        $limitedMessage = $message('4001130001');
        $nextMessage = $message('4001130002');

        exhaustRateLimit(['download_per_day_message' => 1], function (RateLimiter $limiter) {
            $limiter->checkDownload('3001130059');
            $limiter->checkDownload('4001130001');
        });

        $this->downloadService->downloadAllResponses($this->token);
        $this->downloadService->downloadAllReceivedInvoices($this->token);

        // The limiter's refusal still spends the try the run claimed.
        expect($limitedUpload->refresh()->response_path)->toBeNull()
            ->and($limitedUpload->response_attempts)->toBe(1)
            ->and($limitedUpload->response_failed_at)->toBeNull()
            ->and($nextUpload->refresh()->response_path)->not->toBeNull()
            ->and($limitedMessage->refresh()->is_downloaded)->toBeFalse()
            ->and($limitedMessage->download_attempts)->toBe(1)
            ->and($nextMessage->refresh()->is_downloaded)->toBeTrue();
        Http::assertSentCount(2);
    });

    it('still stops the whole batch at the global limit', function () {
        Http::fake([STATUS_URL => anafStatus('ok', '3001130059')]);

        $upload = makeProcessingUpload($this->token);

        exhaustRateLimit(['global_per_minute' => 1], fn (RateLimiter $limiter) => $limiter->checkGlobal());

        expect($this->downloadService->checkAllStatuses($this->token))->toBeFalse();
        expect($upload->refresh()->status)->toBe(UploadStatus::Processing)
            ->and($upload->status_failures)->toBe(0);
        Http::assertNothingSent();
    });
});

/**
 * Topic L3: a listener that throws is reported, never propagated, and never changes the
 * state that was already recorded.
 */
describe('a throwing listener after a recorded download state', function () {
    beforeEach(function () {
        foreach ([InvoiceFailed::class, InvoiceProcessed::class, InvoiceReceived::class] as $event) {
            Event::listen($event, function () {
                throw new RuntimeException('listener failed');
            });
        }
    });

    it('leaves a rejected status verdict as recorded', function () {
        Http::fake([STATUS_URL => anafStatus('nok', '3001130059')]);

        $upload = makeProcessingUpload($this->token);

        $this->downloadService->checkStatus($upload);

        $upload->refresh();
        expect($upload->failure_reason)->toBe(FailureReason::Validation)
            ->and($upload->download_id)->toBe('3001130059')
            ->and($upload->status_failures)->toBe(0);
    });

    it('leaves a downloaded response as recorded', function () {
        Storage::fake('local');
        Http::fake([DOWNLOAD_URL => Http::response(responseZip(), 200, ['Content-Type' => 'application/zip'])]);

        $upload = makeCompletedUpload($this->token);

        $this->downloadService->downloadResponse($upload);

        $upload->refresh();
        expect($upload->response_path)->not->toBeNull()
            ->and($upload->response_failed_at)->toBeNull()
            ->and($upload->errors)->toBeNull();
    });

    it('leaves a downloaded received invoice as recorded', function () {
        Storage::fake('local');
        Http::fake([DOWNLOAD_URL => Http::response(responseZip(), 200, ['Content-Type' => 'application/zip'])]);

        $message = EfacturaMessage::create([
            'efactura_token_id' => $this->token->id,
            'message_id' => '4001130001',
            'type' => 'received',
            'download_id' => '4001130001',
            'is_downloaded' => false,
        ]);

        $this->downloadService->downloadMessage($message);

        $message->refresh();
        expect($message->is_downloaded)->toBeTrue()
            ->and($message->xml_path)->not->toBeNull();
    });
});

describe('per-token batches', function () {
    it('changes only the rows of the token it is given', function () {
        Storage::fake('local');
        Http::fake([
            STATUS_URL => anafStatus('in prelucrare'),
            DOWNLOAD_URL => Http::response(responseZip(), 200, ['Content-Type' => 'application/zip']),
        ]);
        $other = EfacturaToken::create(['cui' => '87654321', 'access_token' => 'ACCESS2', 'refresh_token' => 'REFRESH2', 'expires_at' => now()->addHour(), 'is_active' => true]);
        $upload = fn (array $attributes) => EfacturaUpload::create(['efactura_token_id' => $other->id, 'uploadable_type' => 'App\\Models\\Invoice', 'standard' => 'UBL', ...$attributes]);
        $message = fn (EfacturaToken $token, string $id) => EfacturaMessage::create(['efactura_token_id' => $token->id, 'message_id' => $id, 'type' => 'received', 'download_id' => $id, 'is_downloaded' => false]);

        $poll = makeProcessingUpload($this->token);
        $response = makeCompletedUpload($this->token);
        $received = $message($this->token, '4001130001');
        $otherPoll = $upload(['uploadable_id' => 3, 'status' => UploadStatus::Processing, 'upload_index' => '5001130299']);
        $otherResponse = $upload(['uploadable_id' => 4, 'status' => UploadStatus::Completed, 'download_id' => '3001130099', 'processed_at' => now()]);
        $otherReceived = $message($other, '4001130099');

        $this->downloadService->checkAllStatuses($this->token);
        $this->downloadService->downloadAllResponses($this->token);
        $this->downloadService->downloadAllReceivedInvoices($this->token);

        expect($poll->refresh()->next_poll_at)->not->toBeNull()
            ->and($response->refresh()->response_path)->not->toBeNull()
            ->and($received->refresh()->is_downloaded)->toBeTrue()
            ->and($otherPoll->refresh()->next_poll_at)->toBeNull()
            ->and($otherResponse->refresh()->response_path)->toBeNull()
            ->and($otherReceived->refresh()->is_downloaded)->toBeFalse();
        Http::assertSentCount(3);
    });
});
