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

use BeeCoded\EFactura\Jobs\Concerns\DiscardsWhenStale;
use BeeCoded\EFactura\Models\EfacturaUpload;
use BeeCoded\EFactura\Services\TokenService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessPendingUploads implements ShouldBeUniqueUntilProcessing, ShouldQueue
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

    public function handle(TokenService $tokenService): void
    {
        if ($this->isStale()) {
            return;
        }

        if (!config('efactura.enabled') || !config('efactura.features.upload_invoices')) {
            return;
        }

        $query = EfacturaUpload::pending();

        if ($this->cui) {
            $token = $tokenService->getToken($this->cui);
            if (!$token) {
                Log::warning('EFactura: No token found for CUI in ProcessPendingUploads', ['cui' => $this->cui]);

                return;
            }
            $query->forToken($token);
        }

        $query->each(fn (EfacturaUpload $upload) => ProcessSingleUpload::dispatch($upload));
    }
}
