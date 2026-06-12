<?php

/*
 * This file is part of the Laravel e-Factura package.
 *
 * (c) BEE-CODED <dev.ttl@beecoded.ro>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace BeeCoded\EFactura\Jobs;

use BeeCoded\EFactura\Jobs\Concerns\DiscardsWhenStale;
use BeeCoded\EFactura\Services\DownloadService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CheckUploadStatuses implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use DiscardsWhenStale, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public array $backoff = [60, 180, 300];

    public int $maxExceptions = 3;

    public int $uniqueFor;

    public function __construct(
        public ?string $cui = null,
    ) {
        $this->onQueue(config('efactura.queue'));
        $this->markEnqueued();
        $this->uniqueFor = (int) config('efactura.jobs.unique_for_seconds', 3600);
    }

    public function uniqueId(): string
    {
        return $this->cui ?? 'all';
    }

    public function handle(DownloadService $downloadService): void
    {
        if ($this->isStale()) {
            return;
        }

        if (!config('efactura.enabled') || !config('efactura.features.upload_invoices')) {
            return;
        }

        $downloadService->checkAllStatuses($this->cui);
    }
}
