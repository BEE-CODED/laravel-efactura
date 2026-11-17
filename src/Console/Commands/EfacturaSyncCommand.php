<?php

/*
 * This file is part of the Laravel e-Factura package.
 *
 * (c) BEE-CODED <dev.ttl@beecoded.ro>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace BeeCoded\EFactura\Console\Commands;

use BeeCoded\EFactura\Console\Concerns\ReadsEfacturaInput;
use BeeCoded\EFactura\Exceptions\PipelineTablesDisabledException;
use BeeCoded\EFactura\Services\DownloadService;
use BeeCoded\EFactura\Services\MessageSyncService;
use Illuminate\Console\Command;

/**
 * Syncs, and with --download downloads, for each company in turn. A failing company is
 * reported by its exception class only (the message may hold SQL) and the others still
 * run; the command then exits 1.
 */
class EfacturaSyncCommand extends Command
{
    use ReadsEfacturaInput;

    protected $signature = 'efactura:sync
                            {--cui= : Sync messages for specific CUI only}
                            {--download : Also download received invoices}';

    protected $description = 'Sync messages from ANAF';

    public function handle(MessageSyncService $messageSyncService, DownloadService $downloadService): int
    {
        PipelineTablesDisabledException::throwIfDisabled();

        if (!config('efactura.enabled') || !config('efactura.features.sync_messages')) {
            $this->error('e-Factura sync feature is disabled.');

            return self::FAILURE;
        }

        $tokens = $this->tokensFor();

        if ($tokens === null) {
            return self::FAILURE;
        }

        $download = (bool) $this->option('download');

        if ($download && !config('efactura.features.download_received')) {
            $this->warn('Skipped --download: features.download_received is disabled.');
            $download = false;
        }

        $this->info('Syncing messages from ANAF...');
        $synced = 0;
        $failed = false;

        foreach ($tokens as $token) {
            try {
                $synced += $messageSyncService->syncMessages($token);
            } catch (\Throwable $e) {
                report($e);
                $this->error("Message sync failed for {$token->cui} (".class_basename($e).'); see the log.');
                $failed = true;
            }

            if (!$download) {
                continue;
            }

            $this->info('Downloading received invoices...');

            try {
                if (!$downloadService->downloadAllReceivedInvoices($token)) {
                    $this->error("Received-invoice download stopped for {$token->cui} (rate limit or token unavailable); see the log.");
                    $failed = true;
                }
            } catch (\Throwable $e) {
                report($e);
                $this->error("Received-invoice download failed for {$token->cui} (".class_basename($e).'); see the log.');
                $failed = true;
            }
        }

        $this->info("Synced {$synced} message(s).");

        if ($failed) {
            return self::FAILURE;
        }

        $this->info('Done.');

        return self::SUCCESS;
    }
}
