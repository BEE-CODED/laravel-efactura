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

use BeeCoded\EFactura\Exceptions\PipelineTablesDisabledException;
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
 * it to ANAF. If the worker then dies hard (SIGKILL, OOM, deploy), the row stays in
 * Uploading forever: nothing in the pipeline transitions out of it, and the queue's
 * redelivered copy of that job changes nothing.
 *
 * ProcessSingleUpload::failed() parks the row when the job throws or times out. A dead
 * worker is parked by this job alone: it never runs failed(), and the redelivered copy's
 * MaxAttemptsExceededException is ignored.
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

    /** Below its 15-minute cadence, so a lock a failed queue push left behind holds one tick at most. */
    public int $uniqueFor = 600;

    /** An upload in Uploading this long had its worker die: far above ProcessSingleUpload's 120 s timeout. */
    public const int STALE_MINUTES = 30;

    public function __construct()
    {
        $this->onConnection(config('efactura.connection') ?: null)->onQueue(config('efactura.queue'));
    }

    public function handle(UploadService $uploadService): void
    {
        PipelineTablesDisabledException::throwIfDisabled();

        if (!config('efactura.enabled') || !config('efactura.features.upload_invoices')) {
            return;
        }

        $swept = $uploadService->sweepStaleUploads(self::STALE_MINUTES);

        if ($swept > 0) {
            Log::warning('EFactura: Parked stranded uploads for reconciliation', [
                'count' => $swept,
                'stale_after_minutes' => self::STALE_MINUTES,
            ]);
        }
    }
}
