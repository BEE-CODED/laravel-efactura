<?php

/*
 * This file is part of the Laravel e-Factura package.
 *
 * (c) BEE-CODED <dev.ttl@beecoded.ro>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Give "we tried to fetch the response and ANAF handed us junk" somewhere to live.
 *
 * ANAF can answer /descarcare with a 2xx whose body is not a ZIP — typically a
 * JSON `eroare` payload. The SDK's guardDownloadBody() throws on that, so
 * `response_path` stays null. But `needsResponseDownload()` selects on
 * `whereNull('response_path')`, so the row was re-selected on EVERY scheduled
 * DownloadResponses run: one wasted call per row per run, forever, against a
 * per-message daily quota — and, because the error text is de-duplicated, with no
 * growing symptom to notice.
 *
 * The two obvious shortcuts are both wrong. Writing a placeholder `response_path`
 * would name a file that does not exist. Encoding "gave up" into the free-text
 * `errors` payload is exactly the stringly-typed classification v3.0.0 removed in
 * favour of `failure_reason` — FailureReason's docblock says so explicitly. State
 * the runtime branches on gets a column.
 *
 * The cap is bounded rather than one-shot: a junk body may be a transient ANAF
 * glitch, so it is worth a few attempts before a human is involved. Past the cap
 * the row leaves the query and is surfaced by `efactura:reconcile` — the same
 * "a person decides" path an indeterminate upload takes.
 *
 * The upload's own status is untouched: ANAF accepted the invoice, and only the
 * receipt is missing. Demoting it to Failed would be a worse lie than the loop.
 *
 * RE-ENTRANT: DDL auto-commits on MySQL and is unwrapped on SQLite, so an
 * interrupt can land between the two columns. Each is guarded on its own state.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('efactura_uploads', 'response_attempts')) {
            Schema::table('efactura_uploads', function (Blueprint $table) {
                // Tiny: the cap is single digits. Existing rows start at 0, which
                // is correct — none of them has failed a download yet.
                $table->unsignedTinyInteger('response_attempts')->default(0);
            });
        }

        if (!Schema::hasColumn('efactura_uploads', 'response_failed_at')) {
            Schema::table('efactura_uploads', function (Blueprint $table) {
                $table->timestamp('response_failed_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        // Guarded the same way: a rollback can be re-run after an interrupt.
        if (Schema::hasColumn('efactura_uploads', 'response_failed_at')) {
            Schema::table('efactura_uploads', function (Blueprint $table) {
                $table->dropColumn('response_failed_at');
            });
        }

        if (Schema::hasColumn('efactura_uploads', 'response_attempts')) {
            Schema::table('efactura_uploads', function (Blueprint $table) {
                $table->dropColumn('response_attempts');
            });
        }
    }
};
