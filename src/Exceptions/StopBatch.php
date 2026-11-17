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

/**
 * A read failed for a reason that applies to every row of the token: a rate limit other
 * than the row's own (`grant` false), or a refused, paused or unusable grant (`grant`
 * true). DownloadService stops the batch on it; it never leaves DownloadService.
 *
 * @internal
 */
final class StopBatch extends \RuntimeException
{
    public function __construct(public readonly bool $grant, \Throwable $previous)
    {
        parent::__construct('Stopping reads for this token.', 0, $previous);
    }
}
