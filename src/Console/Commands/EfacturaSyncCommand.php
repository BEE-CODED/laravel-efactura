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

use BeeCoded\EFactura\Exceptions\MessageSyncFailedException;
use BeeCoded\EFactura\Services\DownloadService;
use BeeCoded\EFactura\Services\MessageSyncService;
use BeeCoded\EFactura\Services\TokenService;
use BeeCoded\EFacturaSdk\Support\Validators\VatNumberValidator;
use Illuminate\Console\Command;

class EfacturaSyncCommand extends Command
{
    protected $signature = 'efactura:sync
                            {--cui= : Sync messages for specific CUI only}
                            {--download : Also download received invoices}';

    protected $description = 'Sync messages from ANAF';

    public function handle(
        MessageSyncService $messageSyncService,
        DownloadService $downloadService,
        TokenService $tokenService,
    ): int {
        if (!config('efactura.enabled') || !config('efactura.features.sync_messages')) {
            $this->error('e-Factura sync feature is disabled.');

            return self::FAILURE;
        }

        $cui = $this->option('cui');
        if ($cui) {
            $cui = VatNumberValidator::stripPrefix($cui);
        }

        $this->info('Syncing messages from ANAF...');

        // Sync failures now propagate rather than being swallowed, so report them as
        // a non-zero exit instead of letting the command claim success (or dump a
        // stack trace at an operator).
        try {
            if ($cui) {
                $token = $tokenService->getToken($cui);
                if (!$token) {
                    $this->error("No active token found for CUI: {$cui}");

                    return self::FAILURE;
                }
                $synced = $messageSyncService->syncMessages($token);
            } else {
                $synced = $messageSyncService->syncAllMessages();
            }

            $this->info("Synced {$synced} message(s).");
        } catch (MessageSyncFailedException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } catch (\Throwable $e) {
            report($e);
            $this->error('Message sync failed: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($this->option('download') && config('efactura.features.download_received')) {
            $this->info('Downloading received invoices...');
            $downloadService->downloadAllReceivedInvoices($cui);
        }

        $this->info('Done.');

        return self::SUCCESS;
    }
}
