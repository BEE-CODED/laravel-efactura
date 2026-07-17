<?php

/*
 * This file is part of the Laravel e-Factura package.
 *
 * (c) BEE-CODED <dev.ttl@beecoded.ro>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use BeeCoded\EFactura\Events\TokenRefreshed;
use BeeCoded\EFactura\Models\EfacturaToken;
use BeeCoded\EFactura\Services\TokenService;
use BeeCoded\EFacturaSdk\Data\Invoice\ListMessagesParamsData;
use BeeCoded\EFacturaSdk\Data\Response\ListMessagesResponseData;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

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
     * Our client refreshed in memory, but we only persist AFTER the operation
     * returns. In that window another worker can refresh and persist a NEWER
     * grant. Blindly writing our copy over theirs resurrects a refresh token
     * ANAF already invalidated when it minted theirs, leaving the DB holding a
     * dead grant that nothing can recover without a fresh OAuth flow.
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
        // takes its lock-free path and leaves the refresh to the SDK. This is the
        // only way to reach persistTokenRefreshWithLock — the locked path refreshes
        // BEFORE the operation, so the SDK never has to.
        $token = EfacturaToken::create([
            'cui' => '12345678',
            'access_token' => 'OLD_ACCESS',
            'refresh_token' => 'OLD_REFRESH',
            'expires_at' => now()->addSeconds(121),
            'is_active' => true,
        ]);

        $this->tokenService->executeWithClient($token, function ($client) {
            // Cross into the SDK's own 120s buffer mid-operation, so it refreshes.
            $this->travel(2)->seconds();

            // Our client refreshes here, minting OURS_* (expires in 1800s).
            $result = $client->getMessages(new ListMessagesParamsData(cif: '12345678', days: 60));

            // Meanwhile a sibling worker refreshes and persists a LATER grant.
            EfacturaToken::where('cui', '12345678')->first()->update([
                'access_token' => 'THEIRS_ACCESS',
                'refresh_token' => 'THEIRS_REFRESH',
                'expires_at' => now()->addSeconds(3600),
            ]);

            return $result;
        });

        // Ours expires sooner, so theirs must survive.
        $token->refresh();
        expect($token->refresh_token)->toBe('THEIRS_REFRESH')
            ->and($token->access_token)->toBe('THEIRS_ACCESS');
    });
});

describe('token freshness at second precision', function () {
    /**
     * The stored expiry is a `timestamp` column, truncated to whole seconds.
     * An in-memory Carbon carries microseconds. So a naive comparison makes our
     * copy look strictly newer than a sibling's whenever both land in the SAME
     * second — the tie that is supposed to protect the sibling never happens,
     * and the guard silently does nothing in the narrow window it exists for.
     *
     * Both grants expire in the same second here: ours must NOT win.
     */
    it('does not treat sub-second precision as being fresher than a stored tie', function () {
        Http::fake([
            OAUTH_TOKEN_URL => Http::response([
                'access_token' => 'OURS_ACCESS',
                'refresh_token' => 'OURS_REFRESH',
                'expires_in' => 3600,
                'token_type' => 'Bearer',
            ]),
            LIST_MESSAGES_URL => Http::response($this->listMessagesBody),
        ]);

        $token = EfacturaToken::create([
            'cui' => '12345678',
            'access_token' => 'OLD_ACCESS',
            'refresh_token' => 'OLD_REFRESH',
            'expires_at' => now()->addSeconds(121),
            'is_active' => true,
        ]);

        $this->tokenService->executeWithClient($token, function ($client) {
            $this->travel(2)->seconds();

            $result = $client->getMessages(new ListMessagesParamsData(cif: '12345678', days: 60));

            // Sibling's grant expires in the SAME second as ours, and the column
            // truncates its microseconds away.
            EfacturaToken::where('cui', '12345678')->first()->update([
                'access_token' => 'THEIRS_ACCESS',
                'refresh_token' => 'THEIRS_REFRESH',
                'expires_at' => now()->addSeconds(3600),
            ]);

            return $result;
        });

        $token->refresh();
        expect($token->refresh_token)->toBe('THEIRS_REFRESH');
    });
});
