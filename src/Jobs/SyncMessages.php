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

use BeeCoded\EFactura\Models\EfacturaToken;
use BeeCoded\EFactura\Services\MessageSyncService;
use Illuminate\Database\Eloquent\Builder;

/**
 * Syncs the ANAF message list of a company. Every usable company is due.
 */
class SyncMessages extends CompanyBatchJob
{
    protected function feature(): string
    {
        return 'sync_messages';
    }

    protected function due(): ?Builder
    {
        return null;
    }

    public function run(EfacturaToken $token, MessageSyncService $messageSyncService): void
    {
        // Errors bubble, so the queue retries this company alone.
        $messageSyncService->syncMessages($token);
    }
}
