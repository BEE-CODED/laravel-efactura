<?php

declare(strict_types=1);

use BeeCoded\EFactura\Enums\AnafLookupStatus;
use BeeCoded\EFactura\Services\CachedAnafLookup;
use BeeCoded\EFacturaSdk\Services\ApiClients\AnafDetailsClient;
use Carbon\CarbonImmutable;
use Illuminate\Cache\ArrayStore;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

function registryFound(string $cui, bool $inactive = false): array
{
    return ['found' => [[
        'date_generale' => ['cui' => (int) $cui, 'data' => now('Europe/Bucharest')->toDateString(), 'denumire' => 'Registry fixture', 'stare_inregistrare' => 'INREGISTRAT din data 01.01.2000'],
        'stare_inactiv' => ['statusInactivi' => $inactive, 'dataInactivare' => '', 'dataReactivare' => '', 'dataPublicare' => '', 'dataRadiere' => ''],
    ]], 'notFound' => []];
}

beforeEach(function () {
    config(['efactura-sdk.rate_limits.company_lookup_per_second' => 100]);
    $this->travelTo(CarbonImmutable::parse('2026-09-12 12:00:00', 'Europe/Bucharest'));
    $this->lookup = new CachedAnafLookup(new AnafDetailsClient(strict: true, tries: 1, timeoutSeconds: 2));
});

it('caches a found company for the day and a missing CUI for 15 minutes, per CUI', function () {
    // The TTLs are read through time travel, which a shared store (Redis) does not follow.
    Cache::setDefaultDriver('array');
    // Built at call time: the strict client checks that ANAF echoes today's date.
    $answers = [fn () => registryFound('34567892', inactive: true), fn () => ['found' => [], 'notFound' => [123453]], fn () => registryFound('123453'), fn () => registryFound('34567892')];
    Http::fake([AnafDetailsClient::getBaseUrl() => function () use (&$answers) {
        return Http::response(array_shift($answers)());
    }]);

    expect($this->lookup->cachedRead('34567892'))->toBeNull();
    $found = $this->lookup->resolve('RO34567892');
    expect([$found->status, $found->cui, $found->queryDate, $found->active])->toBe([AnafLookupStatus::Found, '34567892', '2026-09-12', false])
        ->and($this->lookup->resolve('34567892'))->toEqual($found)
        ->and($this->lookup->cachedRead('34567892'))->toEqual($found)
        ->and($this->lookup->resolve('123453')->status)->toBe(AnafLookupStatus::NotFound);
    expect($this->lookup->resolve('123453')->status)->toBe(AnafLookupStatus::NotFound)
        ->and($this->lookup->cachedRead('123453')?->status)->toBe(AnafLookupStatus::NotFound);
    Http::assertSentCount(2);

    $this->travel(901)->seconds();
    expect($this->lookup->resolve('123453')->status)->toBe(AnafLookupStatus::Found)
        ->and($this->lookup->resolve('34567892')->active)->toBeFalse();
    Http::assertSentCount(3);

    $this->travel(86400)->seconds();
    expect($this->lookup->resolve('34567892')->active)->toBeTrue();
    Http::assertSentCount(4);
});

it('keeps a found company until the end of the day', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-12 00:00:30', 'Europe/Bucharest'));
    Http::fake([AnafDetailsClient::getBaseUrl() => fn () => Http::response(registryFound('34567892'))]);

    $this->lookup->resolve('34567892');
    $this->travelTo(CarbonImmutable::parse('2026-09-12 23:59:30', 'Europe/Bucharest'));

    expect($this->lookup->cachedRead('34567892')?->status)->toBe(AnafLookupStatus::Found);
    Http::assertSentCount(1);
});

it('never reads a verdict cached for another lookup URL', function () {
    $sandbox = 'https://sandbox.anaf.test/api/PlatitorTvaRest/v9/tva';
    Http::fake([
        AnafDetailsClient::getBaseUrl() => fn () => Http::response(registryFound('34567892')),
        $sandbox => fn () => Http::response(registryFound('34567892', inactive: true)),
    ]);

    expect($this->lookup->resolve('34567892')->active)->toBeTrue();
    config(['efactura-sdk.endpoints.company_lookup' => $sandbox]);

    expect($this->lookup->cachedRead('34567892'))->toBeNull()
        ->and($this->lookup->resolve('34567892')->active)->toBeFalse();
    Http::assertSentCount(2);
});

it('still answers when the cache cannot store the verdict', function () {
    Cache::extend('put-fails', fn () => Cache::repository(new class extends ArrayStore
    {
        public function put($key, $value, $seconds)
        {
            // The SDK's rate limiter shares the store, so only the verdict's write fails.
            if (str_starts_with($key, 'efactura:anaf-lookup:')) {
                throw new RuntimeException('cache down');
            }

            return parent::put($key, $value, $seconds);
        }
    }));
    config(['cache.stores.put-fails' => ['driver' => 'put-fails'], 'cache.default' => 'put-fails']);
    Http::fake([AnafDetailsClient::getBaseUrl() => fn () => Http::response(registryFound('34567892'))]);

    expect($this->lookup->resolve('34567892')->status)->toBe(AnafLookupStatus::Found);
});

it('reads an answer that does not confirm exactly the CUI asked as unavailable, uncached', function (array $body) {
    // A non-strict client passes these through, so the lookup's own identity check decides.
    $lookup = new CachedAnafLookup(new AnafDetailsClient(tries: 1));
    Http::fake([AnafDetailsClient::getBaseUrl() => Http::response($body)]);

    expect($lookup->resolve('34567892')->status)->toBe(AnafLookupStatus::Unavailable)
        ->and($lookup->cachedRead('34567892'))->toBeNull();
})->with([
    'another company' => [registryFound('123453')],
    'two companies' => [['found' => [...registryFound('34567892')['found'], ...registryFound('123453')['found']], 'notFound' => []]],
    'another CUI not found' => [['found' => [], 'notFound' => [123453]]],
    'empty answer' => [['found' => [], 'notFound' => []]],
]);

it('reads a day rollover during the call as unavailable, uncached', function () {
    $body = registryFound('34567892');
    Http::fake([AnafDetailsClient::getBaseUrl() => function () use ($body) {
        $this->travelTo(CarbonImmutable::parse('2026-09-13 00:00:01', 'Europe/Bucharest'));

        return Http::response($body);
    }]);
    $this->travelTo(CarbonImmutable::parse('2026-09-12 23:59:59', 'Europe/Bucharest'));
    $result = $this->lookup->resolve('34567892');

    expect([$result->status, $result->queryDate])->toBe([AnafLookupStatus::Unavailable, '2026-09-12'])
        ->and($this->lookup->cachedRead('34567892'))->toBeNull();
});

it('reads a rate limit as busy, uncached', function () {
    Http::fake([AnafDetailsClient::getBaseUrl() => Http::response([], 429)]);
    expect($this->lookup->resolve('34567892')->status)->toBe(AnafLookupStatus::Busy)
        ->and($this->lookup->cachedRead('34567892'))->toBeNull();
});

it('ignores a malformed cached verdict and replaces it with a fresh one', function () {
    // The stored key is found by listing the array store.
    Cache::setDefaultDriver('array');
    Http::fake([AnafDetailsClient::getBaseUrl() => Http::response(registryFound('34567892'))]);
    $this->lookup->resolve('34567892');
    $key = collect(Cache::getStore()->all())->keys()->sole(fn (string $key) => str_contains($key, 'efactura:anaf-lookup:'));
    Cache::put($key, ['status' => 'found', 'cui' => '34567892', 'queryDate' => '2026-09-12', 'active' => 'true'], 900);

    expect($this->lookup->cachedRead('34567892'))->toBeNull()
        ->and($this->lookup->resolve('34567892')->active)->toBeTrue();
    Http::assertSentCount(2);
    expect(Cache::get($key))->toBe(['status' => 'found', 'cui' => '34567892', 'queryDate' => '2026-09-12', 'active' => true]);
});

it('refuses a CUI that is not 2 to 10 digits', function () {
    $this->lookup->resolve('RO12AB');
})->throws(InvalidArgumentException::class);
