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
            $downloadService->checkStatus($upload);

            $upload = $upload->fresh();
            if (!$upload) {
                $this->warn("Upload #{$uploadId} was deleted (possibly due to token deletion).");

                return self::SUCCESS;
            }

            if ($upload->download_id) {
                $this->info('Downloading response...');
                $downloadService->downloadResponse($upload);
            }

            $this->info('Done.');

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
