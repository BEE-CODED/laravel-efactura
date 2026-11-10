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

use BeeCoded\EFactura\Enums\StatusCheckOutcome;
use BeeCoded\EFactura\Models\EfacturaUpload;
use BeeCoded\EFactura\Services\DownloadService;
use BeeCoded\EFacturaSdk\Support\Validators\VatNumberValidator;
use Illuminate\Console\Command;

class EfacturaStatusCommand extends Command
{
    protected $signature = 'efactura:status
                            {--cui= : Check statuses for specific CUI only}
                            {--upload= : Check status for specific upload ID}';

    protected $description = 'Check upload statuses and download responses';

    public function handle(DownloadService $downloadService): int
    {
        if (!config('efactura.enabled') || !config('efactura.features.upload_invoices')) {
            $this->error('e-Factura upload feature is disabled.');

            return self::FAILURE;
        }

        $uploadId = $this->option('upload');
        $cui = $this->option('cui');
        if ($cui) {
            $cui = VatNumberValidator::stripPrefix($cui);
        }

        if ($uploadId) {
            $upload = EfacturaUpload::find($uploadId);
            if (!$upload) {
                $this->error("Upload #{$uploadId} not found.");

                return self::FAILURE;
            }

            $this->info("Checking status for upload #{$uploadId}...");
            $outcome = $downloadService->checkStatus($upload);

            if ($outcome === StatusCheckOutcome::RateLimited) {
                $this->warn("Skipped: the per-message status limit of upload #{$uploadId} is reached, try again later.");

                return self::FAILURE;
            }

            if ($outcome === StatusCheckOutcome::Failed) {
                $this->error("The status check of upload #{$uploadId} failed; see the log.");

                return self::FAILURE;
            }

            $upload = $upload->fresh();
            if (!$upload) {
                $this->warn("Upload #{$uploadId} was deleted (possibly due to token deletion).");

                return self::SUCCESS;
            }

            if ($outcome === StatusCheckOutcome::NotPollable) {
                $this->warn("Upload #{$uploadId} was not polled: ".match (true) {
                    !$upload->upload_index => 'it has no ANAF upload index yet.',
                    $upload->isTerminal() => "it is already {$upload->status->value}.",
                    default => 'it has no e-Factura token.',
                });
            }

            if ($upload->download_id) {
                $this->info('Downloading response...');
                $downloadService->downloadResponse($upload);

                // A skipped or failed download only logs; say so instead of implying it worked.
                $after = $upload->fresh();
                if ($after !== null && $after->response_path === null) {
                    $this->warn("The response of upload #{$uploadId} was not downloaded (limit reached or error; see the log). ".(
                        $after->response_attempts < EfacturaUpload::MAX_RESPONSE_ATTEMPTS
                            ? 'DownloadResponses retries it.'
                            : "It failed {$after->response_attempts} times and is no longer downloaded automatically."
                    ));
                }
            }

            if ($outcome === StatusCheckOutcome::Checked) {
                $this->info('Done.');
            }

            return self::SUCCESS;
        }

        $this->info('Checking all upload statuses...');
        $downloadService->checkAllStatuses($cui);

        $this->info('Downloading pending responses...');
        $downloadService->downloadAllResponses($cui);

        $this->info('Done.');

        return self::SUCCESS;
    }
}
