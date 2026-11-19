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
use BeeCoded\EFactura\Enums\FailureReason;
use BeeCoded\EFactura\Exceptions\PipelineTablesDisabledException;
use BeeCoded\EFactura\Models\EfacturaUpload;
use BeeCoded\EFactura\Services\TokenService;
use BeeCoded\EFactura\Services\UploadService;
use Illuminate\Console\Command;

/**
 * Queues each company's due pending uploads (UploadService::dispatchPendingUploads()): each
 * then gets the rate-limit pre-flight and its row's retry schedule. A company whose grant is
 * paused, or whose stored CUI is not canonical, is skipped with one line; its uploads wait in
 * Pending.
 *
 * --sync sends them inline instead, for deployments without a queue worker: one try per
 * due upload, and a company's run stops at its first rate-limited upload or once its grant
 * is paused.
 */
class EfacturaUploadCommand extends Command
{
    use ReadsEfacturaInput;

    protected $signature = 'efactura:upload
                            {--cui= : Process uploads for specific CUI only}
                            {--sync : Send the due uploads inline instead of queueing them (stops a company at its first rate limit or paused grant)}';

    protected $description = 'Queue pending e-Factura uploads for processing';

    public function handle(UploadService $uploadService, TokenService $tokenService): int
    {
        PipelineTablesDisabledException::throwIfDisabled();

        if (!config('efactura.enabled') || !config('efactura.features.upload_invoices')) {
            $this->error('e-Factura upload feature is disabled.');

            return self::FAILURE;
        }

        $tokens = $this->tokensFor();

        if ($tokens === null) {
            return self::FAILURE;
        }

        $sync = (bool) $this->option('sync');
        $count = 0;

        foreach ($tokens as $token) {
            if ($tokenService->refreshCooldownSeconds($token) > 0) {
                $this->line("{$token->cui}: skipped (cooling).");

                continue;
            }

            if (!$sync) {
                $count += $uploadService->dispatchPendingUploads($token);

                continue;
            }

            foreach (EfacturaUpload::awaitingDispatch()->forToken($token)->lazyById() as $upload) {
                $uploadService->processUpload($upload);
                $count++;

                // No queue to wait in: the company's other uploads wait for a later run. $upload->token
                // is the instance processUpload() used, so a refresh that rotated it still matches.
                if ($upload->fresh()?->failure_reason === FailureReason::RateLimited || $tokenService->refreshCooldownSeconds($upload->token ?? $token) > 0) {
                    break;
                }
            }
        }

        if ($sync) {
            $this->info("Processed {$count} upload(s).");

            return self::SUCCESS;
        }

        $this->info("Queued {$count} pending upload(s) for processing.");

        if ($count > 0) {
            $connection = config('efactura.connection') ?: null;

            $this->comment('Ensure a queue worker is running on the "'
                .(config('efactura.queue') ?: config('queue.connections.'.($connection ?? config('queue.default')).'.queue', 'default')).'" queue'
                .($connection === null ? '.' : ' of the "'.$connection.'" connection.'));
        }

        return self::SUCCESS;
    }
}
