<?php

/*
 * This file is part of the Laravel e-Factura package.
 *
 * (c) BEE-CODED <dev.ttl@beecoded.ro>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use BeeCoded\EFactura\Models\EfacturaToken;
use BeeCoded\EFactura\Services\TokenService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * The OAuth CSRF state must be verifiable WITHOUT the initiating session.
 *
 * The documented CLI flow could never complete: `efactura:auth` stored the state in
 * the console process's session — artisan runs no StartSession middleware, so nothing
 * was ever persisted — and the user's browser then hit /efactura/callback with an
 * entirely different session anyway. validateOAuthState() read the callback request's
 * session, found nothing, and always answered "Invalid or expired state parameter".
 *
 * These tests drive the REAL artisan command and the REAL HTTP callback route, so a
 * regression to session-bound state fails them.
 */
const ANAF_TOKEN_URL = 'https://logincert.anaf.ro/anaf-oauth2/v1/token';

beforeEach(function () {
    config([
        'efactura-sdk.oauth.client_id' => 'test-client-id',
        'efactura-sdk.oauth.client_secret' => 'test-client-secret',
        'efactura-sdk.oauth.redirect_uri' => 'https://app.test/efactura/callback',
    ]);

    $this->tokenService = app(TokenService::class);
});

/**
 * Pull the encoded state parameter back out of an authorization URL.
 */
function stateFromUrl(string $url): string
{
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    return $query['state'];
}

describe('the CLI authorization flow', function () {
    it('produces a state the web callback can actually validate', function () {
        Http::fake([
            ANAF_TOKEN_URL => Http::response([
                'access_token' => 'CLI_ACCESS',
                'refresh_token' => 'CLI_REFRESH',
                'expires_in' => 3600,
            ]),
        ]);

        // The console process: no session middleware, no browser.
        expect(Artisan::call('efactura:auth', ['cui' => '18547290']))->toBe(0);

        $url = collect(explode("\n", Artisan::output()))
            ->first(fn ($line) => str_contains($line, 'anaf-oauth2'));

        expect($url)->not->toBeNull();

        // CRITICAL: model production, not the test process.
        //
        // Testbench runs the console command and the HTTP request against ONE shared
        // in-memory session, so session-stored state appears to survive the hop and
        // the whole flow looks green. In production it cannot: artisan boots no
        // StartSession middleware (nothing is ever persisted), and the user's browser
        // arrives at the callback with an entirely different session anyway.
        //
        // Flushing here reinstates that boundary. Without it, this test passes
        // against the very bug it exists to catch.
        $this->flushSession();

        // The browser: a completely different session from the console's.
        $response = $this->get('/efactura/callback?code=AUTH_CODE&state='.urlencode(stateFromUrl(trim($url))));

        $response->assertSessionHas('efactura_success', true);

        $token = EfacturaToken::where('cui', '18547290')->first();
        expect($token)->not->toBeNull()
            ->and($token->access_token)->toBe('CLI_ACCESS');
    });
});

describe('the web authorization flow', function () {
    it('still completes end to end', function () {
        Http::fake([
            ANAF_TOKEN_URL => Http::response([
                'access_token' => 'WEB_ACCESS',
                'refresh_token' => 'WEB_REFRESH',
                'expires_in' => 3600,
            ]),
        ]);

        $redirect = $this->get('/efactura/auth/18547290');
        $redirect->assertRedirect();

        $state = stateFromUrl($redirect->headers->get('Location'));

        $this->get('/efactura/callback?code=AUTH_CODE&state='.urlencode($state))
            ->assertSessionHas('efactura_success', true);

        expect(EfacturaToken::where('cui', '18547290')->first()->access_token)->toBe('WEB_ACCESS');
    });
});

describe('CSRF protection remains real', function () {
    it('rejects a state that was never issued', function () {
        $forged = base64_encode(json_encode([
            'cui' => '18547290',
            'token' => bin2hex(random_bytes(32)),
        ]));

        expect($this->tokenService->validateOAuthState($forged))->toBeNull();
    });

    it('rejects a state whose CUI was tampered with', function () {
        $url = $this->tokenService->getAuthorizationUrl('18547290');
        $decoded = json_decode(base64_decode(stateFromUrl($url)), true);

        $tampered = base64_encode(json_encode([
            'cui' => '99999999',
            'token' => $decoded['token'],
        ]));

        expect($this->tokenService->validateOAuthState($tampered))->toBeNull();
    });

    it('is single use', function () {
        $state = stateFromUrl($this->tokenService->getAuthorizationUrl('18547290'));

        expect($this->tokenService->validateOAuthState($state))->toBe('18547290');
        expect($this->tokenService->validateOAuthState($state))->toBeNull();
    });

    it('expires', function () {
        $state = stateFromUrl($this->tokenService->getAuthorizationUrl('18547290'));

        $this->travel(16)->minutes();

        expect($this->tokenService->validateOAuthState($state))->toBeNull();
    });

    it('rejects malformed state', function () {
        expect($this->tokenService->validateOAuthState(null))->toBeNull();
        expect($this->tokenService->validateOAuthState('not-valid-base64!!!'))->toBeNull();
        expect($this->tokenService->validateOAuthState(base64_encode('not json')))->toBeNull();
    });

    it('does not leave the issued state behind after a successful callback', function () {
        $state = stateFromUrl($this->tokenService->getAuthorizationUrl('18547290'));
        $decoded = json_decode(base64_decode($state), true);

        $this->tokenService->validateOAuthState($state);

        expect(Cache::has('efactura:oauth_state:'.$decoded['token']))->toBeFalse();
    });
});
