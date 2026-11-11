<?php

/*
 * This file is part of the Laravel e-Factura package.
 *
 * (c) BEE-CODED <dev.ttl@beecoded.ro>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * efactura_uploads.response_verification: how the stored response archive was checked
 * (verified / limits_only / mismatch; null for a response stored before 4.0).
 *
 * efactura_uploads.next_poll_at: when the next status read is due (null = due now), so a
 * status run reads each upload on the SDK's PollSchedule instead of on every run.
 *
 * RE-ENTRANT like 000007: DDL auto-commits on MySQL and is unwrapped on SQLite, so each
 * change is guarded on its own state.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('efactura_uploads', 'response_verification')) {
            Schema::table('efactura_uploads', function (Blueprint $table) {
                $table->string('response_verification')->nullable();
            });
        }

        if (!Schema::hasColumn('efactura_uploads', 'next_poll_at')) {
            Schema::table('efactura_uploads', function (Blueprint $table) {
                $table->timestamp('next_poll_at')->nullable();
            });
        }

        if (!Schema::hasIndex('efactura_uploads', ['status', 'next_poll_at'])) {
            Schema::table('efactura_uploads', function (Blueprint $table) {
                $table->index(['status', 'next_poll_at']);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex('efactura_uploads', ['status', 'next_poll_at'])) {
            Schema::table('efactura_uploads', function (Blueprint $table) {
                $table->dropIndex(['status', 'next_poll_at']);
            });
        }

        foreach (['next_poll_at', 'response_verification'] as $column) {
            if (Schema::hasColumn('efactura_uploads', $column)) {
                Schema::table('efactura_uploads', function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
    }
};
