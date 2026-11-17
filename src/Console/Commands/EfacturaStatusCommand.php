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
use BeeCoded\EFactura\Enums\StatusCheckOutcome;
use BeeCoded\EFactura\Exceptions\PipelineTablesDisabledException;
use BeeCoded\EFactura\Services\DownloadService;
use Illuminate\Console\Command;

/**
 * Exits 1 when the given --cui or --upload names nothing, when the status check of --upload
 * hit a rate limit or failed, or when the run of any company stopped (a rate limit or an
 * unavailable token).
 */
class EfacturaStatusCommand extends Command
{
    use ReadsEfacturaInput;

    protected $signature = 'efactura:status
                            {--cui= : Check statuses for specific CUI only}
                            {--upload= : Check status for specific upload ID}';

    protected $description = 'Check upload statuses and download responses';

    public function handle(DownloadService $downloadService): int
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

        $uploadId = $this->given('upload');

        if ($uploadId !== null) {
            $upload = $this->uploadFor($uploadId, $this->given('cui') !== null ? $tokens->first() : null);
            if (!$upload) {
                $this->error("Upload #{$uploadId} not found.");

                return self::FAILURE;
            }

            $this->info("Checking status for upload #{$uploadId}...");
            $outcome = $downloadService->checkStatus($upload);

            if ($outcome === StatusCheckOutcome::RateLimited) {
                $this->warn("Upload #{$uploadId}: ANAF rate limit reached, try again later.");

                return self::FAILURE;
            }

            if ($outcome === StatusCheckOutcome::Failed) {
                $this->error("The status check of upload #{$uploadId} failed; see the log.");

                return self::FAILURE;
            }

            $upload = $upload->fresh();
            if (!$upload) {
                $this->warn("Upload #{$uploadId} was deleted (possibly due to token deletion).");

                return self::SUCCESS;
            }

            if ($outcome === StatusCheckOutcome::NotPollable) {
                $this->warn("Upload #{$uploadId} was not polled: ".match (true) {
                    $upload->isTerminal() => "it is already {$upload->status->value}.",
                    !$upload->upload_index => 'it has no ANAF upload index yet.',
                    default => 'it has no e-Factura token.',
                });
            }

            if ($upload->download_id && !$upload->response_path) {
                $this->info('Downloading response...');
                // A skipped or failed download only logs; say so instead of implying it worked.
                if (!$downloadService->downloadResponse($upload) && $upload->fresh()?->response_path === null) {
                    $this->warn("The response of upload #{$uploadId} was not downloaded; see the log.");
                }
            }

            if ($outcome === StatusCheckOutcome::Checked) {
                $this->info('Done.');
            }

            return self::SUCCESS;
        }

        $this->info('Checking all upload statuses...');
        $failed = false;

        foreach ($tokens as $token) {
            // A stopped status run skips that company's responses: they wait for the next run.
            if (!$downloadService->checkAllStatuses($token) || !$downloadService->downloadAllResponses($token)) {
                $this->warn("Stopped for {$token->cui} (rate limit or token unavailable); see the log.");
                $failed = true;
            }
        }

        if ($failed) {
            return self::FAILURE;
        }

        $this->info('Done.');

        return self::SUCCESS;
    }
}
