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

use BeeCoded\EFactura\Models\EfacturaUpload;
use BeeCoded\EFactura\Services\UploadService;
use BeeCoded\EFacturaSdk\Services\RateLimiter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessSingleUpload implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;

    public int $maxExceptions = 3;

    private \DateTimeImmutable $retryDeadline;

    public function __construct(
        public EfacturaUpload $upload,
    ) {
        $this->onQueue(config('efactura.queue'));

        $hours = (int) config('efactura.rate_limit.retry_window_hours', 24);
        $this->retryDeadline = new \DateTimeImmutable("+{$hours} hours");
    }

    /**
     * Fixed retry window from job creation — rate-limit releases don't count
     * toward a retry cap. After this window expires, the job fails permanently.
     */
    public function retryUntil(): \DateTime
    {
        return \DateTime::createFromImmutable($this->retryDeadline);
    }

    public function handle(UploadService $uploadService, RateLimiter $rateLimiter): void
    {
        if (!config('efactura.enabled') || !config('efactura.features.upload_invoices')) {
            return;
        }

        // Pre-check: release immediately if global rate limit quota exhausted
        if ($rateLimiter->isEnabled()) {
            $quota = $rateLimiter->getRemainingQuota('global');
            if ($quota['remaining'] <= 0) {
                $delay = max($quota['resetsIn'], 10);

                Log::info('EFactura: Rate limit quota exhausted, releasing job', [
                    'upload_id' => $this->upload->id,
                    'delay_seconds' => $delay,
                ]);

                $this->release($delay);

                return;
            }
        }

        $uploadService->processUpload($this->upload);

        // Post-check: race condition — rate limit hit during actual API call
        // Uses atomic reset (WHERE status = Failed) to prevent overwriting concurrent transitions
        $this->upload->refresh();
        if ($this->upload->isFailed() && $this->isRateLimitError()) {
            if ($uploadService->resetForRateLimit($this->upload)) {
                Log::info('EFactura: Rate limit hit during upload, resetting to pending', [
                    'upload_id' => $this->upload->id,
                ]);

                $this->release(60);
            }
        }
    }

    public function failed(Throwable $exception): void
    {
        Log::error('EFactura: Upload job failed permanently', [
            'upload_id' => $this->upload->id,
            'error' => $exception->getMessage(),
        ]);
    }

    private function isRateLimitError(): bool
    {
        $errors = $this->upload->errors;
        if (!$errors) {
            return false;
        }

        $encoded = json_encode($errors, JSON_INVALID_UTF8_SUBSTITUTE);
        if ($encoded === false) {
            return false;
        }

        // Check for controlled marker set by UploadService, plus legacy text matching for backward compatibility
        return str_contains($encoded, 'RATE_LIMIT_EXCEEDED:')
            || str_contains(strtolower($encoded), 'rate limit');
    }
}
