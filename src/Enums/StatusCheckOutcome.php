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
 * What DownloadService::checkStatus() did with one upload.
 */
enum StatusCheckOutcome: string
{
    /** ANAF answered and the answer was recorded ("still in progress" included). */
    case Checked = 'checked';

    /** Nothing to poll: no upload index, already final, or no token. */
    case NotPollable = 'not_pollable';

    /** Skipped: this upload's per-message status limit is reached; a later run polls it. */
    case RateLimited = 'rate_limited';

    /** The check failed and was logged: transient failures are retried, permanent ones counted. */
    case Failed = 'failed';
}
