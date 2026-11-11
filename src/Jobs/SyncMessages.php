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
use BeeCoded\EFactura\Services\MessageSyncService;
use BeeCoded\EFactura\Services\TokenService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Syncs the ANAF message list of one CUI.
 *
 * Without a CUI it only dispatches one job per active token: each CUI gets its own
 * timeout and its own retries, so one slow or broken CUI neither uses up the others'
 * budget nor makes the queue list the healthy ones again. Unique per CUI.
 */
class SyncMessages implements ShouldBeUnique, ShouldQueue
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

    public function handle(MessageSyncService $messageSyncService, TokenService $tokenService): void
    {
        PipelineTablesDisabledException::throwIfDisabled();

        if ($this->isStale()) {
            return;
        }

        if (!config('efactura.enabled') || !config('efactura.features.sync_messages')) {
            return;
        }

        if ($this->cui === null) {
            foreach ($tokenService->getActiveTokens() as $token) {
                dispatch((new self($token->cui))->asFanOutChild());
            }

            return;
        }

        $token = $tokenService->getToken($this->cui);

        if (!$token) {
            Log::warning('EFactura: No token found for CUI', ['cui' => $this->cui]);

            return;
        }

        // Errors bubble, so the queue retries this CUI alone.
        $messageSyncService->syncMessages($token);
    }
}
