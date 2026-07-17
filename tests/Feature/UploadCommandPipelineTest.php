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
use BeeCoded\EFactura\Jobs\ProcessSingleUpload;
use BeeCoded\EFactura\Models\EfacturaToken;
use BeeCoded\EFactura\Models\EfacturaUpload;
use BeeCoded\EFactura\Services\UploadService;
use BeeCoded\EFactura\Tests\Fixtures\TestInvoice;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

/**
 * `efactura:upload` called UploadService::processPendingUploads() synchronously,
 * bypassing ProcessSingleUpload entirely — so no rate-limit pre-flight, no release,
 * no retry. A backlog past ANAF's quota marked every remaining invoice Failed and
 * fired an InvoiceFailed storm.
 */
beforeEach(function () {
    config(['efactura.enabled' => true, 'efactura.features.upload_invoices' => true]);

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
});

function makePending(int $modelId, ?EfacturaToken $token = null): EfacturaUpload
{
    $invoice = TestInvoice::create([
        'number' => 'INV-'.$modelId,
        'cui' => '12345678',
        'total' => 100,
    ]);

    return EfacturaUpload::create([
        'efactura_token_id' => ($token ?? test()->token)->id,
        'uploadable_type' => TestInvoice::class,
        'uploadable_id' => $invoice->id,
        'status' => UploadStatus::Pending,
        'standard' => 'UBL',
    ]);
}

describe('efactura:upload by default', function () {
    it('dispatches each pending upload through the rate-limit-aware job', function () {
        Queue::fake();

        $a = makePending(1);
        $b = makePending(2);

        $this->artisan('efactura:upload')->assertExitCode(0);

        Queue::assertPushed(ProcessSingleUpload::class, 2);
        Queue::assertPushed(ProcessSingleUpload::class, fn ($job) => $job->upload->id === $a->id);
        Queue::assertPushed(ProcessSingleUpload::class, fn ($job) => $job->upload->id === $b->id);
    });

    it('does not upload synchronously', function () {
        Queue::fake();

        $upload = makePending(1);

        $this->artisan('efactura:upload')->assertExitCode(0);

        // Still pending: the job owns the actual transmission.
        expect($upload->fresh()->status)->toBe(UploadStatus::Pending);
    });

    it('filters by CUI', function () {
        Queue::fake();

        $otherToken = EfacturaToken::create([
            'cui' => '87654321',
            'access_token' => 'test',
            'refresh_token' => 'test',
            'expires_at' => now()->addHour(),
            'is_active' => true,
        ]);

        $mine = makePending(1);
        makePending(2, $otherToken);

        $this->artisan('efactura:upload', ['--cui' => '12345678'])->assertExitCode(0);

        Queue::assertPushed(ProcessSingleUpload::class, 1);
        Queue::assertPushed(ProcessSingleUpload::class, fn ($job) => $job->upload->id === $mine->id);
    });

    it('fails when the CUI has no token', function () {
        Queue::fake();

        $this->artisan('efactura:upload', ['--cui' => '99999999'])->assertExitCode(1);

        Queue::assertNothingPushed();
    });
});

describe('efactura:upload --sync', function () {
    it('still processes inline for users without a queue worker', function () {
        Queue::fake();

        $uploadService = Mockery::mock(UploadService::class);
        $uploadService->shouldReceive('processPendingUploads')->once()->with(null)->andReturn(1);
        app()->instance(UploadService::class, $uploadService);

        $this->artisan('efactura:upload', ['--sync' => true])->assertExitCode(0);

        Queue::assertNothingPushed();
    });

    /**
     * The synchronous path has no queue to release into, so the only safe response to
     * exhausting ANAF's quota is to stop. Previously it ploughed on and marked the
     * whole remaining backlog Failed.
     */
    it('stops at the first rate-limited upload instead of failing the whole backlog', function () {
        $first = makePending(1);
        $second = makePending(2);
        $third = makePending(3);

        $uploadService = Mockery::mock(UploadService::class)->makePartial();
        $uploadService->shouldReceive('processUpload')
            ->once()
            ->andReturnUsing(function (EfacturaUpload $upload) {
                $upload->update([
                    'status' => UploadStatus::Failed,
                    'failure_reason' => FailureReason::RateLimited,
                    'errors' => ['quota exhausted'],
                ]);
            });

        $processed = $uploadService->processPendingUploads();

        expect($processed)->toBe(1);

        // The rest are untouched, still queued for a later run.
        expect($second->fresh()->status)->toBe(UploadStatus::Pending)
            ->and($third->fresh()->status)->toBe(UploadStatus::Pending);
    });
});
