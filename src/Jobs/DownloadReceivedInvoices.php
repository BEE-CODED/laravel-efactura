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
use BeeCoded\EFactura\Models\EfacturaMessage;
use BeeCoded\EFactura\Models\EfacturaToken;
use BeeCoded\EFactura\Services\DownloadService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

class DownloadReceivedInvoices implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public array $backoff = [60, 180, 300];

    public int $maxExceptions = 3;

    public function __construct(
        public ?string $cui = null,
    ) {
        $this->onQueue(config('efactura.queue'));
    }

    public function uniqueId(): string
    {
        return $this->cui ?? 'all';
    }

    /**
     * A run longer than the schedule interval must not overlap the next one on another
     * worker: both would spend the same messages' daily download quota.
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

        if (!config('efactura.enabled') || !config('efactura.features.download_received')) {
            return;
        }

        // One job per CUI with due work, each with its own timeout and retries.
        if ($this->cui === null) {
            EfacturaToken::active()
                ->whereIn('id', EfacturaMessage::notDownloaded()->received()
                    ->where('download_attempts', '<', EfacturaMessage::MAX_DOWNLOAD_ATTEMPTS)
                    ->select('efactura_token_id'))
                ->pluck('cui')
                ->each(fn (string $cui) => static::dispatch($cui));

            return;
        }

        $downloadService->downloadAllReceivedInvoices($this->cui);
    }
}
