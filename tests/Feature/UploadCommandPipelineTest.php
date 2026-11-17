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
use BeeCoded\EFactura\Services\TokenService;
use BeeCoded\EFactura\Services\UploadService;
use BeeCoded\EFactura\Tests\Fixtures\TestInvoice;
use BeeCoded\EFacturaSdk\Services\RateLimiter;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/**
 * `efactura:upload` used to upload synchronously,
 * bypassing ProcessSingleUpload entirely — so no rate-limit pre-flight, no release,
 * no retry. A backlog past ANAF's quota marked every remaining invoice Failed and
 * fired an InvoiceFailed storm.
 */
beforeEach(function () {
    config(['efactura.enabled' => true, 'efactura.features.upload_invoices' => true]);

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
        Queue::assertPushed(ProcessSingleUpload::class, 1);
        expect($upload->fresh()->status)->toBe(UploadStatus::Pending);
    });

    it('skips a paused company with one line and queues the others', function () {
        Queue::fake();

        $cooling = EfacturaToken::create([
            'cui' => '87654321',
            'access_token' => 'test',
            'refresh_token' => 'test',
            'expires_at' => now()->addHour(),
            'is_active' => true,
        ]);
        $mine = makePending(1);
        makePending(2, $cooling);
        coolDownGrant($cooling, 600);

        $this->artisan('efactura:upload')
            ->expectsOutputToContain('87654321: skipped (cooling).')
            ->expectsOutputToContain('Queued 1 pending upload(s)')
            ->assertExitCode(0);

        Queue::assertPushed(ProcessSingleUpload::class, 1);
        Queue::assertPushed(ProcessSingleUpload::class, fn ($job) => $job->upload->is($mine));
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
    it('sends each due upload inline, without a lease, for users without a queue worker', function () {
        Queue::fake();
        $due = makePending(1);

        $uploadService = Mockery::mock(UploadService::class);
        $uploadService->shouldReceive('processUpload')->once()
            ->withArgs(fn (...$args) => count($args) === 1 && $args[0]->is($due));
        app()->instance(UploadService::class, $uploadService);

        $this->artisan('efactura:upload', ['--sync' => true])
            ->expectsOutputToContain('Processed 1 upload(s).')
            ->assertExitCode(0);

        Queue::assertNothingPushed();
    });

    /**
     * The synchronous path has no queue to wait in, so it stops a company's run at its first
     * rate-limited upload; the rest keep waiting, due, for a later run.
     */
    it('stops at the first rate-limited upload instead of failing the whole backlog', function () {
        makePending(1);
        $second = makePending(2);
        $third = makePending(3);

        $uploadService = Mockery::mock(UploadService::class.'[processUpload]', [app(TokenService::class), app(RateLimiter::class)]);
        $uploadService->shouldReceive('processUpload')
            ->once()
            ->andReturnUsing(function (EfacturaUpload $upload) {
                $upload->update([
                    'status' => UploadStatus::Pending,
                    'failure_reason' => FailureReason::RateLimited,
                    'next_poll_at' => now()->addMinute(),
                    'errors' => ['quota exhausted'],
                ]);
            });
        app()->instance(UploadService::class, $uploadService);

        $this->artisan('efactura:upload', ['--sync' => true])
            ->expectsOutputToContain('Processed 1 upload(s).')
            ->assertExitCode(0);

        // The rest are untouched, still due for a later run.
        expect($second->fresh()->status)->toBe(UploadStatus::Pending)
            ->and($second->fresh()->next_poll_at)->toBeNull()
            ->and($third->fresh()->status)->toBe(UploadStatus::Pending)
            ->and($third->fresh()->next_poll_at)->toBeNull();
    });

    // The first upload's refresh is refused and pauses the grant: the others are not touched.
    it('stops a company whose grant ANAF refuses during the run', function () {
        Storage::fake('local');
        $this->token->update(['expires_at' => now()->addSeconds(60)]);
        makePending(1);
        $second = makePending(2);
        $third = makePending(3);
        Http::fake(['https://logincert.anaf.ro/anaf-oauth2/v1/token' => Http::response(['error' => 'invalid_grant'], 400)]);

        $this->artisan('efactura:upload', ['--sync' => true])
            ->expectsOutputToContain('Processed 1 upload(s).')
            ->assertExitCode(0);

        Http::assertSentCount(1);
        expect($second->fresh())->status->toBe(UploadStatus::Pending)
            ->next_poll_at->toBeNull()
            ->and($third->fresh())->status->toBe(UploadStatus::Pending)
            ->next_poll_at->toBeNull();
    });

    it('prints a paused company as skipped, not processed', function () {
        $cooling = EfacturaToken::create([
            'cui' => '87654321',
            'access_token' => 'test',
            'refresh_token' => 'test',
            'expires_at' => now()->addHour(),
            'is_active' => true,
        ]);
        makePending(1, $cooling);
        makePending(2, $cooling);
        coolDownGrant($cooling, 600);

        $uploadService = Mockery::mock(UploadService::class);
        $uploadService->shouldNotReceive('processUpload');
        app()->instance(UploadService::class, $uploadService);

        $this->artisan('efactura:upload', ['--sync' => true, '--cui' => '87654321'])
            ->expectsOutputToContain('87654321: skipped (cooling).')
            ->expectsOutputToContain('Processed 0 upload(s).')
            ->assertExitCode(0);
    });

    it('fails an upload whose model class no longer exists and still sends the others', function () {
        Storage::fake('local');
        Http::fake(['https://api.anaf.ro/test/FCTEL/rest/upload*' => Http::response('<?xml version="1.0"?><header xmlns="mfp:anaf:dgti:spv:respUploadFisier:v1" ExecutionStatus="0" index_incarcare="5001130257"/>')]);
        $removed = EfacturaUpload::create([
            'efactura_token_id' => $this->token->id,
            'uploadable_type' => 'App\\Models\\RemovedInvoice',
            'uploadable_id' => 1,
            'status' => UploadStatus::Pending,
            'standard' => 'UBL',
        ]);
        $good = makePending(2);

        $this->artisan('efactura:upload', ['--sync' => true])->assertExitCode(0);

        Http::assertSentCount(1);
        expect($removed->fresh())->status->toBe(UploadStatus::Failed)
            ->failure_reason->toBe(FailureReason::Validation)
            ->and($good->fresh()->status)->toBe(UploadStatus::Processing);
    });

    it('fails when the CUI has no token', function () {
        $this->artisan('efactura:upload', ['--sync' => true, '--cui' => '99999999'])
            ->expectsOutput('No active token found for CUI: 99999999')
            ->assertExitCode(1);
    });
});
