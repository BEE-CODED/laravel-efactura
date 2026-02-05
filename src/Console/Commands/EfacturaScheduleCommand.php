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

use BeeCoded\EFactura\Jobs\CheckUploadStatuses;
use BeeCoded\EFactura\Jobs\DownloadReceivedInvoices;
use BeeCoded\EFactura\Jobs\DownloadResponses;
use BeeCoded\EFactura\Jobs\ProcessPendingUploads;
use BeeCoded\EFactura\Jobs\SyncMessages;
use Illuminate\Console\Command;

class EfacturaScheduleCommand extends Command
{
    protected $signature = 'efactura:schedule';

    protected $description = 'Register e-Factura scheduled jobs';

    public function handle(): int
    {
        if (!config('efactura.enabled')) {
            $this->warn('e-Factura is disabled.');

            return self::SUCCESS;
        }

        $this->dispatchIfDue('upload_invoices', ProcessPendingUploads::class);
        $this->dispatchIfDue('check_statuses', CheckUploadStatuses::class);
        $this->dispatchIfDue('download_responses', DownloadResponses::class);
        $this->dispatchIfDue('download_received', DownloadReceivedInvoices::class);
        $this->dispatchIfDue('sync_messages', SyncMessages::class);

        $this->info('e-Factura jobs dispatched.');

        return self::SUCCESS;
    }

    protected function dispatchIfDue(string $configKey, string $jobClass): void
    {
        $cron = config("efactura.schedule.{$configKey}");

        if (!$cron) {
            return;
        }

        // Check if the cron expression matches current time
        if ($this->cronMatches($cron)) {
            dispatch(new $jobClass);
            $this->line("Dispatched: {$jobClass}");
        }
    }

    protected function cronMatches(string $cron): bool
    {
        // Use preg_split to handle multiple spaces gracefully
        $cronParts = preg_split('/\s+/', trim($cron), -1, PREG_SPLIT_NO_EMPTY);
        if ($cronParts === false || count($cronParts) !== 5) {
            return false;
        }

        $now = now();
        [$minute, $hour, $day, $month, $weekday] = $cronParts;

        return $this->matchesPart($minute, $now->minute)
            && $this->matchesPart($hour, $now->hour)
            && $this->matchesPart($day, $now->day)
            && $this->matchesPart($month, $now->month)
            && $this->matchesPart($weekday, $now->dayOfWeek);
    }

    protected function matchesPart(string $pattern, int $value): bool
    {
        $pattern = trim($pattern);

        if ($pattern === '*') {
            return true;
        }

        if ($pattern === '') {
            return false; // Invalid empty pattern
        }

        // Handle */n patterns
        if (str_starts_with($pattern, '*/')) {
            $divisor = (int) substr($pattern, 2);

            return $divisor > 0 && $value % $divisor === 0;
        }

        // Handle comma-separated values
        if (str_contains($pattern, ',')) {
            $values = array_map('intval', array_map('trim', explode(',', $pattern)));

            return in_array($value, $values, true);
        }

        // Handle ranges
        if (str_contains($pattern, '-')) {
            $parts = explode('-', $pattern);
            if (count($parts) !== 2) {
                return false; // Invalid range format
            }
            [$start, $end] = array_map('intval', $parts);
            if ($start < 0 || $end < 0 || $start > $end) {
                return false; // Invalid range values
            }

            return $value >= $start && $value <= $end;
        }

        // Handle single numeric values
        $numericValue = (int) $pattern;

        return $numericValue >= 0 && $numericValue === $value;
    }
}
