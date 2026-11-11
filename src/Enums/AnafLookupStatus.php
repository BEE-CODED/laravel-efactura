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
 * The verdict of a CachedAnafLookup.
 */
enum AnafLookupStatus: string
{
    /** ANAF confirmed exactly this company; AnafLookupResult::$active says whether it is active. Cached 24 h. */
    case Found = 'found';

    /** ANAF answered that this CUI does not exist. Cached 15 min. */
    case NotFound = 'not_found';

    /** No usable answer: transport failure, an answer that does not confirm the request, or a day rollover. Not cached. */
    case Unavailable = 'unavailable';

    /** The lookup rate limit was reached (the SDK's own limiter or ANAF's 429). Not cached. */
    case Busy = 'busy';
}
