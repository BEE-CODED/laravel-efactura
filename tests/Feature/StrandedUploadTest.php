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
use BeeCoded\EFactura\Jobs\ProcessSingleUpload;
use BeeCoded\EFactura\Jobs\SweepStaleUploads;
use BeeCoded\EFactura\Models\EfacturaToken;
use BeeCoded\EFactura\Models\EfacturaUpload;
use BeeCoded\EFactura\Services\UploadService;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\TimeoutExceededException;
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

        (new ProcessSingleUpload($upload))->failed(new TimeoutExceededException('ProcessSingleUpload has timed out.'));

        $upload->refresh();

        expect($upload->status)->toBe(UploadStatus::Failed)
            ->and($upload->failure_reason)->toBe(FailureReason::Indeterminate);
    });

    it('never re-drives it to ANAF, because it may already be filed', function () {
        Queue::fake();
        $upload = makeUploadingRow();

        (new ProcessSingleUpload($upload))->failed(new TimeoutExceededException('ProcessSingleUpload has timed out.'));

        expect(app(UploadService::class)->dispatchPendingUploads($this->token))->toBe(0)
            ->and($upload->fresh()->status)->toBe(UploadStatus::Failed);
        Queue::assertNothingPushed();
    });

    it('announces a genuine terminal failure so an operator can reconcile', function () {
        Event::fake([InvoiceFailed::class]);

        $upload = makeUploadingRow();
        (new ProcessSingleUpload($upload))->failed(new TimeoutExceededException('ProcessSingleUpload has timed out.'));

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

        (new ProcessSingleUpload($upload))->failed(new TimeoutExceededException('ProcessSingleUpload has timed out.'));

        expect($upload->fresh()->status)->toBe(UploadStatus::Processing)
            ->and($upload->fresh()->failure_reason)->toBeNull();
    });
});

/**
 * A job that ends without recording an outcome (it threw, or ran out of time) on a row that is
 * still Pending sent nothing. It counts as one failed attempt, and the fifth ends the upload.
 */
describe('a job that fails before anything was sent', function () {
    it('counts one attempt per failed job and abandons the upload at the fifth', function () {
        Event::fake([InvoiceFailed::class]);

        $upload = EfacturaUpload::create([
            'efactura_token_id' => $this->token->id,
            'uploadable_type' => 'App\\Models\\Invoice',
            'uploadable_id' => 5,
            'status' => UploadStatus::Pending,
            'standard' => 'UBL',
        ]);

        foreach (range(1, UploadService::MAX_TRANSIENT_ATTEMPTS - 1) as $attempt) {
            (new ProcessSingleUpload($upload->fresh()))->failed(new TimeoutExceededException('ProcessSingleUpload has timed out.'));

            expect($upload->fresh())->status->toBe(UploadStatus::Pending)
                ->failure_reason->toBe(FailureReason::Transient)
                ->status_failures->toBe($attempt);
            $this->travel(UploadService::TRANSIENT_RETRY_DELAY + 1)->seconds();
        }

        (new ProcessSingleUpload($upload->fresh()))->failed(new TimeoutExceededException('ProcessSingleUpload has timed out.'));

        $upload->refresh();
        expect($upload->status)->toBe(UploadStatus::Failed)
            ->and($upload->failure_reason)->toBe(FailureReason::Abandoned)
            // Nothing was ever transmitted from a Pending row, so this is NOT a case
            // for human reconciliation against ANAF.
            ->and($upload->failure_reason->needsReconciliation())->toBeFalse();

        Event::assertDispatchedTimes(InvoiceFailed::class, 1);
    });

    it('is not handed straight back by the dispatcher', function () {
        Queue::fake();

        $upload = EfacturaUpload::create([
            'efactura_token_id' => $this->token->id,
            'uploadable_type' => 'App\\Models\\Invoice',
            'uploadable_id' => 7,
            'status' => UploadStatus::Pending,
            'standard' => 'UBL',
        ]);

        (new ProcessSingleUpload($upload))->failed(new TimeoutExceededException('ProcessSingleUpload has timed out.'));

        expect(app(UploadService::class)->dispatchPendingUploads($this->token))->toBe(0);
        Queue::assertNothingPushed();

        $this->travel(UploadService::TRANSIENT_RETRY_DELAY + 1)->seconds();
        expect(app(UploadService::class)->dispatchPendingUploads($this->token))->toBe(1);
    });

    // The worker reports a timeout as TimeoutExceededException, a MaxAttemptsExceededException.
    it('counts a timeout of the job the dispatcher leased the row to', function () {
        $this->freezeSecond();
        Queue::fake();
        $upload = EfacturaUpload::create([
            'efactura_token_id' => $this->token->id,
            'uploadable_type' => 'App\\Models\\Invoice',
            'uploadable_id' => 8,
            'status' => UploadStatus::Pending,
            'standard' => 'UBL',
        ]);
        app(UploadService::class)->dispatchPendingUploads($this->token);
        $job = Queue::pushed(ProcessSingleUpload::class)->first();

        $job->failed(new TimeoutExceededException('ProcessSingleUpload has timed out.'));

        expect($upload->fresh())->status->toBe(UploadStatus::Pending)
            ->failure_reason->toBe(FailureReason::Transient)
            ->status_failures->toBe(1)
            ->and($upload->fresh()->next_poll_at->timestamp)->toBe(now()->addSeconds(UploadService::TRANSIENT_RETRY_DELAY)->timestamp);
    });

    // A worker that dies preparing the document (out of memory building the UBL) never calls
    // failed(); the queue's redelivery fails with MaxAttemptsExceededException instead. The row
    // still holds that job's lease, so the try counts, and repeated deaths end the upload.
    it('counts a job whose worker died preparing the upload when the queue gives up on its redelivery', function () {
        Queue::fake();
        Event::fake([InvoiceFailed::class]);
        $upload = EfacturaUpload::create([
            'efactura_token_id' => $this->token->id,
            'uploadable_type' => 'App\\Models\\Invoice',
            'uploadable_id' => 9,
            'status' => UploadStatus::Pending,
            'standard' => 'UBL',
        ]);

        foreach (range(1, UploadService::MAX_TRANSIENT_ATTEMPTS - 1) as $attempt) {
            expect(app(UploadService::class)->dispatchPendingUploads($this->token))->toBe(1);
            Queue::pushed(ProcessSingleUpload::class)->last()
                ->failed(new MaxAttemptsExceededException('ProcessSingleUpload has been attempted too many times.'));

            expect($upload->fresh())->status->toBe(UploadStatus::Pending)
                ->failure_reason->toBe(FailureReason::Transient)
                ->status_failures->toBe($attempt);
            $this->travel(UploadService::TRANSIENT_RETRY_DELAY + 1)->seconds();
        }

        expect(app(UploadService::class)->dispatchPendingUploads($this->token))->toBe(1);
        Queue::pushed(ProcessSingleUpload::class)->last()
            ->failed(new MaxAttemptsExceededException('ProcessSingleUpload has been attempted too many times.'));

        expect($upload->fresh())->status->toBe(UploadStatus::Failed)
            ->failure_reason->toBe(FailureReason::Abandoned);
        Event::assertDispatchedTimes(InvoiceFailed::class, 1);
    });

    it('leaves the row to a later dispatch when the queue gives up on an older job copy', function () {
        Queue::fake();
        $upload = EfacturaUpload::create([
            'efactura_token_id' => $this->token->id,
            'uploadable_type' => 'App\\Models\\Invoice',
            'uploadable_id' => 10,
            'status' => UploadStatus::Pending,
            'standard' => 'UBL',
        ]);
        app(UploadService::class)->dispatchPendingUploads($this->token);
        $old = Queue::pushed(ProcessSingleUpload::class)->first();
        $this->travel(301)->seconds();
        app(UploadService::class)->dispatchPendingUploads($this->token);
        $lease = $upload->fresh()->next_poll_at;

        $old->failed(new MaxAttemptsExceededException('ProcessSingleUpload has been attempted too many times.'));

        expect($upload->fresh())->status->toBe(UploadStatus::Pending)
            ->status_failures->toBe(0)
            ->and($upload->fresh()->next_poll_at->equalTo($lease))->toBeTrue();
    });

    /**
     * The guard still matters in the other direction: a job that dies while the row is
     * genuinely in flight is indeterminate, not merely abandoned.
     */
    it('still parks a genuinely in-flight upload as indeterminate', function () {
        $upload = makeUploadingRow();

        (new ProcessSingleUpload($upload))->failed(new TimeoutExceededException('ProcessSingleUpload has timed out.'));

        expect($upload->fresh()->failure_reason)->toBe(FailureReason::Indeterminate);
    });
});

describe('the stale-uploading janitor', function () {
    it('parks rows stuck in uploading past the staleness window', function () {
        $upload = makeUploadingRow();
        EfacturaUpload::where('id', $upload->id)->update(['updated_at' => now()->subMinutes(45)]);

        app()->call([new SweepStaleUploads, 'handle']);

        $upload->refresh();
        expect($upload->status)->toBe(UploadStatus::Failed)
            ->and($upload->failure_reason)->toBe(FailureReason::Indeterminate);
    });

    it('leaves an in-flight upload alone', function () {
        $upload = makeUploadingRow();

        app()->call([new SweepStaleUploads, 'handle']);

        expect($upload->fresh()->status)->toBe(UploadStatus::Uploading);
    });

    it('does not touch rows in other states', function () {
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

    it('parks a row stuck for 31 minutes and leaves one stuck for 29', function () {
        $stuck = makeUploadingRow();
        $recent = EfacturaUpload::create([
            'efactura_token_id' => $this->token->id,
            'uploadable_type' => 'App\\Models\\Invoice',
            'uploadable_id' => 2,
            'status' => UploadStatus::Uploading,
            'standard' => 'UBL',
        ]);
        EfacturaUpload::whereKey($stuck->id)->update(['updated_at' => now()->subMinutes(31)]);
        EfacturaUpload::whereKey($recent->id)->update(['updated_at' => now()->subMinutes(29)]);

        app()->call([new SweepStaleUploads, 'handle']);

        expect($stuck->fresh()->failure_reason)->toBe(FailureReason::Indeterminate)
            ->and($recent->fresh()->status)->toBe(UploadStatus::Uploading);
    });

    it('never parks a three-minute-old upload, whatever a leftover stale_uploading_minutes says', function () {
        Event::fake([InvoiceFailed::class]);
        config(['efactura.jobs.stale_uploading_minutes' => 1]);
        $upload = makeUploadingRow();
        EfacturaUpload::whereKey($upload->id)->update(['updated_at' => now()->subMinutes(3)]);

        app()->call([new SweepStaleUploads, 'handle']);

        expect($upload->fresh()->status)->toBe(UploadStatus::Uploading);
        Event::assertNotDispatched(InvoiceFailed::class);
    });

    it('swept rows are never auto-resubmitted', function () {
        Queue::fake();

        $upload = makeUploadingRow();
        EfacturaUpload::where('id', $upload->id)->update(['updated_at' => now()->subMinutes(45)]);

        app()->call([new SweepStaleUploads, 'handle']);

        expect(app(UploadService::class)->dispatchPendingUploads($this->token))->toBe(0);
        Queue::assertNothingPushed();
    });
});

describe('a row waiting for its next try', function () {
    it('is not dispatched before it is due', function () {
        Queue::fake();

        $due = EfacturaUpload::create([
            'efactura_token_id' => $this->token->id,
            'uploadable_type' => 'App\\Models\\Invoice',
            'uploadable_id' => 1,
            'status' => UploadStatus::Pending,
        ]);
        $waiting = EfacturaUpload::create([
            'efactura_token_id' => $this->token->id,
            'uploadable_type' => 'App\\Models\\Invoice',
            'uploadable_id' => 2,
            'status' => UploadStatus::Pending,
            'failure_reason' => FailureReason::RateLimited,
            'next_poll_at' => now()->addMinutes(2),
        ]);

        expect(app(UploadService::class)->dispatchPendingUploads($this->token))->toBe(1);
        Queue::assertPushed(ProcessSingleUpload::class, fn (ProcessSingleUpload $job) => $job->upload->is($due));

        // A rate-limited upload is never given up for waiting: it is taken once it is due.
        $this->travel(3)->minutes();
        expect(app(UploadService::class)->dispatchPendingUploads($this->token))->toBe(1);
        Queue::assertPushed(ProcessSingleUpload::class, fn (ProcessSingleUpload $job) => $job->upload->is($waiting));
    });
});
