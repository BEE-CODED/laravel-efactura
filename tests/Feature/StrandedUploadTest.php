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
use BeeCoded\EFactura\Jobs\ProcessPendingUploads;
use BeeCoded\EFactura\Jobs\ProcessSingleUpload;
use BeeCoded\EFactura\Jobs\RetryRateLimitedUploads;
use BeeCoded\EFactura\Jobs\SweepStaleUploads;
use BeeCoded\EFactura\Models\EfacturaToken;
use BeeCoded\EFactura\Models\EfacturaUpload;
use BeeCoded\EFactura\Services\UploadService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

/**
 * "Uploading" was a dead-end state.
 *
 * Pending --(atomic claim)--> Uploading --> nothing drove it out. If the worker died
 * after the claim (job timeout / SIGKILL), the row sat in Uploading forever, and the
 * queue's own retry of the same job re-ran processUpload(), whose claim
 * (WHERE status = pending) matched zero rows and silently reported success.
 *
 * The fix must NOT naively reset Uploading -> Pending: the crash may have happened
 * after the POST reached ANAF, and re-driving would DOUBLE-FILE a legal invoice.
 */
beforeEach(function () {
    $this->token = EfacturaToken::create([
        'cui' => '12345678',
        'access_token' => 'test',
        'refresh_token' => 'test',
        'expires_at' => now()->addHour(),
        'is_active' => true,
    ]);
});

function makeUploadingRow(): EfacturaUpload
{
    return EfacturaUpload::create([
        'efactura_token_id' => test()->token->id,
        'uploadable_type' => 'App\\Models\\Invoice',
        'uploadable_id' => 1,
        'status' => UploadStatus::Uploading,
        'standard' => 'UBL',
    ]);
}

describe('a job that dies mid-flight', function () {
    it('parks the stranded uploading row as indeterminate instead of leaving it forever', function () {
        $upload = makeUploadingRow();

        (new ProcessSingleUpload($upload))->failed(new RuntimeException('Job timed out'));

        $upload->refresh();

        expect($upload->status)->toBe(UploadStatus::Failed)
            ->and($upload->failure_reason)->toBe(FailureReason::Indeterminate);
    });

    it('never re-drives it to ANAF, because it may already be filed', function () {
        $upload = makeUploadingRow();

        (new ProcessSingleUpload($upload))->failed(new RuntimeException('Job timed out'));

        expect(app(UploadService::class)->resetForRetry($upload->refresh()))->toBeFalse();
        expect($upload->fresh()->status)->toBe(UploadStatus::Failed);
    });

    it('is excluded from the automatic retry job', function () {
        config(['efactura.enabled' => true, 'efactura.features.upload_invoices' => true]);
        Queue::fake();

        $upload = makeUploadingRow();
        (new ProcessSingleUpload($upload))->failed(new RuntimeException('Job timed out'));

        (new RetryRateLimitedUploads)->handle();

        Queue::assertNothingPushed();
        expect($upload->fresh()->status)->toBe(UploadStatus::Failed);
    });

    it('announces a genuine terminal failure so an operator can reconcile', function () {
        Event::fake([InvoiceFailed::class]);

        $upload = makeUploadingRow();
        (new ProcessSingleUpload($upload))->failed(new RuntimeException('Job timed out'));

        Event::assertDispatched(InvoiceFailed::class);
    });

    /**
     * The guard matters: a job can also "fail" after the upload already reached a
     * legitimate terminal state. We must not rewrite that outcome.
     */
    it('does not touch an upload that already left the uploading state', function () {
        $upload = EfacturaUpload::create([
            'efactura_token_id' => $this->token->id,
            'uploadable_type' => 'App\\Models\\Invoice',
            'uploadable_id' => 1,
            'status' => UploadStatus::Processing,
            'upload_index' => 'IDX1',
            'standard' => 'UBL',
        ]);

        (new ProcessSingleUpload($upload))->failed(new RuntimeException('Job timed out'));

        expect($upload->fresh()->status)->toBe(UploadStatus::Processing)
            ->and($upload->fresh()->failure_reason)->toBeNull();
    });
});

describe('the retry deadline', function () {
    /**
     * retry_window_hours was not a cap at all.
     *
     * failed() delegates to parkStrandedUploadAsIndeterminate(), whose guard is
     * WHERE status = uploading. But BOTH deadline-expiry routes leave the row PENDING:
     * handle() calls resetForRetry() (-> Pending) and then release()s, and the rate-limit
     * pre-check releases a still-Pending row outright. So at the 24h retryUntil deadline
     * the guard matched ZERO rows, returned false silently, and no InvoiceFailed ever
     * fired — then the scheduled ProcessPendingUploads picked the Pending row straight
     * back up with a brand-new 24h window. Same shape as the transient cap: a per-job cap
     * silently reset by a scheduled batch job.
     */
    it('drives a pending upload to a terminal state when the job finally gives up', function () {
        Event::fake([InvoiceFailed::class]);

        $upload = EfacturaUpload::create([
            'efactura_token_id' => $this->token->id,
            'uploadable_type' => 'App\\Models\\Invoice',
            'uploadable_id' => 5,
            'status' => UploadStatus::Pending,
            'standard' => 'UBL',
        ]);

        (new ProcessSingleUpload($upload))->failed(
            new RuntimeException('Job has exceeded the maximum retry deadline')
        );

        $upload->refresh();

        expect($upload->status)->toBe(UploadStatus::Failed)
            ->and($upload->failure_reason)->not->toBeNull()
            ->and($upload->failure_reason->isRetryable())->toBeFalse()
            // Nothing was ever transmitted from a Pending row, so this is NOT a case
            // for human reconciliation against ANAF.
            ->and($upload->failure_reason->needsReconciliation())->toBeFalse();

        Event::assertDispatched(InvoiceFailed::class);
    });

    /**
     * A rate-limited upload waits out its release as Pending. When the window expires it
     * must terminate too, rather than loop for retry_max_age_days.
     */
    it('terminates a rate-limited upload whose window expired', function () {
        $upload = EfacturaUpload::create([
            'efactura_token_id' => $this->token->id,
            'uploadable_type' => 'App\\Models\\Invoice',
            'uploadable_id' => 6,
            'status' => UploadStatus::Pending,
            'failure_reason' => FailureReason::RateLimited,
            'standard' => 'UBL',
        ]);

        (new ProcessSingleUpload($upload))->failed(new RuntimeException('Retry deadline exceeded'));

        $upload->refresh();

        expect($upload->status)->toBe(UploadStatus::Failed)
            ->and($upload->failure_reason->isRetryable())->toBeFalse();
    });

    it('is not handed straight back by the scheduled pending-upload dispatcher', function () {
        config(['efactura.enabled' => true, 'efactura.features.upload_invoices' => true]);
        Queue::fake();

        $upload = EfacturaUpload::create([
            'efactura_token_id' => $this->token->id,
            'uploadable_type' => 'App\\Models\\Invoice',
            'uploadable_id' => 7,
            'status' => UploadStatus::Pending,
            'standard' => 'UBL',
        ]);

        (new ProcessSingleUpload($upload))->failed(new RuntimeException('Retry deadline exceeded'));

        app()->call([new ProcessPendingUploads, 'handle']);

        Queue::assertNothingPushed();
    });

    /**
     * The guard still matters in the other direction: a job that dies while the row is
     * genuinely in flight is indeterminate, not merely abandoned.
     */
    it('still parks a genuinely in-flight upload as indeterminate', function () {
        $upload = makeUploadingRow();

        (new ProcessSingleUpload($upload))->failed(new RuntimeException('Job timed out'));

        expect($upload->fresh()->failure_reason)->toBe(FailureReason::Indeterminate);
    });
});

describe('the stale-uploading janitor', function () {
    it('parks rows stuck in uploading past the staleness window', function () {
        config(['efactura.jobs.stale_uploading_minutes' => 30]);

        $upload = makeUploadingRow();
        EfacturaUpload::where('id', $upload->id)->update(['updated_at' => now()->subMinutes(45)]);

        app()->call([new SweepStaleUploads, 'handle']);

        $upload->refresh();
        expect($upload->status)->toBe(UploadStatus::Failed)
            ->and($upload->failure_reason)->toBe(FailureReason::Indeterminate);
    });

    it('leaves an in-flight upload alone', function () {
        config(['efactura.jobs.stale_uploading_minutes' => 30]);

        $upload = makeUploadingRow();

        app()->call([new SweepStaleUploads, 'handle']);

        expect($upload->fresh()->status)->toBe(UploadStatus::Uploading);
    });

    it('does not touch rows in other states', function () {
        config(['efactura.jobs.stale_uploading_minutes' => 30]);

        $pending = EfacturaUpload::create([
            'efactura_token_id' => $this->token->id,
            'uploadable_type' => 'App\\Models\\Invoice',
            'uploadable_id' => 2,
            'status' => UploadStatus::Pending,
            'standard' => 'UBL',
        ]);
        EfacturaUpload::where('id', $pending->id)->update(['updated_at' => now()->subDays(3)]);

        app()->call([new SweepStaleUploads, 'handle']);

        expect($pending->fresh()->status)->toBe(UploadStatus::Pending);
    });

    it('swept rows are never auto-resubmitted', function () {
        config([
            'efactura.enabled' => true,
            'efactura.features.upload_invoices' => true,
            'efactura.jobs.stale_uploading_minutes' => 30,
        ]);
        Queue::fake();

        $upload = makeUploadingRow();
        EfacturaUpload::where('id', $upload->id)->update(['updated_at' => now()->subMinutes(45)]);

        app()->call([new SweepStaleUploads, 'handle']);
        (new RetryRateLimitedUploads)->handle();

        Queue::assertNothingPushed();
    });
});

describe('rate-limited rows awaiting release', function () {
    /**
     * markAsRateLimited releases the claim back to Pending, which is honest: the
     * upload never left the pipeline. But `pending()` matches on status alone, so
     * the row instantly became visible to the scheduled ProcessPendingUploads —
     * while its OWN ProcessSingleUpload is still queued on a 60s release.
     *
     * ProcessSingleUpload has no uniqueness guard, so every 5-minute tick adds
     * another job for the same row, each retrying every 60s until the 24h
     * retryUntil. With `rate_limits.enabled = false` — the documented case where
     * real ANAF 429s arrive, and where the quota pre-flight is skipped entirely —
     * that is unbounded ANAF hammering, and it saturates the 1000/60min quota so
     * thoroughly that the quota cannot reset.
     *
     * A genuinely pending row has failure_reason NULL (resetForRetry clears it),
     * so the reason is what distinguishes "queued, nobody is on it" from "queued,
     * a job is waiting out a rate limit".
     */
    it('does not dispatch a row that is waiting out a rate limit', function () {
        Queue::fake();

        // A real pending row: nobody is working it.
        $fresh = EfacturaUpload::create([
            'efactura_token_id' => $this->token->id,
            'uploadable_type' => 'App\\Models\\Invoice',
            'uploadable_id' => 1,
            'status' => UploadStatus::Pending,
        ]);

        // A row whose own ProcessSingleUpload is queued on a 60s release.
        EfacturaUpload::create([
            'efactura_token_id' => $this->token->id,
            'uploadable_type' => 'App\\Models\\Invoice',
            'uploadable_id' => 2,
            'status' => UploadStatus::Pending,
            'failure_reason' => FailureReason::RateLimited,
        ]);

        $dispatched = app(UploadService::class)->dispatchPendingUploads();

        expect($dispatched)->toBe(1);
        Queue::assertPushed(ProcessSingleUpload::class, 1);
        Queue::assertPushed(
            ProcessSingleUpload::class,
            fn (ProcessSingleUpload $job) => $job->upload->is($fresh)
        );
    });
});
