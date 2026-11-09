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
