<?php

declare(strict_types=1);

/*
 * This file is part of the Laravel e-Factura package.
 *
 * (c) BEE-CODED <dev.ttl@beecoded.ro>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace BeeCoded\EFactura\Jobs;

use BeeCoded\EFactura\Enums\FailureReason;
use BeeCoded\EFactura\Enums\UploadStatus;
use BeeCoded\EFactura\Models\EfacturaUpload;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Retries e-Factura uploads that failed for a reason that is safe to re-submit.
 *
 * That means rate limiting (the common case this job is named for) and transient
 * pre-flight/authentication errors — every reason where FailureReason::isRetryable()
 * holds, i.e. where the document provably never entered ANAF's filing pipeline.
 *
 * Uploads marked Indeterminate are deliberately NEVER picked up here: their original
 * attempt may already have reached ANAF, so re-sending would double-file a legal
 * invoice. They require human reconciliation (see `efactura:reconcile`).
 *
 * Resets qualifying uploads back to "pending" and dispatches ProcessSingleUpload for
 * each, ensuring they go through the rate-limit-aware upload path. Only retries
 * uploads within the configured max age window.
 */
class RetryRateLimitedUploads implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;

    public array $backoff = [60, 180, 300];

    public int $maxExceptions = 3;

    public int $uniqueFor = 600;

    public function __construct()
    {
        $this->onQueue(config('efactura.queue'));
    }

    public function handle(): void
    {
        if (!config('efactura.enabled') || !config('efactura.features.upload_invoices')) {
            return;
        }

        $batchSize = config('efactura.rate_limit.retry_batch_size', 250);
        $maxAgeDays = config('efactura.rate_limit.retry_max_age_days', 7);

        $uploads = $this->retryableQuery($maxAgeDays)
            ->oldest()
            ->limit($batchSize)
            ->get();

        if ($uploads->isEmpty()) {
            return;
        }

        // Atomic: only reset uploads still Failed AND still retryable. The
        // failure_reason guard is what keeps an Indeterminate upload — one that may
        // already sit in ANAF's pipeline — from being blindly re-submitted here.
        $resetCount = EfacturaUpload::whereIn('id', $uploads->pluck('id'))
            ->where('status', UploadStatus::Failed)
            ->whereIn('failure_reason', FailureReason::retryable())
            ->update([
                'status' => UploadStatus::Pending,
                'failure_reason' => null,
                'errors' => null,
                'processed_at' => null,
            ]);

        // Dispatch each through rate-limit-aware ProcessSingleUpload. Rows whose reset
        // lost a race are harmless: processUpload's claim (WHERE status = pending)
        // matches nothing and the job returns without touching ANAF.
        foreach ($uploads as $upload) {
            ProcessSingleUpload::dispatch($upload);
        }

        Log::info('EFactura: Reset retryable uploads to pending', [
            'reset_count' => $resetCount,
            'remaining' => $this->retryableQuery($maxAgeDays)->count(),
        ]);
    }

    /**
     * Uploads we can prove never reached ANAF's pipeline and that nothing else
     * will re-drive.
     *
     * Two shapes qualify, and both are safety nets rather than the normal path —
     * ProcessSingleUpload re-drives its own row by releasing itself:
     *
     *  - `Failed` + a retryable reason: the worker died between marking the
     *    failure and releasing itself. Also the shape the v2->v3 migration
     *    backfills onto legacy rows.
     *  - `Pending` + `rate_limited`: as of v3.0.0 markAsRateLimited releases the
     *    claim back to Pending, so this is a live rate-limited row. It is
     *    deliberately invisible to dispatchPendingUploads (which would stack a
     *    duplicate job on top of the released one every tick), which means if
     *    that released job dies, this job is the ONLY thing that recovers it.
     *
     * Selecting on the indexed `failure_reason` column replaces the previous
     * `where('errors', 'like', ...)`. That predicate was fatal on Postgres (no
     * `json ~~ text` operator, so the job threw on every run and nothing was ever
     * retried) and wrong everywhere else — it both missed ANAF's Romanian 429 bodies
     * and would have re-submitted any unrelated failure whose text merely mentioned
     * a rate limit.
     *
     * @return Builder<EfacturaUpload>
     */
    private function retryableQuery(int $maxAgeDays): Builder
    {
        return EfacturaUpload::where(function (Builder $query) {
            $query->where(function (Builder $failed) {
                $failed->where('status', UploadStatus::Failed)
                    ->whereIn('failure_reason', FailureReason::retryable());
            })->orWhere(function (Builder $released) {
                $released->where('status', UploadStatus::Pending)
                    ->where('failure_reason', FailureReason::RateLimited);
            });
        })
            ->where('created_at', '>=', now()->subDays($maxAgeDays));
    }
}
