<?php

declare(strict_types=1);

use Carbon\CarbonInterface;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Guards the two properties of the v3 migration set that are not about what any
 * single migration does, but about how the set behaves when a run goes wrong:
 *
 *  - ORDER: the migration that restores service (token encryption) must run
 *    before any migration that can abort, or an abort strands it.
 *  - RE-ENTRANCY: a migration that does DDL and then a backfill must survive
 *    being interrupted between the two, because the DDL is already committed
 *    but the migration is not yet recorded as run.
 */

/**
 * Migration filenames in the order Laravel will actually run them.
 *
 * Laravel sorts by filename, so this is the real execution order — not an
 * approximation of it.
 *
 * @return list<string>
 */
function orderedMigrationFilenames(): array
{
    $names = array_map('basename', glob(__DIR__.'/../../database/migrations/*.php') ?: []);

    sort($names);

    return array_values($names);
}

/**
 * Position of the single migration whose filename contains $needle.
 *
 * Fails loudly on 0 or 2+ matches: a silent 0 would make every ordering
 * assertion below vacuous.
 */
function migrationPositionOf(string $needle): int
{
    $matches = array_keys(array_filter(
        orderedMigrationFilenames(),
        fn (string $name): bool => str_contains($name, $needle),
    ));

    expect($matches)->toHaveCount(1, "Expected exactly one migration matching '{$needle}'");

    return $matches[0];
}

/**
 * Load the migration whose filename contains $needle.
 *
 * Deliberately resolved by glob rather than by number: these tests exist because
 * the numbering is load-bearing, so they must not be the thing that has to be
 * hand-edited when it changes.
 */
function loadMigrationMatching(string $needle): object
{
    $paths = array_values(array_filter(
        glob(__DIR__.'/../../database/migrations/*.php') ?: [],
        fn (string $path): bool => str_contains(basename($path), $needle),
    ));

    expect($paths)->toHaveCount(1, "Expected exactly one migration matching '{$needle}'");

    return require $paths[0];
}

describe('v3 migration ordering', function () {
    it('encrypts tokens before running any migration that can abort', function () {
        // The unique-index migration is designed to ABORT on pre-3.0 duplicates.
        // The encryption migration is what RESTORES service for v3 code that is
        // already deployed and already expects encrypted tokens. They touch
        // different tables and have no interdependency, so the order is ours to
        // choose — and the only safe choice puts the restore first. Otherwise a
        // duplicate (or a lock timeout, or any DDL error) aborts the run and
        // strands encryption, leaving every company throwing DecryptException.
        expect(migrationPositionOf('encrypt_efactura_token_credentials'))
            ->toBeLessThan(migrationPositionOf('add_unique_uploadable_index'));
    });

    it('encrypts tokens before the failure_reason backfill', function () {
        // Same reasoning: the failure_reason migration runs an unbounded backfill
        // that can die on a big table. Nothing that can fail should precede the
        // migration that restores service.
        expect(migrationPositionOf('encrypt_efactura_token_credentials'))
            ->toBeLessThan(migrationPositionOf('add_failure_reason'));
    });
});

describe('failure_reason migration re-entrancy', function () {
    it('does not throw when up() runs a second time', function () {
        // The real scenario: Schema::table() is an ALTER, which auto-commits on
        // MySQL and is not wrapped in a transaction on SQLite either. The backfill
        // that follows is unbounded. If it dies partway, the column exists but the
        // migration was never recorded — so `php artisan migrate` re-runs it. A
        // non-guarded up() then dies on "Duplicate column name 'failure_reason'",
        // and the only exit is manual DB surgery.
        //
        // RefreshDatabase has already run this migration, so the column is present
        // and this call IS the second run.
        expect(Schema::hasColumn('efactura_uploads', 'failure_reason'))->toBeTrue();

        loadMigrationMatching('add_failure_reason')->up();

        // Reaching here at all is the point — a re-run must not throw. The schema
        // must also still be intact rather than merely un-thrown.
        expect(Schema::hasColumn('efactura_uploads', 'failure_reason'))->toBeTrue()
            ->and(Schema::hasIndex('efactura_uploads', ['failure_reason']))->toBeTrue();
    });

    it('adds the index on a re-run when the interrupt landed between column and index', function () {
        // The column and the index are separate statements, so an interrupt can
        // leave the column committed and the index missing. Guarding both behind a
        // single hasColumn() check would skip the index forever, silently costing
        // RetryRateLimitedUploads its index. Simulate that exact half-state.
        Schema::table('efactura_uploads', function (Blueprint $table) {
            $table->dropIndex(['failure_reason']);
        });

        expect(Schema::hasColumn('efactura_uploads', 'failure_reason'))->toBeTrue()
            ->and(Schema::hasIndex('efactura_uploads', ['failure_reason']))->toBeFalse();

        loadMigrationMatching('add_failure_reason')->up();

        expect(Schema::hasIndex('efactura_uploads', ['failure_reason']))->toBeTrue();
    });

    it('resumes the backfill on a re-run', function () {
        // Proves the guard skips only the DDL, not the work: a row left unclassified
        // by an interrupted backfill must still get classified by the re-run.
        $tokenId = DB::table('efactura_tokens')->insertGetId([
            'cui' => '12345678',
            'access_token' => 'irrelevant',
            'refresh_token' => 'irrelevant',
            'expires_at' => now()->addHour(),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('efactura_uploads')->insert([
            'efactura_token_id' => $tokenId,
            'uploadable_type' => 'App\\Models\\Invoice',
            'uploadable_id' => 1,
            'status' => 'failed',
            'errors' => json_encode(['RATE_LIMIT_EXCEEDED: too many calls']),
            'failure_reason' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        loadMigrationMatching('add_failure_reason')->up();

        expect(DB::table('efactura_uploads')->where('uploadable_id', 1)->value('failure_reason'))
            ->toBe('rate_limited');
    });
});

describe('v4 reclassification of 3.x rejections that kept an index', function () {
    /**
     * 3.x recorded an upload ANAF had given an index (and a nok answer without a download
     * id) as a validation rejection. queueUpload() recycles validation rows, so re-queueing
     * one after upgrading filed the invoice a second time.
     */
    it('parks an indexed validation failure without a download id for reconciliation', function () {
        $tokenId = DB::table('efactura_tokens')->insertGetId([
            'cui' => '12345678',
            'access_token' => 'irrelevant',
            'refresh_token' => 'irrelevant',
            'expires_at' => now()->addHour(),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row = fn (int $id, ?string $index, ?string $downloadId) => [
            'efactura_token_id' => $tokenId,
            'uploadable_type' => 'App\\Models\\Invoice',
            'uploadable_id' => $id,
            'status' => 'failed',
            'failure_reason' => 'validation',
            'upload_index' => $index,
            'download_id' => $downloadId,
            'created_at' => now(),
            'updated_at' => now(),
        ];
        DB::table('efactura_uploads')->insert([
            $row(1, '5001130255', null),
            // ANAF's own rejection, with its error report: stays a rejection.
            $row(2, '5001130256', '3001130059'),
            // Never taken in: stays a rejection.
            $row(3, null, null),
            // A pre-3.0 failure 000005 left unclassified, with an index: parked too.
            [...$row(4, '5001130257', null), 'failure_reason' => null],
            // The same without an index: left as it is.
            [...$row(5, null, null), 'failure_reason' => null],
        ]);

        loadMigrationMatching('add_failure_counters')->up();

        expect(DB::table('efactura_uploads')->orderBy('uploadable_id')->pluck('failure_reason')->all())
            ->toBe(['indeterminate', 'validation', 'validation', 'indeterminate', null]);
    });
});

describe('v4 per-token message key', function () {
    it('survives a re-run, including one interrupted between its two index changes', function () {
        $migration = loadMigrationMatching('key_efactura_messages_per_token');

        $migration->up();

        // MySQL commits each ALTER on its own: the new index exists, the old one is back.
        Schema::table('efactura_messages', fn (Blueprint $table) => $table->unique('message_id'));
        $migration->up();

        expect(Schema::hasIndex('efactura_messages', ['efactura_token_id', 'message_id'], 'unique'))->toBeTrue()
            ->and(Schema::hasIndex('efactura_messages', ['message_id'], 'unique'))->toBeFalse();
    });
});

describe('v4 failure counters', function () {
    it('drops next_download_at on rollback and adds it once on a re-run', function () {
        $migration = loadMigrationMatching('add_failure_counters');

        $migration->down();
        expect(Schema::hasColumn('efactura_messages', 'next_download_at'))->toBeFalse();

        $migration->up();
        $migration->up();
        expect(Schema::hasColumn('efactura_messages', 'next_download_at'))->toBeTrue();
    });

    /**
     * 4.0 never leaves a rate-limited or transient upload Failed: it waits in Pending. A 3.x
     * row in that state was provably not sent; a recent one is retried, an older one is not
     * filed weeks late.
     */
    it('returns recent unsent 3.x failures to Pending and abandons older ones', function () {
        $tokenId = DB::table('efactura_tokens')->insertGetId([
            'cui' => '12345678',
            'access_token' => 'irrelevant',
            'refresh_token' => 'irrelevant',
            'expires_at' => now()->addHour(),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $row = fn (int $id, string $status, string $reason, ?CarbonInterface $processedAt) => [
            'efactura_token_id' => $tokenId,
            'uploadable_type' => 'App\\Models\\Invoice',
            'uploadable_id' => $id,
            'status' => $status,
            'failure_reason' => $reason,
            'processed_at' => $processedAt,
            'status_failures' => 3,
            'created_at' => now(),
            'updated_at' => now(),
        ];
        DB::table('efactura_uploads')->insert([
            $row(1, 'failed', 'rate_limited', now()->subDay()),
            $row(2, 'failed', 'transient', now()->subDays(6)),
            $row(3, 'failed', 'rate_limited', now()->subDays(8)),
            $row(4, 'failed', 'transient', null),
            $row(5, 'failed', 'validation', now()),
            $row(6, 'pending', 'rate_limited', null),
        ]);

        $migration = loadMigrationMatching('add_failure_counters');
        $migration->up();
        $migration->up();

        expect(DB::table('efactura_uploads')->orderBy('uploadable_id')->get(['status', 'failure_reason', 'status_failures'])->map(fn ($r) => [$r->status, $r->failure_reason, (int) $r->status_failures])->all())
            ->toBe([
                ['pending', 'rate_limited', 0],
                ['pending', 'transient', 0],
                ['failed', 'abandoned', 3],
                ['failed', 'abandoned', 3],
                ['failed', 'validation', 3],
                ['pending', 'rate_limited', 3],
            ])
            ->and(DB::table('efactura_uploads')->whereIn('uploadable_id', [1, 2])->whereNotNull('processed_at')->count())->toBe(0);
    });
});

describe('v4 redundant upload indexes', function () {
    it('drops the plain indexes the unique and (status, next_poll_at) indexes cover, also on a re-run, and restores them on rollback', function () {
        $plain = fn (array $columns) => collect(Schema::getIndexes('efactura_uploads'))
            ->contains(fn (array $index) => $index['columns'] === $columns && !$index['unique'] && !$index['primary']);
        $migration = loadMigrationMatching('add_response_verification_and_next_poll');

        expect($plain(['uploadable_type', 'uploadable_id']))->toBeFalse()
            ->and($plain(['status']))->toBeFalse()
            ->and(Schema::hasIndex('efactura_uploads', 'efactura_uploads_uploadable_unique'))->toBeTrue()
            ->and(Schema::hasIndex('efactura_uploads', ['status', 'next_poll_at']))->toBeTrue()
            ->and(Schema::hasIndex('efactura_uploads', ['status', 'efactura_token_id', 'processed_at']))->toBeTrue();

        // An interrupt before the drop committed.
        Schema::table('efactura_uploads', fn (Blueprint $table) => $table->index('status'));
        $migration->up();
        expect($plain(['status']))->toBeFalse();

        $migration->down();
        expect($plain(['status']))->toBeTrue()
            ->and($plain(['uploadable_type', 'uploadable_id']))->toBeTrue()
            ->and(Schema::hasIndex('efactura_uploads', ['status', 'efactura_token_id', 'processed_at']))->toBeFalse();

        $migration->up();
        expect(Schema::hasIndex('efactura_uploads', ['status', 'efactura_token_id', 'processed_at']))->toBeTrue();
    });

    // 000006's abort message sends operators to run the later migrations with --path while
    // duplicates remain: the morph columns must not be left without any index then.
    it('keeps the plain (uploadable_type, uploadable_id) index while the 000006 unique index is missing', function () {
        $plain = fn (array $columns) => collect(Schema::getIndexes('efactura_uploads'))
            ->contains(fn (array $index) => $index['columns'] === $columns && !$index['unique'] && !$index['primary']);
        $migration = loadMigrationMatching('add_response_verification_and_next_poll');
        $migration->down();
        loadMigrationMatching('add_unique_uploadable_index')->down();
        expect(Schema::hasIndex('efactura_uploads', ['uploadable_type', 'uploadable_id'], 'unique'))->toBeFalse();

        $migration->up();

        expect($plain(['uploadable_type', 'uploadable_id']))->toBeTrue()
            ->and($plain(['status']))->toBeFalse();

        // The teardown rollback runs 000006's down().
        loadMigrationMatching('add_unique_uploadable_index')->up();
    });
});
