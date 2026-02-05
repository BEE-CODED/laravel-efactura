<?php

/*
 * This file is part of the Laravel e-Factura package.
 *
 * (c) BEE-CODED <dev.ttl@beecoded.ro>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace BeeCoded\EFactura\Events;

use BeeCoded\EFactura\Models\EfacturaUpload;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class InvoiceProcessed
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public EfacturaUpload $upload,
    ) {}
}
