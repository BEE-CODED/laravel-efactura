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

class EfacturaUploadCommand extends Command
{
    protected $signature = 'efactura:upload {--cui= : Process uploads for specific CUI only}';

    protected $description = 'Process pending e-Factura uploads';

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

        $this->info('Processing pending uploads...');
        $uploadService->processPendingUploads($cui);
        $this->info('Done.');

        return self::SUCCESS;
    }
}
