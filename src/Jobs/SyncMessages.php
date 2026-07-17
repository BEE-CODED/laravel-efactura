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

use BeeCoded\EFactura\Exceptions\MessageSyncFailedException;
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

            // A single-CUI sync has no healthy neighbours to protect, and its failure
            // may well be a transient ANAF blip, so it retries normally.
            $messageSyncService->syncMessages($token);

            return;
        }

        // Otherwise sync all active tokens
        try {
            $messageSyncService->syncAllMessages();
        } catch (MessageSyncFailedException $e) {
            $this->failBatch($e);
        }
    }

    /**
     * Dead-letter a partially-failed batch WITHOUT retrying it.
     *
     * syncAllMessages() already isolates per-token failures and re-runs nothing, so
     * by the time it throws, every healthy CUI has synced and the only thing left is
     * a report of what did not. Letting that exception bubble would tell the queue
     * worker to run the WHOLE batch again — from scratch — re-hitting
     * /listaMesajeFactura for every HEALTHY CUI on each of tries=3. With 50 tokens and
     * one permanently-revoked refresh token, that is 3x the list calls per healthy CUI
     * per hour against ANAF's 750/day/CUI budget, spent re-asking questions that were
     * already answered, to fix a token that no retry can fix.
     *
     * fail() keeps the failure just as loud — the job still dead-letters and still
     * fires JobFailed — it simply does not pay for the same answer three times.
     */
    protected function failBatch(MessageSyncFailedException $e): void
    {
        Log::error('EFactura: batch message sync failed, not retrying', [
            'failed_cuis' => array_keys($e->failures),
            'error' => $e->getMessage(),
        ]);

        // Outside a worker (e.g. a synchronous dispatch in a console context) there is
        // no queue job to fail, so surface the error rather than swallow it.
        if ($this->job === null) {
            throw $e;
        }

        $this->fail($e);
    }
}
