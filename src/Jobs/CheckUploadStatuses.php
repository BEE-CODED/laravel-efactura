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

use BeeCoded\EFactura\Exceptions\PipelineTablesDisabledException;
use BeeCoded\EFactura\Jobs\Concerns\DiscardsWhenStale;
use BeeCoded\EFactura\Models\EfacturaToken;
use BeeCoded\EFactura\Models\EfacturaUpload;
use BeeCoded\EFactura\Services\DownloadService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
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

    /**
     * A run longer than the schedule interval must not overlap the next one on another
     * worker: both would spend the same rows' daily ANAF quota.
     *
     * @return list<object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping($this->uniqueId()))->dontRelease()->expireAfter($this->timeout + 30)];
    }

    public function handle(DownloadService $downloadService): void
    {
        PipelineTablesDisabledException::throwIfDisabled();

        if ($this->isStale()) {
            return;
        }

        if (!config('efactura.enabled') || !config('efactura.features.upload_invoices')) {
            return;
        }

        // One job per CUI with due work, each with its own timeout and retries.
        if ($this->cui === null) {
            EfacturaToken::active()
                ->whereIn('id', EfacturaUpload::needsStatusCheck()->select('efactura_token_id'))
                ->pluck('cui')
                ->each(fn (string $cui) => dispatch((new self($cui))->asFanOutChild()));

            return;
        }

        $downloadService->checkAllStatuses($this->cui);
    }
}
