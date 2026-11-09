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
use BeeCoded\EFactura\Enums\UploadStatus;
use BeeCoded\EFactura\Events\InvoiceFailed;
use BeeCoded\EFactura\Events\InvoiceProcessed;
use BeeCoded\EFactura\Events\InvoiceReceived;
use BeeCoded\EFactura\Jobs\CheckUploadStatuses;
use BeeCoded\EFactura\Jobs\DownloadReceivedInvoices;
use BeeCoded\EFactura\Jobs\DownloadResponses;
use BeeCoded\EFactura\Models\EfacturaMessage;
use BeeCoded\EFactura\Models\EfacturaToken;
use BeeCoded\EFactura\Models\EfacturaUpload;
use BeeCoded\EFactura\Services\DownloadService;
use BeeCoded\EFacturaSdk\Exceptions\ApiException;
use BeeCoded\EFacturaSdk\Exceptions\RateLimitExceededException;
use BeeCoded\EFacturaSdk\Services\RateLimiter;
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
    ]);
}

describe('checkStatus', function () {
    it('stops the batch instead of walking the backlog when ANAF rate limits us', function () {
        // A real ANAF 429 arrives as ApiException(429) — the SDK's isRetryable()
        // deliberately excludes 429, so it throws rather than retrying.
        Http::fake([STATUS_URL => Http::response(
            ['eroare' => 'S-au facut deja 100 de apeluri in ultimele 24 ore'],
            429
        )]);

        expect(fn () => $this->downloadService->checkStatus(makeProcessingUpload($this->token)))
            ->toThrow(ApiException::class);
    });

    it('refuses to poll an unknown upload forever in silence', function () {
        // ANAF answers an unknown index_incarcare with 200 + {"eroare"}, so `stare`
        // parses to null: isReady() and isFailed() are BOTH false and the old code
        // fell through to "still in progress, do nothing" — every 10 minutes, forever,
        // logging nothing because nothing threw. SweepStaleUploads does not rescue it
        // either: it sweeps Uploading, and this row is Processing.
        Http::fake([STATUS_URL => Http::response(
            ['eroare' => 'Nu exista niciun upload cu indexul=5001130255'],
            200
        )]);

        $upload = makeProcessingUpload($this->token);

        $this->downloadService->checkStatus($upload);

        $upload->refresh();

        expect($upload->status)->not->toBe(UploadStatus::Processing);
        expect($upload->failure_reason)->toBe(FailureReason::Indeterminate);
    });

    it('still does nothing while ANAF is genuinely processing', function () {
        // The ONLY legitimate no-op. Must survive the fix.
        Http::fake([STATUS_URL => Http::response(['stare' => 'in prelucrare'], 200)]);

        $upload = makeProcessingUpload($this->token);

        $this->downloadService->checkStatus($upload);

        expect($upload->fresh()->status)->toBe(UploadStatus::Processing);
        expect($upload->fresh()->failure_reason)->toBeNull();
    });

    it('completes an upload ANAF reports as ok', function () {
        Http::fake([STATUS_URL => Http::response(
            ['stare' => 'ok', 'id_descarcare' => 'DL999'],
            200
        )]);

        $upload = makeProcessingUpload($this->token);

        $this->downloadService->checkStatus($upload);

        expect($upload->fresh()->status)->toBe(UploadStatus::Completed);
    });

    it('fails an upload ANAF did not take in, without a download or retry', function () {
        Event::fake([InvoiceFailed::class]);
        Http::fake([STATUS_URL => Http::response(['stare' => NOT_PROCESSED_STARE], 200)]);

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
});

describe('downloadResponse', function () {
    it('stops the batch instead of walking the backlog when ANAF rate limits us', function () {
        Http::fake([DOWNLOAD_URL => Http::response(
            ['eroare' => 'S-au facut deja 100 de apeluri in ultimele 24 ore'],
            429
        )]);

        expect(fn () => $this->downloadService->downloadResponse(makeCompletedUpload($this->token)))
            ->toThrow(ApiException::class);
    });

    it('records a poisoned download loudly instead of swallowing it', function () {
        // The SDK's guardDownloadBody() throws ApiException on a 2xx body that is not
        // a ZIP. Swallowing it into a log line left response_path null, so
        // needsResponseDownload() re-selected this row on EVERY scheduled
        // DownloadResponses run, forever, with nothing recorded against the upload.
        //
        // NOTE: this records and escalates the failure; it does not yet EXCLUDE the row
        // from needsResponseDownload(), which would need a column/scope change in files
        // outside this change's ownership.
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
        expect($upload->response_path)->toBeNull();
    });

    it('stores a real ZIP body', function () {
        Storage::fake('local');

        Http::fake([DOWNLOAD_URL => Http::response(
            "PK\x03\x04FAKEZIPBODY",
            200,
            ['Content-Type' => 'application/zip']
        )]);

        $upload = makeCompletedUpload($this->token);

        $this->downloadService->downloadResponse($upload);

        expect($upload->fresh()->response_path)->not->toBeNull();
    });
});

describe('a poisoned response must not be re-downloaded forever', function () {
    /**
     * ANAF answers 2xx but the body is not a ZIP, so guardDownloadBody() throws
     * and response_path stays null. needsResponseDownload() keys on
     * `whereNull('response_path')`, so the row is re-selected on EVERY scheduled
     * DownloadResponses run — one wasted /descarcare call per row per run,
     * forever, against a 100/day-per-message quota.
     *
     * A fake response_path would be a lie (no such file), and encoding "we gave
     * up" into the free-text `errors` payload is exactly the stringly-typed
     * classification v3 removed. So the attempt count gets its own column.
     *
     * Bounded rather than immediate: a junk body may be a transient ANAF glitch,
     * so it is worth a few tries before a human is involved.
     */
    it('stops selecting the row once the attempt cap is reached', function () {
        Storage::fake('local');

        Http::fake([DOWNLOAD_URL => Http::response(
            ['eroare' => 'Nu exista niciun mesaj cu id-ul=3001130059'],
            200,
            ['Content-Type' => 'application/json']
        )]);

        $upload = makeCompletedUpload($this->token);

        // Every scheduled run re-attempts it while it is still under the cap.
        for ($run = 1; $run <= 3; $run++) {
            expect(EfacturaUpload::needsResponseDownload()->pluck('id')->all())
                ->toContain($upload->id);

            $this->downloadService->downloadResponse($upload->refresh());
        }

        // Having burned the cap, it must drop out of the query instead of
        // hammering ANAF for the rest of time.
        expect(EfacturaUpload::needsResponseDownload()->pluck('id')->all())
            ->not->toContain($upload->id);

        $upload->refresh();
        expect($upload->response_attempts)->toBe(3)
            ->and($upload->response_failed_at)->not->toBeNull()
            // ANAF accepted the invoice; only the receipt is missing.
            ->and($upload->status)->toBe(UploadStatus::Completed)
            ->and($upload->response_path)->toBeNull();
    });

    it('does not count a successful download against the cap', function () {
        Storage::fake('local');

        Http::fake([DOWNLOAD_URL => Http::response(
            "PK\x03\x04FAKEZIPBODY",
            200,
            ['Content-Type' => 'application/zip']
        )]);

        $upload = makeCompletedUpload($this->token);

        $this->downloadService->downloadResponse($upload);

        $upload->refresh();
        expect($upload->response_attempts)->toBe(0)
            ->and($upload->response_failed_at)->toBeNull()
            ->and($upload->response_path)->not->toBeNull();
    });
});

/**
 * Topic L5: no status poll can loop forever, and none records a false verdict.
 */
describe('status answers without a download id', function () {
    it('completes an ok without a download id, with a note, and never downloads a response for it', function () {
        Http::fake([
            STATUS_URL => Http::response(['stare' => 'ok'], 200),
            DOWNLOAD_URL => Http::response("PK\x03\x04FAKEZIPBODY", 200, ['Content-Type' => 'application/zip']),
        ]);

        $upload = makeProcessingUpload($this->token);

        (new CheckUploadStatuses)->handle($this->downloadService);

        $upload->refresh();
        expect($upload->status)->toBe(UploadStatus::Completed)
            ->and($upload->download_id)->toBeNull()
            ->and($upload->failure_reason)->toBeNull()
            ->and($upload->errors)->toBe([__('efactura::messages.completed_without_download_id')]);

        (new DownloadResponses)->handle($this->downloadService);

        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/descarcare'));
        expect($upload->fresh()->response_path)->toBeNull();
    });

    it('parks a nok without a download id as indeterminate, keeping the index', function (string|array $body) {
        Event::fake([InvoiceFailed::class]);
        Http::fake([STATUS_URL => Http::response($body, 200)]);

        $upload = makeProcessingUpload($this->token);

        $this->downloadService->checkStatus($upload);

        $upload->refresh();
        expect($upload->status)->toBe(UploadStatus::Failed)
            ->and($upload->failure_reason)->toBe(FailureReason::Indeterminate)
            ->and($upload->upload_index)->toBe('5001130255')
            ->and($upload->errors[0])->toBe(__('efactura::messages.status_nok_without_download_id', ['index' => '5001130255']));
        Event::assertDispatched(InvoiceFailed::class);
    })->with([
        // XmlParser maps a bare <Errors/> header to nok.
        'XML Errors header' => '<?xml version="1.0" encoding="UTF-8"?><header xmlns="mfp:anaf:dgti:efactura:stareMesajFactura:v1"><Errors errorMessage="Nu aveti dreptul de inteorgare pentru id_incarcare= 5001130255"/></header>',
        'JSON nok' => [['stare' => 'nok', 'Errors' => ['Nu aveti dreptul de inteorgare']]],
    ]);
});

describe('permanent status-check failures', function () {
    it('parks an upload after the configured number of permanent failures, not before', function () {
        config(['efactura.polling.max_status_failures' => 3]);
        Event::fake([InvoiceFailed::class]);
        Http::fake([STATUS_URL => Http::response(['eroare' => 'Bad request'], 400)]);

        $upload = makeProcessingUpload($this->token);

        for ($run = 1; $run <= 2; $run++) {
            (new CheckUploadStatuses)->handle($this->downloadService);
        }

        $upload->refresh();
        expect($upload->status)->toBe(UploadStatus::Processing)
            ->and($upload->status_failures)->toBe(2);
        Event::assertNotDispatched(InvoiceFailed::class);

        (new CheckUploadStatuses)->handle($this->downloadService);

        $upload->refresh();
        expect($upload->status)->toBe(UploadStatus::Failed)
            ->and($upload->failure_reason)->toBe(FailureReason::Indeterminate)
            ->and($upload->upload_index)->toBe('5001130255')
            ->and($upload->errors[0])->toBe(__('efactura::messages.status_poll_failures_exhausted', ['index' => '5001130255', 'count' => 3]));
        Event::assertDispatchedTimes(InvoiceFailed::class, 1);

        // A parked row leaves the polling query.
        (new CheckUploadStatuses)->handle($this->downloadService);
        Http::assertSentCount(3);
    });

    it('defaults the bound to five permanent failures', function () {
        Http::fake([STATUS_URL => Http::response(['eroare' => 'Bad request'], 400)]);

        $upload = makeProcessingUpload($this->token);

        for ($run = 1; $run <= 4; $run++) {
            $this->downloadService->checkStatus($upload->refresh());
        }
        expect($upload->refresh()->status)->toBe(UploadStatus::Processing);

        $this->downloadService->checkStatus($upload->refresh());
        expect($upload->refresh()->failure_reason)->toBe(FailureReason::Indeterminate);
    });

    it('never parks an upload for ANAF or transport outages', function () {
        config(['efactura.polling.max_status_failures' => 2]);
        Http::fake([STATUS_URL => Http::response('Service Unavailable', 503)]);

        $upload = makeProcessingUpload($this->token);

        for ($run = 1; $run <= 4; $run++) {
            $this->downloadService->checkStatus($upload->refresh());
        }

        $upload->refresh();
        expect($upload->status)->toBe(UploadStatus::Processing)
            ->and($upload->failure_reason)->toBeNull()
            ->and($upload->status_failures)->toBe(0);
    });

    it('parks an upload whose stored index the SDK refuses', function () {
        config(['efactura.polling.max_status_failures' => 2]);
        Http::fake();

        $upload = makeProcessingUpload($this->token);
        $upload->update(['upload_index' => 'INDEX123']);

        $this->downloadService->checkStatus($upload->refresh());
        expect($upload->refresh()->status)->toBe(UploadStatus::Processing);

        $this->downloadService->checkStatus($upload->refresh());

        $upload->refresh();
        expect($upload->status)->toBe(UploadStatus::Failed)
            ->and($upload->failure_reason)->toBe(FailureReason::Indeterminate)
            ->and($upload->upload_index)->toBe('INDEX123');
        Http::assertNothingSent();
    });

    it('resets the count on any successful read', function () {
        config(['efactura.polling.max_status_failures' => 3]);
        Http::fake([STATUS_URL => Http::sequence()
            ->push(['eroare' => 'Bad request'], 400)
            ->push(['eroare' => 'Bad request'], 400)
            ->push(['stare' => 'in prelucrare'], 200)
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
});

describe('permanent download failures', function () {
    it('counts a 4xx response download against the attempt cap', function () {
        Storage::fake('local');
        Http::fake([DOWNLOAD_URL => Http::response(['eroare' => 'Forbidden'], 403)]);

        $upload = makeCompletedUpload($this->token);

        for ($run = 1; $run <= EfacturaUpload::MAX_RESPONSE_ATTEMPTS; $run++) {
            (new DownloadResponses)->handle($this->downloadService);
        }

        expect($upload->refresh()->response_attempts)->toBe(EfacturaUpload::MAX_RESPONSE_ATTEMPTS);

        (new DownloadResponses)->handle($this->downloadService);
        Http::assertSentCount(EfacturaUpload::MAX_RESPONSE_ATTEMPTS);
    });

    it('does not count a 5xx response download', function () {
        Storage::fake('local');
        Http::fake([DOWNLOAD_URL => Http::response('Service Unavailable', 503)]);

        $upload = makeCompletedUpload($this->token);

        $this->downloadService->downloadResponse($upload);

        expect($upload->refresh()->response_attempts)->toBe(0);
    });

    it('stops re-downloading a received invoice that fails permanently', function () {
        Storage::fake('local');
        Http::fake([DOWNLOAD_URL => Http::response(['eroare' => 'Not found'], 404)]);

        $message = EfacturaMessage::create([
            'efactura_token_id' => $this->token->id,
            'message_id' => '4001130001',
            'type' => 'received',
            'download_id' => '4001130001',
            'is_downloaded' => false,
        ]);

        for ($run = 1; $run <= EfacturaMessage::MAX_DOWNLOAD_ATTEMPTS + 1; $run++) {
            (new DownloadReceivedInvoices)->handle($this->downloadService);
        }

        expect($message->refresh()->download_attempts)->toBe(EfacturaMessage::MAX_DOWNLOAD_ATTEMPTS)
            ->and($message->is_downloaded)->toBeFalse();
        Http::assertSentCount(EfacturaMessage::MAX_DOWNLOAD_ATTEMPTS);
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
        Http::fake([STATUS_URL => Http::response(['stare' => 'ok', 'id_descarcare' => '3001130059'], 200)]);

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

        (new CheckUploadStatuses)->handle($this->downloadService);

        expect($limited->refresh()->status)->toBe(UploadStatus::Processing)
            ->and($limited->status_failures)->toBe(0)
            ->and($next->refresh()->status)->toBe(UploadStatus::Completed);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'id_incarcare=5001130256'));
    });

    it('makes efactura:status --upload report a skipped upload instead of "Done."', function () {
        Http::fake([STATUS_URL => Http::response(['stare' => 'ok', 'id_descarcare' => '3001130059'], 200)]);
        $upload = makeProcessingUpload($this->token);

        exhaustRateLimit(['status_per_day_message' => 1], fn (RateLimiter $limiter) => $limiter->checkStatusQuery('5001130255'));

        $this->artisan('efactura:status', ['--upload' => $upload->id])
            ->expectsOutputToContain('Skipped: the per-message status limit')
            ->doesntExpectOutput('Done.')
            ->assertExitCode(1);

        expect($upload->refresh()->status)->toBe(UploadStatus::Processing);
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
            STATUS_URL => Http::response(['stare' => 'ok', 'id_descarcare' => '3001130059'], 200),
            DOWNLOAD_URL => Http::response("PK\x03\x04FAKEZIPBODY", 200, ['Content-Type' => 'application/zip']),
        ]);
        $upload = makeProcessingUpload($this->token);

        exhaustRateLimit(['download_per_day_message' => 1], fn (RateLimiter $limiter) => $limiter->checkDownload('3001130059'));

        $this->artisan('efactura:status', ['--upload' => $upload->id])
            ->expectsOutputToContain('was not downloaded')
            ->assertExitCode(0);

        expect($upload->refresh()->response_path)->toBeNull();
    });

    it('still prints "Done." from efactura:status --upload when ANAF answered', function () {
        Http::fake([STATUS_URL => Http::response(['stare' => 'in prelucrare'], 200)]);
        $upload = makeProcessingUpload($this->token);

        $this->artisan('efactura:status', ['--upload' => $upload->id])
            ->expectsOutput('Done.')
            ->assertExitCode(0);
    });

    it('skips a response and a received invoice at their per-message download limit', function () {
        Storage::fake('local');
        Http::fake([DOWNLOAD_URL => Http::response("PK\x03\x04FAKEZIPBODY", 200, ['Content-Type' => 'application/zip'])]);

        $limitedUpload = makeCompletedUpload($this->token);
        $nextUpload = EfacturaUpload::create([
            'efactura_token_id' => $this->token->id,
            'uploadable_type' => 'App\\Models\\Invoice',
            'uploadable_id' => 3,
            'status' => UploadStatus::Completed,
            'download_id' => '3001130060',
            'standard' => 'UBL',
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

        (new DownloadResponses)->handle($this->downloadService);
        (new DownloadReceivedInvoices)->handle($this->downloadService);

        expect($limitedUpload->refresh()->response_path)->toBeNull()
            ->and($limitedUpload->response_attempts)->toBe(0)
            ->and($nextUpload->refresh()->response_path)->not->toBeNull()
            ->and($limitedMessage->refresh()->is_downloaded)->toBeFalse()
            ->and($limitedMessage->download_attempts)->toBe(0)
            ->and($nextMessage->refresh()->is_downloaded)->toBeTrue();
        Http::assertSentCount(2);
    });

    it('still stops the whole batch at the global limit', function () {
        Http::fake([STATUS_URL => Http::response(['stare' => 'ok', 'id_descarcare' => '3001130059'], 200)]);

        $upload = makeProcessingUpload($this->token);

        exhaustRateLimit(['global_per_minute' => 1], fn (RateLimiter $limiter) => $limiter->checkGlobal());

        expect(fn () => (new CheckUploadStatuses)->handle($this->downloadService))
            ->toThrow(RateLimitExceededException::class);
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
        Http::fake([STATUS_URL => Http::response(['stare' => 'nok', 'id_descarcare' => '3001130059'], 200)]);

        $upload = makeProcessingUpload($this->token);

        $this->downloadService->checkStatus($upload);

        $upload->refresh();
        expect($upload->failure_reason)->toBe(FailureReason::Validation)
            ->and($upload->download_id)->toBe('3001130059')
            ->and($upload->status_failures)->toBe(0);
    });

    it('leaves a downloaded response as recorded', function () {
        Storage::fake('local');
        Http::fake([DOWNLOAD_URL => Http::response("PK\x03\x04FAKEZIPBODY", 200, ['Content-Type' => 'application/zip'])]);

        $upload = makeCompletedUpload($this->token);

        $this->downloadService->downloadResponse($upload);

        $upload->refresh();
        expect($upload->response_path)->not->toBeNull()
            ->and($upload->response_attempts)->toBe(0)
            ->and($upload->errors)->toBeNull();
    });

    it('leaves a downloaded received invoice as recorded', function () {
        Storage::fake('local');
        Http::fake([DOWNLOAD_URL => Http::response("PK\x03\x04FAKEZIPBODY", 200, ['Content-Type' => 'application/zip'])]);

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
            ->and($message->download_attempts)->toBe(0);
    });
});
