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
 * An index on (status, efactura_token_id, processed_at) for the response-download reads. Status
 * leads: one led by the token column could stand in for the foreign key's own index on
 * MySQL/MariaDB, which would then refuse to drop it. Named: the generated name overruns the
 * 64-character limit with a table prefix.
 *
 * Drops two indexes others cover: the plain (uploadable_type, uploadable_id) index from
 * morphs(), covered by the 000006 unique index (only when that index exists), and the plain
 * `status` index, covered by (status, next_poll_at).
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

        if (!Schema::hasIndex('efactura_uploads', ['status', 'efactura_token_id', 'processed_at'])) {
            Schema::table('efactura_uploads', function (Blueprint $table) {
                $table->index(['status', 'efactura_token_id', 'processed_at'], 'efactura_uploads_response_due_index');
            });
        }

        // The morphs() index goes only once a unique index covers it: run with --path before 000006
        // (its abort message's route), it stays, and is left in place when 000006 runs later.
        $drop = [['status']];
        if (Schema::hasIndex('efactura_uploads', ['uploadable_type', 'uploadable_id'], 'unique')) {
            $drop[] = ['uploadable_type', 'uploadable_id'];
        }

        foreach ($drop as $columns) {
            if ($name = $this->plainIndex($columns)) {
                Schema::table('efactura_uploads', fn (Blueprint $table) => $table->dropIndex($name));
            }
        }
    }

    public function down(): void
    {
        foreach ([['uploadable_type', 'uploadable_id'], ['status']] as $columns) {
            if (!$this->plainIndex($columns)) {
                Schema::table('efactura_uploads', fn (Blueprint $table) => $table->index($columns));
            }
        }

        if (Schema::hasIndex('efactura_uploads', ['status', 'next_poll_at'])) {
            Schema::table('efactura_uploads', function (Blueprint $table) {
                $table->dropIndex(['status', 'next_poll_at']);
            });
        }

        if (Schema::hasIndex('efactura_uploads', 'efactura_uploads_response_due_index')) {
            Schema::table('efactura_uploads', function (Blueprint $table) {
                $table->dropIndex('efactura_uploads_response_due_index');
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

    /**
     * The name of the index on exactly these columns that is neither unique nor primary, so
     * the 000006 unique index never matches, whatever the prefix or name.
     *
     * @param  list<string>  $columns
     */
    private function plainIndex(array $columns): ?string
    {
        foreach (Schema::getIndexes('efactura_uploads') as $index) {
            if ($index['columns'] === $columns && !$index['unique'] && !$index['primary']) {
                return $index['name'];
            }
        }

        return null;
    }
};
