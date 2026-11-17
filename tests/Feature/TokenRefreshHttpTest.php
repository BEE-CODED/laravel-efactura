<?php

/*
 * This file is part of the Laravel e-Factura package.
 *
 * (c) BEE-CODED <dev.ttl@beecoded.ro>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use BeeCoded\EFactura\Enums\UploadStatus;
use BeeCoded\EFactura\Events\TokenRefreshed;
use BeeCoded\EFactura\Exceptions\RefreshCooldownException;
use BeeCoded\EFactura\Facades\EFactura;
use BeeCoded\EFactura\Models\EfacturaMessage;
use BeeCoded\EFactura\Models\EfacturaToken;
use BeeCoded\EFactura\Models\EfacturaUpload;
use BeeCoded\EFactura\Services\DownloadService;
use BeeCoded\EFactura\Services\TokenService;
use BeeCoded\EFacturaSdk\Contracts\AnafAuthenticatorInterface;
use BeeCoded\EFacturaSdk\Data\Auth\OAuthTokensData;
use BeeCoded\EFacturaSdk\Data\Invoice\ListMessagesParamsData;
use BeeCoded\EFacturaSdk\Data\Response\ListMessagesResponseData;
use BeeCoded\EFacturaSdk\Exceptions\ApiException;
use BeeCoded\EFacturaSdk\Exceptions\AuthenticationException;
use Illuminate\Cache\ArrayLock;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Database\QueryException;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * End-to-end token-refresh tests that exercise the REAL SDK client over a faked
 * HTTP transport.
 *
 * These deliberately do NOT mock TokenService::createClient(). The wrapper's
 * lock and the SDK's own lock both key on "efactura:token_refresh:{cui}", and
 * that collision is only observable when the real SDK code runs. Mocking the
 * client out is what let the self-deadlock ship green.
 */
const OAUTH_TOKEN_URL = 'https://logincert.anaf.ro/anaf-oauth2/v1/token';
const LIST_MESSAGES_URL = 'https://api.anaf.ro/test/FCTEL/rest/listaMesajeFactura*';

beforeEach(function () {
    config([
        'efactura-sdk.sandbox' => true,
        'efactura-sdk.oauth.client_id' => 'test-client-id',
        'efactura-sdk.oauth.client_secret' => 'test-client-secret',
        'efactura-sdk.oauth.redirect_uri' => 'https://app.test/efactura/callback',
    ]);

    $this->tokenService = app(TokenService::class);

    $this->listMessagesBody = [
        'mesaje' => [],
        'serial' => '1234AA456',
        'cui' => '12345678',
        'titlu' => 'Lista Mesaje disponibile din ultimele 60 zile',
    ];
});

/**
 * Token expiring inside BOTH layers' 120s buffer.
 *
 * This is the deterministic trigger: the wrapper's TOKEN_EXPIRY_BUFFER_SECONDS
 * and the SDK's TOKEN_EXPIRY_BUFFER_SECONDS are both 120, so the wrapper takes
 * its locked path exactly when the SDK decides it must refresh.
 */
function makeExpiringToken(): EfacturaToken
{
    return EfacturaToken::create([
        'cui' => '12345678',
        'access_token' => 'OLD_ACCESS',
        'refresh_token' => 'OLD_REFRESH',
        'expires_at' => now()->addSeconds(60),
        'is_active' => true,
    ]);
}

function makeFreshToken(): EfacturaToken
{
    return EfacturaToken::create([
        'cui' => '12345678',
        'access_token' => 'FRESH_ACCESS',
        'refresh_token' => 'FRESH_REFRESH',
        'expires_at' => now()->addHour(),
        'is_active' => true,
    ]);
}

describe('token refresh over the real SDK client', function () {
    it('refreshes an expiring token and completes the operation', function () {
        Event::fake([TokenRefreshed::class]);

        Http::fake([
            OAUTH_TOKEN_URL => Http::response([
                'access_token' => 'NEW_ACCESS',
                'refresh_token' => 'NEW_REFRESH',
                'expires_in' => 3600,
                'token_type' => 'Bearer',
            ]),
            LIST_MESSAGES_URL => Http::response($this->listMessagesBody),
        ]);

        $token = makeExpiringToken();

        $result = $this->tokenService->executeWithClient(
            $token,
            fn ($client) => $client->getMessages(new ListMessagesParamsData(cif: '12345678', days: 60))
        );

        // The operation must actually run and return the SDK's real DTO.
        expect($result)->toBeInstanceOf(ListMessagesResponseData::class);

        // The refreshed credentials must be persisted.
        $token->refresh();
        expect($token->access_token)->toBe('NEW_ACCESS');
        expect($token->refresh_token)->toBe('NEW_REFRESH');
        expect($token->expires_at->isFuture())->toBeTrue();
        expect(now()->diffInMinutes($token->expires_at))->toBeGreaterThan(50);

        Event::assertDispatched(TokenRefreshed::class);
    });

    it('sends the refreshed access token on the API request, not the stale one', function () {
        Http::fake([
            OAUTH_TOKEN_URL => Http::response([
                'access_token' => 'NEW_ACCESS',
                'refresh_token' => 'NEW_REFRESH',
                'expires_in' => 3600,
            ]),
            LIST_MESSAGES_URL => Http::response($this->listMessagesBody),
        ]);

        $this->tokenService->executeWithClient(
            makeExpiringToken(),
            fn ($client) => $client->getMessages(new ListMessagesParamsData(cif: '12345678', days: 60))
        );

        Http::assertSent(fn ($request) => str_contains($request->url(), 'listaMesajeFactura')
            && $request->hasHeader('Authorization', 'Bearer NEW_ACCESS'));
    });

    // A lock that expires mid-request lets another worker spend the same rotating refresh token.
    it('holds the refresh lock past a long SDK timeout', function () {
        config(['efactura-sdk.http.timeout' => '150']);
        $taken = null;
        Http::fake([
            OAUTH_TOKEN_URL => function () use (&$taken) {
                $this->travel(125)->seconds();
                $taken = Cache::lock('efactura:token_refresh:12345678', 10)->get();

                return Http::response(['access_token' => 'NEW_ACCESS', 'refresh_token' => 'NEW_REFRESH', 'expires_in' => 3600]);
            },
            LIST_MESSAGES_URL => Http::response($this->listMessagesBody),
        ]);

        $this->tokenService->executeWithClient(
            makeExpiringToken(),
            fn ($client) => $client->getMessages(new ListMessagesParamsData(cif: '12345678', days: 60))
        );

        expect($taken)->toBeFalse();
    });

    it('refreshes exactly once for a single expiring-token operation', function () {
        Http::fake([
            OAUTH_TOKEN_URL => Http::response([
                'access_token' => 'NEW_ACCESS',
                'refresh_token' => 'NEW_REFRESH',
                'expires_in' => 3600,
            ]),
            LIST_MESSAGES_URL => Http::response($this->listMessagesBody),
        ]);

        $this->tokenService->executeWithClient(
            makeExpiringToken(),
            fn ($client) => $client->getMessages(new ListMessagesParamsData(cif: '12345678', days: 60))
        );

        // ANAF rotates refresh tokens: a double refresh would invalidate the good one.
        Http::assertSentCount(2); // 1 token refresh + 1 API call
    });

    it('does not refresh a fresh token and still runs the operation', function () {
        Event::fake([TokenRefreshed::class]);

        Http::fake([
            OAUTH_TOKEN_URL => Http::response([], 500), // must never be hit
            LIST_MESSAGES_URL => Http::response($this->listMessagesBody),
        ]);

        $token = makeFreshToken();

        $result = $this->tokenService->executeWithClient(
            $token,
            fn ($client) => $client->getMessages(new ListMessagesParamsData(cif: '12345678', days: 60))
        );

        expect($result)->toBeInstanceOf(ListMessagesResponseData::class);

        $token->refresh();
        expect($token->access_token)->toBe('FRESH_ACCESS');
        expect($token->last_used_at)->not->toBeNull();

        Event::assertNotDispatched(TokenRefreshed::class);
        Http::assertSentCount(1);
    });

    it('still updates last_used_at after refreshing an expiring token', function () {
        Http::fake([
            OAUTH_TOKEN_URL => Http::response([
                'access_token' => 'NEW_ACCESS',
                'refresh_token' => 'NEW_REFRESH',
                'expires_in' => 3600,
            ]),
            LIST_MESSAGES_URL => Http::response($this->listMessagesBody),
        ]);

        $token = makeExpiringToken();
        expect($token->last_used_at)->toBeNull();

        $this->tokenService->executeWithClient(
            $token,
            fn ($client) => $client->getMessages(new ListMessagesParamsData(cif: '12345678', days: 60))
        );

        $token->refresh();
        expect($token->last_used_at)->not->toBeNull();
    });

    it('throws when the token has been deactivated behind our back', function () {
        Http::fake([
            OAUTH_TOKEN_URL => Http::response([
                'access_token' => 'NEW_ACCESS',
                'refresh_token' => 'NEW_REFRESH',
                'expires_in' => 3600,
            ]),
            LIST_MESSAGES_URL => Http::response($this->listMessagesBody),
        ]);

        $token = makeExpiringToken();

        // Simulate another process deactivating the token before we take the lock.
        EfacturaToken::where('id', $token->id)->update(['is_active' => false]);

        expect(fn () => $this->tokenService->executeWithClient(
            $token,
            fn ($client) => $client->getMessages(new ListMessagesParamsData(cif: '12345678', days: 60))
        ))->toThrow(RuntimeException::class, 'has been deactivated');
    });

    it('takes the fast path when another process already refreshed the token', function () {
        Event::fake([TokenRefreshed::class]);

        Http::fake([
            OAUTH_TOKEN_URL => Http::response([], 500), // must never be hit
            LIST_MESSAGES_URL => Http::response($this->listMessagesBody),
        ]);

        $token = makeExpiringToken();

        // Another process refreshed it after we decided the token looked expiring.
        //
        // Written through a SEPARATE model instance, which is what the other process
        // genuinely holds: TokenService persists via $token->update(), so the write
        // goes through the `encrypted` cast. A query-builder update() would bypass
        // casts (Builder::update() drops straight to toBase()) and store plaintext —
        // a state no production code path can produce. Loading a second instance
        // leaves our $token stale in memory, which is the point of the test.
        EfacturaToken::findOrFail($token->id)->update([
            'access_token' => 'OTHER_PROCESS_ACCESS',
            'refresh_token' => 'OTHER_PROCESS_REFRESH',
            'expires_at' => now()->addHour(),
        ]);

        $result = $this->tokenService->executeWithClient(
            $token,
            fn ($client) => $client->getMessages(new ListMessagesParamsData(cif: '12345678', days: 60))
        );

        expect($result)->toBeInstanceOf(ListMessagesResponseData::class);

        $token->refresh();
        expect($token->access_token)->toBe('OTHER_PROCESS_ACCESS');

        Event::assertNotDispatched(TokenRefreshed::class);
        Http::assertSentCount(1);
    });
});

describe('rotated refresh tokens', function () {
    /**
     * The SDK refreshes with whatever refresh token its client holds in memory.
     * ANAF rotates refresh tokens: spending one invalidates it. So if another
     * worker rotated this CUI's token after our client was constructed, our
     * in-memory copy is a spent token — using it both fails AND can invalidate
     * the grant the other worker just obtained, forcing a re-authorisation.
     *
     * The SDK's defence is the `tokenReloader` closure, which it calls under its
     * own lock immediately before refreshing. It is a no-op unless the caller
     * supplies it, and this package is the only consumer of the SDK.
     *
     * Asserting on the wire (what refresh token ANAF actually receives) rather
     * than on the closure keeps this honest: a reloader that is passed but never
     * consulted would still pass a mock-based test.
     */
    it('refreshes with the token another worker rotated, not its own stale copy', function () {
        Http::fake([
            OAUTH_TOKEN_URL => Http::response([
                'access_token' => 'NEW_ACCESS',
                'refresh_token' => 'NEW_REFRESH',
                'expires_in' => 3600,
                'token_type' => 'Bearer',
            ]),
            LIST_MESSAGES_URL => Http::response($this->listMessagesBody),
        ]);

        $token = makeExpiringToken();

        // Build the client BEFORE the rotation, so its in-memory copy is stale -
        // exactly the state a queued job holds when a sibling worker refreshes.
        $client = $this->tokenService->createClient($token);

        // Another worker rotates the grant and persists it.
        EfacturaToken::where('cui', '12345678')->first()->update([
            'access_token' => 'ROTATED_ACCESS',
            'refresh_token' => 'ROTATED_REFRESH',
            'expires_at' => now()->addSeconds(60),
        ]);

        $client->getMessages(new ListMessagesParamsData(cif: '12345678', days: 60));

        Http::assertSent(function ($request) {
            if ($request->url() !== OAUTH_TOKEN_URL) {
                return false;
            }

            expect($request['refresh_token'])->toBe('ROTATED_REFRESH');

            return true;
        });
    });
});

describe('persisting a refresh that raced another worker', function () {
    /**
     * The mirror image of the reloader case, on the write side.
     *
     * Our client refreshed, and between ANAF minting our grant and our save another
     * worker refreshed and persisted a NEWER grant. Writing our copy over theirs
     * resurrects a refresh token ANAF already invalidated when it minted theirs,
     * leaving the DB holding a dead grant that nothing can recover without a fresh
     * OAuth flow. Both saves (the SDK persister's and the one after the call) must
     * fail their compare-and-set on the spent refresh token.
     *
     * Asserted on the stored row, which is the thing that actually matters.
     */
    it('does not overwrite a newer grant persisted by another worker', function () {
        Http::fake([
            OAUTH_TOKEN_URL => Http::response([
                'access_token' => 'OURS_ACCESS',
                'refresh_token' => 'OURS_REFRESH',
                'expires_in' => 1800,
                'token_type' => 'Bearer',
            ]),
            LIST_MESSAGES_URL => Http::response($this->listMessagesBody),
        ]);

        // 121s of life: OUTSIDE the wrapper's 120s buffer, so executeWithClient
        // takes its lock-free path and leaves the refresh to the SDK, whose
        // persister saves the grant mid-call.
        $token = EfacturaToken::create([
            'cui' => '12345678',
            'access_token' => 'OLD_ACCESS',
            'refresh_token' => 'OLD_REFRESH',
            'expires_at' => now()->addSeconds(121),
            'is_active' => true,
        ]);

        // A sibling worker persists a LATER grant just before our first save of the grant runs.
        $sibling = false;
        DB::beforeExecuting(function (string $query) use (&$sibling) {
            // Unquoted names: MySQL and MariaDB quote identifiers with backticks.
            if ($sibling || !str_starts_with($query, 'update') || !str_contains($query, 'efactura_tokens') || !str_contains($query, 'refresh_token')) {
                return;
            }
            $sibling = true;
            EfacturaToken::where('cui', '12345678')->first()->update([
                'access_token' => 'THEIRS_ACCESS',
                'refresh_token' => 'THEIRS_REFRESH',
                'expires_at' => now()->addSeconds(3600),
            ]);
        });

        $this->tokenService->executeWithClient($token, function ($client) {
            // Cross into the SDK's own 120s buffer mid-operation, so it refreshes.
            $this->travel(2)->seconds();

            // Our client refreshes here, minting OURS_*.
            return $client->getMessages(new ListMessagesParamsData(cif: '12345678', days: 60));
        });

        // Our saves name OLD_REFRESH as the spent token; the row holds THEIRS, so it survives.
        $token->refresh();
        expect($sibling)->toBeTrue()
            ->and($token->refresh_token)->toBe('THEIRS_REFRESH')
            ->and($token->access_token)->toBe('THEIRS_ACCESS');
    });
});

/**
 * Topic L2/L6: token bookkeeping never replaces an ANAF call's result, and a grant ANAF
 * rotated is saved without a lock that could time out.
 *
 * Save failures are forced at the database layer (a beforeExecuting hook on the
 * conditional UPDATE), because the save is a query-builder write that fires no model
 * events.
 */
function failTokenSaves(): void
{
    DB::connection()->beforeExecuting(function (string $query) {
        // Only the conditional save: the teardown rollback also updates the credentials.
        if (str_starts_with(strtolower($query), 'update') && str_contains($query, 'refresh_token') && str_contains($query, 'is_active')) {
            throw new RuntimeException('token save failed');
        }
    });
}

/**
 * Every log record written from here on, as [level, message, context].
 */
function captureLogs(): ArrayObject
{
    $logs = new ArrayObject;
    Log::listen(fn (MessageLogged $log) => $logs->append([$log->level, $log->message, $log->context]));

    return $logs;
}

/**
 * A token just outside the wrapper's 120s buffer: the operation runs unlocked, and the
 * test crosses into the SDK's buffer mid-call so the SDK refreshes in memory.
 */
function makeTokenTheSdkWillRefresh(): EfacturaToken
{
    return EfacturaToken::create([
        'cui' => '12345678',
        'access_token' => 'OLD_ACCESS',
        'refresh_token' => 'OLD_REFRESH',
        'expires_at' => now()->addSeconds(121),
        'is_active' => true,
    ]);
}

describe('token bookkeeping after an ANAF call', function () {
    beforeEach(function () {
        config([
            'efactura-sdk.http.retry_times' => 1,
            'efactura-sdk.http.retry_delay' => 0,
        ]);
    });

    it('returns the result and logs critical when a grant the SDK refreshed cannot be saved', function () {
        $logs = captureLogs();
        Http::fake([
            OAUTH_TOKEN_URL => Http::response(['access_token' => 'NEW_ACCESS', 'refresh_token' => 'NEW_REFRESH', 'expires_in' => 3600]),
            LIST_MESSAGES_URL => Http::response($this->listMessagesBody),
        ]);
        failTokenSaves();

        $token = makeTokenTheSdkWillRefresh();

        $result = $this->tokenService->executeWithClient($token, function ($client) {
            $this->travel(2)->seconds();

            return $client->getMessages(new ListMessagesParamsData(cif: '12345678', days: 60));
        });

        expect($result)->toBeInstanceOf(ListMessagesResponseData::class)
            ->and($token->fresh()->refresh_token)->toBe('OLD_REFRESH');
        expect($logs->getArrayCopy())->toContain(['critical', 'EFactura: refreshed token could not be saved; re-authorisation may be needed', ['cui' => '12345678', 'exception' => RuntimeException::class]]);
    });

    it('saves a grant the SDK refreshed during a call that then threw, and rethrows the original', function () {
        Http::fake([
            OAUTH_TOKEN_URL => Http::response(['access_token' => 'NEW_ACCESS', 'refresh_token' => 'NEW_REFRESH', 'expires_in' => 3600]),
            LIST_MESSAGES_URL => Http::response(['eroare' => 'Bad request'], 400),
        ]);

        $token = makeTokenTheSdkWillRefresh();

        expect(fn () => $this->tokenService->executeWithClient($token, function ($client) {
            $this->travel(2)->seconds();

            return $client->getMessages(new ListMessagesParamsData(cif: '12345678', days: 60));
        }))->toThrow(ApiException::class);

        $token->refresh();
        expect($token->refresh_token)->toBe('NEW_REFRESH')
            ->and($token->access_token)->toBe('NEW_ACCESS');
    });

    it('saves a grant the SDK refreshed even while another worker holds the refresh lock', function () {
        Http::fake([
            OAUTH_TOKEN_URL => Http::response(['access_token' => 'NEW_ACCESS', 'refresh_token' => 'NEW_REFRESH', 'expires_in' => 3600]),
            LIST_MESSAGES_URL => Http::response($this->listMessagesBody),
        ]);

        $token = makeTokenTheSdkWillRefresh();
        $siblingLock = Cache::lock('efactura:token_refresh:12345678', 120);

        $this->tokenService->executeWithClient($token, function ($client) use ($siblingLock) {
            $this->travel(2)->seconds();
            $result = $client->getMessages(new ListMessagesParamsData(cif: '12345678', days: 60));

            // A sibling starts its own refresh after ours: a persist that waited on this
            // lock timed out and lost the grant ANAF had already rotated.
            expect($siblingLock->get())->toBeTrue();

            return $result;
        });

        $siblingLock->release();
        expect($token->fresh()->refresh_token)->toBe('NEW_REFRESH');
    });

    /**
     * Saved only after the call, a grant the SDK rotated left the store holding the spent
     * refresh token once the SDK released its lock: a sibling taking the lock reloaded and
     * spent it again. The save must land while the SDK still holds the lock.
     */
    it('stores a grant the SDK refreshed while the SDK still holds the refresh lock', function () {
        Http::fake([
            OAUTH_TOKEN_URL => Http::response(['access_token' => 'NEW_ACCESS', 'refresh_token' => 'NEW_REFRESH', 'expires_in' => 3600]),
            LIST_MESSAGES_URL => Http::response($this->listMessagesBody),
        ]);

        $token = makeTokenTheSdkWillRefresh();
        $lockHeldAtSave = null;

        DB::listen(function ($query) use (&$lockHeldAtSave) {
            if ($lockHeldAtSave === null && str_starts_with(strtolower($query->sql), 'update') && str_contains($query->sql, 'refresh_token')) {
                $probe = Cache::lock('efactura:token_refresh:12345678', 5);
                $lockHeldAtSave = !$probe->get();
                $lockHeldAtSave || $probe->release();
            }
        });

        $storedDuringCall = $this->tokenService->executeWithClient($token, function ($client) use ($token) {
            $this->travel(2)->seconds();
            $client->getMessages(new ListMessagesParamsData(cif: '12345678', days: 60));

            return EfacturaToken::find($token->id)->refresh_token;
        });

        expect($lockHeldAtSave)->toBeTrue()
            ->and($storedDuringCall)->toBe('NEW_REFRESH');
    });

    it('fires TokenRefreshed once for a grant the SDK refreshed on a hand-driven client', function () {
        Event::fake([TokenRefreshed::class]);
        Http::fake([
            OAUTH_TOKEN_URL => Http::response(['access_token' => 'NEW_ACCESS', 'refresh_token' => 'NEW_REFRESH', 'expires_in' => 3600]),
            LIST_MESSAGES_URL => Http::response($this->listMessagesBody),
        ]);

        $client = $this->tokenService->createClient(makeTokenTheSdkWillRefresh());
        $this->travel(2)->seconds();
        $client->getMessages(new ListMessagesParamsData(cif: '12345678', days: 60));

        // The documented hand-driven pattern, after the client already saved under its lock.
        $this->tokenService->handleClientTokenRefresh($client, EfacturaToken::first());

        Event::assertDispatchedTimes(TokenRefreshed::class, 1);
        expect(EfacturaToken::first()->refresh_token)->toBe('NEW_REFRESH');
    });

    it('keeps the result when recording last_used_at fails', function () {
        $logs = captureLogs();
        Http::fake([LIST_MESSAGES_URL => Http::response($this->listMessagesBody)]);
        EfacturaToken::updating(function (EfacturaToken $token) {
            if ($token->isDirty('last_used_at')) {
                throw new RuntimeException('last_used_at save failed');
            }
        });

        $result = $this->tokenService->executeWithClient(
            makeFreshToken(),
            fn ($client) => $client->getMessages(new ListMessagesParamsData(cif: '12345678', days: 60))
        );

        expect($result)->toBeInstanceOf(ListMessagesResponseData::class);
        expect($logs->getArrayCopy())->toContain(['warning', 'EFactura: token bookkeeping after an ANAF call failed', ['cui' => '12345678', 'exception' => RuntimeException::class]])
            ->and(array_column($logs->getArrayCopy(), 0))->not->toContain('critical');
    });

    it('throws a message naming only the CUI, sending nothing, when an explicit refresh cannot be saved', function () {
        $logs = captureLogs();
        Http::fake([
            OAUTH_TOKEN_URL => Http::response(['access_token' => 'NEW_ACCESS', 'refresh_token' => 'NEW_REFRESH', 'expires_in' => 3600]),
            LIST_MESSAGES_URL => Http::response($this->listMessagesBody),
        ]);
        DB::connection()->beforeExecuting(function (string $query) {
            if (str_starts_with(strtolower($query), 'update') && str_contains($query, 'refresh_token') && str_contains($query, 'is_active')) {
                throw new QueryException('testing', $query, ['SECRET_CIPHERTEXT'], new RuntimeException('SQLSTATE[HY000]: database is locked', 5));
            }
        });

        $ran = false;

        try {
            $this->tokenService->executeWithClient(makeExpiringToken(), function () use (&$ran) {
                $ran = true;
            });
            $this->fail('Expected the save failure');
        } catch (RuntimeException $e) {
            expect($e->getMessage())->toBe('EFactura: the token for CUI 12345678 could not be saved')
                ->and($e->getPrevious())->toBeNull();
        }

        expect($ran)->toBeFalse();
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'listaMesajeFactura'));
        expect($logs->getArrayCopy())->toContain(['warning', 'EFactura: the token for CUI could not be saved', ['cui' => '12345678', 'exception' => QueryException::class, 'code' => 5]])
            ->and($logs->getArrayCopy())->toContain(['critical', 'EFactura: refreshed token could not be saved; re-authorisation may be needed', ['cui' => '12345678', 'exception' => RuntimeException::class]])
            ->and(json_encode($logs->getArrayCopy()))->not->toContain('SECRET_CIPHERTEXT');
    });
});

/**
 * A cache store whose locks call $onRelease with their name before releasing (or instead,
 * when it throws), the default store until the test ends.
 */
function useLockReleaseProbe(Closure $onRelease): void
{
    Cache::extend('lock-probe', fn () => Cache::repository(new class($onRelease) extends ArrayStore
    {
        public function __construct(private Closure $onRelease)
        {
            parent::__construct();
        }

        public function lock($name, $seconds = 0, $owner = null)
        {
            return new class($this, $name, $seconds, $owner, $this->onRelease) extends ArrayLock
            {
                public function __construct($store, $name, $seconds, $owner, private Closure $onRelease)
                {
                    parent::__construct($store, $name, $seconds, $owner);
                }

                public function release()
                {
                    ($this->onRelease)($this->name);

                    return parent::release();
                }
            };
        }
    }));
    config(['cache.stores.lock-probe' => ['driver' => 'lock-probe'], 'cache.default' => 'lock-probe']);
}

function reauthorisedGrant(): OAuthTokensData
{
    return new OAuthTokensData(accessToken: 'REAUTH_ACCESS', refreshToken: 'REAUTH_REFRESH', expiresAt: now()->addMinutes(30));
}

describe('one writer for a refreshed grant', function () {
    beforeEach(function () {
        config(['efactura-sdk.http.retry_times' => 1, 'efactura-sdk.http.retry_delay' => 0]);
        Http::fake([
            OAUTH_TOKEN_URL => Http::response(['access_token' => 'NEW_ACCESS', 'refresh_token' => 'NEW_REFRESH', 'expires_in' => 3600]),
            LIST_MESSAGES_URL => Http::response($this->listMessagesBody),
        ]);
    });

    it('keeps a re-authorisation stored after the SDK refreshed during the call', function (bool $persisterFails) {
        $logs = captureLogs();
        if ($persisterFails) {
            $failed = false;
            DB::connection()->beforeExecuting(function (string $query) use (&$failed) {
                if (!$failed && str_starts_with(strtolower($query), 'update') && str_contains($query, 'refresh_token')) {
                    $failed = true;

                    throw new RuntimeException('token save failed');
                }
            });
        }

        $this->tokenService->executeWithClient(makeTokenTheSdkWillRefresh(), function ($client) {
            $this->travel(2)->seconds();
            $result = $client->getMessages(new ListMessagesParamsData(cif: '12345678', days: 60));
            $this->tokenService->storeToken('12345678', reauthorisedGrant());

            return $result;
        });

        expect(EfacturaToken::sole()->refresh_token)->toBe('REAUTH_REFRESH')
            ->and(array_column($logs->getArrayCopy(), 0))->not->toContain('critical');
    })->with(['the persister stored the refresh' => [false], 'the persister failed' => [true]]);

    it('keeps a re-authorisation a hand-driven client finds when saving its refresh', function () {
        Event::fake([TokenRefreshed::class]);

        $client = $this->tokenService->createClient(makeTokenTheSdkWillRefresh());
        $this->travel(2)->seconds();
        $client->getMessages(new ListMessagesParamsData(cif: '12345678', days: 60));
        $this->tokenService->storeToken('12345678', reauthorisedGrant());

        $this->tokenService->handleClientTokenRefresh($client, EFactura::getToken('12345678'));

        expect(EfacturaToken::sole()->refresh_token)->toBe('REAUTH_REFRESH');
        Event::assertDispatchedTimes(TokenRefreshed::class, 1);
    });

    it('writes a grant the SDK refreshed during a call once', function () {
        Event::fake([TokenRefreshed::class]);
        $writes = 0;
        DB::listen(function ($query) use (&$writes) {
            if (str_starts_with(strtolower($query->sql), 'update') && str_contains($query->sql, 'refresh_token')) {
                $writes++;
            }
        });

        $this->tokenService->executeWithClient(makeTokenTheSdkWillRefresh(), function ($client) {
            $this->travel(2)->seconds();

            return $client->getMessages(new ListMessagesParamsData(cif: '12345678', days: 60));
        });

        expect($writes)->toBe(1);
        Event::assertDispatchedTimes(TokenRefreshed::class, 1);
    });

    it('reads the token no more after storing the grant the SDK refreshed', function () {
        Event::fake([TokenRefreshed::class]);
        $armed = true;
        $saved = false;
        DB::connection()->beforeExecuting(function (string $query) use (&$armed, &$saved) {
            $sql = strtolower($query);
            if ($armed && $saved && str_starts_with($sql, 'select') && str_contains($sql, 'efactura_tokens')) {
                throw new RuntimeException('token re-read');
            }
            $saved = $saved || (str_starts_with($sql, 'update') && str_contains($sql, 'refresh_token'));
        });

        $result = $this->tokenService->executeWithClient(makeTokenTheSdkWillRefresh(), function ($client) {
            $this->travel(2)->seconds();

            return $client->getMessages(new ListMessagesParamsData(cif: '12345678', days: 60));
        });
        $armed = false;

        expect($saved)->toBeTrue()
            ->and($result)->toBeInstanceOf(ListMessagesResponseData::class);
        Event::assertDispatchedTimes(TokenRefreshed::class, 1);
    });

    it('refreshes a grant ANAF gave without expires_in hourly, not on every call', function () {
        Http::fake([
            OAUTH_TOKEN_URL => Http::response(['access_token' => 'NEW_ACCESS', 'refresh_token' => 'NEW_REFRESH']),
            LIST_MESSAGES_URL => Http::response($this->listMessagesBody),
        ]);
        $token = makeExpiringToken();
        $list = fn ($client) => $client->getMessages(new ListMessagesParamsData(cif: '12345678', days: 60));

        $this->tokenService->executeWithClient($token, $list);
        $this->tokenService->executeWithClient($token, $list);

        Http::assertSentCount(3);
        expect($token->fresh()->expires_at->diffInSeconds(now()->addHour(), true))->toBeLessThan(5);
    });

    it('runs the operation and stores the grant when releasing the refresh lock fails', function () {
        useLockReleaseProbe(fn () => throw new RuntimeException('lock store down'));
        $token = makeExpiringToken();

        $result = $this->tokenService->executeWithClient($token, fn ($client) => $client->getMessages(new ListMessagesParamsData(cif: '12345678', days: 60)));

        expect($result)->toBeInstanceOf(ListMessagesResponseData::class)
            ->and($token->fresh()->refresh_token)->toBe('NEW_REFRESH');
    });
});

describe('a paused grant', function () {
    beforeEach(function () {
        config(['efactura-sdk.http.retry_times' => 1, 'efactura-sdk.http.retry_delay' => 0]);
    });

    it('is paused for 15 minutes after ANAF refuses a request with 401', function () {
        Http::fake([LIST_MESSAGES_URL => Http::response(['eroare' => 'Unauthorized'], 401)]);
        $token = makeFreshToken();
        $list = fn ($client) => $client->getMessages(new ListMessagesParamsData(cif: '12345678', days: 60));

        expect(fn () => $this->tokenService->executeWithClient($token, $list))
            ->toThrow(fn (AuthenticationException $e) => expect($e)->not->toBeInstanceOf(RefreshCooldownException::class)->and($e->getCode())->toBe(401));
        expect($this->tokenService->refreshCooldownSeconds($token))->toBeGreaterThanOrEqual(890)->toBeLessThanOrEqual(900);

        expect(fn () => $this->tokenService->executeWithClient($token, $list))
            ->toThrow(RefreshCooldownException::class, 'is paused for');
        Http::assertSentCount(1);
    });

    it('is paused for 15 minutes after the token endpoint refuses the refresh with 400', function () {
        Http::fake([OAUTH_TOKEN_URL => Http::response(['error' => 'invalid_grant'], 400)]);
        $token = makeExpiringToken();
        $list = fn ($client) => $client->getMessages(new ListMessagesParamsData(cif: '12345678', days: 60));

        expect(fn () => $this->tokenService->executeWithClient($token, $list))
            ->toThrow(fn (AuthenticationException $e) => expect($e)->not->toBeInstanceOf(RefreshCooldownException::class)->and($e->getCode())->toBe(400));
        expect($this->tokenService->refreshCooldownSeconds($token))->toBeGreaterThanOrEqual(890)->toBeLessThanOrEqual(900);

        // Past a one-minute pause, still inside the 15-minute one.
        $this->travel(61)->seconds();
        expect(fn () => $this->tokenService->executeWithClient($token, $list))
            ->toThrow(RefreshCooldownException::class, 'is paused for');
        Http::assertSentCount(1);
    });

    it('is paused for a minute after the token endpoint fails', function (int $status) {
        Http::fake([OAUTH_TOKEN_URL => Http::response(['error' => 'unavailable'], $status)]);
        $token = makeExpiringToken();
        $list = fn ($client) => $client->getMessages(new ListMessagesParamsData(cif: '12345678', days: 60));

        expect(fn () => $this->tokenService->executeWithClient($token, $list))->toThrow(AuthenticationException::class);
        $sent = count(Http::recorded());
        expect($this->tokenService->refreshCooldownSeconds($token))->toBeGreaterThanOrEqual(1)->toBeLessThanOrEqual(60);

        expect(fn () => $this->tokenService->executeWithClient($token, $list))->toThrow(RefreshCooldownException::class);
        Http::assertSentCount($sent);
    })->with(['429' => [429], '503' => [503]]);

    it('stores the pause before it releases the refresh lock', function () {
        Http::fake([OAUTH_TOKEN_URL => Http::response(['error' => 'slow down'], 429)]);
        $token = makeExpiringToken();
        $pauseKey = "efactura:token_refresh_refused:{$token->id}:".sha1('OLD_REFRESH');
        $atRelease = [];
        useLockReleaseProbe(function (string $name) use (&$atRelease, $pauseKey) {
            $atRelease[$name] = Cache::get($pauseKey);
        });

        expect(fn () => $this->tokenService->executeWithClient($token, fn () => null))->toThrow(AuthenticationException::class);

        expect($atRelease['efactura:token_refresh:12345678'] ?? null)->not->toBeNull();
    });

    it('keeps the refusal when the pause cannot be stored', function () {
        Cache::extend('pause-fails', fn () => Cache::repository(new class extends ArrayStore
        {
            public function put($key, $value, $seconds)
            {
                if (str_starts_with($key, 'efactura:token_refresh_refused:')) {
                    throw new RuntimeException('cache down');
                }

                return parent::put($key, $value, $seconds);
            }
        }));
        config(['cache.stores.pause-fails' => ['driver' => 'pause-fails'], 'cache.default' => 'pause-fails']);
        Http::fake([OAUTH_TOKEN_URL => Http::response(['error' => 'invalid_grant'], 400)]);

        expect(fn () => $this->tokenService->executeWithClient(makeExpiringToken(), fn () => null))
            ->toThrow(fn (AuthenticationException $e) => expect($e->getCode())->toBe(400));
    });
});

/**
 * Topic L3: a TokenRefreshed listener that throws never replaces the result, and the
 * grant is already saved.
 */
describe('a throwing TokenRefreshed listener', function () {
    beforeEach(function () {
        Event::listen(TokenRefreshed::class, function () {
            throw new RuntimeException('listener failed');
        });

        Http::fake([
            OAUTH_TOKEN_URL => Http::response(['access_token' => 'NEW_ACCESS', 'refresh_token' => 'NEW_REFRESH', 'expires_in' => 3600]),
            LIST_MESSAGES_URL => Http::response($this->listMessagesBody),
        ]);
    });

    it('keeps the result of a call during which the SDK refreshed', function () {
        $token = makeTokenTheSdkWillRefresh();

        $result = $this->tokenService->executeWithClient($token, function ($client) {
            $this->travel(2)->seconds();

            return $client->getMessages(new ListMessagesParamsData(cif: '12345678', days: 60));
        });

        expect($result)->toBeInstanceOf(ListMessagesResponseData::class)
            ->and($token->fresh()->refresh_token)->toBe('NEW_REFRESH');
    });

    it('still runs the operation after an explicit refresh', function () {
        $token = makeExpiringToken();

        $result = $this->tokenService->executeWithClient(
            $token,
            fn ($client) => $client->getMessages(new ListMessagesParamsData(cif: '12345678', days: 60))
        );

        expect($result)->toBeInstanceOf(ListMessagesResponseData::class)
            ->and($token->fresh()->refresh_token)->toBe('NEW_REFRESH');
    });
});

describe('a grant ANAF refuses to refresh', function () {
    /**
     * A dead grant was refreshed again for every row of every run: one refused token
     * request per upload, status check, download and sync.
     */
    it('is not sent to ANAF again for the next row in the run', function () {
        Http::fake([
            OAUTH_TOKEN_URL => Http::response(['error' => 'invalid_grant'], 400),
            'https://api.anaf.ro/test/FCTEL/rest/stareMesaj*' => Http::response('<header stare="in prelucrare"/>'),
        ]);

        $token = makeExpiringToken();
        foreach (['5001130255', '5001130256'] as $i => $index) {
            EfacturaUpload::create([
                'efactura_token_id' => $token->id,
                'uploadable_type' => 'App\\Models\\Invoice',
                'uploadable_id' => $i + 1,
                'status' => UploadStatus::Processing,
                'upload_index' => $index,
            ]);
        }

        app(DownloadService::class)->checkAllStatuses($token);

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->url() === OAUTH_TOKEN_URL);
        // Not deactivated: the company re-authorises, and a new grant is tried at once.
        expect($token->fresh()->is_active)->toBeTrue();
    });

    it('is not asked again by a worker that waited on the lock while it was refused', function () {
        Http::fake([OAUTH_TOKEN_URL => Http::response(['error' => 'invalid_grant'], 400)]);
        $token = makeExpiringToken();
        $list = fn ($client) => $client->getMessages(new ListMessagesParamsData(cif: '12345678', days: 60));

        // This worker finds no cooldown and waits on the lock; meanwhile another takes it
        // and is refused.
        $other = true;
        Event::listen(CacheMissed::class, function (CacheMissed $event) use (&$other, $token, $list) {
            if ($other && str_starts_with($event->key, 'efactura:token_refresh_refused:')) {
                $other = false;
                expect(fn () => $this->tokenService->executeWithClient(EfacturaToken::find($token->id), $list))
                    ->toThrow(AuthenticationException::class);
            }
        });

        expect(fn () => $this->tokenService->executeWithClient($token, $list))
            ->toThrow(RefreshCooldownException::class);
        expect($other)->toBeFalse();
        Http::assertSentCount(1);
    });

    // Code 0 is no answer from ANAF (here: missing OAuth configuration): the grant was not
    // refused, so it must not be shut out for 15 minutes.
    it('starts no cooldown for a refresh failure ANAF did not answer', function (Closure $breakIt) {
        Http::fake();
        $token = makeExpiringToken();
        $breakIt($token);

        try {
            $this->tokenService->executeWithClient($token, fn () => null);
            $this->fail('Expected AuthenticationException');
        } catch (AuthenticationException $e) {
            expect($e->getCode())->toBe(0);
        }

        expect($this->tokenService->refreshCooldownSeconds($token->fresh()))->toBe(0);
        Http::assertNothingSent();
    })->with([
        'missing OAuth configuration' => [function () {
            config(['efactura-sdk.oauth.client_id' => '']);
            app()->forgetInstance(AnafAuthenticatorInterface::class);
        }],
    ]);

    it('stops a status run at the refused grant, and the next run before any row', function () {
        Http::fake([OAUTH_TOKEN_URL => Http::response(['error' => 'invalid_grant'], 400)]);
        $token = makeExpiringToken();
        foreach (['5001130255', '5001130256', '5001130257'] as $i => $index) {
            EfacturaUpload::create([
                'efactura_token_id' => $token->id,
                'uploadable_type' => 'App\\Models\\Invoice',
                'uploadable_id' => $i + 1,
                'status' => UploadStatus::Processing,
                'upload_index' => $index,
            ]);
        }
        // Refused: the pause starts.
        expect(app(DownloadService::class)->checkAllStatuses($token))->toBeFalse();

        Log::spy();
        expect(app(DownloadService::class)->checkAllStatuses($token))->toBeFalse();

        Log::shouldNotHaveReceived('error');
        Http::assertSentCount(1);
    });

    it('stops download runs at a paused grant before any row', function () {
        Http::fake();
        $token = makeExpiringToken();
        foreach (['3001130051', '3001130052'] as $i => $id) {
            EfacturaUpload::create(['efactura_token_id' => $token->id, 'uploadable_type' => 'App\\Models\\Invoice', 'uploadable_id' => $i + 1, 'status' => UploadStatus::Completed, 'download_id' => $id, 'processed_at' => now()]);
            EfacturaMessage::create(['efactura_token_id' => $token->id, 'message_id' => "400113000{$i}", 'type' => 'received', 'download_id' => "400113000{$i}", 'is_downloaded' => false]);
        }
        coolDownGrant($token, 600);

        Log::spy();
        expect(app(DownloadService::class)->downloadAllResponses($token))->toBeFalse()
            ->and(app(DownloadService::class)->downloadAllReceivedInvoices($token))->toBeFalse()
            ->and(EfacturaUpload::whereNotNull('next_poll_at')->count())->toBe(0)
            ->and(EfacturaMessage::whereNotNull('next_download_at')->count())->toBe(0);

        Log::shouldNotHaveReceived('error');
        Http::assertNothingSent();
    });

    it('tries a grant re-authorised mid-run although the run still holds the old token', function () {
        Http::fake([
            OAUTH_TOKEN_URL => Http::sequence()
                ->push(['error' => 'invalid_grant'], 400)
                ->push(['access_token' => 'NEW_ACCESS', 'refresh_token' => 'NEW_REFRESH', 'expires_in' => 3600]),
            LIST_MESSAGES_URL => Http::response($this->listMessagesBody),
        ]);
        $token = makeExpiringToken();
        $list = fn ($client) => $client->getMessages(new ListMessagesParamsData(cif: '12345678', days: 60));

        expect(fn () => $this->tokenService->executeWithClient($token, $list))
            ->toThrow(AuthenticationException::class);

        // The company re-authorises elsewhere; this run keeps its in-memory model.
        EfacturaToken::find($token->id)->update(['refresh_token' => 'REAUTHORISED_REFRESH']);

        expect($this->tokenService->executeWithClient($token, $list))->toBeInstanceOf(ListMessagesResponseData::class);
        Http::assertSent(fn ($request) => $request->url() === OAUTH_TOKEN_URL
            && str_contains($request->body(), 'refresh_token=REAUTHORISED_REFRESH'));
    });
});
