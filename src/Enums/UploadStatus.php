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

enum UploadStatus: string
{
    case Pending = 'pending';
    case Uploading = 'uploading';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Failed]);
    }

    public function isPending(): bool
    {
        return $this === self::Pending;
    }

    public function isInProgress(): bool
    {
        return in_array($this, [self::Uploading, self::Processing]);
    }
}
