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
use BeeCoded\EFactura\Services\UploadService;

/**
 * Thrown when queueing an upload would risk filing the same document at ANAF twice, or in
 * a mode other than the one asked for.
 *
 * Raised only where silently returning the existing record would be wrong:
 *
 *  - its delivery is indeterminate: a human must reconcile it first (efactura:reconcile);
 *  - it is Uploading, Processing or Completed and is re-queued with different options,
 *    which would describe a filing other than the one made;
 *  - it failed and kept changing while it was being re-queued: queue it again.
 *
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
        // ANAF decides an indexed upload's outcome under its index, so --not-filed needs --despite-index then.
        // An index ANAF cannot have assigned (kept from an unreadable answer) is not offered as --index.
        $index = preg_match(UploadService::UPLOAD_INDEX_PATTERN, (string) $upload->upload_index) === 1 ? $upload->upload_index : '<index_incarcare as shown in SPV>';
        $resolution = $upload->upload_index !== null
            ? "`php artisan efactura:reconcile --filed={$upload->id} --index={$index}` once you have found it in SPV, "
                ."or `php artisan efactura:reconcile --not-filed={$upload->id} --despite-index` if you checked SPV under that index and it is not there."
            : "`php artisan efactura:reconcile --filed={$upload->id} --index=<index_incarcare>` "
                ."or `php artisan efactura:reconcile --not-filed={$upload->id}`.";

        return new self(
            "Cannot re-queue upload {$upload->id}: its delivery to ANAF is indeterminate. "
                .'Re-submitting could double-file this invoice. Reconcile it first with '.$resolution,
            $upload,
        );
    }
}
