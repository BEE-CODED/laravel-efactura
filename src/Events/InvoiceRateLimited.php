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

/**
 * An upload hit ANAF's rate limit and will be retried automatically.
 *
 * This is a TRANSIENT, non-terminal signal. It exists so that `InvoiceFailed` can
 * mean what consumers assume it means: the filing is genuinely over. Previously a
 * rate-limit hit fired `InvoiceFailed` and was then silently reset to Pending and
 * retried, so anyone alerting on `InvoiceFailed` got a false alarm followed by a
 * successful upload.
 *
 * Do not treat this as a failure. The upload stays in the pipeline.
 */
class InvoiceRateLimited
{
    use Dispatchable, SerializesModels;

    /**
     * @param  array<int, string>  $errors
     * @param  int|null  $retryAfterSeconds  Hint from the SDK/ANAF, when available.
     */
    public function __construct(
        public EfacturaUpload $upload,
        public array $errors = [],
        public ?int $retryAfterSeconds = null,
    ) {}
}
