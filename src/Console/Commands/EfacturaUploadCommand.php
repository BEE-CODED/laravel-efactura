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

use BeeCoded\EFactura\Exceptions\PipelineTablesDisabledException;
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
        PipelineTablesDisabledException::throwIfDisabled();

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

        $leftOut = $uploadService->pendingLeftOut($cui) ?? ['owned' => 0, 'cooling' => 0];
        if ($leftOut['owned'] + $leftOut['cooling'] > 0) {
            $this->comment(sprintf(
                'Left out %d pending upload(s): %d recently queued or rate-limited (a later run takes the recent ones once '
                    .'they are idle; rate-limited ones are retried by their own job or the rate-limit retry), '
                    .'%d on a company whose token refresh ANAF refused recently (taken after the cooldown).',
                $leftOut['owned'] + $leftOut['cooling'],
                $leftOut['owned'],
                $leftOut['cooling'],
            ));
        }

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

        $cooling = $uploadService->pendingLeftOut($cui)['cooling'] ?? 0;
        if ($cooling > 0) {
            $this->comment("Skipped {$cooling} upload(s) of a company whose token refresh ANAF refused recently; a later run takes them after the cooldown.");
        }

        return self::SUCCESS;
    }
}
