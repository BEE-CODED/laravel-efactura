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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Enforces one e-Factura upload per uploadable model, at the database.
 *
 * UploadService::queueFor() already refuses to create a second row, but a read-then-
 * create check cannot win a genuine race: two concurrent requests for the same
 * invoice can both find nothing and both insert. Each row then passes the per-row
 * atomic claim independently and the invoice is filed at ANAF twice. Only a
 * constraint closes that window.
 *
 * A plain (non-partial) unique index is sufficient because the service now recycles
 * the existing row on re-upload instead of inserting another one — so a model never
 * legitimately has more than one upload. That also keeps this portable: MySQL has no
 * partial indexes.
 *
 * UPGRADE NOTE: this migration ABORTS if the table already contains duplicates left
 * by a pre-3.0 release. That is deliberate. Duplicates here mean an invoice may have
 * been filed at ANAF more than once, and silently dropping rows — or silently
 * skipping the constraint — is not a decision a migration gets to make about legal
 * records. Resolve them, then re-run. See the v3 upgrade notes.
 *
 * RUNS LAST, DELIBERATELY. This is the only v3 migration that is EXPECTED to abort,
 * so it is numbered behind the ones that must not be stranded by an abort — above
 * all the token-encryption migration, which is what restores service for the v3 code
 * that is already deployed. An abort here now costs only this index: the tokens are
 * encrypted and e-Factura keeps working. See MigrationOrderTest.
 *
 * COST: on a large efactura_uploads this is not free. Building a unique index takes
 * a brief exclusive metadata lock and can run for minutes on millions of rows, and
 * it can fail outright on innodb_lock_wait_timeout under concurrent write load. On a
 * big table, consider pt-online-schema-change or gh-ost instead of running it inline.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->guardAgainstExistingDuplicates();

        Schema::table('efactura_uploads', function (Blueprint $table) {
            $table->unique(['uploadable_type', 'uploadable_id'], 'efactura_uploads_uploadable_unique');
        });
    }

    public function down(): void
    {
        Schema::table('efactura_uploads', function (Blueprint $table) {
            $table->dropUnique('efactura_uploads_uploadable_unique');
        });
    }

    /**
     * Fail before touching the schema, so an abort leaves THIS TABLE'S SCHEMA
     * untouched rather than half-migrated.
     *
     * Scope note: that is a statement about this migration only, not about the
     * database. The migrations ordered before this one have already committed and
     * been recorded by the time this runs, and an abort here does not undo them —
     * nor should it, since one of them is what keeps e-Factura working. Recovering
     * from an abort means resolving the duplicates and re-running `migrate`; it does
     * not mean the run "did nothing".
     */
    private function guardAgainstExistingDuplicates(): void
    {
        $duplicates = DB::table('efactura_uploads')
            ->select('uploadable_type', 'uploadable_id')
            ->groupBy('uploadable_type', 'uploadable_id')
            ->havingRaw('COUNT(*) > 1')
            ->limit(20)
            ->get();

        if ($duplicates->isEmpty()) {
            return;
        }

        $examples = $duplicates
            ->map(fn ($row) => "  - {$row->uploadable_type} #{$row->uploadable_id}")
            ->implode(PHP_EOL);

        throw new RuntimeException(
            'Cannot add the unique (uploadable_type, uploadable_id) index: efactura_uploads '
            .'already contains duplicate uploads for the same model.'.PHP_EOL.PHP_EOL
            .'These were created by a pre-3.0 queueUpload(), which had no duplicate guard. '
            .'Each duplicate may mean an invoice was filed at ANAF more than once, so this '
            .'migration will not resolve them for you.'.PHP_EOL.PHP_EOL
            .'Affected models (first 20):'.PHP_EOL.$examples.PHP_EOL.PHP_EOL
            .'Review each against ANAF, keep the row reflecting the real filing, remove the '
            .'rest, then re-run `php artisan migrate`.'.PHP_EOL.PHP_EOL
            .'This abort is safe to take your time over: it is the LAST v3 migration, so the '
            .'ones before it — including token encryption — have already run. e-Factura keeps '
            .'working. Only this index is missing, which means the pre-3.0 duplicate race stays '
            .'open until you finish.'
        );
    }
};
