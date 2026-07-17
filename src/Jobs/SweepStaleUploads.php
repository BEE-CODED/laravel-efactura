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

use BeeCoded\EFactura\Services\UploadService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Rescues uploads stranded in the "uploading" state.
 *
 * processUpload() atomically claims a row into Uploading immediately before sending
 * it to ANAF. If the worker then dies hard (job timeout, SIGKILL, OOM, deploy), the
 * row stays in Uploading forever: nothing in the pipeline transitions out of it, and
 * the queue's own retry of that job re-runs processUpload(), whose claim
 * (WHERE status = pending) matches zero rows and silently returns "success".
 *
 * ProcessSingleUpload::failed() catches the cases the queue actually reports. This
 * job is the safety net for the ones it never does — a SIGKILL'd worker never gets
 * to run failed() at all.
 *
 * Stranded rows are parked as Failed/Indeterminate, NOT returned to Pending: the
 * crash may have happened after the POST reached ANAF, and re-submitting would
 * double-file a legal invoice. Resolving them requires a human (efactura:reconcile).
 *
 * Schedule it a few times an hour:
 *
 *     Schedule::job(new SweepStaleUploads)->everyFifteenMinutes();
 */
class SweepStaleUploads implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public array $backoff = [60, 180, 300];

    public int $maxExceptions = 3;

    public int $uniqueFor;

    public function __construct()
    {
        $this->onQueue(config('efactura.queue'));
        $this->uniqueFor = (int) config('efactura.jobs.unique_for_seconds', 3600);
    }

    public function handle(UploadService $uploadService): void
    {
        if (!config('efactura.enabled') || !config('efactura.features.upload_invoices')) {
            return;
        }

        $staleAfterMinutes = (int) config('efactura.jobs.stale_uploading_minutes', 30);

        if ($staleAfterMinutes <= 0) {
            return;
        }

        $swept = $uploadService->sweepStaleUploads($staleAfterMinutes);

        if ($swept > 0) {
            Log::warning('EFactura: Parked stranded uploads for reconciliation', [
                'count' => $swept,
                'stale_after_minutes' => $staleAfterMinutes,
            ]);
        }
    }
}
