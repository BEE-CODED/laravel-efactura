<?php

declare(strict_types=1);

use BeeCoded\EFactura\Services\RetryingAnafDetailsClient;
use BeeCoded\EFacturaSdk\Contracts\AnafDetailsClientInterface;
use BeeCoded\EFacturaSdk\Data\Company\CompanyLookupResultData;
use BeeCoded\EFacturaSdk\Exceptions\RateLimitExceededException;
use Illuminate\Support\Sleep;
use Mockery as m;

beforeEach(function () {
    Sleep::fake(); // make retry backoff instant + assertable
});

function rl(int $retryAfter = 1): RateLimitExceededException
{
    return new RateLimitExceededException('limit', remaining: 0, retryAfterSeconds: $retryAfter);
}

it('returns immediately when the first attempt succeeds (no sleep)', function () {
    $ok = CompanyLookupResultData::success([]);
    $inner = m::mock(AnafDetailsClientInterface::class);
    $inner->shouldReceive('getCompanyData')->once()->andReturn($ok);

    $client = new RetryingAnafDetailsClient($inner, 5);

    expect($client->getCompanyData('123'))->toBe($ok);
    Sleep::assertSleptTimes(0);
});

it('retries on RateLimitExceededException and succeeds within the attempt budget', function () {
    $ok = CompanyLookupResultData::success([]);
    $inner = m::mock(AnafDetailsClientInterface::class);
    // throw 4 times, succeed on the 5th (5 total attempts)
    $inner->shouldReceive('getCompanyData')->times(5)->andReturnUsing(
        fn () => throw rl(),
        fn () => throw rl(),
        fn () => throw rl(),
        fn () => throw rl(),
        fn () => $ok,
    );

    $client = new RetryingAnafDetailsClient($inner, 5);

    expect($client->getCompanyData('123'))->toBe($ok);
    Sleep::assertSleptTimes(4); // 4 backoffs between 5 attempts
});

it('re-throws after exhausting all attempts', function () {
    $inner = m::mock(AnafDetailsClientInterface::class);
    $inner->shouldReceive('getCompanyData')->times(5)->andThrow(rl());

    $client = new RetryingAnafDetailsClient($inner, 5);

    expect(fn () => $client->getCompanyData('123'))->toThrow(RateLimitExceededException::class);
    Sleep::assertSleptTimes(4);
});

it('sleeps at least the retryAfterSeconds between attempts', function () {
    $ok = CompanyLookupResultData::success([]);
    $inner = m::mock(AnafDetailsClientInterface::class);
    $inner->shouldReceive('getCompanyData')->twice()->andReturnUsing(
        fn () => throw rl(3),
        fn () => $ok,
    );

    (new RetryingAnafDetailsClient($inner, 5))->getCompanyData('123');

    Sleep::assertSlept(fn ($duration) => $duration->seconds >= 3, times: 1);
});

it('does NOT retry a failure() result (only the rate-limit exception)', function () {
    $failure = CompanyLookupResultData::failure('bad cui');
    $inner = m::mock(AnafDetailsClientInterface::class);
    $inner->shouldReceive('getCompanyData')->once()->andReturn($failure);

    expect((new RetryingAnafDetailsClient($inner, 5))->getCompanyData('123'))->toBe($failure);
    Sleep::assertSleptTimes(0);
});

it('applies the same retry policy to batchGetCompanyData', function () {
    $ok = CompanyLookupResultData::success([]);
    $inner = m::mock(AnafDetailsClientInterface::class);
    $inner->shouldReceive('batchGetCompanyData')->twice()->andReturnUsing(
        fn () => throw rl(),
        fn () => $ok,
    );

    expect((new RetryingAnafDetailsClient($inner, 5))->batchGetCompanyData(['1', '2']))->toBe($ok);
    Sleep::assertSleptTimes(1);
});

it('delegates isValidVatCode without retry or network', function () {
    $inner = m::mock(AnafDetailsClientInterface::class);
    $inner->shouldReceive('isValidVatCode')->once()->with('RO123')->andReturn(true);

    expect((new RetryingAnafDetailsClient($inner, 5))->isValidVatCode('RO123'))->toBeTrue();
});
