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
use BeeCoded\EFactura\Events\InvoiceFailed;
use BeeCoded\EFactura\Models\EfacturaUpload;
use BeeCoded\EFactura\Services\UploadService;
use BeeCoded\EFacturaSdk\Services\RateLimiter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessSingleUpload implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;

    public int $maxExceptions = 3;

    private \DateTimeImmutable $retryDeadline;

    public function __construct(
        public EfacturaUpload $upload,
    ) {
        $this->onQueue(config('efactura.queue'));

        $hours = (int) config('efactura.rate_limit.retry_window_hours', 24);
        $this->retryDeadline = new \DateTimeImmutable("+{$hours} hours");
    }

    /**
     * Fixed retry window from job creation — rate-limit releases don't count
     * toward a retry cap. After this window expires, the job fails permanently.
     */
    public function retryUntil(): \DateTime
    {
        return \DateTime::createFromImmutable($this->retryDeadline);
    }

    public function handle(UploadService $uploadService, RateLimiter $rateLimiter): void
    {
        if (!config('efactura.enabled') || !config('efactura.features.upload_invoices')) {
            return;
        }

        // Pre-check: release immediately if global rate limit quota exhausted
        if ($rateLimiter->isEnabled()) {
            $quota = $rateLimiter->getRemainingQuota('global');
            if ($quota['remaining'] <= 0) {
                $delay = max($quota['resetsIn'], 10);

                Log::info('EFactura: Rate limit quota exhausted, releasing job', [
                    'upload_id' => $this->upload->id,
                    'delay_seconds' => $delay,
                ]);

                $this->release($delay);

                return;
            }
        }

        $uploadService->processUpload($this->upload);

        // Post-check: the upload may have failed for a reason that is safe to re-drive
        // (rate limit, or a transient auth/pre-flight error). The reset is atomic and
        // itself refuses any non-retryable reason, so an Indeterminate upload can
        // never be re-submitted from here.
        $this->upload->refresh();

        $reason = $this->upload->failure_reason;

        // A rate-limited upload never leaves Pending — it is still in the pipeline,
        // waiting out ANAF's quota, so there is no failed state to reset. Just wait.
        if ($this->upload->isPending() && $reason === FailureReason::RateLimited) {
            $delay = $this->retryDelayFor($reason);

            Log::info('EFactura: Upload rate-limited, releasing for a later attempt', [
                'upload_id' => $this->upload->id,
                'delay_seconds' => $delay,
            ]);

            $this->release($delay);

            return;
        }

        if (!$this->upload->isFailed()) {
            return;
        }

        if ($reason === null || !$reason->isRetryable()) {
            return;
        }

        if ($this->transientAttemptsExhausted($reason)) {
            $this->giveUpOnTransientFailure($uploadService, $reason);

            return;
        }

        if ($uploadService->resetForRetry($this->upload)) {
            $delay = $this->retryDelayFor($reason);

            Log::info('EFactura: Retryable upload failure, resetting to pending', [
                'upload_id' => $this->upload->id,
                'failure_reason' => $reason->value,
                'delay_seconds' => $delay,
            ]);

            $this->release($delay);
        }
    }

    /**
     * The job is over: it threw its last attempt, or it ran past retryUntil().
     *
     * Two very different rows can arrive here, and they must not be treated alike.
     *
     * UPLOADING — a crash between the atomic claim and a terminal transition. Nothing
     * drives such a row out: the queue's own retry re-runs processUpload(), whose claim
     * (WHERE status = pending) matches zero rows and silently reports success having done
     * nothing. We deliberately do NOT reset it to Pending — the process may have died AFTER
     * the POST reached ANAF, so re-driving would double-file a legal invoice. It is parked
     * as Failed/Indeterminate for a human to reconcile against ANAF's message list.
     *
     * PENDING — the retry-window expiry routes. handle() calls resetForRetry() (-> Pending)
     * and then release()s, and the rate-limit pre-check release()s a still-Pending row
     * outright. This used to delegate to parkStranded* unconditionally, whose
     * `WHERE status = uploading` guard matched ZERO rows here: it returned false silently,
     * no terminal state was reached, no InvoiceFailed ever fired, and ProcessPendingUploads
     * handed the Pending row straight back with a brand-new 24h window — so
     * retry_window_hours was not a cap at all. Nothing was transmitted from a Pending row,
     * so it is abandoned terminally instead.
     */
    public function failed(Throwable $exception): void
    {
        Log::error('EFactura: Upload job failed permanently', [
            'upload_id' => $this->upload->id,
            'error' => $exception->getMessage(),
        ]);

        $uploadService = app(UploadService::class);

        $parked = $uploadService->parkStrandedUploadAsIndeterminate(
            $this->upload,
            'Upload job died mid-flight: '.$exception->getMessage(),
        );

        if ($parked) {
            return;
        }

        $uploadService->abandonUnsentUpload(
            $this->upload,
            'Upload job gave up before the document was sent to ANAF: '.$exception->getMessage(),
        );
    }

    /**
     * Transient failures get a bounded number of re-drives. Without a cap, a durable
     * fault (a revoked token answering 401 forever) would release every few seconds
     * for the whole retry window and hammer ANAF.
     */
    private function transientAttemptsExhausted(FailureReason $reason): bool
    {
        if ($reason !== FailureReason::Transient) {
            return false;
        }

        $max = (int) config('efactura.jobs.max_transient_attempts', 5);

        return $max > 0 && $this->attempts() >= $max;
    }

    /**
     * The upload keeps failing transiently and we are out of attempts.
     *
     * It is provably not filed, so nothing is at risk — but the row must be retired to a
     * NON-RETRYABLE reason, not merely announced. Firing InvoiceFailed while leaving it
     * Failed/transient left it matching exactly what RetryRateLimitedUploads selects
     * (Failed + rate_limited|transient), so the scheduled job reset it to Pending with a
     * fresh attempts counter and a fresh 24h window: this "give up" then ran again every
     * ~10 minutes for retry_max_age_days, firing a false InvoiceFailed each time for an
     * upload that was immediately retried — the very false-alarm-then-retry contract
     * violation InvoiceRateLimited was introduced to eliminate.
     *
     * abandonFailure() both makes the transition (guarded, atomic) and fires the event.
     */
    private function giveUpOnTransientFailure(UploadService $uploadService, FailureReason $reason): void
    {
        Log::error('EFactura: Transient upload failure did not clear, giving up', [
            'upload_id' => $this->upload->id,
            'attempts' => $this->attempts(),
        ]);

        $uploadService->abandonFailure($this->upload, $reason, $this->recordedErrors());
    }

    /**
     * The error messages recorded on the row, as the list of strings they actually are.
     *
     * The model declares `errors` as array<string, mixed> because it is a generic json
     * cast, but everything written to it is a list of message strings. Narrow it here
     * rather than widening abandonFailure()'s contract to match the looser declaration.
     *
     * @return array<int, string>
     */
    private function recordedErrors(): array
    {
        return array_values(array_filter($this->upload->errors ?? [], is_string(...)));
    }

    private function retryDelayFor(FailureReason $reason): int
    {
        if ($reason === FailureReason::RateLimited) {
            return 60;
        }

        return (int) config('efactura.jobs.transient_retry_delay_seconds', 30);
    }
}
