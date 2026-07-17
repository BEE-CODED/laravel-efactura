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
use BeeCoded\EFactura\Models\EfacturaToken;
use BeeCoded\EFactura\Models\EfacturaUpload;
use BeeCoded\EFactura\Services\UploadService;
use BeeCoded\EFactura\Tests\Fixtures\TestInvoice;
use BeeCoded\EFacturaSdk\Facades\UblBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
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
