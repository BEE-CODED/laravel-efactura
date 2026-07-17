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
 * One or more tokens failed to sync during a batch message sync.
 *
 * Batch syncing keeps per-token isolation on purpose — one company's revoked token
 * must not stop every other company from syncing — but the batch as a whole must not
 * then report success. This exception carries what failed so the caller (a queue job,
 * an artisan command, an error tracker) can be loud about it.
 */
class MessageSyncFailedException extends \RuntimeException
{
    /**
     * @param  array<string, string>  $failures  Map of CUI => error message.
     */
    public function __construct(
        string $message,
        public readonly array $failures = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * @param  array<string, string>  $failures  Map of CUI => error message.
     */
    public static function forTokens(array $failures, int $total): self
    {
        $detail = collect($failures)
            ->map(fn (string $error, string $cui) => "{$cui}: {$error}")
            ->implode('; ');

        return new self(
            sprintf(
                'e-Factura message sync failed for %d of %d token(s) — %s',
                count($failures),
                $total,
                $detail,
            ),
            $failures,
        );
    }
}
