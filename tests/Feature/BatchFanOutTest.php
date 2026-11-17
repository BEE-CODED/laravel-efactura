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
use BeeCoded\EFactura\Jobs\ProcessPendingUploads;
use BeeCoded\EFactura\Jobs\SyncMessages;
use BeeCoded\EFactura\Models\EfacturaMessage;
use BeeCoded\EFactura\Models\EfacturaToken;
use BeeCoded\EFactura\Models\EfacturaUpload;
use BeeCoded\EFactura\Services\DownloadService;
use BeeCoded\EFactura\Services\MessageSyncService;
use BeeCoded\EFactura\Services\UploadService;
use Illuminate\Cache\Events\RetrievingKey;
use Illuminate\Queue\Connectors\ConnectorInterface;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * A null-CUI batch job used to work through every tenant inside one 120 s job: one slow
 * CUI used up the budget of all the others. It now dispatches one job per usable company
 * with due work, and a per-company job is queued once and never runs twice at once.
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

    if ($job === ProcessPendingUploads::class) {
        EfacturaUpload::create([
            'efactura_token_id' => $token->id,
            'uploadable_type' => 'App\\Models\\Invoice',
            'uploadable_id' => $id,
            'status' => UploadStatus::Pending,
        ]);

        return;
    }

    if ($job === SyncMessages::class) {
        return;
    }

    EfacturaUpload::create([
        'efactura_token_id' => $token->id,
        'uploadable_type' => 'App\\Models\\Invoice',
        'uploadable_id' => $id,
        ...($job === CheckUploadStatuses::class
            ? ['status' => UploadStatus::Processing, 'upload_index' => (string) (5001130000 + $id)]
            : ['status' => UploadStatus::Completed, 'upload_index' => (string) (5001130000 + $id), 'download_id' => (string) (3001130000 + $id), 'processed_at' => now()]),
    ]);
}

dataset('batch jobs', [
    'status checks' => [CheckUploadStatuses::class, DownloadService::class, 'checkAllStatuses', true],
    'response downloads' => [DownloadResponses::class, DownloadService::class, 'downloadAllResponses', true],
    'received invoices' => [DownloadReceivedInvoices::class, DownloadService::class, 'downloadAllReceivedInvoices', true],
    'message sync' => [SyncMessages::class, MessageSyncService::class, 'syncMessages', 0],
    'pending uploads' => [ProcessPendingUploads::class, UploadService::class, 'dispatchPendingUploads', 0],
]);

describe('a null-CUI batch job', function () {
    /**
     * Inactive, paused and legacy (non-canonical CUI) tokens are left out, and so is a
     * company with nothing due, except for SyncMessages, which always has work.
     */
    it('dispatches one job per usable company with due work, under its canonical CUI, and does none itself', function (string $job, string $service, string $method) {
        Queue::fake();
        $this->mock($service)->shouldNotReceive($method);

        $a = fanOutToken('12345678');
        $b = fanOutToken('87654321');
        $c = fanOutToken('11111111', active: false);
        $d = fanOutToken('22222222');
        $e = fanOutToken('012345678');
        refuseGrant($d);
        foreach ([$a, $c, $d, $e] as $i => $token) {
            giveDueWork($job, $token, $i + 1);
        }
        if ($job === ProcessPendingUploads::class) {
            // Leased or waiting out a limit: not due, so no job.
            EfacturaUpload::create(['efactura_token_id' => $b->id, 'uploadable_type' => 'App\\Models\\Invoice', 'uploadable_id' => 5, 'status' => UploadStatus::Pending, 'next_poll_at' => now()->addHour()]);
        }

        app()->call([new $job, 'handle']);

        expect(Queue::pushed($job)->pluck('cui')->sort()->values()->all())
            ->toBe($job === SyncMessages::class ? ['12345678', '87654321'] : ['12345678']);
    })->with('batch jobs');

    // Reading a pause decrypts the grant and asks the cache: a company with nothing due needs neither.
    it('reads no pause for a company with nothing due', function (string $job) {
        Queue::fake();
        giveDueWork($job, fanOutToken('12345678'), 1);
        $idle = fanOutToken('87654321');
        $keys = [];
        Event::listen(RetrievingKey::class, function (RetrievingKey $event) use (&$keys) {
            $keys[] = $event->key;
        });

        app()->call([new $job, 'handle']);

        expect(array_filter($keys, fn (string $key) => str_starts_with($key, "efactura:token_refresh_refused:{$idle->id}:")))->toBe([]);
        Queue::assertPushed($job, fn ($pushed) => $pushed->cui === '12345678');
    })->with([CheckUploadStatuses::class, DownloadResponses::class, DownloadReceivedInvoices::class, ProcessPendingUploads::class]);

    it('frees the schedule two minutes after a queue push failed', function () {
        // An array lock honours travel(); a Redis TTL would not.
        config(['cache.default' => 'array', 'queue.connections.broken' => ['driver' => 'broken'], 'queue.default' => 'broken']);
        Queue::extend('broken', fn () => new class implements ConnectorInterface
        {
            public function connect(array $config)
            {
                throw new RuntimeException('push failed');
            }
        });

        try {
            dispatch(new ProcessPendingUploads);
        } catch (RuntimeException) {
        }

        Queue::fake();
        $this->travel(121)->seconds();
        dispatch(new ProcessPendingUploads);

        Queue::assertPushed(ProcessPendingUploads::class);
    });

    it('SyncMessages is unique per company', function () {
        Queue::fake();
        fanOutToken('12345678');

        SyncMessages::dispatch('12345678');
        SyncMessages::dispatch('RO12345678');
        Queue::assertPushed(SyncMessages::class, 1);
    });

    it('DownloadReceivedInvoices is unique per company', function () {
        Queue::fake();
        fanOutToken('12345678');

        DownloadReceivedInvoices::dispatch('12345678');
        DownloadReceivedInvoices::dispatch('RO12345678');
        Queue::assertPushed(DownloadReceivedInvoices::class, 1);
    });
});

describe('a per-CUI batch job', function () {
    it('does not run while another run of it holds the company, whatever the CUI spelling', function (string $job, string $service, string $method, bool|int $returns) {
        fanOutToken('12345678');
        $called = 0;
        $this->mock($service)->shouldReceive($method)
            ->with(Mockery::on(fn ($token) => $token instanceof EfacturaToken && $token->cui === '12345678'))
            ->andReturnUsing(function () use (&$called, $returns) {
                $called++;

                return $returns;
            });

        $lock = Cache::lock((new $job('12345678'))->middleware()[0]->getLockKey(new $job('12345678')), 150);
        expect($lock->get())->toBeTrue();

        $job::dispatch('ro12345678');
        expect($called)->toBe(0);

        $lock->release();
        $job::dispatch('RO 1234 5678');
        expect($called)->toBe(1);
    })->with('batch jobs');

    it('keys one company on one id, whatever the CUI spelling', function (string $job) {
        expect((new $job('RO12345678'))->uniqueId())->toBe('12345678')
            ->and((new $job(' 12345678'))->uniqueId())->toBe('12345678');
    })->with('batch jobs');

    /**
     * A blank or malformed CUI used to mean "every company", or reached the service as it was.
     */
    it('fails when it runs with a CUI that is not one, and touches nothing', function (string $job, string $service, string $method, bool|int $returns, string $cui) {
        Http::fake();
        Event::fake([JobFailed::class]);
        $token = fanOutToken('12345678');
        giveDueWork($job, $token, 1);
        $pending = EfacturaUpload::create([
            'efactura_token_id' => $token->id,
            'uploadable_type' => 'App\\Models\\Invoice',
            'uploadable_id' => 99,
            'status' => UploadStatus::Pending,
        ]);

        Queue::connection('sync')->push(new $job($cui));

        Event::assertDispatched(JobFailed::class, fn (JobFailed $event) => $event->exception instanceof InvalidArgumentException
            && $event->exception->getMessage() === "Invalid CUI: '{$cui}'");
        Http::assertNothingSent();
        expect($pending->fresh()->next_poll_at)->toBeNull();
    })->with('batch jobs')->with(['', ' ', 'RO', '0', '0123']);
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
