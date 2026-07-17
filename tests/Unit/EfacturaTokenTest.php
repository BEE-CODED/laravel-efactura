<?php

use BeeCoded\EFactura\Models\EfacturaToken;
use BeeCoded\EFacturaSdk\Data\Auth\OAuthTokensData;
use Carbon\Carbon as BaseCarbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\DateFactory;
use Illuminate\Support\Facades\Date;

beforeEach(function () {
    $this->token = EfacturaToken::create([
        'cui' => '12345678',
        'access_token' => 'test_access_token',
        'refresh_token' => 'test_refresh_token',
        'expires_at' => now()->addHour(),
        'is_active' => true,
    ]);
});

describe('EfacturaToken Model', function () {
    it('can be created with fillable attributes', function () {
        expect($this->token)->toBeInstanceOf(EfacturaToken::class);
        $this->assertDatabaseHas('efactura_tokens', ['cui' => '12345678']);
    });

    it('casts expires_at to datetime', function () {
        expect($this->token->expires_at)->toBeInstanceOf(Carbon::class);
    });

    it('casts is_active to boolean', function () {
        expect($this->token->is_active)->toBeBool()->toBeTrue();
    });

    it('casts last_used_at to datetime', function () {
        $this->token->update(['last_used_at' => now()]);
        expect($this->token->fresh()->last_used_at)->toBeInstanceOf(Carbon::class);
    });

    it('hides tokens when serialized', function () {
        $array = $this->token->toArray();
        expect($array)->not->toHaveKey('access_token');
        expect($array)->not->toHaveKey('refresh_token');
    });

    describe('getVatNumber', function () {
        it('returns CUI without RO prefix', function () {
            expect($this->token->getVatNumber())->toBe('12345678');
        });
    });

    describe('isExpired', function () {
        it('returns true when expires_at is null', function () {
            $token = new EfacturaToken(['expires_at' => null]);
            expect($token->isExpired())->toBeTrue();
        });

        it('returns true when token has expired', function () {
            $token = new EfacturaToken(['expires_at' => now()->subMinute()]);
            expect($token->isExpired())->toBeTrue();
        });

        it('returns false when token is valid', function () {
            expect($this->token->isExpired())->toBeFalse();
        });
    });

    describe('isExpiringSoon', function () {
        it('returns true when expires_at is null', function () {
            $token = new EfacturaToken(['expires_at' => null]);
            expect($token->isExpiringSoon())->toBeTrue();
        });

        it('returns true within buffer period', function () {
            $token = new EfacturaToken(['expires_at' => now()->addSeconds(200)]);
            expect($token->isExpiringSoon(300))->toBeTrue();
        });

        it('returns false outside buffer period', function () {
            $token = new EfacturaToken(['expires_at' => now()->addMinutes(10)]);
            expect($token->isExpiringSoon(300))->toBeFalse();
        });

        it('does not mutate expires_at', function () {
            $expiresAt = now()->addHour();
            $originalTimestamp = $expiresAt->timestamp;
            $token = new EfacturaToken(['expires_at' => $expiresAt->copy()]);

            // Call isExpiringSoon multiple times
            $token->isExpiringSoon(300);
            $token->isExpiringSoon(300);

            // The expires_at timestamp should still be the same
            expect($token->expires_at->timestamp)->toBe($originalTimestamp);
        });
    });

    describe('toOAuthTokensData', function () {
        it('returns correct DTO', function () {
            $dto = $this->token->toOAuthTokensData();

            expect($dto)->toBeInstanceOf(OAuthTokensData::class);
            expect($dto->accessToken)->toBe('test_access_token');
            expect($dto->refreshToken)->toBe('test_refresh_token');
            expect($dto->expiresAt->equalTo($this->token->expires_at))->toBeTrue();
        });

        describe('with immutable dates', function () {
            // Date::use() writes a process-global static on DateFactory that Testbench does NOT
            // reset between tests, so it must be undone here or the rest of the suite silently
            // runs on immutable dates. afterEach runs even when the test fails.
            beforeEach(function () {
                Date::use(CarbonImmutable::class);
            });

            afterEach(function () {
                DateFactory::useDefault();
            });

            it('hydrates expires_at as CarbonImmutable', function () {
                // Guards the premise: if this stops being immutable, the tests below prove nothing.
                expect($this->token->fresh()->expires_at)->toBeInstanceOf(CarbonImmutable::class);
            });

            it('builds the DTO without a TypeError', function () {
                // CarbonImmutable is not a Carbon subclass, so before SDK v2.3 this threw
                // TypeError on every upload, download and sync via TokenService.
                $dto = $this->token->fresh()->toOAuthTokensData();

                expect($dto)->toBeInstanceOf(OAuthTokensData::class)
                    ->and($dto->expiresAt)->toBeInstanceOf(BaseCarbon::class)
                    ->and($dto->expiresAt->equalTo($this->token->expires_at))->toBeTrue();
            });
        });
    });

    describe('scopes', function () {
        beforeEach(function () {
            EfacturaToken::create([
                'cui' => '99999999',
                'access_token' => 'inactive',
                'refresh_token' => 'inactive',
                'expires_at' => now()->addHour(),
                'is_active' => false,
            ]);
        });

        it('active scope filters active tokens', function () {
            $tokens = EfacturaToken::active()->get();

            expect($tokens)->toHaveCount(1);
            expect($tokens->first()->cui)->toBe('12345678');
        });

        it('forCui scope filters by CUI', function () {
            $tokens = EfacturaToken::forCui('12345678')->get();

            expect($tokens)->toHaveCount(1);
            expect($tokens->first()->cui)->toBe('12345678');
        });
    });

    describe('relationships', function () {
        it('has uploads relationship', function () {
            expect($this->token->uploads())
                ->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\HasMany::class);
        });

        it('has messages relationship', function () {
            expect($this->token->messages())
                ->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\HasMany::class);
        });
    });
});
