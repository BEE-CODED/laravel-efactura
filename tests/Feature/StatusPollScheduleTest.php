<?php

declare(strict_types=1);

use BeeCoded\EFactura\Enums\FailureReason;
use BeeCoded\EFactura\Enums\UploadStatus;
use BeeCoded\EFactura\Jobs\CheckUploadStatuses;
use BeeCoded\EFactura\Models\EfacturaToken;
use BeeCoded\EFactura\Models\EfacturaUpload;
use BeeCoded\EFactura\Services\DownloadService;
use BeeCoded\EFactura\Services\UploadService;
use BeeCoded\EFactura\Tests\Fixtures\TestInvoice;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

const POLL_STATUS_URL = 'https://api.anaf.ro/test/FCTEL/rest/stareMesaj*';
const POLL_UPLOAD_URL = 'https://api.anaf.ro/test/FCTEL/rest/upload*';

beforeEach(function () {
    config(['efactura-sdk.http.retry_times' => 1, 'efactura-sdk.http.retry_delay' => 0]);
    $this->token = EfacturaToken::create(['cui' => '12345678', 'access_token' => 'ACCESS', 'refresh_token' => 'REFRESH', 'expires_at' => now()->addDay()]);
    $this->processing = fn (?int $ageSeconds) => EfacturaUpload::create([
        'efactura_token_id' => $this->token->id,
        'uploadable_type' => 'App\\Models\\Invoice',
        'uploadable_id' => 1,
        'status' => UploadStatus::Processing,
        'upload_index' => '5001130255',
        'uploaded_at' => $ageSeconds === null ? null : now()->subSeconds($ageSeconds),
        'standard' => 'UBL',
    ]);
});

it('reads an in-progress upload again on the SDK schedule, and fans out only when it is due', function (?int $ageSeconds, int $interval) {
    Http::fake([POLL_STATUS_URL => anafStatus('in prelucrare')]);
    $this->freezeSecond();
    $upload = ($this->processing)($ageSeconds);

    app(DownloadService::class)->checkAllStatuses($this->token);
    expect($upload->fresh()->next_poll_at->getTimestamp())->toBe(now()->getTimestamp() + max($interval - 60, 60));

    app(DownloadService::class)->checkAllStatuses($this->token);
    Http::assertSentCount(1);

    Queue::fake();
    app()->call([new CheckUploadStatuses, 'handle']);
    Queue::assertNothingPushed();

    $this->travel($interval)->seconds();
    app()->call([new CheckUploadStatuses, 'handle']);
    Queue::assertPushed(CheckUploadStatuses::class, fn (CheckUploadStatuses $job) => $job->cui === '12345678');
})->with([
    'first ten minutes' => [120, 60],
    'first hour' => [1800, 300],
    'first three hours' => [7200, 900],
    'older' => [20000, 3600],
    'no upload time' => [null, 3600],
]);

it('makes an upload due again at every transition that resets its status failures', function (Closure $transition) {
    $upload = ($this->processing)(60);
    $upload->update(['next_poll_at' => now()->addHour()]);

    $transition->call($this, $upload);

    expect($upload->fresh()->next_poll_at)->toBeNull();
})->with([
    'resolved as filed' => [function (EfacturaUpload $upload) {
        $upload->update(['status' => UploadStatus::Failed, 'failure_reason' => FailureReason::Indeterminate]);
        app(UploadService::class)->resolveIndeterminateAsFiled($upload, '5001130256');
        expect(EfacturaUpload::needsStatusCheck()->pluck('id')->all())->toBe([$upload->id]);
    }],
    'resolved as not filed' => [function (EfacturaUpload $upload) {
        $upload->update(['status' => UploadStatus::Failed, 'failure_reason' => FailureReason::Indeterminate]);
        expect(app(UploadService::class)->resolveIndeterminateAsNotFiled($upload, despiteIndex: true))->toBeTrue();
    }],
    'a failed row queued again' => [function (EfacturaUpload $upload) {
        $invoice = TestInvoice::create(['number' => 'INV-1', 'cui' => '12345678']);
        $upload->update(['status' => UploadStatus::Failed, 'failure_reason' => FailureReason::Validation, 'uploadable_type' => $invoice->getMorphClass(), 'uploadable_id' => $invoice->id]);
        expect(app(UploadService::class)->queueUpload($invoice)->is($upload))->toBeTrue();
    }],
    'uploaded again' => [function (EfacturaUpload $upload) {
        Storage::fake('local');
        Http::fake([POLL_UPLOAD_URL => Http::response('<?xml version="1.0"?><header xmlns="mfp:anaf:dgti:spv:respUploadFisier:v1" ExecutionStatus="0" index_incarcare="5001130256"/>')]);
        $invoice = TestInvoice::create(['number' => 'INV-1', 'cui' => '12345678']);
        $lease = now()->addSeconds(300)->toDateTimeString();
        $upload->update(['status' => UploadStatus::Pending, 'upload_index' => null, 'next_poll_at' => $lease, 'uploadable_type' => $invoice->getMorphClass(), 'uploadable_id' => $invoice->id]);

        app(UploadService::class)->processUpload($upload, $lease);

        expect([$upload->fresh()->status, $upload->fresh()->upload_index])->toBe([UploadStatus::Processing, '5001130256']);
    }],
]);

it('leaves the schedule of an upload another run finished while this one read it', function () {
    $upload = ($this->processing)(60);
    Http::fake([POLL_STATUS_URL => function () use ($upload) {
        // As recordVerdict() writes it: the response download is due at once.
        EfacturaUpload::whereKey($upload->id)->update(['status' => UploadStatus::Completed, 'next_poll_at' => null]);

        return anafStatus('in prelucrare');
    }]);

    app(DownloadService::class)->checkStatus($upload);

    expect($upload->fresh()->next_poll_at)->toBeNull();
});

it('does not read an upload again before its slot after a read that failed after sending', function () {
    Http::fake([POLL_STATUS_URL => Http::response('Bad Gateway', 502)]);
    $this->freezeSecond();
    $upload = ($this->processing)(20000);

    app(DownloadService::class)->checkAllStatuses($this->token);

    expect($upload->fresh()->next_poll_at?->getTimestamp())->toBe(now()->getTimestamp() + 3540)
        ->and($upload->fresh()->status_failures)->toBe(0);

    app(DownloadService::class)->checkAllStatuses($this->token);
    Http::assertSentCount(1);
});

it('reads an upload once when a second run starts while the first one reads it', function () {
    ($this->processing)(60);
    $nested = true;
    Http::fake([POLL_STATUS_URL => function () use (&$nested) {
        if ($nested) {
            $nested = false;
            app(DownloadService::class)->checkAllStatuses($this->token);
        }

        return anafStatus('in prelucrare');
    }]);

    app(DownloadService::class)->checkAllStatuses($this->token);

    Http::assertSentCount(1);
});

it('reads never-read and then the most overdue uploads first, and starts no new read after 90 seconds', function () {
    $overdue = ($this->processing)(20000);
    $overdue->update(['next_poll_at' => now()->subSeconds(60)]);
    EfacturaUpload::create([
        'efactura_token_id' => $this->token->id,
        'uploadable_type' => 'App\\Models\\Invoice',
        'uploadable_id' => 2,
        'status' => UploadStatus::Processing,
        'upload_index' => '5001130256',
        'uploaded_at' => now()->subSeconds(20000),
        'standard' => 'UBL',
    ]);
    // Created last, so the id tiebreak cannot hide a reversed order.
    EfacturaUpload::create([
        'efactura_token_id' => $this->token->id,
        'uploadable_type' => 'App\\Models\\Invoice',
        'uploadable_id' => 3,
        'status' => UploadStatus::Processing,
        'upload_index' => '5001130257',
        'uploaded_at' => now()->subSeconds(20000),
        'next_poll_at' => now()->subSeconds(600),
        'standard' => 'UBL',
    ]);
    Http::fake([POLL_STATUS_URL => function () {
        $this->travel(46)->seconds();

        return anafStatus('in prelucrare');
    }]);

    app(DownloadService::class)->checkAllStatuses($this->token);

    // The third read would start at 92 s, past the 90 s deadline.
    expect(Http::recorded()->map(function (array $pair) {
        parse_str((string) parse_url($pair[0]->url(), PHP_URL_QUERY), $query);

        return $query['id_incarcare'];
    })->all())->toBe(['5001130256', '5001130257']);
});
