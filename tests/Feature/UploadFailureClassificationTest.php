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
use BeeCoded\EFactura\Services\TokenService;
use BeeCoded\EFactura\Services\UploadService;
use BeeCoded\EFactura\Tests\Fixtures\TestInvoice;
use BeeCoded\EFacturaSdk\Facades\UblBuilder;
use BeeCoded\EFacturaSdk\Services\RateLimiter;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
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

        Event::assertDispatched(InvoiceRateLimited::class);
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
});

describe('the persisted state of a rate-limited upload', function () {
    /**
     * InvoiceRateLimited's contract is explicit: "Do not treat this as a failure. The
     * upload stays in the pipeline." Writing status=Failed/processed_at=now() said the
     * exact opposite to every consumer that reads the MODEL rather than listening to the
     * event — and the row flips back to Pending 60 seconds later anyway.
     */
    it('stays in the pipeline at the model layer while it waits out the limit', function () {
        Http::fake([UPLOAD_URL => Http::response('prea multe apeluri', 429)]);

        $upload = makePendingUpload();
        $this->uploadService->processUpload($upload);

        $upload->refresh();

        expect($upload->status)->toBe(UploadStatus::Pending)
            ->and($upload->isFailed())->toBeFalse()
            ->and($upload->isTerminal())->toBeFalse()
            ->and($upload->processed_at)->toBeNull()
            // The classification is still recorded — the retry path reads it.
            ->and($upload->failure_reason)->toBe(FailureReason::RateLimited);
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

    /**
     * Moving the claim must not weaken it: it is still the single gate that decides who
     * transmits.
     */
    it('still stops a second worker that claimed the row during preparation', function () {
        $upload = makePendingUpload();

        makeUblSpy(function () use ($upload) {
            EfacturaUpload::where('id', $upload->id)->update(['status' => UploadStatus::Uploading]);
        });

        Http::fake([UPLOAD_URL => Http::response('ok', 200)]);

        $this->uploadService->processUpload($upload);

        Http::assertNothingSent();
    });
});

describe('a token that predates the encryption migration', function () {
    /**
     * v3 deliberately makes a pre-migration (plaintext) token row throw DecryptException
     * on read. That throw happens strictly BEFORE any HTTP request, so the document
     * provably never entered ANAF's pipeline — but DecryptException extends
     * RuntimeException, matched no specific catch, and fell through to catch(\Throwable)
     * => Indeterminate: "NEVER auto-resubmitted, requires human reconciliation".
     *
     * During a normal deploy window (code live before migrate finishes) that wrote off
     * every upload for every company, and running the migration afterwards did NOT
     * unpark them.
     */
    it('is not written off as indeterminate, because nothing was ever transmitted', function () {
        // Exactly as the pre-v3 release left the row: raw, unencrypted credentials.
        DB::table('efactura_tokens')->where('id', $this->token->id)->update([
            'access_token' => 'PLAINTEXT_ACCESS',
            'refresh_token' => 'PLAINTEXT_REFRESH',
        ]);

        Http::fake([UPLOAD_URL => Http::response('should never be reached', 200)]);

        $upload = makePendingUpload();
        $this->uploadService->processUpload($upload);

        $upload->refresh();

        expect($upload->failure_reason)->not->toBe(FailureReason::Indeterminate)
            ->and($upload->failure_reason)->toBe(FailureReason::Transient)
            ->and($upload->failure_reason->needsReconciliation())->toBeFalse()
            // It fixes itself the moment the migration lands, so it must stay re-drivable.
            ->and($upload->failure_reason->isRetryable())->toBeTrue();

        Http::assertNothingSent();
    });
});

describe('a deactivated token', function () {
    /**
     * Only the locked refresh path re-checked is_active, so a deactivated token that was
     * not about to expire still filed invoices.
     */
    it('refuses a deactivated token before anything is sent', function () {
        $upload = makePendingUpload();
        $this->token->update(['is_active' => false]);

        Http::fake([UPLOAD_URL => Http::response('should never be reached', 200)]);

        $this->uploadService->processUpload($upload);

        expect($upload->fresh()->status)->toBe(UploadStatus::Failed)
            ->and($upload->fresh()->failure_reason)->toBe(FailureReason::Transient)
            ->and($upload->fresh()->errors)->toBe(['Token for CUI 12345678 has been deactivated']);

        Http::assertNothingSent();
    });
});

describe('an XML file that cannot be stored', function () {
    /**
     * put() returning false was ignored, and a storage exception was recorded as a
     * terminal validation failure of the invoice. Storage is ours to fix: retry it.
     */
    it('is a transient failure, announced to nobody, and nothing is sent', function () {
        Event::fake([InvoiceFailed::class]);
        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('put')->andReturn(false);
        Storage::set('local', $disk);

        Http::fake([UPLOAD_URL => Http::response('should never be reached', 200)]);

        $upload = makePendingUpload();
        $this->uploadService->processUpload($upload);

        expect($upload->fresh()->status)->toBe(UploadStatus::Failed)
            ->and($upload->fresh()->failure_reason)->toBe(FailureReason::Transient)
            ->and($upload->fresh()->xml_path)->toBeNull();

        Event::assertNotDispatched(InvoiceFailed::class);
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

    it('parks an answer without a usable index or status as indeterminate', function (string $body, array $expectedErrors) {
        Event::fake([InvoiceFailed::class, InvoiceUploaded::class]);
        Http::fake([UPLOAD_URL => Http::response($body, 200)]);

        $upload = makePendingUpload();
        $this->uploadService->processUpload($upload);

        $upload->refresh();
        expect($upload->status)->toBe(UploadStatus::Failed)
            ->and($upload->failure_reason)->toBe(FailureReason::Indeterminate)
            ->and($upload->upload_index)->toBeNull()
            ->and($upload->errors)->toBe($expectedErrors);
        Event::assertDispatched(InvoiceFailed::class, fn (InvoiceFailed $e) => $e->errors === $expectedErrors);
        Event::assertNotDispatched(InvoiceUploaded::class);
    })->with([
        'success with a non-numeric index' => [
            '<header xmlns="mfp:anaf:dgti:spv:respUploadFisier:v1" dateResponse="202407171230" ExecutionStatus="0" index_incarcare="abc"/>',
            fn () => [__('efactura::messages.upload_outcome_unknown')],
        ],
        'success with a spaced index' => [
            json_encode(['ExecutionStatus' => '0', 'index_incarcare' => '12 345']),
            fn () => [__('efactura::messages.upload_outcome_unknown')],
        ],
        'success with an empty index' => [
            json_encode(['ExecutionStatus' => '0', 'index_incarcare' => '']),
            fn () => [__('efactura::messages.upload_outcome_unknown')],
        ],
        'success without an index' => [
            '<header xmlns="mfp:anaf:dgti:spv:respUploadFisier:v1" dateResponse="202407171230" ExecutionStatus="0"/>',
            fn () => [__('efactura::messages.upload_outcome_unknown')],
        ],
        'errors without a status' => [
            json_encode(['Errors' => ['Eroare la procesare', '']]),
            fn () => [__('efactura::messages.upload_outcome_unknown'), 'Eroare la procesare'],
        ],
        'an unrecognised status without an index' => [
            json_encode(['ExecutionStatus' => '9']),
            fn () => [__('efactura::messages.upload_outcome_unknown')],
        ],
    ]);

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
 * The refresh cooldown refuses an upload without asking ANAF. Counted as a transient
 * failure, it abandoned every upload of the company within about 2.5 minutes.
 */
describe('an upload refused by the refresh cooldown', function () {
    it('waits it out without spending transient attempts, then uploads', function () {
        config([
            'efactura.jobs.max_transient_attempts' => 5,
            'efactura.jobs.transient_retry_delay_seconds' => 30,
        ]);
        Event::fake([InvoiceFailed::class]);
        Http::fake([
            'https://logincert.anaf.ro/anaf-oauth2/v1/token' => Http::sequence()
                ->push(['error' => 'invalid_client'], 401)
                ->push(['access_token' => 'NEW_ACCESS', 'refresh_token' => 'NEW_REFRESH', 'expires_in' => 3600]),
            UPLOAD_URL => Http::response(json_encode(['ExecutionStatus' => '0', 'index_incarcare' => '12345']), 200),
        ]);
        $this->token->update(['expires_at' => now()->addSeconds(60)]);
        $upload = makePendingUpload();

        // One queued job: each release re-runs it with the row read afresh, under the same uuid.
        $queueJob = Mockery::mock(Job::class);
        $queueJob->shouldReceive('uuid')->andReturn('job-uuid-cooldown');
        $queueJob->shouldReceive('release');
        $run = fn () => app()->call([(new ProcessSingleUpload($upload->fresh()))->setJob($queueJob), 'handle']);

        // The first run asks ANAF and is refused; the next five fall in the cooldown.
        foreach (range(1, 6) as $attempt) {
            $run();
            $this->travel(30)->seconds();
        }

        expect($upload->fresh()->status)->toBe(UploadStatus::Pending)
            ->and($upload->fresh()->failure_reason)->toBeNull();
        Event::assertNotDispatched(InvoiceFailed::class);
        Http::assertSentCount(1);

        $this->travel(15)->minutes();
        $run();

        expect($upload->fresh()->status)->toBe(UploadStatus::Processing)
            ->and($upload->fresh()->upload_index)->toBe('12345');
    });

    // Released every 30 s, each run built and stored a new XML file for 24 hours.
    it('sleeps the rest of the cooldown and builds no XML meanwhile', function () {
        Http::fake([
            'https://logincert.anaf.ro/anaf-oauth2/v1/token' => Http::response(['error' => 'invalid_grant'], 400),
            UPLOAD_URL => Http::response('never reached', 500),
        ]);
        $this->token->update(['expires_at' => now()->addSeconds(60)]);
        $upload = makePendingUpload();

        $delays = [];
        $queueJob = Mockery::mock(Job::class);
        $queueJob->shouldReceive('uuid')->andReturn('job-uuid-cooldown-sleep');
        $queueJob->shouldReceive('release')->andReturnUsing(function (int $delay) use (&$delays) {
            $delays[] = $delay;
        });
        $run = fn () => app()->call([(new ProcessSingleUpload($upload->fresh()))->setJob($queueJob), 'handle']);

        $run(); // refused by ANAF: the cooldown starts
        $files = Storage::disk('local')->allFiles();
        $this->travel(30)->seconds();
        $run();

        expect($delays[1])->toBeGreaterThanOrEqual(860)->toBeLessThanOrEqual(870)
            ->and(Storage::disk('local')->allFiles())->toBe($files)
            ->and($upload->fresh()->status)->toBe(UploadStatus::Pending);
        Http::assertSentCount(1);
    });
});

describe('a freshly queued row whose own job waits on the quota pre-check', function () {
    it('gets no second job from the scheduler', function () {
        Queue::fake();
        $upload = $this->uploadService->queueUpload($this->invoice);

        // A month-start backlog: its job starts minutes later and meets an exhausted quota.
        $this->travel(5)->minutes();
        $rateLimiter = Mockery::mock(RateLimiter::class);
        $rateLimiter->shouldReceive('isEnabled')->andReturn(true);
        $rateLimiter->shouldReceive('getRemainingQuota')->with('global')->andReturn(['remaining' => 0, 'resetsIn' => 40]);
        $queueJob = Mockery::mock(Job::class);
        $queueJob->shouldReceive('release')->once()->with(40);
        (new ProcessSingleUpload($upload->fresh()))->setJob($queueJob)->handle($this->uploadService, $rateLimiter);

        app()->call([new ProcessPendingUploads, 'handle']);

        Queue::assertNothingPushed();
    });
});

describe('a model read before the row was reset for a retry', function () {
    // Claimed in memory without syncing originals, the failure write skipped status and
    // reason (equal to the stale ones), leaving a never-sent row in Uploading.
    it('records the failure of its own run', function () {
        $upload = makePendingUpload();
        $upload->update(['status' => UploadStatus::Failed, 'failure_reason' => FailureReason::Transient, 'errors' => ['earlier']]);
        $stale = EfacturaUpload::find($upload->id);

        // Another job resets the row for a retry; this run still holds the Failed copy.
        EfacturaUpload::whereKey($upload->id)->update(['status' => UploadStatus::Pending, 'failure_reason' => null]);
        $this->token->update(['is_active' => false]);
        Http::fake([UPLOAD_URL => Http::response('should never be reached', 200)]);

        $this->uploadService->processUpload($stale);

        expect($upload->fresh()->status)->toBe(UploadStatus::Failed)
            ->and($upload->fresh()->failure_reason)->toBe(FailureReason::Transient);
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

/**
 * The cooldown is stored as its expiry. Redis hands a stored int back as a numeric string,
 * which an is_int() read took for no cooldown at all: every job asked ANAF again.
 */
describe('a refresh cooldown read back as a numeric string', function () {
    beforeEach(function () {
        Http::fake();
        $this->delays = [];
        $this->runJob = function (EfacturaUpload $upload): void {
            $queueJob = Mockery::mock(Job::class);
            $queueJob->shouldReceive('uuid')->andReturn('job-uuid-numeric-cooldown');
            $queueJob->shouldReceive('release')->andReturnUsing(function (int $delay) {
                $this->delays[] = $delay;
            });
            app()->call([(new ProcessSingleUpload($upload->fresh()))->setJob($queueJob), 'handle']);
        };
    });

    it('is seen, and the job sleeps it out without asking ANAF', function () {
        $upload = makePendingUpload();
        coolDownGrant($this->token, 600);

        expect(app(TokenService::class)->refreshCooldownSeconds($this->token))->toBeGreaterThan(590);

        ($this->runJob)($upload);

        expect($this->delays)->toHaveCount(1)
            ->and($this->delays[0])->toBeGreaterThan(590)->toBeLessThanOrEqual(600)
            ->and($upload->fresh()->status)->toBe(UploadStatus::Pending);
        Http::assertNothingSent();
    });

    it('releases a rate-limited row for the rest of the cooldown, not the usual minute', function () {
        $upload = makePendingUpload();
        $upload->update(['failure_reason' => FailureReason::RateLimited]);
        coolDownGrant($this->token, 600);

        ($this->runJob)($upload);

        expect($this->delays)->toHaveCount(1)
            ->and($this->delays[0])->toBeGreaterThan(590)->toBeLessThanOrEqual(600)
            ->and($upload->fresh()->failure_reason)->toBe(FailureReason::RateLimited);
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
        $this->travel(91)->seconds();
        coolDownGrant($this->token, 600);
    });

    it('leave a cooling grant\'s rows out of the scheduled dispatch', function () {
        app()->call([new ProcessPendingUploads, 'handle']);

        Queue::assertPushed(ProcessSingleUpload::class, 1);
        Queue::assertPushed(ProcessSingleUpload::class, fn (ProcessSingleUpload $job) => $job->upload->is($this->other));
    });

    it('leave a cooling grant\'s rows out of dispatchPendingUploads()', function () {
        expect($this->uploadService->dispatchPendingUploads())->toBe(1);

        Queue::assertPushed(ProcessSingleUpload::class, 1);
        Queue::assertPushed(ProcessSingleUpload::class, fn (ProcessSingleUpload $job) => $job->upload->is($this->other));
    });
});

// Only reachable when another worker is refused between this upload's checks and its call.
describe('a cooldown that starts after the row was claimed', function () {
    it('hands the claim back to Pending without spending an attempt or sending anything', function () {
        Http::fake();
        // Expiring, so the call takes the refresh path that re-checks the cooldown.
        $this->token->update(['expires_at' => now()->addSeconds(60)]);
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
            ->and($upload->errors[0])->toContain('ANAF refused to refresh the token');
        Http::assertNothingSent();
    });
});
