<?php

/*
 * This file is part of the Laravel e-Factura package.
 *
 * (c) BEE-CODED <dev.ttl@beecoded.ro>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace BeeCoded\EFactura\Enums;

/**
 * How a stored response archive (efactura_uploads.response_verification) was checked.
 */
enum ResponseVerification: string
{
    /** It holds exactly the uploaded invoice, plus only signatures. */
    case Verified = 'verified';

    /** Only its limits were checked: the upload is not Completed (a rejection report, or the archive of an indeterminate upload), or its uploaded XML is not stored. */
    case LimitsOnly = 'limits_only';

    /** It does not hold the uploaded invoice. Kept as ANAF sent it, and logged. */
    case Mismatch = 'mismatch';
}
