<?php

/*
 * This file is part of the Laravel e-Factura package.
 *
 * (c) BEE-CODED <dev.ttl@beecoded.ro>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace BeeCoded\EFactura\Exceptions;

use BeeCoded\EFactura\Models\EfacturaUpload;

/**
 * Thrown when queueing an upload would risk filing the same document at ANAF twice.
 *
 * Raised only where silently returning the existing record would be wrong — i.e. when
 * the existing upload's delivery is indeterminate and a human must reconcile it first.
 * Ordinary duplicate calls (double-click, application retry) are idempotent and return
 * the existing record instead of throwing.
 */
class DuplicateUploadException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?EfacturaUpload $existingUpload = null,
    ) {
        parent::__construct($message);
    }

    public static function indeterminate(EfacturaUpload $upload): self
    {
        return new self(
            "Cannot re-queue upload {$upload->id}: its delivery to ANAF is indeterminate. "
                .'Re-submitting could double-file this invoice. Reconcile it first with '
                ."`php artisan efactura:reconcile --filed={$upload->id} --index=<index_incarcare>` "
                ."or `php artisan efactura:reconcile --not-filed={$upload->id}`.",
            $upload,
        );
    }
}
