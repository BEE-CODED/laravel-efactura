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

const POLL_STATUS_URL = 'https://api.anaf.ro/test/FCTEL/rest/stareMesaj*';

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

    CheckUploadStatuses::dispatchSync('12345678');
    expect($upload->fresh()->next_poll_at->getTimestamp())->toBe(now()->getTimestamp() + max($interval - 60, 60));

    CheckUploadStatuses::dispatchSync('12345678');
    Http::assertSentCount(1);

    Queue::fake();
    (new CheckUploadStatuses)->handle(app(DownloadService::class));
    Queue::assertNothingPushed();

    $this->travel($interval)->seconds();
    (new CheckUploadStatuses)->handle(app(DownloadService::class));
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
        $upload->update(['status' => UploadStatus::Uploading]);
        app(UploadService::class)->markUploadAsProcessing($upload, '5001130256');
    }],
]);

it('sets no next read on an upload that finished while it was being read', function () {
    $upload = ($this->processing)(60);
    Http::fake([POLL_STATUS_URL => function () use ($upload) {
        EfacturaUpload::whereKey($upload->id)->update(['status' => UploadStatus::Completed]);

        return anafStatus('in prelucrare');
    }]);

    app(DownloadService::class)->checkStatus($upload);

    expect($upload->fresh()->next_poll_at)->toBeNull();
});
