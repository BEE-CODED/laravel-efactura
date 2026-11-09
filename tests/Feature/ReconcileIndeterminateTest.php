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
use Illuminate\Support\Facades\Artisan;

/**
 * The human resolution path for indeterminate uploads.
 *
 * Automatic reconciliation against ANAF is deliberately NOT attempted: an upload
 * parked before ANAF answered has no index_incarcare, one parked by a status check
 * keeps an index that gave no verdict (so it can only be resolved as filed), and
 * ANAF's message listing exposes no invoice number to join on — only
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

    it('refuses an upload ANAF issued an upload index for, leaving it parked', function () {
        $upload = makeIndeterminateUpload();
        $upload->update(['upload_index' => '5001130255', 'uploaded_at' => now()]);

        expect(fn () => $this->uploadService->resolveIndeterminateAsNotFiled($upload))
            ->toThrow(InvalidArgumentException::class, 'cannot be resolved as not filed');

        $upload->refresh();
        expect($upload->status)->toBe(UploadStatus::Failed)
            ->and($upload->failure_reason)->toBe(FailureReason::Indeterminate)
            ->and($upload->upload_index)->toBe('5001130255');
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

    it('lists the upload index and upload time of an upload parked after ANAF indexed it', function () {
        $upload = makeIndeterminateUpload();
        $upload->update(['upload_index' => '5001130255', 'uploaded_at' => '2026-11-09 10:15:00']);

        expect(Artisan::call('efactura:reconcile'))->toBe(0)
            ->and(Artisan::output())->toContain('Upload index', 'Uploaded at', 'Parked at', '5001130255', '2026-11-09 10:15:00');
    });

    it('refuses --not-filed for an upload with an upload index and suggests --filed with it', function () {
        $upload = makeIndeterminateUpload();
        $upload->update(['upload_index' => '5001130255']);

        $this->artisan('efactura:reconcile', ['--not-filed' => (string) $upload->id])
            ->expectsOutputToContain('cannot be resolved as not filed')
            ->expectsOutputToContain("--filed={$upload->id} --index=5001130255")
            ->assertExitCode(1);

        expect($upload->fresh()->status)->toBe(UploadStatus::Failed)
            ->and($upload->fresh()->failure_reason)->toBe(FailureReason::Indeterminate);
    });

    it('fails loudly when the upload is not awaiting reconciliation', function () {
        $upload = makeIndeterminateUpload();
        $upload->update(['failure_reason' => FailureReason::Validation]);

        $this->artisan('efactura:reconcile', ['--not-filed' => (string) $upload->id])
            ->assertExitCode(1);
    });
});

/**
 * Topic L4: the index an operator supplies is an ANAF upload index, or nothing is changed.
 */
describe('the ANAF index given when resolving as filed', function () {
    it('is refused unless it is 1 to 64 digits, leaving the upload parked', function (string $index) {
        $upload = makeIndeterminateUpload();

        expect(fn () => $this->uploadService->resolveIndeterminateAsFiled($upload, $index))
            ->toThrow(InvalidArgumentException::class);

        $upload->refresh();
        expect($upload->status)->toBe(UploadStatus::Failed)
            ->and($upload->failure_reason)->toBe(FailureReason::Indeterminate)
            ->and($upload->upload_index)->toBeNull();
    })->with([
        'letters' => 'abc',
        'a decimal' => '1.5',
        '65 digits' => str_repeat('1', 65),
        'empty' => '',
    ]);

    it('makes the command fail without changing the upload', function (string $index) {
        $upload = makeIndeterminateUpload();

        $this->artisan('efactura:reconcile', ['--filed' => (string) $upload->id, '--index' => $index])
            ->expectsOutputToContain('1 to 64 digits')
            ->assertExitCode(1);

        expect($upload->fresh()->failure_reason)->toBe(FailureReason::Indeterminate)
            ->and($upload->fresh()->upload_index)->toBeNull();
    })->with([
        'letters' => 'abc',
        'a decimal' => '1.5',
        '65 digits' => str_repeat('1', 65),
        'whitespace only' => '   ',
    ]);

    it('is trimmed by the service before it is checked and stored', function () {
        $upload = makeIndeterminateUpload();

        expect($this->uploadService->resolveIndeterminateAsFiled($upload, " 12345\n"))->toBeTrue()
            ->and($upload->fresh()->upload_index)->toBe('12345');
    });

    it('is trimmed by the command before it is checked and stored', function () {
        $upload = makeIndeterminateUpload();

        $this->artisan('efactura:reconcile', ['--filed' => (string) $upload->id, '--index' => "12345\n"])
            ->assertExitCode(0);

        expect($upload->fresh()->status)->toBe(UploadStatus::Processing)
            ->and($upload->fresh()->upload_index)->toBe('12345');
    });
});
