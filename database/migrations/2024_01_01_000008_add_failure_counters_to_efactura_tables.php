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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * v4.0: count permanent failures so no scheduled job retries a broken row forever.
 *
 * `efactura_uploads.status_failures`: status polls that failed permanently (a 4xx or
 * unreadable 2xx answer, an index the SDK refuses). Past
 * `efactura.polling.max_status_failures` the upload is parked as indeterminate for
 * reconciliation; a successful read resets it. Transient failures (5xx, transport,
 * rate limits) are never counted.
 *
 * `efactura_messages.download_attempts`: the same for a received invoice whose
 * download fails permanently. Past the cap it leaves the download query.
 *
 * Existing rows start at 0, which is correct: none has been counted yet.
 *
 * DATA: a 3.x `failed` / `validation` row that has an upload index but no download id is
 * moved to `indeterminate`. A pre-3.0 `failed` row that migration 000005 left without a
 * `failure_reason` is moved on the same condition: queueUpload() recycles it too. 3.x wrote
 * that shape only for a `nok` status without a download id (an unknown index or "Nu aveti dreptul" answer, no verdict on the document); it never
 * stored the index on a failed upload answer, so 3.x upload-answer failures have no index
 * and are not moved. queueUpload() recycles `validation` rows, so re-queueing a moved row
 * would file it twice. 4.0 writes the shape only for "XML cu erori nepreluat de sistem"
 * (ANAF did not take the file in: final, no download id). A rejection with ANAF's error
 * report (a download id) stays `validation`. Idempotent before 4.0 writes rows; re-running
 * it after a rollback (down() does not revert the data) also moves 4.0's own "nepreluat"
 * rows to `indeterminate`, the safe direction (never re-sent automatically).
 *
 * Run it before queue workers start the 4.0 code: 4.0 writes `status_failures` right after
 * ANAF accepts an upload.
 *
 * RE-ENTRANT: DDL auto-commits on MySQL and is unwrapped on SQLite, so an interrupt
 * can land between the two columns. Each is guarded on its own state.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('efactura_uploads', 'status_failures')) {
            Schema::table('efactura_uploads', function (Blueprint $table) {
                $table->unsignedSmallInteger('status_failures')->default(0);
            });
        }

        if (!Schema::hasColumn('efactura_messages', 'download_attempts')) {
            Schema::table('efactura_messages', function (Blueprint $table) {
                $table->unsignedTinyInteger('download_attempts')->default(0);
            });
        }

        DB::table('efactura_uploads')
            ->where('status', 'failed')
            ->where(fn ($query) => $query->where('failure_reason', 'validation')->orWhereNull('failure_reason'))
            ->whereNotNull('upload_index')
            ->whereNull('download_id')
            ->update(['failure_reason' => 'indeterminate']);
    }

    public function down(): void
    {
        if (Schema::hasColumn('efactura_messages', 'download_attempts')) {
            Schema::table('efactura_messages', function (Blueprint $table) {
                $table->dropColumn('download_attempts');
            });
        }

        if (Schema::hasColumn('efactura_uploads', 'status_failures')) {
            Schema::table('efactura_uploads', function (Blueprint $table) {
                $table->dropColumn('status_failures');
            });
        }
    }
};
