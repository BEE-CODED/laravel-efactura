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

use BeeCoded\EFactura\Services\MessageSyncService;
use BeeCoded\EFactura\Services\TokenService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncMessages implements ShouldQueue
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

    public function handle(MessageSyncService $messageSyncService, TokenService $tokenService): void
    {
        if (!config('efactura.enabled') || !config('efactura.features.sync_messages')) {
            return;
        }

        // If CUI is specified, sync only that CUI
        if ($this->cui) {
            $token = $tokenService->getToken($this->cui);

            if (!$token) {
                Log::warning('EFactura: No token found for CUI', ['cui' => $this->cui]);

                return;
            }

            $messageSyncService->syncMessages($token);

            return;
        }

        // Otherwise sync all active tokens
        $messageSyncService->syncAllMessages();
    }
}
