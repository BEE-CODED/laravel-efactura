<?php

/*
 * This file is part of the Laravel e-Factura package.
 *
 * (c) BEE-CODED <dev.ttl@beecoded.ro>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace BeeCoded\EFactura\Contracts;

use BeeCoded\EFacturaSdk\Data\Invoice\InvoiceData;

interface EFacturaUploadableInterface
{
    /**
     * Transform this model into SDK's InvoiceData DTO for upload.
     */
    public function toEfacturaData(): InvoiceData;

    /**
     * Get the CUI for this invoice (determines which token to use).
     */
    public function getEfacturaCui(): string;
}
