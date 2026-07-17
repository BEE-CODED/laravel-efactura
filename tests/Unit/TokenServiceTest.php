<?php

use BeeCoded\EFactura\Events\TokenRefreshed;
use BeeCoded\EFactura\Models\EfacturaToken;
use BeeCoded\EFactura\Services\TokenService;
use BeeCoded\EFacturaSdk\Data\Auth\OAuthTokensData;
use BeeCoded\EFacturaSdk\Services\ApiClients\EFacturaClient;
use Carbon\Carbon as BaseCarbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\DateFactory;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    $this->tokenService = new TokenService;

    $this->token = EfacturaToken::create([
        'cui' => '12345678',
        'access_token' => 'test_access',
        'refresh_token' => 'test_refresh',
        'expires_at' => now()->addHour(),
        'is_active' => true,
    ]);
});

describe('TokenService', function () {
    describe('getToken', function () {
        it('returns active token for CUI', function () {
            $result = $this->tokenService->getToken('12345678');

            expect($result)->toBeInstanceOf(EfacturaToken::class)
                ->cui->toBe('12345678');
        });

        it('strips RO prefix from CUI', function () {
            $result = $this->tokenService->getToken('RO12345678');

            expect($result)->not->toBeNull()
                ->cui->toBe('12345678');
        });

        it('returns null for non-existent CUI', function () {
            $result = $this->tokenService->getToken('99999999');

            expect($result)->toBeNull();
        });

        it('returns null for inactive token', function () {
            $this->token->update(['is_active' => false]);

            $result = $this->tokenService->getToken('12345678');

            expect($result)->toBeNull();
        });
    });

    describe('storeToken', function () {
        it('creates new token for new CUI', function () {
            $tokens = new OAuthTokensData(
                accessToken: 'new_access',
                refreshToken: 'new_refresh',
                expiresAt: now()->addHour(),
            );

            $result = $this->tokenService->storeToken('99999999', $tokens);

            expect($result)->toBeInstanceOf(EfacturaToken::class)
                ->cui->toBe('99999999')
                ->access_token->toBe('new_access')
                ->refresh_token->toBe('new_refresh')
                ->is_active->toBeTrue();
        });

        it('updates existing token for same CUI', function () {
            $tokens = new OAuthTokensData(
                accessToken: 'updated_access',
                refreshToken: 'updated_refresh',
                expiresAt: now()->addHours(2),
            );

            $result = $this->tokenService->storeToken('12345678', $tokens);

            expect($result->id)->toBe($this->token->id)
                ->and($result->access_token)->toBe('updated_access')
                ->and($result->refresh_token)->toBe('updated_refresh');

            // Should not create a duplicate
            $this->assertDatabaseCount('efactura_tokens', 1);
        });

        it('strips RO prefix when storing', function () {
            $tokens = new OAuthTokensData(
                accessToken: 'test',
                refreshToken: 'test',
                expiresAt: now()->addHour(),
            );

            $result = $this->tokenService->storeToken('RO88888888', $tokens);

            expect($result->cui)->toBe('88888888');
        });
    });

    describe('getAuthorizationUrl', function () {
        it('stores state token in session before calling SDK', function () {
            // We can verify session state was created even if SDK call fails
            try {
                $this->tokenService->getAuthorizationUrl('12345678');
            } catch (\Throwable) {
                // SDK might not be configured, but session should be set
            }

            // Find the state key in session
            $sessionKeys = array_keys(session()->all());
            $stateKey = collect($sessionKeys)->first(fn ($key) => str_starts_with($key, 'efactura_oauth_state_'));

            // Verify state was stored (regardless of SDK availability)
            expect($stateKey)->not->toBeNull();

            $storedState = session()->get($stateKey);
            expect($storedState)->toHaveKey('cui')
                ->and($storedState['cui'])->toBe('12345678')
                ->and($storedState)->toHaveKey('created_at');
        });
    });

    describe('validateOAuthState', function () {
        it('returns null for null state', function () {
            $result = $this->tokenService->validateOAuthState(null);

            expect($result)->toBeNull();
        });

        it('returns null for invalid base64', function () {
            $result = $this->tokenService->validateOAuthState('not-valid-base64!!!');

            expect($result)->toBeNull();
        });

        it('returns null for invalid JSON', function () {
            $result = $this->tokenService->validateOAuthState(base64_encode('not json'));

            expect($result)->toBeNull();
        });

        it('returns null when state token not in session', function () {
            $state = base64_encode(json_encode([
                'cui' => '12345678',
                'token' => 'unknown_token',
            ]));

            $result = $this->tokenService->validateOAuthState($state);

            expect($result)->toBeNull();
        });

        it('returns null for expired state', function () {
            $stateToken = 'test_state_token';
            session()->put("efactura_oauth_state_{$stateToken}", [
                'cui' => '12345678',
                'created_at' => now()->subMinutes(20)->timestamp, // Expired (> 15 min)
            ]);

            $state = base64_encode(json_encode([
                'cui' => '12345678',
                'token' => $stateToken,
            ]));

            $result = $this->tokenService->validateOAuthState($state);

            expect($result)->toBeNull();
        });

        it('returns null for CUI mismatch', function () {
            $stateToken = 'test_state_token';
            session()->put("efactura_oauth_state_{$stateToken}", [
                'cui' => '12345678',
                'created_at' => now()->timestamp,
            ]);

            $state = base64_encode(json_encode([
                'cui' => '99999999', // Different CUI
                'token' => $stateToken,
            ]));

            $result = $this->tokenService->validateOAuthState($state);

            expect($result)->toBeNull();
        });

        it('returns CUI for valid state and removes from session', function () {
            $stateToken = 'valid_state_token';
            session()->put("efactura_oauth_state_{$stateToken}", [
                'cui' => '12345678',
                'created_at' => now()->timestamp,
            ]);

            $state = base64_encode(json_encode([
                'cui' => '12345678',
                'token' => $stateToken,
            ]));

            $result = $this->tokenService->validateOAuthState($state);

            expect($result)->toBe('12345678');
            expect(session()->has("efactura_oauth_state_{$stateToken}"))->toBeFalse();
        });
    });

    describe('createClientForCui', function () {
        it('throws exception when no token found', function () {
            expect(fn () => $this->tokenService->createClientForCui('99999999'))
                ->toThrow(\RuntimeException::class, 'No active token found for CUI: 99999999');
        });

        it('calls createClient when token exists', function () {
            // This test verifies the method finds the token and calls createClient
            // Actual client creation depends on SDK configuration
            try {
                $this->tokenService->createClientForCui('12345678');
                // If we get here, SDK was configured and worked
                expect(true)->toBeTrue();
            } catch (\RuntimeException $e) {
                // If this is "No active token", the test failed
                expect($e->getMessage())->not->toContain('No active token found');
            } catch (\Exception $e) {
                // SDK not configured - but we verified token was found
                // (otherwise RuntimeException would have been thrown)
                expect(true)->toBeTrue();
            }
        });
    });

    describe('createClient with immutable dates', function () {
        // Date::use() sets a process-global static on DateFactory that Testbench does not
        // reset, so it must be undone or the rest of the suite silently runs on immutable
        // dates. afterEach runs even when the test fails.
        beforeEach(function () {
            Date::use(CarbonImmutable::class);
        });

        afterEach(function () {
            DateFactory::useDefault();
        });

        it('creates a client from a token carrying an immutable expires_at', function () {
            // This is the gate every upload, download and sync crosses:
            // executeWithClient -> createClient -> toOAuthTokensData -> EFacturaClient.
            // A CarbonImmutable expires_at threw a TypeError here before SDK v2.3.
            $token = $this->token->fresh();

            expect($token->expires_at)->toBeInstanceOf(CarbonImmutable::class);

            $client = $this->tokenService->createClient($token);

            expect($client)->toBeInstanceOf(EFacturaClient::class)
                ->and($client->getTokens()->expiresAt)->toBeInstanceOf(BaseCarbon::class)
                ->and($client->getTokens()->expiresAt->equalTo($token->expires_at))->toBeTrue();
        });
    });

    describe('updateTokenFromOAuth', function () {
        it('updates token with new OAuth data', function () {
            $newTokens = new OAuthTokensData(
                accessToken: 'new_access',
                refreshToken: 'new_refresh',
                expiresAt: now()->addHours(2),
            );

            $this->tokenService->updateTokenFromOAuth($this->token, $newTokens);

            $this->token->refresh();
            expect($this->token->access_token)->toBe('new_access')
                ->and($this->token->refresh_token)->toBe('new_refresh');
        });
    });

    describe('handleClientTokenRefresh', function () {
        it('updates token and fires event when client was refreshed', function () {
            Event::fake([TokenRefreshed::class]);

            $mockClient = Mockery::mock(EFacturaClient::class);
            $mockClient->shouldReceive('wasTokenRefreshed')->once()->andReturn(true);
            $mockClient->shouldReceive('getTokens')->once()->andReturn(new OAuthTokensData(
                accessToken: 'refreshed_access',
                refreshToken: 'refreshed_refresh',
                expiresAt: now()->addHours(2),
            ));

            $this->tokenService->handleClientTokenRefresh($mockClient, $this->token);

            $this->token->refresh();
            expect($this->token->access_token)->toBe('refreshed_access');
            expect($this->token->last_used_at)->not->toBeNull();

            Event::assertDispatched(TokenRefreshed::class);
        });

        it('only updates last_used_at when client was not refreshed', function () {
            Event::fake([TokenRefreshed::class]);

            $mockClient = Mockery::mock(EFacturaClient::class);
            $mockClient->shouldReceive('wasTokenRefreshed')->once()->andReturn(false);

            $originalAccessToken = $this->token->access_token;

            $this->tokenService->handleClientTokenRefresh($mockClient, $this->token);

            $this->token->refresh();
            expect($this->token->access_token)->toBe($originalAccessToken);
            expect($this->token->last_used_at)->not->toBeNull();

            Event::assertNotDispatched(TokenRefreshed::class);
        });
    });

    describe('deactivateToken', function () {
        it('deactivates token for CUI', function () {
            $this->tokenService->deactivateToken('12345678');

            $this->token->refresh();
            expect($this->token->is_active)->toBeFalse();
        });

        it('deactivates token with RO prefix', function () {
            $this->tokenService->deactivateToken('RO12345678');

            $this->token->refresh();
            expect($this->token->is_active)->toBeFalse();
        });

        it('does nothing for non-existent CUI', function () {
            // Should not throw
            $this->tokenService->deactivateToken('99999999');

            // Original token should remain unchanged
            $this->token->refresh();
            expect($this->token->is_active)->toBeTrue();
        });
    });

    describe('getActiveTokens', function () {
        it('returns only active tokens', function () {
            EfacturaToken::create([
                'cui' => '22222222',
                'access_token' => 'test',
                'refresh_token' => 'test',
                'expires_at' => now()->addHour(),
                'is_active' => true,
            ]);

            EfacturaToken::create([
                'cui' => '33333333',
                'access_token' => 'test',
                'refresh_token' => 'test',
                'expires_at' => now()->addHour(),
                'is_active' => false,
            ]);

            $tokens = $this->tokenService->getActiveTokens();

            expect($tokens)->toHaveCount(2);
            expect($tokens->pluck('cui')->toArray())->toContain('12345678', '22222222');
        });
    });

    describe('executeWithClient', function () {
        it('calls operation with fresh token without acquiring lock', function () {
            // Token expires in 1 hour - well beyond 2 min buffer
            expect($this->token->isExpiringSoon(120))->toBeFalse();

            // Create partial mock to control client creation
            $mockClient = Mockery::mock(EFacturaClient::class);
            $mockClient->shouldReceive('wasTokenRefreshed')->once()->andReturn(false);

            $partialService = Mockery::mock(TokenService::class)->makePartial();
            $partialService->shouldReceive('createClient')
                ->once()
                ->with($this->token)
                ->andReturn($mockClient);

            $result = $partialService->executeWithClient($this->token, fn ($client) => 'operation_result');

            expect($result)->toBe('operation_result');
        });

        it('executes operation and handles SDK token refresh on fresh token', function () {
            Event::fake([TokenRefreshed::class]);

            $newTokens = new OAuthTokensData(
                accessToken: 'sdk_refreshed_access',
                refreshToken: 'sdk_refreshed_refresh',
                expiresAt: now()->addHours(2),
            );

            $mockClient = Mockery::mock(EFacturaClient::class);
            $mockClient->shouldReceive('wasTokenRefreshed')->once()->andReturn(true);
            $mockClient->shouldReceive('getTokens')->once()->andReturn($newTokens);

            $partialService = Mockery::mock(TokenService::class)->makePartial();
            $partialService->shouldReceive('createClient')
                ->once()
                ->andReturn($mockClient);

            $result = $partialService->executeWithClient($this->token, fn ($client) => 'result_with_refresh');

            expect($result)->toBe('result_with_refresh');
            Event::assertDispatched(TokenRefreshed::class);
        });

        it('identifies expiring token correctly', function () {
            // Set token to expire in 1 minute (within 2 min buffer)
            $this->token->update(['expires_at' => now()->addSeconds(60)]);

            expect($this->token->isExpiringSoon(120))->toBeTrue();
        });

        it('throws exception if token is deactivated during lock', function () {
            // Set token to expire soon to trigger lock path
            $this->token->update(['expires_at' => now()->addSeconds(60)]);

            // Deactivate before calling - simulates race condition
            $this->token->update(['is_active' => false]);

            expect(fn () => $this->tokenService->executeWithClient($this->token, fn () => 'test'))
                ->toThrow(\RuntimeException::class, 'Token for CUI 12345678 has been deactivated');
        });

        it('acquires lock and executes when token is expiring', function () {
            // Set token to expire soon to trigger lock path
            $this->token->update(['expires_at' => now()->addSeconds(60)]);

            $mockClient = Mockery::mock(EFacturaClient::class);
            $mockClient->shouldReceive('wasTokenRefreshed')->once()->andReturn(false);

            $partialService = Mockery::mock(TokenService::class)->makePartial();
            $partialService->shouldReceive('createClient')
                ->once()
                ->andReturn($mockClient);

            $result = $partialService->executeWithClient($this->token, fn ($client) => 'locked_result');

            expect($result)->toBe('locked_result');
        });

        it('releases lock early if another process refreshed token', function () {
            // Set token to expire soon to trigger lock path
            $this->token->update(['expires_at' => now()->addSeconds(60)]);

            $mockClient = Mockery::mock(EFacturaClient::class);
            $mockClient->shouldReceive('wasTokenRefreshed')->andReturn(false);

            $partialService = Mockery::mock(TokenService::class)->makePartial();
            $partialService->shouldReceive('createClient')
                ->andReturn($mockClient);

            // First call acquires lock
            $result1 = $partialService->executeWithClient($this->token, function ($client) {
                // Simulate another process refreshing the token
                $this->token->update(['expires_at' => now()->addHours(2)]);

                return 'first_result';
            });

            // Token now fresh - second call should work without waiting for lock
            $result2 = $partialService->executeWithClient($this->token, fn ($client) => 'second_result');

            expect($result1)->toBe('first_result');
            expect($result2)->toBe('second_result');
        });
    });
});
