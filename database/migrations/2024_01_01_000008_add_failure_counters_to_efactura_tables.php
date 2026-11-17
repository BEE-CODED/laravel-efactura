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
 * v4.0: the failure counters and the received-invoice schedule.
 *
 * `efactura_uploads.status_failures`: one count per phase. While the upload is Pending it
 * counts send attempts that failed before ANAF answered; while it is Processing it counts
 * status reads ANAF refused (a 4xx or unreadable 2xx answer, an index the SDK refuses),
 * up to `DownloadService::MAX_STATUS_FAILURES`, after which the upload is parked as
 * indeterminate for reconciliation. A status answer resets it.
 *
 * `efactura_messages.download_attempts`: download tries made for a received invoice, with
 * no cap. `efactura_messages.next_download_at`: when the next try is due (null = due now);
 * the tries back off from an hour to a day, for the 60 days ANAF keeps the archive.
 *
 * Existing rows start at 0 and due, which is correct: none has been counted yet.
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
 * DATA: 4.0 never leaves a `rate_limited` or `transient` upload Failed; it waits in Pending
 * for its next try. A 3.x Failed row with either reason was provably not sent. One processed
 * within the last 7 days goes back to Pending (reason kept, count 0) and is sent again;
 * an older one becomes `abandoned`, so an invoice is not filed weeks late. Idempotent: 4.0
 * never writes those Failed rows.
 *
 * Stop 3.x queue workers before running this migration, and start the 4.0 workers after
 * it: a 3.x worker can still write the Failed rows the conversion above removes.
 *
 * RE-ENTRANT: DDL auto-commits on MySQL and is unwrapped on SQLite, so an interrupt
 * can land between the columns. Each is guarded on its own state.
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
                $table->unsignedSmallInteger('download_attempts')->default(0);
            });
        }

        if (!Schema::hasColumn('efactura_messages', 'next_download_at')) {
            Schema::table('efactura_messages', function (Blueprint $table) {
                $table->timestamp('next_download_at')->nullable();
            });
        }

        DB::table('efactura_uploads')
            ->where('status', 'failed')
            ->where(fn ($query) => $query->where('failure_reason', 'validation')->orWhereNull('failure_reason'))
            ->whereNotNull('upload_index')
            ->whereNull('download_id')
            ->update(['failure_reason' => 'indeterminate']);

        // Recent first: the next statement abandons whatever this one left Failed.
        DB::table('efactura_uploads')
            ->where('status', 'failed')
            ->whereIn('failure_reason', ['rate_limited', 'transient'])
            ->where('processed_at', '>=', now()->subDays(7))
            ->update(['status' => 'pending', 'status_failures' => 0, 'processed_at' => null]);

        DB::table('efactura_uploads')
            ->where('status', 'failed')
            ->whereIn('failure_reason', ['rate_limited', 'transient'])
            ->update(['failure_reason' => 'abandoned']);
    }

    public function down(): void
    {
        foreach (['next_download_at', 'download_attempts'] as $column) {
            if (Schema::hasColumn('efactura_messages', $column)) {
                Schema::table('efactura_messages', function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }

        if (Schema::hasColumn('efactura_uploads', 'status_failures')) {
            Schema::table('efactura_uploads', function (Blueprint $table) {
                $table->dropColumn('status_failures');
            });
        }
    }
};
