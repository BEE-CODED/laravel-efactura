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
use BeeCoded\EFactura\Models\EfacturaUpload;
use BeeCoded\EFactura\Services\UploadService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * One try at sending one upload. The row, not the queue, owns the retry schedule: every
 * outcome that is not final writes when the row is due again (next_poll_at), and the next
 * UploadService::dispatchPendingUploads() after that queues a new job. Hence one try.
 */
class ProcessSingleUpload implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    // Its only model is its own upload: once that is gone there is nothing to send.
    public bool $deleteWhenMissingModels = true;

    /**
     * @param  string|null  $lease  The next_poll_at the dispatch reserved the row with. A job
     *                              without one works the row only when it is due.
     */
    public function __construct(
        public EfacturaUpload $upload,
        public ?string $lease = null,
    ) {
        $this->onConnection(config('efactura.connection') ?: null)->onQueue(config('efactura.queue'));
    }

    public function handle(UploadService $uploadService): void
    {
        PipelineTablesDisabledException::throwIfDisabled();

        // The row keeps its lease and is dispatched again once that ends.
        if (!config('efactura.enabled') || !config('efactura.features.upload_invoices')) {
            return;
        }

        $uploadService->processUpload($this->upload, $this->lease);
    }

    /**
     * The job threw or timed out, or the queue gave up on a redelivered copy
     * (MaxAttemptsExceededException) of a run whose worker died.
     *
     * An Uploading row is parked for reconciliation (a dead worker's by SweepStaleUploads) and
     * a Pending one counts one transient attempt: see UploadService::jobFailed().
     */
    public function failed(Throwable $exception): void
    {
        app(UploadService::class)->jobFailed($this->upload, $this->lease, $exception);
    }
}
