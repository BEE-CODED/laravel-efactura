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
use BeeCoded\EFactura\Exceptions\DuplicateUploadException;
use BeeCoded\EFactura\Models\EfacturaToken;
use BeeCoded\EFactura\Models\EfacturaUpload;
use BeeCoded\EFactura\Services\UploadService;
use BeeCoded\EFactura\Tests\Fixtures\TestInvoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * queueUpload() used to create() unconditionally. Two calls for the same model — a
 * double-clicked button, an application-level retry — produced two Pending rows.
 * Each row passed the per-row atomic claim independently, so BOTH were uploaded and
 * the invoice was filed twice. The trait models the relationship as morphOne (1:1),
 * so the second row was not even reachable through it.
 */
beforeEach(function () {
    if (!Schema::hasTable('test_invoices')) {
        Schema::create('test_invoices', function ($table) {
            $table->id();
            $table->string('number');
            $table->string('cui');
            $table->decimal('total', 10, 2)->nullable();
        });
    }

    $this->token = EfacturaToken::create([
        'cui' => '12345678',
        'access_token' => 'test',
        'refresh_token' => 'test',
        'expires_at' => now()->addHour(),
        'is_active' => true,
    ]);

    $this->invoice = TestInvoice::create([
        'number' => 'INV-001',
        'cui' => '12345678',
        'total' => 100,
    ]);

    $this->uploadService = app(UploadService::class);
});

/**
 * Run $assertions against a faithfully simulated create/constraint race, then
 * clean up. POSTGRES-ONLY — skips on sqlite, with a real reason.
 *
 * A genuine race winner is a different request that has already COMMITTED on
 * another connection. That is what makes it survive queueFor()'s savepoint
 * rollback and be visible to the recovery re-read. Simulating it on our own
 * connection (via a plain `creating` listener) does not work once the savepoint
 * exists: the listener's insert lands inside the savepoint and is rolled back
 * with the failed one — and under RefreshDatabase every test already runs inside
 * a transaction, so the savepoint is always taken. So the winner must be
 * committed on a SECOND connection to the SAME database, which sqlite :memory:
 * cannot provide (a second connection there is a distinct, empty database).
 *
 * The winner is committed for real, outside RefreshDatabase's rolled-back
 * transaction, so it is deleted explicitly afterwards.
 */
function raceWinnerTest(object $test, Closure $assertions): void
{
    if (DB::connection()->getDriverName() === 'sqlite') {
        test()->markTestSkipped('Requires a driver that poisons transactions and shares a database across connections (Postgres). Runs on the CI Postgres job.');
    }

    $default = config('database.default');
    config(['database.connections.efactura_race_winner' => config("database.connections.{$default}")]);
    $winnerConnection = DB::connection('efactura_race_winner');

    $raced = false;
    EfacturaUpload::creating(function () use (&$raced, $test, $winnerConnection) {
        if ($raced) {
            return;
        }
        $raced = true;

        // Commits immediately on the second connection: durable, outside our
        // savepoint — exactly a concurrent request that beat us to the insert.
        $winnerConnection->table('efactura_uploads')->insert([
            'efactura_token_id' => $test->token->id,
            'uploadable_type' => TestInvoice::class,
            'uploadable_id' => $test->invoice->id,
            'status' => UploadStatus::Pending->value,
            'standard' => 'UBL',
            'is_extern' => false,
            'is_self_billed' => false,
            'is_b2c' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    try {
        $assertions();
    } finally {
        EfacturaUpload::flushEventListeners();
        // The winner was committed on its own connection, so RefreshDatabase's
        // rollback does not reach it. Remove it so tests stay isolated.
        $winnerConnection->table('efactura_uploads')
            ->where('uploadable_type', TestInvoice::class)
            ->where('uploadable_id', $test->invoice->id)
            ->delete();
    }
}

describe('queueUpload duplicate guard', function () {
    it('returns the same record instead of filing the invoice twice', function () {
        $first = $this->uploadService->queueUpload($this->invoice);
        $second = $this->uploadService->queueUpload($this->invoice);

        expect($second->id)->toBe($first->id);
        expect(EfacturaUpload::count())->toBe(1);
    });

    it('refuses to queue a second upload while one is in flight', function () {
        $first = $this->uploadService->queueUpload($this->invoice);
        $first->update(['status' => UploadStatus::Uploading]);

        $second = $this->uploadService->queueUpload($this->invoice);

        expect($second->id)->toBe($first->id);
        expect(EfacturaUpload::count())->toBe(1);
    });

    it('refuses to re-file an invoice ANAF already accepted', function () {
        $first = $this->uploadService->queueUpload($this->invoice);
        $first->update(['status' => UploadStatus::Completed]);

        $second = $this->uploadService->queueUpload($this->invoice);

        expect($second->id)->toBe($first->id);
        expect($second->status)->toBe(UploadStatus::Completed);
        expect(EfacturaUpload::count())->toBe(1);
    });

    it('guards B2C uploads too', function () {
        $first = $this->uploadService->queueB2CUpload($this->invoice);
        $second = $this->uploadService->queueB2CUpload($this->invoice);

        expect($second->id)->toBe($first->id);
        expect(EfacturaUpload::count())->toBe(1);
    });

    it('does not confuse two different models', function () {
        $other = TestInvoice::create(['number' => 'INV-002', 'cui' => '12345678', 'total' => 50]);

        $this->uploadService->queueUpload($this->invoice);
        $this->uploadService->queueUpload($other);

        expect(EfacturaUpload::count())->toBe(2);
    });
});

describe('the create/constraint race', function () {
    /**
     * The unique index closes the duplicate-ROW window, but queueFor() was still a
     * SELECT-then-create() with nothing catching the constraint violation. The LOSER of
     * the race got an unhandled QueryException — a 500 — even though the documented
     * contract is idempotent ("returns the existing record") and the docblock advertises
     * only DuplicateUploadException, so callers written to the contract do not catch it.
     * Inside a caller's transaction it also aborted that transaction.
     */
    it('returns the existing row when a concurrent request wins the insert', function () {
        raceWinnerTest($this, function () {
            $upload = $this->uploadService->queueUpload($this->invoice);

            expect($upload->exists)->toBeTrue()
                ->and($upload->uploadable_id)->toBe($this->invoice->id)
                ->and($upload->status)->toBe(UploadStatus::Pending);

            expect(EfacturaUpload::count())->toBe(1);
        });
    });

    /**
     * The service guard is a read-then-create check and cannot win a genuine race:
     * two concurrent requests can both find nothing and both insert. Only the
     * constraint closes that window.
     */
    it('rejects a second upload row for the same model outright', function () {
        $this->uploadService->queueUpload($this->invoice);

        expect(fn () => EfacturaUpload::create([
            'efactura_token_id' => $this->token->id,
            'uploadable_type' => TestInvoice::class,
            'uploadable_id' => $this->invoice->id,
            'status' => UploadStatus::Pending,
            'standard' => 'UBL',
        ]))->toThrow(Illuminate\Database\QueryException::class);

        expect(EfacturaUpload::count())->toBe(1);
    });
});

describe('queueing with different options', function () {
    /**
     * reuseExistingUpload() returned before $attributes were applied, so queueB2CUpload()
     * on a model with a Pending B2B row handed back the B2B row unchanged, with no signal
     * — and the invoice was then filed to ANAF in the WRONG MODE. Same for standard
     * (UBL vs CII), extern and self_billed.
     *
     * A Pending row has not been transmitted, so the new options are simply applied.
     */
    it('applies the new mode to an upload that has not been sent yet', function () {
        $first = $this->uploadService->queueUpload($this->invoice);

        expect($first->is_b2c)->toBeFalse();

        $second = $this->uploadService->queueB2CUpload($this->invoice);

        expect($second->id)->toBe($first->id)
            ->and($second->is_b2c)->toBeTrue();

        expect($first->fresh()->is_b2c)->toBeTrue();
        expect(EfacturaUpload::count())->toBe(1);
    });

    it('applies a changed standard to a pending upload', function () {
        $first = $this->uploadService->queueUpload($this->invoice);

        $second = $this->uploadService->queueUpload($this->invoice, ['standard' => 'CII', 'extern' => true]);

        expect($second->id)->toBe($first->id)
            ->and($second->standard)->toBe('CII')
            ->and($second->is_extern)->toBeTrue();
    });

    /**
     * Once the document is in flight or filed, its mode is a fact about what was SENT.
     * Silently rewriting it would make the row lie about the filing; silently ignoring
     * the request would file in the wrong mode. Refuse loudly instead.
     */
    it('refuses to change the mode of an upload already in flight', function () {
        $first = $this->uploadService->queueUpload($this->invoice);
        $first->update(['status' => UploadStatus::Uploading]);

        expect(fn () => $this->uploadService->queueB2CUpload($this->invoice))
            ->toThrow(DuplicateUploadException::class);

        expect($first->fresh()->is_b2c)->toBeFalse();
    });

    it('refuses to change the mode of an upload ANAF already accepted', function () {
        $first = $this->uploadService->queueUpload($this->invoice);
        $first->update(['status' => UploadStatus::Completed]);

        expect(fn () => $this->uploadService->queueUpload($this->invoice, ['standard' => 'CII']))
            ->toThrow(DuplicateUploadException::class);

        expect($first->fresh()->standard)->toBe('UBL');
    });

    /**
     * Re-queueing with the SAME options stays idempotent — that is the double-click case.
     */
    it('stays idempotent when the options are unchanged', function () {
        $first = $this->uploadService->queueUpload($this->invoice);
        $first->update(['status' => UploadStatus::Uploading]);

        $second = $this->uploadService->queueUpload($this->invoice);

        expect($second->id)->toBe($first->id)
            ->and($second->status)->toBe(UploadStatus::Uploading);
    });
});

describe('re-uploading after a failure', function () {
    it('reuses the existing row so the model keeps exactly one upload', function () {
        $first = $this->uploadService->queueUpload($this->invoice);
        $first->update([
            'status' => UploadStatus::Failed,
            'failure_reason' => FailureReason::Validation,
            'errors' => ['Bad XML'],
        ]);

        $second = $this->uploadService->queueUpload($this->invoice);

        expect($second->id)->toBe($first->id)
            ->and($second->status)->toBe(UploadStatus::Pending)
            ->and($second->failure_reason)->toBeNull()
            ->and($second->errors)->toBeNull();

        expect(EfacturaUpload::count())->toBe(1);
    });

    it('applies the new options when re-queueing', function () {
        $first = $this->uploadService->queueUpload($this->invoice);
        $first->update(['status' => UploadStatus::Failed, 'failure_reason' => FailureReason::Validation]);

        $second = $this->uploadService->queueUpload($this->invoice, ['standard' => 'CII', 'extern' => true]);

        expect($second->id)->toBe($first->id)
            ->and($second->standard)->toBe('CII')
            ->and($second->is_extern)->toBeTrue();
    });

    it('keeps the morphOne relation pointing at the live upload', function () {
        $first = $this->uploadService->queueUpload($this->invoice);
        $first->update(['status' => UploadStatus::Failed, 'failure_reason' => FailureReason::Validation]);

        $this->uploadService->queueUpload($this->invoice);

        expect($this->invoice->fresh()->efacturaUpload->status)->toBe(UploadStatus::Pending);
    });

    /**
     * The legal guard: an indeterminate upload may ALREADY be filed at ANAF. Silently
     * re-queueing it here would double-file it, bypassing the whole reconciliation
     * path. Refuse loudly and make the operator resolve it.
     */
    it('refuses to re-queue an upload whose delivery is indeterminate', function () {
        $first = $this->uploadService->queueUpload($this->invoice);
        $first->update([
            'status' => UploadStatus::Failed,
            'failure_reason' => FailureReason::Indeterminate,
        ]);

        expect(fn () => $this->uploadService->queueUpload($this->invoice))
            ->toThrow(DuplicateUploadException::class, 'indeterminate');

        expect(EfacturaUpload::count())->toBe(1);
        expect($first->fresh()->status)->toBe(UploadStatus::Failed);
    });
});

describe('the create/constraint race inside a caller transaction', function () {
    /**
     * When queueUpload runs inside a caller's OWN transaction, recovering from the
     * race must not break that transaction. On Postgres a failed statement poisons
     * the ENTIRE transaction — every later query, including queueFor()'s own
     * findExisting re-read AND anything the caller does afterwards, throws "current
     * transaction is aborted". queueFor() therefore wraps its insert in
     * withSavepointIfNeeded(): inside a transaction it opens a SAVEPOINT and rolls
     * back only to it, leaving the caller's transaction usable.
     *
     * WHY THE WINNER IS ON A SEPARATE CONNECTION: a savepoint rollback also undoes
     * any row inserted on the SAME connection after the savepoint. A real race
     * winner is a different request that has already COMMITTED on another
     * connection, so it survives our rollback — which is exactly what makes
     * findExisting able to see it. Simulating the winner on our own connection
     * (as the no-transaction race test does) would have it swept away by the
     * savepoint. That separate, committed connection is why this is Postgres-only:
     * on sqlite :memory: a second connection is a distinct database with no shared
     * table. The CI matrix runs this against a real Postgres, where the pre-fix
     * code aborts the caller transaction at the re-read.
     */
    it('leaves the caller transaction usable after recovering from the race', function () {
        raceWinnerTest($this, function () {
            // The caller owns the transaction; queueUpload runs inside it and, on
            // recovery, must not leave it poisoned.
            $result = DB::transaction(function () {
                $upload = $this->uploadService->queueUpload($this->invoice);

                // The proof: a write issued AFTER the recovered violation, still
                // inside the same transaction. On a poisoned Postgres transaction
                // this second statement throws "current transaction is aborted".
                $this->invoice->update(['number' => 'INV-POST-RACE']);

                return $upload;
            });

            expect($result->uploadable_id)->toBe($this->invoice->id)
                ->and(EfacturaUpload::count())->toBe(1)
                ->and($this->invoice->fresh()->number)->toBe('INV-POST-RACE');
        });
    });
});
