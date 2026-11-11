<?php

/*
 * This file is part of the Laravel e-Factura package.
 *
 * (c) BEE-CODED <dev.ttl@beecoded.ro>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace BeeCoded\EFactura\Services;

use BeeCoded\EFactura\Enums\AnafLookupStatus;

/**
 * One CachedAnafLookup verdict for a CUI on an ANAF date (Y-m-d, Europe/Bucharest).
 * $active is set only when the status is Found.
 */
final readonly class AnafLookupResult
{
    public function __construct(
        public AnafLookupStatus $status,
        public string $cui,
        public string $queryDate,
        public ?bool $active = null,
    ) {}
}
