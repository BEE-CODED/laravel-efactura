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

use BeeCoded\EFactura\Models\EfacturaMessage;
use BeeCoded\EFactura\Models\EfacturaToken;
use BeeCoded\EFactura\Services\DownloadService;
use Illuminate\Database\Eloquent\Builder;

/**
 * Downloads a company's received invoices whose next try is due.
 */
class DownloadReceivedInvoices extends CompanyBatchJob
{
    protected function feature(): string
    {
        return 'download_received';
    }

    protected function due(): ?Builder
    {
        return EfacturaMessage::needsDownload();
    }

    public function run(EfacturaToken $token, DownloadService $downloadService): void
    {
        $downloadService->downloadAllReceivedInvoices($token);
    }
}
