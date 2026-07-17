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
use BeeCoded\EFactura\Models\EfacturaToken;
use BeeCoded\EFactura\Models\EfacturaUpload;
use BeeCoded\EFactura\Services\DownloadService;
use BeeCoded\EFacturaSdk\Exceptions\ApiException;
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
