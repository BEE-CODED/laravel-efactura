<?php

declare(strict_types=1);

/*
 * This file is part of the Laravel e-Factura package.
 *
 * (c) BEE-CODED <dev.ttl@beecoded.ro>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace BeeCoded\EFactura\Jobs;

use BeeCoded\EFactura\Enums\UploadStatus;
use BeeCoded\EFactura\Models\EfacturaUpload;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Retries e-Factura uploads that failed due to API rate limiting.
 *
 * Resets failed uploads back to "pending" and dispatches ProcessSingleUpload
 * for each, ensuring they go through the rate-limit-aware upload path.
 *
 * Only retries uploads within the configured max age window.
 */
class RetryRateLimitedUploads implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;

    public array $backoff = [60, 180, 300];

    public int $maxExceptions = 3;

    public int $uniqueFor = 600;

    public function __construct()
    {
        $this->onQueue(config('efactura.queue'));
    }

    public function handle(): void
    {
        if (!config('efactura.enabled') || !config('efactura.features.upload_invoices')) {
            return;
        }

        $batchSize = config('efactura.rate_limit.retry_batch_size', 250);
        $maxAgeDays = config('efactura.rate_limit.retry_max_age_days', 7);

        $uploads = EfacturaUpload::where('status', UploadStatus::Failed)
            ->where(function ($query) {
                $query->where('errors', 'like', '%RATE_LIMIT_EXCEEDED:%')
                    ->orWhere('errors', 'like', '%rate limit%');
            })
            ->where('created_at', '>=', now()->subDays($maxAgeDays))
            ->oldest()
            ->limit($batchSize)
            ->get();

        if ($uploads->isEmpty()) {
            return;
        }

        // Atomic: only reset uploads still in Failed state (prevents clobbering concurrent transitions)
        $resetCount = EfacturaUpload::whereIn('id', $uploads->pluck('id'))
            ->where('status', UploadStatus::Failed)
            ->update([
                'status' => UploadStatus::Pending,
                'errors' => null,
                'processed_at' => null,
            ]);

        // Dispatch each through rate-limit-aware ProcessSingleUpload
        foreach ($uploads as $upload) {
            ProcessSingleUpload::dispatch($upload);
        }

        Log::info('EFactura: Reset rate-limited uploads to pending', [
            'reset_count' => $resetCount,
            'remaining' => EfacturaUpload::where('status', UploadStatus::Failed)
                ->where(function ($query) {
                    $query->where('errors', 'like', '%RATE_LIMIT_EXCEEDED:%')
                        ->orWhere('errors', 'like', '%rate limit%');
                })
                ->where('created_at', '>=', now()->subDays($maxAgeDays))
                ->count(),
        ]);
    }
}
