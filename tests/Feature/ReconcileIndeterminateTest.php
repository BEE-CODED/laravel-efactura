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
use BeeCoded\EFactura\Models\EfacturaToken;
use BeeCoded\EFactura\Models\EfacturaUpload;
use BeeCoded\EFactura\Services\UploadService;

/**
 * The human resolution path for indeterminate uploads.
 *
 * Automatic reconciliation against ANAF is deliberately NOT attempted: a stranded
 * upload never received an index_incarcare (that is only assigned on a successful
 * response), and ANAF's message listing exposes no invoice number to join on — only
 * id / cif / data_creare / tip / detalii / id_solicitare. Matching would mean
 * downloading and parsing every candidate ZIP in the window, burning ANAF quota to
 * guess at a legal question. So an operator decides, and these methods make their
 * decision safe to apply.
 */
beforeEach(function () {
    $this->token = EfacturaToken::create([
        'cui' => '12345678',
        'access_token' => 'test',
        'refresh_token' => 'test',
        'expires_at' => now()->addHour(),
        'is_active' => true,
    ]);

    $this->uploadService = app(UploadService::class);
});

function makeIndeterminateUpload(): EfacturaUpload
{
    return EfacturaUpload::create([
        'efactura_token_id' => test()->token->id,
        'uploadable_type' => 'App\\Models\\Invoice',
        'uploadable_id' => 1,
        'status' => UploadStatus::Failed,
        'failure_reason' => FailureReason::Indeterminate,
        'standard' => 'UBL',
        'errors' => ['Upload job died mid-flight'],
    ]);
}

describe('resolving an indeterminate upload as FILED', function () {
    it('hands it back to the normal status-check pipeline with the real ANAF index', function () {
        $upload = makeIndeterminateUpload();

        $resolved = $this->uploadService->resolveIndeterminateAsFiled($upload, '5001130255');

        expect($resolved)->toBeTrue();

        $upload->refresh();
        expect($upload->status)->toBe(UploadStatus::Processing)
            ->and($upload->upload_index)->toBe('5001130255')
            ->and($upload->failure_reason)->toBeNull()
            ->and($upload->uploaded_at)->not->toBeNull();
    });

    it('makes the upload visible to the status checker again', function () {
        $upload = makeIndeterminateUpload();
        $this->uploadService->resolveIndeterminateAsFiled($upload, '5001130255');

        expect(EfacturaUpload::needsStatusCheck()->pluck('id')->all())->toContain($upload->id);
    });

    it('refuses to touch an upload that is not indeterminate', function () {
        $upload = makeIndeterminateUpload();
        $upload->update(['failure_reason' => FailureReason::Validation]);

        expect($this->uploadService->resolveIndeterminateAsFiled($upload, '5001130255'))->toBeFalse();
        expect($upload->fresh()->status)->toBe(UploadStatus::Failed);
    });
});

describe('resolving an indeterminate upload as NOT FILED', function () {
    it('returns it to pending so the normal upload path re-drives it', function () {
        $upload = makeIndeterminateUpload();

        $resolved = $this->uploadService->resolveIndeterminateAsNotFiled($upload);

        expect($resolved)->toBeTrue();

        $upload->refresh();
        expect($upload->status)->toBe(UploadStatus::Pending)
            ->and($upload->failure_reason)->toBeNull()
            ->and($upload->errors)->toBeNull();
    });

    it('refuses to touch an upload that is not indeterminate', function () {
        $upload = makeIndeterminateUpload();
        $upload->update(['failure_reason' => FailureReason::RateLimited]);

        expect($this->uploadService->resolveIndeterminateAsNotFiled($upload))->toBeFalse();
        expect($upload->fresh()->status)->toBe(UploadStatus::Failed);
    });
});

describe('the efactura:reconcile command', function () {
    it('lists uploads awaiting reconciliation', function () {
        $upload = makeIndeterminateUpload();

        $this->artisan('efactura:reconcile')
            ->expectsOutputToContain((string) $upload->id)
            ->assertExitCode(0);
    });

    it('reports a clean state when nothing needs reconciling', function () {
        $this->artisan('efactura:reconcile')
            ->expectsOutputToContain('No uploads are awaiting reconciliation')
            ->assertExitCode(0);
    });

    it('resolves an upload as filed', function () {
        $upload = makeIndeterminateUpload();

        $this->artisan('efactura:reconcile', [
            '--filed' => (string) $upload->id,
            '--index' => '5001130255',
        ])->assertExitCode(0);

        expect($upload->fresh()->status)->toBe(UploadStatus::Processing)
            ->and($upload->fresh()->upload_index)->toBe('5001130255');
    });

    it('requires an ANAF index when resolving as filed', function () {
        $upload = makeIndeterminateUpload();

        $this->artisan('efactura:reconcile', ['--filed' => (string) $upload->id])
            ->assertExitCode(1);

        expect($upload->fresh()->status)->toBe(UploadStatus::Failed);
    });

    it('resolves an upload as not filed', function () {
        $upload = makeIndeterminateUpload();

        $this->artisan('efactura:reconcile', ['--not-filed' => (string) $upload->id])
            ->assertExitCode(0);

        expect($upload->fresh()->status)->toBe(UploadStatus::Pending);
    });

    it('fails loudly when the upload is not awaiting reconciliation', function () {
        $upload = makeIndeterminateUpload();
        $upload->update(['failure_reason' => FailureReason::Validation]);

        $this->artisan('efactura:reconcile', ['--not-filed' => (string) $upload->id])
            ->assertExitCode(1);
    });
});
