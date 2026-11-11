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
use BeeCoded\EFactura\Events\InvoiceFailed;
use BeeCoded\EFactura\Jobs\CheckUploadStatuses;
use BeeCoded\EFactura\Jobs\DownloadReceivedInvoices;
use BeeCoded\EFactura\Jobs\DownloadResponses;
use BeeCoded\EFactura\Jobs\SyncMessages;
use BeeCoded\EFactura\Models\EfacturaMessage;
use BeeCoded\EFactura\Models\EfacturaToken;
use BeeCoded\EFactura\Models\EfacturaUpload;
use BeeCoded\EFactura\Services\DownloadService;
use BeeCoded\EFactura\Services\MessageSyncService;
use BeeCoded\EFactura\Services\UploadService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

/**
 * A null-CUI batch job used to work through every tenant inside one 120 s job: one slow
 * CUI used up the budget of all the others. It now dispatches one job per CUI with due
 * work, and a per-CUI job never runs twice at once.
 */
function fanOutToken(string $cui, bool $active = true): EfacturaToken
{
    return EfacturaToken::create([
        'cui' => $cui,
        'access_token' => 'ACCESS_'.$cui,
        'refresh_token' => 'REFRESH_'.$cui,
        'expires_at' => now()->addHour(),
        'is_active' => $active,
    ]);
}

/**
 * Give a token one row of the work a job is due for.
 */
function giveDueWork(string $job, EfacturaToken $token, int $id): void
{
    if ($job === DownloadReceivedInvoices::class) {
        EfacturaMessage::create([
            'efactura_token_id' => $token->id,
            'message_id' => (string) (3001130000 + $id),
            'type' => 'received',
            'download_id' => (string) (3001130000 + $id),
            'details' => [],
        ]);

        return;
    }

    EfacturaUpload::create([
        'efactura_token_id' => $token->id,
        'uploadable_type' => 'App\\Models\\Invoice',
        'uploadable_id' => $id,
        ...($job === CheckUploadStatuses::class
            ? ['status' => UploadStatus::Processing, 'upload_index' => (string) (5001130000 + $id)]
            : ['status' => UploadStatus::Completed, 'upload_index' => (string) (5001130000 + $id), 'download_id' => (string) (3001130000 + $id)]),
    ]);
}

dataset('batch jobs', [
    'status checks' => [CheckUploadStatuses::class, 'checkAllStatuses'],
    'response downloads' => [DownloadResponses::class, 'downloadAllResponses'],
    'received invoices' => [DownloadReceivedInvoices::class, 'downloadAllReceivedInvoices'],
]);

describe('a null-CUI batch job', function () {
    it('dispatches one job per active CUI with due work and does none itself', function (string $job, string $method) {
        Queue::fake();
        $this->mock(DownloadService::class)->shouldNotReceive($method);

        giveDueWork($job, fanOutToken('12345678'), 1);
        giveDueWork($job, fanOutToken('12345678'.'9'), 2);
        giveDueWork($job, fanOutToken('87654321', active: false), 3);
        fanOutToken('11111111'); // nothing due

        app()->call([new $job, 'handle']);

        expect(Queue::pushed($job)->pluck('cui')->sort()->values()->all())->toBe(['12345678', '123456789']);
    })->with('batch jobs');

    it('SyncMessages dispatches one job per active CUI', function () {
        Queue::fake();
        $this->mock(MessageSyncService::class)->shouldNotReceive('syncMessages', 'syncAllMessages');

        fanOutToken('12345678');
        fanOutToken('87654321');
        fanOutToken('11111111', active: false);

        app()->call([new SyncMessages, 'handle']);

        expect(Queue::pushed(SyncMessages::class)->pluck('cui')->sort()->values()->all())->toBe(['12345678', '87654321']);
    });

    it('SyncMessages is unique per CUI', function () {
        Queue::fake();
        fanOutToken('12345678');

        SyncMessages::dispatch('12345678');
        SyncMessages::dispatch('12345678');
        Queue::assertPushed(SyncMessages::class, 1);
    });

    /**
     * A child queues behind its siblings. It used to inherit the parent's dispatch time, so
     * every child starting over max_staleness_seconds later dropped itself, on every run.
     */
    it('runs a child that waited behind its siblings past the stale limit', function (string $job, string $service, string $method) {
        config(['efactura.jobs.max_staleness_seconds' => 120]);
        Queue::fake();
        $token = fanOutToken('12345678');
        if ($job !== SyncMessages::class) {
            giveDueWork($job, $token, 1);
        }

        app()->call([new $job, 'handle']);
        $child = Queue::pushed($job)->sole();
        $this->travel(200)->seconds();

        $this->mock($service)->shouldReceive($method)->once()->andReturn(0);
        app()->call([$child, 'handle']);
    })->with('stale-guarded fan-outs');

    it('still drops a per-CUI job the scheduler dispatched that waited too long', function (string $job, string $service, string $method) {
        config(['efactura.jobs.max_staleness_seconds' => 120]);
        Queue::fake();
        fanOutToken('12345678');

        $job::dispatch('12345678');
        $queued = Queue::pushed($job)->sole();
        $this->travel(200)->seconds();

        $this->mock($service)->shouldNotReceive($method);
        app()->call([$queued, 'handle']);
    })->with('stale-guarded fan-outs');
});

dataset('stale-guarded fan-outs', [
    'status checks' => [CheckUploadStatuses::class, DownloadService::class, 'checkAllStatuses'],
    'response downloads' => [DownloadResponses::class, DownloadService::class, 'downloadAllResponses'],
    'message sync' => [SyncMessages::class, MessageSyncService::class, 'syncMessages'],
]);

describe('a per-CUI batch job', function () {
    it('does not run while another run of it holds the CUI', function (string $job, string $method) {
        $called = 0;
        $this->mock(DownloadService::class)->shouldReceive($method)->with('12345678')->andReturnUsing(function () use (&$called) {
            $called++;
        });

        $lock = Cache::lock((new $job('12345678'))->middleware()[0]->getLockKey(new $job('12345678')), 150);
        expect($lock->get())->toBeTrue();

        $job::dispatch('12345678');
        expect($called)->toBe(0);

        $lock->release();
        $job::dispatch('12345678');
        expect($called)->toBe(1);
    })->with('batch jobs');
});

/**
 * each() pages with OFFSET; rows leaving the scope during page 1 shift the rest below the
 * next page's offset, so a run used to skip them.
 */
it('sweeps every stale row in one run, also past the first thousand', function () {
    Event::fake([InvoiceFailed::class]);
    $token = fanOutToken('12345678');

    DB::table('efactura_uploads')->insert(array_map(fn (int $id) => [
        'efactura_token_id' => $token->id,
        'uploadable_type' => 'App\\Models\\Invoice',
        'uploadable_id' => $id,
        'status' => UploadStatus::Uploading->value,
        'created_at' => now()->subHour(),
        'updated_at' => now()->subHour(),
    ], range(1, 1200)));

    expect(app(UploadService::class)->sweepStaleUploads(30))->toBe(1200)
        ->and(EfacturaUpload::where('failure_reason', FailureReason::Indeterminate)->count())->toBe(1200);
});
