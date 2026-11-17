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
use BeeCoded\EFactura\Events\InvoiceRateLimited;
use BeeCoded\EFactura\Events\InvoiceUploaded;
use BeeCoded\EFactura\Exceptions\DuplicateUploadException;
use BeeCoded\EFactura\Jobs\ProcessPendingUploads;
use BeeCoded\EFactura\Jobs\ProcessSingleUpload;
use BeeCoded\EFactura\Models\EfacturaToken;
use BeeCoded\EFactura\Models\EfacturaUpload;
use BeeCoded\EFactura\Services\DownloadService;
use BeeCoded\EFactura\Services\TokenService;
use BeeCoded\EFactura\Services\UploadService;
use BeeCoded\EFactura\Tests\Fixtures\TestInvoice;
use BeeCoded\EFacturaSdk\Facades\UblBuilder;
use BeeCoded\EFacturaSdk\Services\RateLimiter;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Request as Psr7Request;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/**
 * Upload-failure classification over the REAL SDK client and a faked HTTP transport.
 *
 * These deliberately do NOT mock TokenService or the SDK client. The whole point of
 * FIX 3 is that a genuine ANAF 429 surfaces as ApiException(429) and NOT as the SDK's
 * RateLimitExceededException (which only the client-side pre-flight limiter throws).
 * Mocking the client out is exactly what hid that distinction.
 */
const UPLOAD_URL = 'https://api.anaf.ro/test/FCTEL/rest/upload*';

beforeEach(function () {
    config([
        'efactura-sdk.sandbox' => true,
        'efactura-sdk.oauth.client_id' => 'test-client-id',
        'efactura-sdk.oauth.client_secret' => 'test-client-secret',
        'efactura-sdk.oauth.redirect_uri' => 'https://app.test/efactura/callback',
        // The client-side pre-flight limiter must stay OUT of the way: these tests are
        // about what happens when ANAF itself answers 429.
        'efactura-sdk.rate_limits.enabled' => false,
        // The SDK retries 5xx with a real sleep() between attempts. We are asserting
        // the classification of the final outcome, not the retry loop.
        'efactura-sdk.http.retry_times' => 1,
        'efactura-sdk.http.retry_delay' => 0,
    ]);

    Storage::fake('local');

    $this->token = EfacturaToken::create([
        'cui' => '12345678',
        'access_token' => 'FRESH_ACCESS',
        'refresh_token' => 'FRESH_REFRESH',
        'expires_at' => now()->addHour(),
        'is_active' => true,
    ]);

    $this->invoice = TestInvoice::create([
        'number' => 'INV-001',
        'cui' => '12345678',
        'total' => 100,
    ]);

    $this->uploadService = app(UploadService::class);
});

function makePendingUpload(): EfacturaUpload
{
    return EfacturaUpload::create([
        'efactura_token_id' => test()->token->id,
        'uploadable_type' => TestInvoice::class,
        'uploadable_id' => test()->invoice->id,
        'status' => UploadStatus::Pending,
        'standard' => 'UBL',
    ]);
}

/**
 * Swap the UBL builder for one that runs $hook mid-generation, so a test can observe
 * the row's state at the exact moment the document is still being PREPARED.
 */
function makeUblSpy(callable $hook): void
{
    $spy = new class
    {
        /** @var callable */
        public $hook;

        public function generateInvoiceXml($data): string
        {
            ($this->hook)();

            return '<Invoice>test</Invoice>';
        }
    };

    $spy->hook = $hook;

    UblBuilder::swap($spy);
}

describe('a genuine HTTP 429 from ANAF', function () {
    it('is classified as rate-limited, not as a permanent failure', function () {
        // ANAF's real 429 body is Romanian free text — it contains none of the
        // English "rate limit" phrasing the old LIKE patterns looked for.
        Http::fake([
            UPLOAD_URL => Http::response(
                'S-au facut deja 1000 de apeluri in ultimele 60 de minute',
                429
            ),
        ]);

        $upload = makePendingUpload();

        $this->uploadService->processUpload($upload);

        $upload->refresh();

        // Classified as rate-limited — and, as the name of this test always claimed but
        // the assertion did not, NOT as a permanent failure: the row stays in the pipeline.
        // See 'the persisted state of a rate-limited upload' below.
        expect($upload->failure_reason)->toBe(FailureReason::RateLimited)
            ->and($upload->failure_reason->isRetryable())->toBeTrue()
            ->and($upload->isTerminal())->toBeFalse();
    });
});

describe('event semantics', function () {
    /**
     * A rate-limit hit used to fire InvoiceFailed and was then silently reset to
     * Pending and retried. Anyone alerting on InvoiceFailed got a false alarm
     * followed by a successful upload, and any UI reading the row saw a bogus
     * terminal state in between.
     */
    it('fires InvoiceRateLimited, not InvoiceFailed, on a transient rate-limit hit', function () {
        Event::fake([InvoiceFailed::class, InvoiceRateLimited::class]);

        Http::fake([UPLOAD_URL => Http::response('prea multe apeluri', 429)]);

        $upload = makePendingUpload();
        $this->uploadService->processUpload($upload);

        // ANAF's own 429 names no wait.
        Event::assertDispatched(InvoiceRateLimited::class, fn (InvoiceRateLimited $e) => $e->retryAfterSeconds === null && $e->errors !== []);
        Event::assertNotDispatched(InvoiceFailed::class);
    });

    it('still fires InvoiceFailed for a genuinely terminal rejection', function () {
        Event::fake([InvoiceFailed::class, InvoiceRateLimited::class]);

        Http::fake([UPLOAD_URL => Http::response('Cerere invalida', 400)]);

        $upload = makePendingUpload();
        $this->uploadService->processUpload($upload);

        expect($upload->fresh()->failure_reason)->toBe(FailureReason::Validation);

        Event::assertDispatched(InvoiceFailed::class);
        Event::assertNotDispatched(InvoiceRateLimited::class);
    });
});

describe('transport ambiguity', function () {
    /**
     * The SDK collapses every 5xx to 502 and reports connection loss as 0. Neither
     * proves the document failed to reach ANAF's pipeline — ANAF may have accepted it
     * and then failed to tell us. Retrying those would risk double-filing, so they are
     * parked for human reconciliation instead.
     */
    it('parks a 5xx as indeterminate rather than retrying it', function () {
        Http::fake([UPLOAD_URL => Http::response('Eroare interna', 500)]);

        $upload = makePendingUpload();
        $this->uploadService->processUpload($upload);

        $upload->refresh();

        expect($upload->status)->toBe(UploadStatus::Failed)
            ->and($upload->failure_reason)->toBe(FailureReason::Indeterminate)
            ->and($upload->failure_reason->isRetryable())->toBeFalse();
    });

    // A 408 is a timeout, not a verdict: ANAF may have filed the document. It was validation.
    it('parks a 408 as indeterminate rather than recording a rejection', function () {
        Event::fake([InvoiceFailed::class]);
        Http::fake([UPLOAD_URL => Http::response('Request Timeout', 408)]);

        $upload = makePendingUpload();
        $this->uploadService->processUpload($upload);

        expect($upload->fresh()->failure_reason)->toBe(FailureReason::Indeterminate);
        Event::assertDispatched(InvoiceFailed::class);
    });

    // Nothing usable came back, so the warning must not claim ANAF answered without an index.
    it('leads with the no-answer warning, not the missing-index one, for a thrown uncertain outcome', function (Closure $answer) {
        Event::fake([InvoiceFailed::class]);
        Http::fake([UPLOAD_URL => $answer]);

        $upload = makePendingUpload();
        $this->uploadService->processUpload($upload);

        $upload->refresh();
        expect($upload->failure_reason)->toBe(FailureReason::Indeterminate)
            ->and($upload->errors[0])->toBe(__('efactura::messages.upload_outcome_no_answer'))
            ->and($upload->errors[0])->not->toBe('efactura::messages.upload_outcome_no_answer')
            ->and($upload->errors)->toHaveCount(2);
        Event::assertDispatched(InvoiceFailed::class, fn (InvoiceFailed $e) => $e->errors === $upload->errors);
    })->with([
        'a read timeout after the request was written' => [fn ($request) => Create::rejectionFor(new ConnectException(
            'cURL error 28: Operation timed out after 30001 milliseconds with 0 bytes received',
            new Psr7Request($request->method(), $request->url()),
            null,
            ['errno' => 28, 'request_size' => 412, 'pretransfer_time' => 0.08],
        ))],
        'a 408' => [fn () => Http::response('Request Timeout', 408)],
        'a 5xx' => [fn () => Http::response('Eroare interna', 500)],
    ]);

    // cURL failed to connect, so the request never left: the SDK raises RequestNotSentException,
    // which was an indeterminate 500 and is now retried.
    it('retries an upload the SDK proves was never sent', function () {
        $this->freezeSecond();
        Event::fake([InvoiceFailed::class]);
        Http::fake([UPLOAD_URL => function ($request) {
            $message = 'cURL error 7: Failed to connect to api.anaf.ro port 443';

            return Create::rejectionFor(new ConnectException(
                $message,
                new Psr7Request($request->method(), $request->url()),
                null,
                ['errno' => 7, 'error' => 'Failed to connect to api.anaf.ro port 443'],
            ));
        }]);

        $upload = makePendingUpload();
        $this->uploadService->processUpload($upload);

        expect($upload->fresh())->status->toBe(UploadStatus::Pending)
            ->failure_reason->toBe(FailureReason::Transient)
            ->status_failures->toBe(1)
            ->xml_path->toBeNull()
            ->and($upload->fresh()->next_poll_at->timestamp)->toBe(now()->addSeconds(UploadService::TRANSIENT_RETRY_DELAY)->timestamp);
        Event::assertNotDispatched(InvoiceFailed::class);
    });

    // The send-side counterpart of the preparation retries: each try was claimed and its XML
    // stored, and the fifth unsent one ends the upload and drops the XML it never filed.
    it('abandons an upload at its fifth send that never left', function () {
        Event::fake([InvoiceFailed::class]);
        Http::fake([UPLOAD_URL => fn ($request) => Create::rejectionFor(new ConnectException(
            'cURL error 7: Failed to connect to api.anaf.ro port 443',
            new Psr7Request($request->method(), $request->url()),
            null,
            ['errno' => 7, 'error' => 'Failed to connect to api.anaf.ro port 443'],
        ))]);
        $upload = makePendingUpload();

        foreach (range(1, UploadService::MAX_TRANSIENT_ATTEMPTS - 1) as $attempt) {
            $this->uploadService->processUpload($upload->fresh());

            expect($upload->fresh())->status->toBe(UploadStatus::Pending)
                ->failure_reason->toBe(FailureReason::Transient)
                ->status_failures->toBe($attempt)
                ->xml_path->toBeNull()
                ->and(Storage::disk('local')->allFiles())->toBe([]);
            Event::assertNotDispatched(InvoiceFailed::class);
            $this->travel(UploadService::TRANSIENT_RETRY_DELAY + 1)->seconds();
        }

        $this->uploadService->processUpload($upload->fresh());

        expect($upload->fresh())->status->toBe(UploadStatus::Failed)
            ->failure_reason->toBe(FailureReason::Abandoned)
            ->status_failures->toBe(UploadService::MAX_TRANSIENT_ATTEMPTS)
            ->xml_path->toBeNull()
            ->and(Storage::disk('local')->allFiles())->toBe([]);
        Event::assertDispatchedTimes(InvoiceFailed::class, 1);
    });
});

// status_failures counts the unsent tries while Pending and the refused status reads while
// Processing: ANAF accepting the upload must start the second count from zero.
it('starts counting refused status reads from zero once ANAF accepts an upload that failed to send before', function () {
    Event::fake([InvoiceFailed::class]);
    $sends = 0;
    Http::fake([
        UPLOAD_URL => function ($request) use (&$sends) {
            if (++$sends <= 2) {
                return Create::rejectionFor(new ConnectException(
                    'cURL error 7: Failed to connect to api.anaf.ro port 443',
                    new Psr7Request($request->method(), $request->url()),
                    null,
                    ['errno' => 7, 'error' => 'Failed to connect to api.anaf.ro port 443'],
                ));
            }

            return Http::response('<?xml version="1.0"?><header xmlns="mfp:anaf:dgti:spv:respUploadFisier:v1" ExecutionStatus="0" index_incarcare="5001130259"/>');
        },
        'https://api.anaf.ro/test/FCTEL/rest/stareMesaj*' => Http::response(['eroare' => 'Bad request'], 400),
    ]);
    $upload = makePendingUpload();

    foreach (range(1, 3) as $attempt) {
        $this->uploadService->processUpload($upload->fresh());
        $this->travel(UploadService::TRANSIENT_RETRY_DELAY + 1)->seconds();
    }

    expect($upload->fresh())->status->toBe(UploadStatus::Processing)
        ->upload_index->toBe('5001130259')
        ->status_failures->toBe(0);

    foreach (range(1, DownloadService::MAX_STATUS_FAILURES - 1) as $read) {
        app(DownloadService::class)->checkStatus($upload->fresh());
    }

    expect($upload->fresh())->status->toBe(UploadStatus::Processing)
        ->status_failures->toBe(DownloadService::MAX_STATUS_FAILURES - 1);
    Event::assertNotDispatched(InvoiceFailed::class);
});

describe('the persisted state of a rate-limited upload', function () {
    /**
     * InvoiceRateLimited's contract is explicit: "Do not treat this as a failure. The
     * upload stays in the pipeline." Writing status=Failed/processed_at=now() said the
     * exact opposite to every consumer that reads the MODEL rather than listening to the
     * event — and the row flips back to Pending 60 seconds later anyway.
     */
    it('stays in the pipeline at the model layer while it waits out the limit', function () {
        $this->freezeSecond();
        Http::fake([UPLOAD_URL => Http::response('prea multe apeluri', 429)]);

        $upload = makePendingUpload();
        $this->uploadService->processUpload($upload);

        $upload->refresh();

        expect($upload->status)->toBe(UploadStatus::Pending)
            ->and($upload->isFailed())->toBeFalse()
            ->and($upload->isTerminal())->toBeFalse()
            ->and($upload->processed_at)->toBeNull()
            // The classification is still recorded — the retry path reads it.
            ->and($upload->failure_reason)->toBe(FailureReason::RateLimited)
            // ANAF named no wait, so it waits at least a minute; its attempt left no file.
            ->and($upload->next_poll_at->gte(now()->addSeconds(60)))->toBeTrue()
            ->and($upload->xml_path)->toBeNull()
            ->and(Storage::disk('local')->allFiles())->toBe([]);

        // Not due yet: a run without a lease sends nothing.
        $this->uploadService->processUpload($upload);
        Http::assertSentCount(1);
    });

    /**
     * The layer a consuming application actually reads.
     */
    it('does not look processed or failed to the invoice that owns it', function () {
        Http::fake([UPLOAD_URL => Http::response('prea multe apeluri', 429)]);

        $this->uploadService->processUpload(makePendingUpload());

        $invoice = $this->invoice->fresh();

        expect($invoice->isEfacturaProcessed())->toBeFalse()
            ->and($invoice->getEfacturaStatus())->toBe(UploadStatus::Pending)
            ->and(TestInvoice::efacturaFailed()->count())->toBe(0)
            ->and(TestInvoice::efacturaPending()->count())->toBe(1);
    });
});

describe('the atomic claim', function () {
    /**
     * The claim fired BEFORE XML generation, so ~45 lines of preparation ran with the row
     * already in Uploading. An OOM while building the UBL therefore left the row stranded
     * there, and SweepStaleUploads parked it Indeterminate 30 minutes later — telling an
     * operator to reconcile a document that provably never left the process.
     *
     * parkStrandedUploadAsIndeterminate's docblock justifies never resetting Uploading on
     * the grounds that it "is only ever set immediately before the document goes to ANAF".
     * This is the test that makes that claim true.
     */
    it('is not held while the document is being prepared', function () {
        $upload = makePendingUpload();
        $observed = null;

        makeUblSpy(function () use ($upload, &$observed) {
            $observed = EfacturaUpload::find($upload->id)->status;
        });

        Http::fake([UPLOAD_URL => Http::response('prea multe apeluri', 429)]);

        $this->uploadService->processUpload($upload);

        expect($observed)->toBe(UploadStatus::Pending);
    });

    // A lost claim is not a try: nothing is counted.
    it('sends nothing when one option is changed by a re-queue while the document is being prepared', function (string $method, array $options, string $column, mixed $expected) {
        Http::fake([UPLOAD_URL => Http::response('<?xml version="1.0"?><header xmlns="mfp:anaf:dgti:spv:respUploadFisier:v1" ExecutionStatus="0" index_incarcare="5001130257"/>')]);
        $upload = $this->uploadService->queueUpload($this->invoice);
        makeUblSpy(fn () => $this->uploadService->{$method}($this->invoice, $options));

        $this->uploadService->processUpload($upload);

        Http::assertNothingSent();
        expect($upload->fresh())->status->toBe(UploadStatus::Pending)
            ->xml_path->toBeNull()
            ->status_failures->toBe(0)
            ->{$column}->toBe($expected);
    })->with([
        'standard' => ['queueUpload', ['standard' => 'CII'], 'standard', 'CII'],
        'extern' => ['queueUpload', ['extern' => true], 'is_extern', true],
        'self_billed' => ['queueUpload', ['self_billed' => true], 'is_self_billed', true],
        'b2c' => ['queueB2CUpload', [], 'is_b2c', true],
    ]);

    it('hands its lease back when a re-queue changed the options while the document was prepared', function () {
        Queue::fake();
        Http::fake();
        $upload = $this->uploadService->queueUpload($this->invoice);
        $this->uploadService->dispatchPendingUploads($this->token);
        $job = Queue::pushed(ProcessSingleUpload::class)->first();
        makeUblSpy(fn () => $this->uploadService->queueB2CUpload($this->invoice));

        app()->call([$job, 'handle']);

        Http::assertNothingSent();
        expect(EfacturaUpload::awaitingDispatch()->whereKey($upload->id)->exists())->toBeTrue();
    });

    // The XML is kept, as for any failed read: the row could name it.
    it('hands its lease back when reading the row to remove the unsent XML fails', function () {
        Queue::fake();
        Http::fake();
        $upload = $this->uploadService->queueUpload($this->invoice);
        $this->uploadService->dispatchPendingUploads($this->token);
        $job = Queue::pushed(ProcessSingleUpload::class)->first();
        makeUblSpy(fn () => $this->uploadService->queueB2CUpload($this->invoice));
        $thrown = false;
        // Thrown before the statement runs, so a PostgreSQL test transaction is not aborted.
        DB::connection()->beforeExecuting(function (string $query, array $bindings) use (&$thrown) {
            // Quotes stripped: MySQL and MariaDB quote identifiers with backticks.
            if (!$thrown && str_starts_with(strtolower(str_replace(['"', '`'], '', $query)), 'select xml_path')) {
                $thrown = true;

                throw new QueryException('testing', $query, $bindings, new PDOException('SQLSTATE[08006]: connection failure'));
            }
        });

        app()->call([$job, 'handle']);

        Http::assertNothingSent();
        expect($thrown)->toBeTrue()
            ->and(EfacturaUpload::awaitingDispatch()->whereKey($upload->id)->exists())->toBeTrue()
            ->and($upload->fresh()->status_failures)->toBe(0)
            ->and(Storage::disk('local')->allFiles())->toHaveCount(1);
    });

    // The job loaded the old company's token: sending with it would file under that company.
    it('sends nothing when a re-queue moved the upload to another company while the document was prepared', function () {
        Http::fake();
        EfacturaToken::create([
            'cui' => '87654321',
            'access_token' => 'OTHER_ACCESS',
            'refresh_token' => 'OTHER_REFRESH',
            'expires_at' => now()->addHour(),
            'is_active' => true,
        ]);
        $upload = $this->uploadService->queueUpload($this->invoice);
        makeUblSpy(function () {
            $this->invoice->update(['cui' => '87654321']);
            $this->uploadService->queueUpload($this->invoice);
        });

        $this->uploadService->processUpload($upload);

        Http::assertNothingSent();
        expect($upload->fresh())->status->toBe(UploadStatus::Pending)
            ->xml_path->toBeNull();
    });

    // Laravel re-runs a claim whose connection failed after its commit; the re-run matches nothing.
    it('sends a document whose claim committed before its connection failed', function () {
        Http::fake([UPLOAD_URL => Http::response('<?xml version="1.0"?><header xmlns="mfp:anaf:dgti:spv:respUploadFisier:v1" ExecutionStatus="0" index_incarcare="5001130257"/>')]);
        $ran = false;
        DB::connection()->beforeExecuting(function (string $query, array $bindings) use (&$ran) {
            if (!$ran && str_starts_with(strtolower($query), 'update') && str_contains($query, 'xml_path') && in_array('uploading', $bindings, true)) {
                $ran = true;
                DB::affectingStatement($query, $bindings);
            }
        });

        $this->uploadService->processUpload($upload = makePendingUpload());

        expect($ran)->toBeTrue()
            ->and($upload->fresh())->status->toBe(UploadStatus::Processing)
            ->upload_index->toBe('5001130257');
        Http::assertSentCount(1);
    });

    it('sends what the row says on the next attempt after a re-queue changed it', function () {
        Http::fake([UPLOAD_URL => Http::response('<?xml version="1.0"?><header xmlns="mfp:anaf:dgti:spv:respUploadFisier:v1" ExecutionStatus="0" index_incarcare="5001130257"/>')]);
        $upload = $this->uploadService->queueUpload($this->invoice);
        makeUblSpy(fn () => $this->uploadService->queueB2CUpload($this->invoice, ['standard' => 'CN']));
        $this->uploadService->processUpload($upload);

        makeUblSpy(fn () => null);
        $this->uploadService->processUpload($upload->fresh());

        Http::assertSent(fn ($request) => str_contains($request->url(), '/uploadb2c?') && str_contains($request->url(), 'standard=CN'));
    });
});

/**
 * A grant that cannot be used fails before anything is sent, for every upload of the company
 * alike. It is not the upload's fault, so it is never counted, and the row waits in Pending.
 */
describe('a grant that cannot be used', function () {
    it('leaves the upload pending and uncounted, however often it is tried', function (Closure $setup) {
        Event::fake([InvoiceFailed::class, InvoiceRateLimited::class]);
        Http::fake([
            'https://logincert.anaf.ro/anaf-oauth2/v1/token' => Http::response(['error' => 'invalid_client'], 401),
            UPLOAD_URL => Http::response('should never be reached', 200),
        ]);
        $setup->call($this);
        $upload = makePendingUpload();

        foreach (range(1, 6) as $attempt) {
            $this->uploadService->processUpload($upload->fresh());
            // Past a 15-minute pause, so every cycle reaches the grant again.
            $this->travel(16)->minutes();
        }

        expect($upload->fresh())->status->toBe(UploadStatus::Pending)
            ->failure_reason->toBeNull()
            ->status_failures->toBe(0)
            ->next_poll_at->toBeNull()
            ->xml_path->toBeNull()
            ->and(Storage::disk('local')->allFiles())->toBe([]);
        Event::assertNothingDispatched();
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/upload'));
    })->with([
        'a refresh ANAF refuses with 401' => [fn () => $this->token->update(['expires_at' => now()->addSeconds(60)])],
        // Exactly as the pre-v3 release left the row: raw, unencrypted credentials.
        'a token that predates the encryption migration' => [fn () => DB::table('efactura_tokens')->where('id', $this->token->id)->update([
            'access_token' => 'PLAINTEXT_ACCESS',
            'refresh_token' => 'PLAINTEXT_REFRESH',
        ])],
        'a deactivated token' => [fn () => $this->token->update(['is_active' => false])],
    ]);
});

/**
 * The grant is refused by ANAF on the upload request itself: the client already holds the
 * document, so only isGrantFailure()'s AuthenticationException arm keeps it uncounted.
 */
describe('an upload request refused with 401', function () {
    it('leaves the upload pending and uncounted, however often it is tried', function () {
        Event::fake([InvoiceFailed::class, InvoiceRateLimited::class]);
        Http::fake([UPLOAD_URL => Http::response(['eroare' => 'Unauthorized'], 401)]);
        // Valid for the whole run, so every try reaches the upload request, not a refresh.
        $this->token->update(['expires_at' => now()->addDay()]);
        $upload = makePendingUpload();

        foreach (range(1, 6) as $attempt) {
            $this->uploadService->processUpload($upload->fresh());
            // Past a 15-minute pause, so every cycle reaches ANAF again.
            $this->travel(16)->minutes();
        }

        expect($upload->fresh())->status->toBe(UploadStatus::Pending)
            ->failure_reason->toBeNull()
            ->status_failures->toBe(0)
            ->xml_path->toBeNull()
            ->and(Storage::disk('local')->allFiles())->toBe([]);
        Event::assertNothingDispatched();
        Http::assertSentCount(6);
    });
});

/**
 * Preparation failed for a reason that is ours, not the invoice's: a database or storage error.
 * Nothing was sent, so it is retried, and only the fifth failure ends the upload.
 */
describe('a local error while the upload is prepared', function () {
    it('is retried, announced to nobody, and abandoned at the fifth', function (Closure $setup, string $class) {
        Event::fake([InvoiceFailed::class]);
        Http::fake();
        $setup->call($this);
        $upload = makePendingUpload();

        foreach (range(1, UploadService::MAX_TRANSIENT_ATTEMPTS - 1) as $attempt) {
            $this->uploadService->processUpload($upload->fresh());

            expect($upload->fresh())->status->toBe(UploadStatus::Pending)
                ->failure_reason->toBe(FailureReason::Transient)
                ->status_failures->toBe($attempt)
                // The class only: a database message can carry SQL and data.
                ->errors->toBe([__('efactura::messages.local_failure', ['class' => $class])])
                ->and($this->invoice->fresh()->isEfacturaProcessed())->toBeFalse();
            Event::assertNotDispatched(InvoiceFailed::class);

            $this->travel(UploadService::TRANSIENT_RETRY_DELAY + 1)->seconds();
        }

        $this->uploadService->processUpload($upload->fresh());

        expect($upload->fresh())->status->toBe(UploadStatus::Failed)
            ->failure_reason->toBe(FailureReason::Abandoned)
            ->status_failures->toBe(UploadService::MAX_TRANSIENT_ATTEMPTS);
        Event::assertDispatchedTimes(InvoiceFailed::class, 1);
        Http::assertNothingSent();
    })->with([
        'a disk that does not store the XML' => [function () {
            $disk = Mockery::mock(Filesystem::class);
            $disk->shouldReceive('put')->andReturn(false);
            Storage::set('local', $disk);
        }, 'RuntimeException'],
        // Constructed, not raised by a failing query: that would abort the test transaction on PostgreSQL.
        'a query exception' => [fn () => makeUblSpy(fn () => throw new QueryException('testing', 'select * from invoice_lines', [], new PDOException('SQLSTATE[40P01]: deadlock detected'))), 'QueryException'],
        'a raw PDO exception' => [fn () => makeUblSpy(fn () => throw new PDOException('SQLSTATE[40001]: serialization failure')), 'PDOException'],
    ]);

    it('records an error in the invoice data as a validation failure, once', function () {
        Event::fake([InvoiceFailed::class]);
        Http::fake();
        makeUblSpy(fn () => throw new InvalidArgumentException('The invoice has no lines'));
        $upload = makePendingUpload();

        $this->uploadService->processUpload($upload);

        expect($upload->fresh())->status->toBe(UploadStatus::Failed)
            ->failure_reason->toBe(FailureReason::Validation)
            ->errors->toBe(['The invoice has no lines']);
        Event::assertDispatchedTimes(InvoiceFailed::class, 1);
        Http::assertNothingSent();
    });
});

describe('failure classification is carried in a dedicated column', function () {
    it('records rate_limited without encoding the class in the error text', function () {
        Http::fake([UPLOAD_URL => Http::response('prea multe apeluri', 429)]);

        $upload = makePendingUpload();
        $this->uploadService->processUpload($upload);

        $upload->refresh();

        expect($upload->failure_reason)->toBe(FailureReason::RateLimited);

        // The marker must live in its own column, not smuggled through the JSON text.
        expect(json_encode($upload->errors))->not->toContain('RATE_LIMIT_EXCEEDED:');
    });

    it('classifies an ANAF validation rejection as terminal', function () {
        Http::fake([
            UPLOAD_URL => Http::response(
                '<?xml version="1.0" encoding="UTF-8"?>'
                .'<header xmlns="mfp:anaf:dgti:efactura:stareMesajFactura:v1" '
                .'dateResponse="202407171230" ExecutionStatus="1">'
                .'<Errors errorMessage="Nu exista niciun CIF pentru care sa aveti drept"/>'
                .'</header>',
                200
            ),
        ]);

        $upload = makePendingUpload();
        $this->uploadService->processUpload($upload);

        $upload->refresh();

        expect($upload->status)->toBe(UploadStatus::Failed)
            ->and($upload->failure_reason)->toBe(FailureReason::Validation);
    });
});

/**
 * ANAF's upload answer reached us but does not prove the document was NOT taken in.
 * Recording it as a rejection (Validation) let queueUpload() recycle the row and file the
 * invoice twice; it must be parked for reconciliation instead.
 */
describe('an upload answer that does not say whether the document was taken in', function () {
    it('parks an unreadable 2xx body as indeterminate and refuses to recycle it', function (string $body) {
        Event::fake([InvoiceFailed::class, InvoiceUploaded::class]);
        Http::fake([UPLOAD_URL => Http::response($body, 200)]);

        $upload = makePendingUpload();
        $this->uploadService->processUpload($upload);

        $upload->refresh();
        expect($upload->status)->toBe(UploadStatus::Failed)
            ->and($upload->failure_reason)->toBe(FailureReason::Indeterminate)
            ->and($upload->upload_index)->toBeNull();
        Event::assertDispatched(InvoiceFailed::class);
        Event::assertNotDispatched(InvoiceUploaded::class);

        expect(fn () => $this->uploadService->queueUpload($this->invoice))
            ->toThrow(DuplicateUploadException::class);
        expect($upload->fresh()->failure_reason)->toBe(FailureReason::Indeterminate);
    })->with([
        'HTML page' => '<!DOCTYPE html><html><head><title>Eroare</title></head><body><h1>Serviciu indisponibil</h1></body></html>',
        'malformed XML' => '<?xml version="1.0" encoding="UTF-8"?><header ExecutionStatus="0" index_incarcare="12345"',
    ]);

    // The index ANAF returned is kept, even one that cannot be polled: SPV is searched by it.
    it('parks an answer without a usable index or status as indeterminate', function (string $body, array $expectedErrors, ?string $index) {
        Event::fake([InvoiceFailed::class, InvoiceUploaded::class]);
        Http::fake([UPLOAD_URL => Http::response($body, 200)]);

        $upload = makePendingUpload();
        $this->uploadService->processUpload($upload);

        $upload->refresh();
        expect($upload->status)->toBe(UploadStatus::Failed)
            ->and($upload->failure_reason)->toBe(FailureReason::Indeterminate)
            ->and($upload->upload_index)->toBe($index)
            ->and($upload->errors)->toBe($expectedErrors);
        Event::assertDispatched(InvoiceFailed::class, fn (InvoiceFailed $e) => $e->errors === $expectedErrors);
        Event::assertNotDispatched(InvoiceUploaded::class);
    })->with([
        'success with a non-numeric index' => [
            '<header xmlns="mfp:anaf:dgti:spv:respUploadFisier:v1" dateResponse="202407171230" ExecutionStatus="0" index_incarcare="abc"/>',
            fn () => [__('efactura::messages.upload_outcome_unknown')],
            'abc',
        ],
        'success with a spaced index' => [
            json_encode(['ExecutionStatus' => '0', 'index_incarcare' => '12 345']),
            fn () => [__('efactura::messages.upload_outcome_unknown')],
            '12 345',
        ],
        'success with an empty index' => [
            json_encode(['ExecutionStatus' => '0', 'index_incarcare' => '']),
            fn () => [__('efactura::messages.upload_outcome_unknown')],
            null,
        ],
        'success without an index' => [
            '<header xmlns="mfp:anaf:dgti:spv:respUploadFisier:v1" dateResponse="202407171230" ExecutionStatus="0"/>',
            fn () => [__('efactura::messages.upload_outcome_unknown')],
            null,
        ],
        'errors without a status' => [
            json_encode(['Errors' => ['Eroare la procesare', '']]),
            fn () => [__('efactura::messages.upload_outcome_unknown'), 'Eroare la procesare'],
            null,
        ],
        'an unrecognised status without an index' => [
            json_encode(['ExecutionStatus' => '9']),
            fn () => [__('efactura::messages.upload_outcome_unknown')],
            null,
        ],
    ]);

    it('keeps an index that cannot be polled, so it cannot be resolved as not filed without --despite-index', function () {
        Http::fake([UPLOAD_URL => Http::response(json_encode(['ExecutionStatus' => '0', 'index_incarcare' => 'ABC123']), 200)]);
        $upload = makePendingUpload();

        $this->uploadService->processUpload($upload);

        expect($upload->fresh())->status->toBe(UploadStatus::Failed)
            ->failure_reason->toBe(FailureReason::Indeterminate)
            ->upload_index->toBe('ABC123')
            ->and(fn () => $this->uploadService->resolveIndeterminateAsNotFiled($upload->fresh()))->toThrow(InvalidArgumentException::class);
    });

    it('still rejects an explicit ExecutionStatus=1 without an index as a validation failure', function () {
        Http::fake([UPLOAD_URL => Http::response(json_encode([
            'ExecutionStatus' => '1',
            'Errors' => ['Fisierul nu respecta schema'],
        ]), 200)]);

        $upload = makePendingUpload();
        $this->uploadService->processUpload($upload);

        $upload->refresh();
        expect($upload->failure_reason)->toBe(FailureReason::Validation)
            ->and($upload->errors)->toBe(['Fisierul nu respecta schema']);
    });
});

/**
 * ANAF assigns an upload index only to a document it received. Dropping that index and
 * recording a rejection lost the only handle on a filed invoice.
 */
describe('an upload answer carrying a usable index', function () {
    it('is processing with that index whatever ExecutionStatus says', function (string $body) {
        Event::fake([InvoiceFailed::class, InvoiceUploaded::class]);
        Http::fake([UPLOAD_URL => Http::response($body, 200)]);

        $upload = makePendingUpload();
        $this->uploadService->processUpload($upload);

        $upload->refresh();
        expect($upload->status)->toBe(UploadStatus::Processing)
            ->and($upload->upload_index)->toBe('12345')
            ->and($upload->failure_reason)->toBeNull();
        Event::assertDispatched(InvoiceUploaded::class);
        Event::assertNotDispatched(InvoiceFailed::class);
    })->with([
        'JSON without ExecutionStatus' => json_encode(['index_incarcare' => '12345']),
        // The SDK trims the index, so a trailing newline still leaves a digit index.
        'JSON with a trailing-newline index' => json_encode(['ExecutionStatus' => '0', 'index_incarcare' => "12345\n"]),
        'XML header without ExecutionStatus' => '<header xmlns="mfp:anaf:dgti:spv:respUploadFisier:v1" dateResponse="202407171230" index_incarcare="12345"/>',
        'XML header with ExecutionStatus=1' => '<header xmlns="mfp:anaf:dgti:spv:respUploadFisier:v1" dateResponse="202407171230" ExecutionStatus="1" index_incarcare="12345"/>',
    ]);

    // The stale sweep parked the row while ANAF was answering: the index is the truth.
    it('clears a park written while ANAF answered, and the row stays re-queueable', function () {
        Event::fake([InvoiceUploaded::class]);
        $upload = makePendingUpload();
        Http::fake([UPLOAD_URL => function () use ($upload) {
            app(UploadService::class)->parkStrandedUploadAsIndeterminate(EfacturaUpload::find($upload->id), 'swept');

            return Http::response(json_encode(['ExecutionStatus' => '0', 'index_incarcare' => '12345']));
        }]);

        $this->uploadService->processUpload($upload);

        expect($upload->fresh())->status->toBe(UploadStatus::Processing)
            ->upload_index->toBe('12345')
            ->failure_reason->toBeNull()
            ->errors->toBeNull()
            ->processed_at->toBeNull()
            ->and($this->uploadService->queueUpload($this->invoice)->is($upload))->toBeTrue();
        Event::assertDispatchedTimes(InvoiceUploaded::class, 1);
    });

    it('records the index when the first write of it fails', function () {
        Event::fake([InvoiceFailed::class, InvoiceUploaded::class]);
        Http::fake([UPLOAD_URL => Http::response(json_encode(['ExecutionStatus' => '0', 'index_incarcare' => '12345']))]);
        $thrown = false;
        // Thrown before the statement runs, so a PostgreSQL test transaction is not aborted.
        DB::connection()->beforeExecuting(function (string $query, array $bindings) use (&$thrown) {
            if (!$thrown && str_starts_with(strtolower($query), 'update') && str_contains($query, 'upload_index') && in_array('processing', $bindings, true)) {
                $thrown = true;

                throw new QueryException('testing', $query, $bindings, new PDOException('SQLSTATE[40P01]: deadlock detected'));
            }
        });

        $this->uploadService->processUpload($upload = makePendingUpload());

        expect($thrown)->toBeTrue()
            ->and($upload->fresh())->status->toBe(UploadStatus::Processing)
            ->upload_index->toBe('12345')
            ->failure_reason->toBeNull();
        Event::assertDispatched(InvoiceUploaded::class);
        Event::assertNotDispatched(InvoiceFailed::class);
    });

    // The log line before the retry must not cost the index.
    it('records the index when the first write fails and the log channel is down', function () {
        Http::fake([UPLOAD_URL => Http::response(json_encode(['ExecutionStatus' => '0', 'index_incarcare' => '12345']))]);
        // A listener that throws is a log handler that fails: it surfaces from Log::critical().
        Log::listen(fn (MessageLogged $log) => $log->level === 'critical' ? throw new RuntimeException('log channel down') : null);
        $thrown = false;
        DB::connection()->beforeExecuting(function (string $query, array $bindings) use (&$thrown) {
            if (!$thrown && str_starts_with(strtolower($query), 'update') && str_contains($query, 'upload_index') && in_array('processing', $bindings, true)) {
                $thrown = true;

                throw new QueryException('testing', $query, $bindings, new PDOException('SQLSTATE[40P01]: deadlock detected'));
            }
        });

        $this->uploadService->processUpload($upload = makePendingUpload());

        expect($thrown)->toBeTrue()
            ->and($upload->fresh())->status->toBe(UploadStatus::Processing)
            ->upload_index->toBe('12345');
    });

    // A lost connection after the write committed: the re-run finds the row already recorded.
    it('takes a write that ran before its error was reported as recorded', function () {
        Event::fake([InvoiceFailed::class, InvoiceUploaded::class]);
        Http::fake([UPLOAD_URL => Http::response(json_encode(['ExecutionStatus' => '0', 'index_incarcare' => '12345']))]);
        $thrown = false;
        DB::listen(function (QueryExecuted $query) use (&$thrown) {
            if (!$thrown && str_starts_with(strtolower($query->sql), 'update') && str_contains($query->sql, 'upload_index') && in_array('processing', $query->bindings, true)) {
                $thrown = true;

                throw new QueryException('testing', $query->sql, $query->bindings, new PDOException('SQLSTATE[08006]: connection failure'));
            }
        });

        $this->uploadService->processUpload($upload = makePendingUpload());

        expect($thrown)->toBeTrue()
            ->and($upload->status)->toBe(UploadStatus::Processing)
            ->and($upload->fresh())->status->toBe(UploadStatus::Processing)
            ->upload_index->toBe('12345');
        Event::assertDispatchedTimes(InvoiceUploaded::class, 1);
        Event::assertNotDispatched(InvoiceFailed::class);
    });
});

describe('an outcome write that fails or whose acknowledgement is lost', function () {
    it('records a refusal whose first write fails', function () {
        Event::fake([InvoiceFailed::class]);
        Http::fake([UPLOAD_URL => Http::response('Fisierul nu este valid', 400)]);
        $thrown = false;
        // Thrown before the statement runs, so a PostgreSQL test transaction is not aborted.
        DB::connection()->beforeExecuting(function (string $query, array $bindings) use (&$thrown) {
            if (!$thrown && str_starts_with(strtolower($query), 'update') && in_array('failed', $bindings, true) && in_array('validation', $bindings, true)) {
                $thrown = true;

                throw new QueryException('testing', $query, $bindings, new PDOException('SQLSTATE[40P01]: deadlock detected'));
            }
        });

        $this->uploadService->processUpload($upload = makePendingUpload());

        expect($thrown)->toBeTrue()
            ->and($upload->fresh())->status->toBe(UploadStatus::Failed)
            ->failure_reason->toBe(FailureReason::Validation);
        Event::assertDispatchedTimes(InvoiceFailed::class, 1);
    });

    // Laravel re-runs a write whose connection failed after its commit; the re-run matches nothing.
    it('announces an outcome whose write committed before its connection failed', function (Closure $answer, string $reason, string $event) {
        Event::fake([InvoiceFailed::class, InvoiceRateLimited::class]);
        Http::fake([UPLOAD_URL => $answer]);
        $ran = false;
        DB::connection()->beforeExecuting(function (string $query, array $bindings) use (&$ran, $reason) {
            if (!$ran && str_starts_with(strtolower($query), 'update') && in_array($reason, $bindings, true)) {
                $ran = true;
                DB::affectingStatement($query, $bindings);
            }
        });

        $this->uploadService->processUpload($upload = makePendingUpload());

        // A sent document keeps its file; one that was not sent leaves none.
        expect($ran)->toBeTrue()
            ->and(Storage::disk('local')->allFiles())->toBe(array_filter([$upload->fresh()->xml_path]));
        Event::assertDispatchedTimes($event, 1);
    })->with([
        'a refusal' => [fn () => Http::response('Fisierul nu este valid', 400), 'validation', InvoiceFailed::class],
        'a rate limit' => [fn () => Http::response('prea multe apeluri', 429), 'rate_limited', InvoiceRateLimited::class],
    ]);

    it('logs the error of an upload whose outcome is unknown', function () {
        $logged = [];
        Log::listen(function (MessageLogged $log) use (&$logged) {
            if ($log->message === 'EFactura: Upload outcome is unknown, parked for reconciliation') {
                $logged[] = $log->context['error'] ?? null;
            }
        });
        // An \Error passes both Guzzle's and the SDK's catch (Exception).
        Http::fake([UPLOAD_URL => fn () => throw new TypeError('boom')]);

        $this->uploadService->processUpload($upload = makePendingUpload());

        expect($upload->fresh()->failure_reason)->toBe(FailureReason::Indeterminate)
            ->and($logged)->toContain('boom');
    });
});

/**
 * Topic L2: token bookkeeping after the upload call never replaces its result.
 */
describe('token bookkeeping after the upload call', function () {
    it('keeps the index ANAF assigned when recording last_used_at fails', function () {
        Http::fake([UPLOAD_URL => Http::response(json_encode(['ExecutionStatus' => '0', 'index_incarcare' => '12345']), 200)]);
        EfacturaToken::updating(function (EfacturaToken $token) {
            if ($token->isDirty('last_used_at')) {
                throw new RuntimeException('last_used_at save failed');
            }
        });

        $upload = makePendingUpload();
        $this->uploadService->processUpload($upload);

        $upload->refresh();
        expect($upload->status)->toBe(UploadStatus::Processing)
            ->and($upload->upload_index)->toBe('12345')
            ->and($upload->failure_reason)->toBeNull();
    });
});

/**
 * Topic L3: a listener that throws is reported, never propagated, and never changes the
 * state that was already recorded.
 */
describe('a throwing listener after a recorded upload state', function () {
    beforeEach(function () {
        foreach ([InvoiceUploaded::class, InvoiceFailed::class, InvoiceRateLimited::class] as $event) {
            Event::listen($event, function () {
                throw new RuntimeException('listener failed');
            });
        }
    });

    it('leaves an accepted upload processing with its index', function () {
        Http::fake([UPLOAD_URL => Http::response(json_encode(['ExecutionStatus' => '0', 'index_incarcare' => '12345']), 200)]);

        $upload = makePendingUpload();
        $this->uploadService->processUpload($upload);

        $upload->refresh();
        expect($upload->status)->toBe(UploadStatus::Processing)
            ->and($upload->upload_index)->toBe('12345')
            ->and($upload->failure_reason)->toBeNull();
    });

    it('leaves a rejection recorded as a rejection', function () {
        Http::fake([UPLOAD_URL => Http::response(json_encode([
            'ExecutionStatus' => '1',
            'Errors' => ['Fisierul nu respecta schema'],
        ]), 200)]);

        $upload = makePendingUpload();
        $this->uploadService->processUpload($upload);

        $upload->refresh();
        expect($upload->status)->toBe(UploadStatus::Failed)
            ->and($upload->failure_reason)->toBe(FailureReason::Validation);
    });

    it('leaves a rate-limited upload back in the pipeline', function () {
        Http::fake([UPLOAD_URL => Http::response(['eroare' => 'Limita depasita'], 429)]);

        $upload = makePendingUpload();
        $this->uploadService->processUpload($upload);

        $upload->refresh();
        expect($upload->status)->toBe(UploadStatus::Pending)
            ->and($upload->failure_reason)->toBe(FailureReason::RateLimited);
    });
});

/**
 * A paused grant refuses an upload without asking ANAF. Counted as a transient failure, it
 * abandoned every upload of the company within about 2.5 minutes.
 */
describe('an upload refused by a paused grant', function () {
    it('waits the pause out without spending attempts, then uploads', function () {
        Event::fake([InvoiceFailed::class]);
        Http::fake([
            'https://logincert.anaf.ro/anaf-oauth2/v1/token' => Http::sequence()
                ->push(['error' => 'invalid_client'], 401)
                ->push(['access_token' => 'NEW_ACCESS', 'refresh_token' => 'NEW_REFRESH', 'expires_in' => 3600]),
            UPLOAD_URL => Http::response(json_encode(['ExecutionStatus' => '0', 'index_incarcare' => '12345']), 200),
        ]);
        $this->token->update(['expires_at' => now()->addSeconds(60)]);
        $upload = makePendingUpload();

        // The first run asks ANAF and is refused; the next five fall in the pause.
        foreach (range(1, 6) as $attempt) {
            $this->uploadService->processUpload($upload->fresh());
            $this->travel(30)->seconds();
        }

        expect($upload->fresh())->status->toBe(UploadStatus::Pending)
            ->failure_reason->toBeNull()
            ->status_failures->toBe(0);
        Event::assertNotDispatched(InvoiceFailed::class);
        Http::assertSentCount(1);

        $this->travel(15)->minutes();
        $this->uploadService->processUpload($upload->fresh());

        expect($upload->fresh()->status)->toBe(UploadStatus::Processing)
            ->and($upload->fresh()->upload_index)->toBe('12345');
    });

    // Its lease is handed back, so the next dispatch after the pause takes it at once.
    it('builds no XML and hands its lease back', function () {
        Queue::fake();
        $upload = makePendingUpload();
        $this->uploadService->dispatchPendingUploads($this->token);
        $lease = Queue::pushed(ProcessSingleUpload::class)->first()->lease;
        coolDownGrant($this->token, 600);
        Http::fake();

        $this->uploadService->processUpload($upload->fresh(), $lease);

        expect($lease)->not->toBeNull()
            ->and($upload->fresh())->status->toBe(UploadStatus::Pending)
            ->next_poll_at->toBeNull()
            // Only the hand-back before the claim writes no error.
            ->errors->toBeNull()
            ->and(Storage::disk('local')->allFiles())->toBe([]);
        Http::assertNothingSent();
    });

    // Redis hands a stored int back as a numeric string, which an is_int() read took for no pause.
    it('keeps a rate-limited row\'s reason while the pause lasts', function () {
        Http::fake();
        $upload = makePendingUpload();
        $upload->update(['failure_reason' => FailureReason::RateLimited]);
        coolDownGrant($this->token, 600);

        $this->uploadService->processUpload($upload->fresh());

        expect($upload->fresh())->status->toBe(UploadStatus::Pending)
            ->failure_reason->toBe(FailureReason::RateLimited);
        Http::assertNothingSent();
    });
});

describe('the global quota pre-check', function () {
    it('makes a job wait at least a minute without asking ANAF or announcing a rate limit', function () {
        $this->freezeSecond();
        Event::fake([InvoiceRateLimited::class]);
        Http::fake();
        $rateLimiter = Mockery::mock(RateLimiter::class);
        $rateLimiter->shouldReceive('isEnabled')->andReturn(true);
        $rateLimiter->shouldReceive('getRemainingQuota')->with('global')->andReturn(['remaining' => 0, 'resetsIn' => 40]);
        $this->instance(RateLimiter::class, $rateLimiter);
        app()->forgetInstance(UploadService::class);
        $upload = makePendingUpload();

        app()->call([new ProcessSingleUpload($upload), 'handle']);

        expect($upload->fresh())->status->toBe(UploadStatus::Pending)
            ->failure_reason->toBe(FailureReason::RateLimited)
            ->and($upload->fresh()->next_poll_at->gte(now()->addSeconds(60)))->toBeTrue()
            ->and(Storage::disk('local')->allFiles())->toBe([]);
        Event::assertNotDispatched(InvoiceRateLimited::class);
        Http::assertNothingSent();
    });
});

describe('a model read before the row was reset for a retry', function () {
    // The run must work from the claimed row's values, not the stale Failed copy: the guarded
    // hand-back (uploadingRow) matches on the xml_path this claim wrote.
    it('records the outcome of its own run', function () {
        $upload = makePendingUpload();
        $upload->update(['status' => UploadStatus::Failed, 'failure_reason' => FailureReason::Validation, 'errors' => ['earlier']]);
        $stale = EfacturaUpload::find($upload->id);

        // Another job resets the row for a retry; this run still holds the Failed copy.
        EfacturaUpload::whereKey($upload->id)->update(['status' => UploadStatus::Pending, 'failure_reason' => null]);
        $this->token->update(['is_active' => false]);
        Http::fake([UPLOAD_URL => Http::response('should never be reached', 200)]);

        $this->uploadService->processUpload($stale);

        expect($upload->fresh())->status->toBe(UploadStatus::Pending)
            ->xml_path->toBeNull()
            ->errors->toBe([__('efactura::messages.local_failure', ['class' => 'RuntimeException'])]);
        Http::assertNothingSent();
    });
});

describe('a rate limit answered after the row was parked', function () {
    it('announces no rate limit for a row it did not hand back', function () {
        Event::fake([InvoiceRateLimited::class]);
        $upload = makePendingUpload();
        Http::fake([UPLOAD_URL => function () use ($upload) {
            // The stale sweep parks the row while the request is in flight.
            EfacturaUpload::whereKey($upload->id)->update([
                'status' => UploadStatus::Failed,
                'failure_reason' => FailureReason::Indeterminate,
            ]);

            return Http::response(['eroare' => 'Limita depasita'], 429);
        }]);

        $this->uploadService->processUpload($upload);

        Event::assertNotDispatched(InvoiceRateLimited::class);
        expect($upload->fresh()->failure_reason)->toBe(FailureReason::Indeterminate);
    });
});

// A transport failure answered after the row was parked: this worker no longer owns it.
describe('a send that failed after the row was parked', function () {
    it('leaves the park in place and announces nothing', function () {
        Event::fake([InvoiceFailed::class, InvoiceRateLimited::class]);
        $upload = makePendingUpload();
        Http::fake([UPLOAD_URL => function ($request) use ($upload) {
            EfacturaUpload::whereKey($upload->id)->update([
                'status' => UploadStatus::Failed,
                'failure_reason' => FailureReason::Indeterminate,
            ]);

            return Create::rejectionFor(new ConnectException(
                'cURL error 7: Failed to connect to api.anaf.ro port 443',
                new Psr7Request($request->method(), $request->url()),
                null,
                ['errno' => 7, 'error' => 'Failed to connect to api.anaf.ro port 443'],
            ));
        }]);

        $this->uploadService->processUpload($upload);

        expect($upload->fresh())->status->toBe(UploadStatus::Failed)
            ->failure_reason->toBe(FailureReason::Indeterminate)
            ->status_failures->toBe(0);
        Event::assertNothingDispatched();
    });
});

describe('an upload refused by the SDK\'s daily RASP limit', function () {
    it('waits until the limit lets it through, not a minute', function () {
        Event::fake([InvoiceRateLimited::class]);
        $this->freezeSecond();
        config(['efactura-sdk.rate_limits.enabled' => true, 'efactura-sdk.rate_limits.rasp_upload_per_day_cui' => 1]);
        app()->forgetInstance(RateLimiter::class);
        app(RateLimiter::class)->checkRaspUpload('12345678');
        Http::fake();
        $upload = makePendingUpload();
        $upload->update(['standard' => 'RASP']);

        $this->uploadService->processUpload($upload->fresh());

        $wait = null;
        Event::assertDispatched(InvoiceRateLimited::class, function (InvoiceRateLimited $e) use ($upload, &$wait) {
            $wait = $e->retryAfterSeconds;

            return $e->retryAfterSeconds > 3600 && $e->upload->is($upload);
        });
        expect($upload->fresh())->failure_reason->toBe(FailureReason::RateLimited)
            ->and($upload->fresh()->next_poll_at->timestamp)->toBe(now()->addSeconds($wait)->timestamp);
        Http::assertNothingSent();
    });
});

describe('the dispatchers during a refresh cooldown', function () {
    beforeEach(function () {
        Queue::fake();

        $this->otherToken = EfacturaToken::create([
            'cui' => '87654321',
            'access_token' => 'OTHER_ACCESS',
            'refresh_token' => 'OTHER_REFRESH',
            'expires_at' => now()->addHour(),
            'is_active' => true,
        ]);
        $this->cooling = makePendingUpload();
        $this->other = EfacturaUpload::create([
            'efactura_token_id' => $this->otherToken->id,
            'uploadable_type' => TestInvoice::class,
            'uploadable_id' => TestInvoice::create(['number' => 'INV-002', 'cui' => '87654321', 'total' => 100])->id,
            'status' => UploadStatus::Pending,
            'standard' => 'UBL',
        ]);
        coolDownGrant($this->token, 600);
    });

    it('leave a cooling grant\'s rows out of the scheduled dispatch', function () {
        app()->call([new ProcessPendingUploads, 'handle']);

        Queue::assertPushed(ProcessPendingUploads::class, 1);
        Queue::assertPushed(ProcessPendingUploads::class, fn (ProcessPendingUploads $job) => $job->cui === '87654321');

        app()->call([new ProcessPendingUploads('12345678'), 'handle']);
        app()->call([new ProcessPendingUploads('87654321'), 'handle']);

        Queue::assertPushed(ProcessSingleUpload::class, 1);
        Queue::assertPushed(ProcessSingleUpload::class, fn (ProcessSingleUpload $job) => $job->upload->is($this->other));
    });
});

/**
 * The row is reserved with a lease before its job is queued, so a second dispatch (another
 * scheduler tick, a second server) queues no second job, and only the leased job may claim it.
 */
describe('dispatching pending uploads', function () {
    it('queues one job per row, leased with the row\'s next try', function () {
        Queue::fake();
        $upload = makePendingUpload();

        expect($this->uploadService->dispatchPendingUploads($this->token))->toBe(1)
            ->and($this->uploadService->dispatchPendingUploads($this->token))->toBe(0);

        Queue::assertPushed(ProcessSingleUpload::class, 1);
        $job = Queue::pushed(ProcessSingleUpload::class)->first();
        expect($job->upload->is($upload))->toBeTrue()
            ->and($upload->fresh()->next_poll_at->toDateTimeString())->toBe($job->lease);
    });

    // A row past the quota would only be leased, queued and handed back.
    it('queues no more rows than the global quota has left', function () {
        config(['efactura-sdk.rate_limits.enabled' => true, 'efactura-sdk.rate_limits.global_per_minute' => 2]);
        app()->forgetInstance(RateLimiter::class);
        app()->forgetInstance(UploadService::class);
        Queue::fake();
        foreach (range(1, 5) as $n) {
            EfacturaUpload::create([
                'efactura_token_id' => $this->token->id,
                'uploadable_type' => TestInvoice::class,
                'uploadable_id' => TestInvoice::create(['number' => "INV-Q{$n}", 'cui' => '12345678', 'total' => 100])->id,
                'status' => UploadStatus::Pending,
                'standard' => 'UBL',
            ]);
        }

        expect(app(UploadService::class)->dispatchPendingUploads($this->token))->toBe(2)
            ->and(EfacturaUpload::whereNull('next_poll_at')->count())->toBe(3);
        Queue::assertPushed(ProcessSingleUpload::class, 2);
    });

    // efactura:upload --sync (no lease) sent the row between the dispatcher's read and its lease.
    it('leases and queues nothing for a row another run claimed after it was read', function () {
        Queue::fake();
        $upload = makePendingUpload();
        $raced = false;
        EfacturaUpload::retrieved(function (EfacturaUpload $read) use (&$raced) {
            if ($raced) {
                return;
            }

            $raced = true;
            DB::table('efactura_uploads')->where('id', $read->id)
                ->update(['status' => UploadStatus::Processing->value, 'upload_index' => '5001']);
        });

        expect($this->uploadService->dispatchPendingUploads($this->token))->toBe(0)
            ->and($raced)->toBeTrue()
            ->and($upload->fresh()->next_poll_at)->toBeNull();
        Queue::assertNothingPushed();
    });

    it('lets a job with a stale lease send nothing', function () {
        Queue::fake();
        Http::fake();
        $upload = makePendingUpload();
        $this->uploadService->dispatchPendingUploads($this->token);
        $this->travel(6)->minutes(); // past the first lease
        $this->uploadService->dispatchPendingUploads($this->token);
        [$stale, $current] = Queue::pushed(ProcessSingleUpload::class)->all();

        app()->call([$stale, 'handle']);

        expect($stale->lease)->not->toBe($current->lease)
            ->and($upload->fresh()->status)->toBe(UploadStatus::Pending);
        Http::assertNothingSent();
    });
});

describe('the upload job', function () {
    // The claim UPDATE throws: the stored XML is removed and failed() counts one attempt.
    it('counts a failure before anything was sent once, and keeps no file', function () {
        $this->freezeSecond();
        Event::fake([InvoiceFailed::class]);
        Queue::fake();
        Http::fake();
        $upload = makePendingUpload();
        $this->uploadService->dispatchPendingUploads($this->token);
        $job = Queue::pushed(ProcessSingleUpload::class)->first();
        $thrown = null;
        DB::connection()->beforeExecuting(function (string $query, array $bindings) use (&$thrown) {
            if ($thrown === null && str_starts_with(strtolower($query), 'update') && str_contains($query, 'xml_path') && in_array('uploading', $bindings, true)) {
                throw $thrown = new QueryException('testing', $query, $bindings, new PDOException('SQLSTATE[40P01]: deadlock detected'));
            }
        });

        expect(fn () => app()->call([$job, 'handle']))->toThrow(QueryException::class);
        $job->failed($thrown);

        expect($upload->fresh())->status->toBe(UploadStatus::Pending)
            ->failure_reason->toBe(FailureReason::Transient)
            ->status_failures->toBe(1)
            ->and($upload->fresh()->next_poll_at->timestamp)->toBe(now()->addSeconds(UploadService::TRANSIENT_RETRY_DELAY)->timestamp)
            ->and(Storage::disk('local')->allFiles())->toBe([]);
        Event::assertNotDispatched(InvoiceFailed::class);
        Http::assertNothingSent();
    });

    // The worker's timeout fires while the document is on the wire.
    it('parks the row its own lease claimed when it times out mid-send', function () {
        Event::fake([InvoiceFailed::class]);
        Queue::fake();
        $upload = makePendingUpload();
        $this->uploadService->dispatchPendingUploads($this->token);
        $job = Queue::pushed(ProcessSingleUpload::class)->first();
        Http::fake([UPLOAD_URL => function () use ($job) {
            $job->failed(new TimeoutExceededException('ProcessSingleUpload has timed out.'));

            return Http::response('prea multe apeluri', 429);
        }]);

        app()->call([$job, 'handle']);

        expect($upload->fresh())->status->toBe(UploadStatus::Failed)
            ->failure_reason->toBe(FailureReason::Indeterminate);
        Event::assertDispatchedTimes(InvoiceFailed::class, 1);
    });

    // The queue redelivered a copy of a job that already ran; the row belongs to that run.
    it('leaves the row alone when the queue gives up on a redelivered copy', function () {
        Event::fake([InvoiceFailed::class]);
        $upload = makePendingUpload();
        $upload->update(['status' => UploadStatus::Uploading]);

        (new ProcessSingleUpload($upload))->failed(new MaxAttemptsExceededException('attempted too many times'));

        expect($upload->fresh()->status)->toBe(UploadStatus::Uploading);
        Event::assertNotDispatched(InvoiceFailed::class);
    });

    it('leaves a waiting row as it is while uploads are switched off', function () {
        Queue::fake();
        Http::fake();
        $upload = makePendingUpload();
        $this->uploadService->dispatchPendingUploads($this->token);
        $job = Queue::pushed(ProcessSingleUpload::class)->first();
        $upload->update(['failure_reason' => FailureReason::RateLimited]);
        $before = $upload->fresh()->next_poll_at;
        config(['efactura.features.upload_invoices' => false]);

        app()->call([$job, 'handle']);

        expect($upload->fresh())->failure_reason->toBe(FailureReason::RateLimited)
            ->and($upload->fresh()->next_poll_at->equalTo($before))->toBeTrue();
        Http::assertNothingSent();
    });
});

// Only reachable when another worker is refused between this upload's checks and its call.
describe('a pause that starts after processUpload\'s own check', function () {
    it('hands the claim back to Pending without spending an attempt or sending anything', function () {
        Http::fake();
        $upload = makePendingUpload();

        // processUpload()'s own check finds no cooldown; another worker is refused right after.
        $refused = false;
        Event::listen(CacheMissed::class, function (CacheMissed $event) use (&$refused) {
            if (!$refused && str_starts_with($event->key, 'efactura:token_refresh_refused:')) {
                $refused = true;
                coolDownGrant(EfacturaToken::find($this->token->id), 900);
            }
        });

        $this->uploadService->processUpload($upload);

        $upload->refresh();
        expect($refused)->toBeTrue()
            ->and($upload->status)->toBe(UploadStatus::Pending)
            ->and($upload->failure_reason)->toBeNull()
            ->and($upload->status_failures)->toBe(0)
            ->and($upload->next_poll_at)->toBeNull()
            ->and($upload->errors[0])->toContain('is paused for')
            // Nothing was sent: no file is kept for this attempt.
            ->and($upload->xml_path)->toBeNull()
            ->and(Storage::disk('local')->allFiles())->toBe([]);
        Http::assertNothingSent();
    });
});

/**
 * The XML is stored before the claim, and every re-drive after a hand-back stored another
 * file while the row named only the last one: about one file a minute per rate-limited row.
 */
describe('the XML file of an attempt that was not sent', function () {
    it('is deleted when ANAF answers 429, so retries leave no files behind', function () {
        Http::fake([UPLOAD_URL => Http::response('prea multe apeluri', 429)]);
        $upload = makePendingUpload();

        foreach (range(1, 3) as $attempt) {
            $this->uploadService->processUpload($upload->fresh());
            $this->travel(61)->seconds(); // past the rate limit's next try
        }

        expect($upload->fresh()->failure_reason)->toBe(FailureReason::RateLimited)
            ->and($upload->fresh()->xml_path)->toBeNull()
            ->and(Storage::disk('local')->allFiles())->toBe([]);
        Http::assertSentCount(3);
    });

    it('is deleted when the attempt fails before anything is sent', function () {
        $upload = makePendingUpload();
        $this->token->update(['is_active' => false]);

        $this->uploadService->processUpload($upload);

        expect($upload->fresh()->failure_reason)->toBeNull()
            ->and($upload->fresh()->xml_path)->toBeNull()
            ->and(Storage::disk('local')->allFiles())->toBe([]);
    });

    it('is deleted when another worker wins the claim', function () {
        $upload = makePendingUpload();

        makeUblSpy(function () use ($upload) {
            EfacturaUpload::where('id', $upload->id)->update([
                'status' => UploadStatus::Uploading,
                'xml_path' => 'efactura-test/uploads/12345678/other.xml',
            ]);
        });
        Http::fake([UPLOAD_URL => Http::response('ok', 200)]);

        $this->uploadService->processUpload($upload);

        Http::assertNothingSent();
        expect(Storage::disk('local')->allFiles())->toBe([]);
    });

    it('is deleted when the claim throws before it ran, and kept when it ran', function (bool $ran) {
        Http::fake();
        $upload = makePendingUpload();
        $isClaim = fn (string $query): bool => str_starts_with(strtolower($query), 'update') && str_contains($query, 'xml_path');
        $failed = false;
        $fail = function () use (&$failed): void {
            $failed = true;

            throw new QueryException('testing', 'update efactura_uploads', [], new PDOException('SQLSTATE[40P01]: deadlock detected'));
        };
        $ran
            ? DB::listen(fn (QueryExecuted $query) => !$failed && $isClaim($query->sql) && $fail())
            : DB::connection()->beforeExecuting(fn (string $query) => !$failed && $isClaim($query) && $fail());

        expect(fn () => $this->uploadService->processUpload($upload))->toThrow(QueryException::class);

        Http::assertNothingSent();
        if ($ran) {
            expect(Storage::disk('local')->allFiles())->toBe([$upload->fresh()->xml_path]);
        } else {
            expect(Storage::disk('local')->allFiles())->toBe([])
                ->and($upload->fresh()->status)->toBe(UploadStatus::Pending);
        }
    })->with(['before the claim ran' => false, 'after the claim ran' => true]);

    // A second run of the row in the same second used to store under the same name.
    it('keeps the file of the run that was sent when another run of the row stores in the same second', function () {
        $this->freezeSecond();
        Http::fake([UPLOAD_URL => Http::response('<?xml version="1.0"?><header xmlns="mfp:anaf:dgti:spv:respUploadFisier:v1" ExecutionStatus="0" index_incarcare="5001130258"/>')]);
        $upload = makePendingUpload();
        UblBuilder::swap(new class($upload)
        {
            private int $calls = 0;

            public function __construct(private EfacturaUpload $upload) {}

            public function generateInvoiceXml($data): string
            {
                // Run A is preparing when run B takes the row and sends it.
                if (++$this->calls === 1) {
                    app(UploadService::class)->processUpload(EfacturaUpload::find($this->upload->id));

                    return '<Invoice>A</Invoice>';
                }

                return '<Invoice>B</Invoice>';
            }
        });

        $this->uploadService->processUpload($upload);

        Http::assertSentCount(1);
        expect(Storage::disk('local')->allFiles())->toBe([$upload->fresh()->xml_path])
            ->and(Storage::disk('local')->get($upload->fresh()->xml_path))->toBe('<Invoice>B</Invoice>');
    });

    it('is kept for an attempt that was sent', function () {
        Http::fake([UPLOAD_URL => Http::response(
            '<?xml version="1.0"?><header xmlns="mfp:anaf:dgti:spv:respUploadFisier:v1" ExecutionStatus="0" index_incarcare="5001130255"/>'
        )]);
        $upload = makePendingUpload();

        $this->uploadService->processUpload($upload);

        expect($upload->fresh()->status)->toBe(UploadStatus::Processing)
            ->and(Storage::disk('local')->exists($upload->fresh()->xml_path))->toBeTrue();
    });
});

describe('a B2C upload', function () {
    it('sends the standard it was queued with to /uploadb2c', function () {
        Http::fake([UPLOAD_URL => Http::response(
            '<?xml version="1.0"?><header xmlns="mfp:anaf:dgti:spv:respUploadFisier:v1" ExecutionStatus="0" index_incarcare="5001130256"/>'
        )]);
        makeUblSpy(fn () => null);

        $this->uploadService->processUpload($this->uploadService->queueB2CUpload($this->invoice, ['standard' => 'CN']));

        Http::assertSent(fn ($request) => str_contains($request->url(), '/uploadb2c?')
            && str_contains($request->url(), 'standard=CN'));
    });
});
