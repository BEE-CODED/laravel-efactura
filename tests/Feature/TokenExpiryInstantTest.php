<?php

declare(strict_types=1);

use BeeCoded\EFactura\Models\EfacturaToken;
use BeeCoded\EFactura\Services\TokenService;
use BeeCoded\EFacturaSdk\Data\Auth\OAuthTokensData;
use BeeCoded\EFacturaSdk\Services\ApiClients\EFacturaClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * PostgreSQL only: expires_at is `timestamp with time zone` (2024_01_01_000009) and every
 * write carries its offset, so the stored instant does not depend on the session time zone.
 * Run with the app time zone (Europe/Bucharest) and the session (America/New_York) apart.
 */
beforeEach(function () {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('expires_at is a time-zone-aware column on PostgreSQL only.');
    }

    $this->previousTimezone = date_default_timezone_get();
    date_default_timezone_set('Europe/Bucharest');
    config(['app.timezone' => 'Europe/Bucharest']);
    // Rolled back with the test's transaction.
    DB::statement("set time zone 'America/New_York'");
});

afterEach(function () {
    if (isset($this->previousTimezone)) {
        date_default_timezone_set($this->previousTimezone);
    }
});

function storedExpiryEpoch(): int
{
    return (int) DB::selectOne('select extract(epoch from expires_at) as epoch from efactura_tokens')->epoch;
}

it('converts naive app-timezone wall times to the same instants, both ways, once', function () {
    $migration = require __DIR__.'/../../database/migrations/2024_01_01_000009_make_efactura_token_expiry_an_instant.php';
    $migration->down();
    DB::table('efactura_tokens')->insert(['cui' => '12345678', 'access_token' => 'a', 'refresh_token' => 'r', 'expires_at' => '2026-11-10 12:00:00', 'is_active' => true]);

    $migration->up();
    $migration->up();

    expect(storedExpiryEpoch())->toBe(Carbon::parse('2026-11-10 12:00:00', 'Europe/Bucharest')->getTimestamp())
        ->and(EfacturaToken::sole()->expires_at->getTimestamp())->toBe(Carbon::parse('2026-11-10 12:00:00', 'Europe/Bucharest')->getTimestamp());

    $migration->down();
    expect(DB::table('efactura_tokens')->value('expires_at'))->toBe('2026-11-10 12:00:00');
    $migration->up();
});

it('writes refreshed expiries as instants, whatever the session time zone', function () {
    $stored = Carbon::parse('2026-11-10 12:00:00', 'Europe/Bucharest');
    DB::table('efactura_tokens')->insert(['cui' => '12345678', 'access_token' => encrypt('old', false), 'refresh_token' => encrypt('old', false), 'expires_at' => '2026-11-10 12:00:00+02:00', 'is_active' => true]);
    $token = EfacturaToken::sole();
    expect($token->expires_at->getTimestamp())->toBe($stored->getTimestamp());

    $refresh = function (Carbon $expiresAt, string $grant) use ($token) {
        $client = Mockery::mock(EFacturaClient::class);
        $client->shouldReceive('wasTokenRefreshed')->andReturn(true);
        $client->shouldReceive('getTokens')->andReturn(new OAuthTokensData(accessToken: $grant, refreshToken: $grant, expiresAt: $expiresAt));
        app(TokenService::class)->handleClientTokenRefresh($client, $token);
    };

    // The expires_at binding carries its offset: a naive one would be read in New York, hours later.
    $refresh($stored->copy()->addHour(), 'fresh');
    expect($token->fresh()->refresh_token)->toBe('fresh')
        ->and(storedExpiryEpoch())->toBe($stored->copy()->addHour()->getTimestamp());
});

it('shows an existing token\'s expiry in the app time zone, whatever the session time zone', function () {
    DB::table('efactura_tokens')->insert(['cui' => '12345674', 'access_token' => encrypt('a', false), 'refresh_token' => encrypt('r', false), 'expires_at' => '2026-11-10 12:00:00+02:00', 'is_active' => true]);

    $this->artisan('efactura:auth', ['cui' => '12345674'])
        ->expectsOutput('Expires: 2026-11-10 12:00:00 EET')
        ->expectsConfirmation('Do you want to generate a new authorization URL anyway?', 'no')
        ->assertExitCode(0);
});
