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
use BeeCoded\EFactura\Models\EfacturaUpload;
use BeeCoded\EFactura\Services\UploadService;
use Illuminate\Database\Eloquent\Builder;

/**
 * Queues one ProcessSingleUpload per pending upload of a company whose next try is due.
 */
class ProcessPendingUploads extends CompanyBatchJob
{
    protected function feature(): string
    {
        return 'upload_invoices';
    }

    protected function due(): ?Builder
    {
        return EfacturaUpload::awaitingDispatch();
    }

    public function run(EfacturaToken $token, UploadService $uploadService): void
    {
        $uploadService->dispatchPendingUploads($token);
    }
}
