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

it('clears the parked outcome\'s response and attempt counters on either resolution', function (Closure $resolve) {
    $upload = makeIndeterminateUpload();
    $upload->update([
        'download_id' => '3001234567',
        'response_path' => 'efactura/responses/old.zip',
        'response_attempts' => 3,
        'response_failed_at' => now(),
        'status_failures' => 2,
    ]);

    expect($resolve($this->uploadService, $upload))->toBeTrue();

    $upload->refresh();
    expect($upload->download_id)->toBeNull()
        ->and($upload->response_path)->toBeNull()
        ->and($upload->response_attempts)->toBe(0)
        ->and($upload->response_failed_at)->toBeNull()
        ->and($upload->status_failures)->toBe(0);
})->with([
    'filed' => [fn (UploadService $service, EfacturaUpload $upload) => $service->resolveIndeterminateAsFiled($upload, '5001130255')],
    'not filed' => [fn (UploadService $service, EfacturaUpload $upload) => $service->resolveIndeterminateAsNotFiled($upload)],
]);

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
            ->expectsOutputToContain("--not-filed={$upload->id} --despite-index")
            ->assertExitCode(1);

        expect($upload->fresh()->status)->toBe(UploadStatus::Failed)
            ->and($upload->fresh()->failure_reason)->toBe(FailureReason::Indeterminate);
    });

    /**
     * PostgreSQL refuses a non-numeric bigint and MySQL cuts it to its leading digits, and a
     * bare or '0' option used to fall through to the listing with exit 0.
     */
    it('says an option that names no upload is not found, leaving the upload parked', function (Closure $options) {
        $upload = makeIndeterminateUpload();

        $this->artisan('efactura:reconcile', $options($upload))
            ->expectsOutputToContain('not found')
            ->assertExitCode(1);

        expect($upload->fresh()->status)->toBe(UploadStatus::Failed)
            ->and($upload->fresh()->failure_reason)->toBe(FailureReason::Indeterminate);
    })->with([
        'a decimal --not-filed' => fn (EfacturaUpload $upload) => ['--not-filed' => "{$upload->id}.0"],
        'a bare --not-filed' => fn () => ['--not-filed' => null],
        'a zero --filed' => fn () => ['--filed' => '0', '--index' => '1'],
    ]);

    it('leaves an upload parked with another ANAF index than the one given without a confirmation', function () {
        $upload = makeIndeterminateUpload();
        $upload->update(['upload_index' => '5001130255']);

        $this->artisan('efactura:reconcile', ['--filed' => (string) $upload->id, '--index' => '5001130299', '--no-interaction' => true])
            ->expectsOutputToContain("Upload {$upload->id} was left as it is.")
            ->assertExitCode(1);

        expect($upload->fresh()->upload_index)->toBe('5001130255')
            ->and($upload->fresh()->failure_reason)->toBe(FailureReason::Indeterminate);
    });

    it('resolves an upload as filed under another index once the operator confirms it', function () {
        $upload = makeIndeterminateUpload();
        $upload->update(['upload_index' => '5001130255']);

        $this->artisan('efactura:reconcile', ['--filed' => (string) $upload->id, '--index' => '5001130299'])
            ->expectsConfirmation("Upload {$upload->id} has ANAF upload index 5001130255, not 5001130299. Did you find this invoice in SPV under 5001130299?", 'yes')
            ->assertExitCode(0);

        expect($upload->fresh()->upload_index)->toBe('5001130299')
            ->and($upload->fresh()->status)->toBe(UploadStatus::Processing);
    });

    it('fails loudly when the upload is not awaiting reconciliation', function () {
        $upload = makeIndeterminateUpload();
        $upload->update(['failure_reason' => FailureReason::Validation]);

        $this->artisan('efactura:reconcile', ['--not-filed' => (string) $upload->id])
            ->assertExitCode(1);
    });
});

/**
 * An indexed row ANAF keeps answering with an error had no exit: --not-filed was refused
 * and --filed parked it again on the next status check. The operator who checked SPV
 * under the index and did not find the invoice can now say so, explicitly.
 */
describe('resolving an indexed upload as not filed despite its index', function () {
    $question = fn (EfacturaUpload $upload) => "Upload {$upload->id} has ANAF upload index 5001130255. "
        .'Did you check SPV under that index and find the invoice is NOT there? If it is filed, it will be filed twice.';

    it('re-queues it once the operator confirms, keeping the index in its errors', function () use ($question) {
        $upload = makeIndeterminateUpload();
        $upload->update(['upload_index' => '5001130255', 'uploaded_at' => now()]);

        $this->artisan('efactura:reconcile', ['--not-filed' => (string) $upload->id, '--despite-index' => true])
            ->expectsConfirmation($question($upload), 'yes')
            ->assertExitCode(0);

        $upload->refresh();
        expect($upload->status)->toBe(UploadStatus::Pending)
            ->and($upload->failure_reason)->toBeNull()
            ->and($upload->upload_index)->toBeNull()
            ->and($upload->uploaded_at)->toBeNull()
            ->and(implode(' ', $upload->errors))->toContain('5001130255');
    });

    it('changes nothing when the operator declines', function () use ($question) {
        $upload = makeIndeterminateUpload();
        $upload->update(['upload_index' => '5001130255']);

        $this->artisan('efactura:reconcile', ['--not-filed' => (string) $upload->id, '--despite-index' => true])
            ->expectsConfirmation($question($upload), 'no')
            ->assertExitCode(1);

        expect($upload->fresh()->failure_reason)->toBe(FailureReason::Indeterminate)
            ->and($upload->fresh()->upload_index)->toBe('5001130255');
    });

    it('changes nothing without an interactive confirmation', function () {
        $upload = makeIndeterminateUpload();
        $upload->update(['upload_index' => '5001130255']);

        $this->artisan('efactura:reconcile', [
            '--not-filed' => (string) $upload->id,
            '--despite-index' => true,
            '--no-interaction' => true,
        ])->assertExitCode(1);

        expect($upload->fresh()->failure_reason)->toBe(FailureReason::Indeterminate);
    });

    it('changes nothing when the index changed after the operator\'s decision was read', function () {
        $upload = makeIndeterminateUpload();
        $upload->update(['upload_index' => '5001130255']);

        // A concurrent writer records another index between the service's read and its write.
        $raced = false;
        EfacturaUpload::retrieved(function () use (&$raced, $upload) {
            if (!$raced) {
                $raced = true;
                EfacturaUpload::whereKey($upload->id)->update(['upload_index' => '5001130299']);
            }
        });

        expect($this->uploadService->resolveIndeterminateAsNotFiled($upload, despiteIndex: true))->toBeFalse()
            ->and($raced)->toBeTrue()
            ->and($upload->fresh()->failure_reason)->toBe(FailureReason::Indeterminate)
            ->and($upload->fresh()->upload_index)->toBe('5001130299');
    });

    it('lets the invoice be queued again afterwards', function () {
        $invoice = TestInvoice::create(['number' => 'INV-001', 'cui' => '12345678', 'total' => 100]);
        $upload = makeIndeterminateUpload();
        $upload->update(['uploadable_type' => TestInvoice::class, 'uploadable_id' => $invoice->id, 'upload_index' => '5001130255']);

        expect(fn () => $this->uploadService->queueUpload($invoice))
            ->toThrow(DuplicateUploadException::class, "--not-filed={$upload->id} --despite-index");

        expect($this->uploadService->resolveIndeterminateAsNotFiled($upload, despiteIndex: true))->toBeTrue()
            ->and($this->uploadService->queueUpload($invoice)->is($upload))->toBeTrue()
            ->and($upload->fresh()->status)->toBe(UploadStatus::Pending);
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
