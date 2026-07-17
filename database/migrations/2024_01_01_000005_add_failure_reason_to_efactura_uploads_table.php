<?php

/*
 * This file is part of the Laravel e-Factura package.
 *
 * (c) BEE-CODED <dev.ttl@beecoded.ro>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use BeeCoded\EFactura\Enums\FailureReason;
use BeeCoded\EFactura\Enums\UploadStatus;
use BeeCoded\EFactura\Models\EfacturaUpload;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the authoritative failure classification column.
 *
 * Previously the failure class was encoded into the free-text `errors` JSON payload
 * ("RATE_LIMIT_EXCEEDED: ...") and queried with `where('errors', 'like', ...)`.
 * `errors` is a `json` column and Postgres has no `json ~~ text` operator, so that
 * query threw on every RetryRateLimitedUploads run — rate-limited uploads were never
 * retried on Postgres at all. This column is indexed and driver-agnostic.
 *
 * RE-ENTRANT BY NECESSITY: this migration does DDL and then an unbounded backfill.
 * `ALTER TABLE` auto-commits on MySQL and is not wrapped in a transaction on SQLite,
 * so the two are not atomic and no wrapping transaction can make them so. If the
 * backfill is interrupted — SIGKILL, deploy timeout, connection drop — the column is
 * already committed while the migration was never recorded as run, so `php artisan
 * migrate` will try it again. Every step is therefore guarded on its own current
 * state: a re-run skips what already exists and resumes the backfill, instead of
 * dying on "Duplicate column name 'failure_reason'" and needing manual DB surgery.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Guarded independently, not as one `if`: an interrupt can land between the
        // column and the index (Laravel emits them as separate statements), so a
        // re-run must be able to find the column present and the index missing.
        if (!Schema::hasColumn('efactura_uploads', 'failure_reason')) {
            Schema::table('efactura_uploads', function (Blueprint $table) {
                $table->string('failure_reason')->nullable();
            });
        }

        if (!Schema::hasIndex('efactura_uploads', ['failure_reason'])) {
            Schema::table('efactura_uploads', function (Blueprint $table) {
                $table->index('failure_reason');
            });
        }

        // Always runs: it is the step most likely to have been interrupted, and it
        // is naturally idempotent — it only ever sets a reason on rows that carry
        // the legacy marker, to the same value it would have set the first time.
        $this->backfillFromLegacyErrorText();
    }

    public function down(): void
    {
        Schema::table('efactura_uploads', function (Blueprint $table) {
            $table->dropIndex(['failure_reason']);
            $table->dropColumn('failure_reason');
        });
    }

    /**
     * Reconstruct the classification for rows failed by the previous release.
     *
     * Deliberately done in PHP rather than as a `LIKE` over `errors`: casting a json
     * column to text for a pattern match is exactly the driver-specific hazard this
     * migration exists to remove, and the row count here is bounded by failed uploads.
     *
     * Anything that isn't recognisably a legacy rate-limit marker is left NULL rather
     * than guessed at — a wrong guess of `rate_limited` would hand the row to the
     * retry job and risk double-filing a legal invoice.
     */
    private function backfillFromLegacyErrorText(): void
    {
        EfacturaUpload::query()
            ->where('status', UploadStatus::Failed)
            ->whereNotNull('errors')
            ->chunkById(500, function ($uploads) {
                foreach ($uploads as $upload) {
                    $encoded = json_encode($upload->getRawOriginal('errors'));

                    if ($encoded === false || !str_contains($encoded, 'RATE_LIMIT_EXCEEDED:')) {
                        continue;
                    }

                    EfacturaUpload::where('id', $upload->id)
                        ->update(['failure_reason' => FailureReason::RateLimited]);
                }
            });
    }
};
