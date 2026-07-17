<?php

/*
 * This file is part of the Laravel e-Factura package.
 *
 * (c) BEE-CODED <dev.ttl@beecoded.ro>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace BeeCoded\EFactura\Console\Commands;

use BeeCoded\EFactura\Services\UploadService;
use BeeCoded\EFacturaSdk\Support\Validators\VatNumberValidator;
use Illuminate\Console\Command;

/**
 * Queues pending uploads onto the rate-limit-aware pipeline.
 *
 * This used to call UploadService::processPendingUploads() synchronously, which
 * bypasses ProcessSingleUpload entirely: no quota pre-flight, no release-and-retry.
 * Running it against a backlog larger than ANAF's remaining quota marked every
 * invoice past the limit permanently Failed and fired an InvoiceFailed storm — for a
 * condition that is purely transient.
 *
 * Dispatching per row instead means each upload gets the pre-flight check and, when
 * the quota is gone, is released back to the queue rather than failed. --sync keeps
 * the old inline behaviour for deployments without a queue worker, but now stops at
 * the first rate limit instead of burning down the backlog.
 */
class EfacturaUploadCommand extends Command
{
    protected $signature = 'efactura:upload
                            {--cui= : Process uploads for specific CUI only}
                            {--sync : Process inline instead of queueing (no rate-limit retry; stops at the first rate limit)}';

    protected $description = 'Queue pending e-Factura uploads for processing';

    public function handle(UploadService $uploadService): int
    {
        if (!config('efactura.enabled') || !config('efactura.features.upload_invoices')) {
            $this->error('e-Factura upload feature is disabled.');

            return self::FAILURE;
        }

        $cui = $this->option('cui');
        if ($cui) {
            $cui = VatNumberValidator::stripPrefix($cui);
        }

        if ($this->option('sync')) {
            return $this->processInline($uploadService, $cui);
        }

        $dispatched = $uploadService->dispatchPendingUploads($cui);

        if ($dispatched === null) {
            $this->error("No active token found for CUI: {$cui}");

            return self::FAILURE;
        }

        $this->info("Queued {$dispatched} pending upload(s) for processing.");

        if ($dispatched > 0) {
            $this->comment('Ensure a queue worker is running on the "'
                .(config('efactura.queue') ?? 'default').'" queue.');
        }

        return self::SUCCESS;
    }

    private function processInline(UploadService $uploadService, ?string $cui): int
    {
        $this->warn('Running inline: no rate-limit retry. Uploads stop at the first rate limit.');

        $processed = $uploadService->processPendingUploads($cui);

        $this->info("Processed {$processed} upload(s).");

        return self::SUCCESS;
    }
}
