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
 * Thrown by the upload and message pipeline (services, jobs, commands) when
 * `efactura.pipeline_tables` is false: its tables were never migrated. Tokens, OAuth and
 * the raw client keep working.
 */
class PipelineTablesDisabledException extends \LogicException
{
    public static function throwIfDisabled(): void
    {
        if (!config('efactura.pipeline_tables', true)) {
            throw new self('The e-Factura upload and message pipeline is off (efactura.pipeline_tables is false), so its tables do not exist.');
        }
    }
}
