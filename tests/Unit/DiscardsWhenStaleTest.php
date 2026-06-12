<?php

use BeeCoded\EFactura\Jobs\CheckUploadStatuses;

it('stamps the enqueue timestamp on construction', function () {
    $job = new CheckUploadStatuses;

    expect($job->enqueuedAt)->toBeGreaterThan(0)
        ->and(abs($job->enqueuedAt - now()->getTimestamp()))->toBeLessThanOrEqual(1);
});

it('is not stale immediately after construction', function () {
    config(['efactura.jobs.max_staleness_seconds' => 120]);

    expect((new CheckUploadStatuses)->isStale())->toBeFalse();
});

it('is stale once it has waited longer than the configured max', function () {
    config(['efactura.jobs.max_staleness_seconds' => 120]);

    $job = new CheckUploadStatuses;
    $job->enqueuedAt = now()->subSeconds(200)->getTimestamp();

    expect($job->isStale())->toBeTrue();
});

it('is never stale when the staleness guard is disabled (<= 0)', function () {
    config(['efactura.jobs.max_staleness_seconds' => 0]);

    $job = new CheckUploadStatuses;
    $job->enqueuedAt = now()->subSeconds(9999)->getTimestamp();

    expect($job->isStale())->toBeFalse();
});
