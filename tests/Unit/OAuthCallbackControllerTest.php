<?php

use BeeCoded\EFactura\Events\TokenStored;
use BeeCoded\EFactura\Http\Controllers\OAuthCallbackController;
use BeeCoded\EFactura\Models\EfacturaToken;
use BeeCoded\EFactura\Services\TokenService;
use BeeCoded\EFacturaSdk\Data\Auth\OAuthTokensData;
use BeeCoded\EFacturaSdk\Exceptions\AuthenticationException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Request as Psr7Request;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->tokenService = Mockery::mock(TokenService::class);
    $this->controller = new OAuthCallbackController($this->tokenService);
});

describe('OAuthCallbackController', function () {
    describe('redirect', function () {
        // Use a valid Romanian CUI (18547290 has valid checksum)
        it('redirects to authorization URL for valid CUI with RO prefix', function () {
            $this->tokenService->shouldReceive('getAuthorizationUrl')
                ->with('RO18547290', null, null)
                ->once()
                ->andReturn('https://auth.example.com/oauth?state=xyz');

            $response = $this->controller->redirect(Request::create('/efactura/auth/RO18547290'), 'RO18547290');

            expect($response->getTargetUrl())->toBe('https://auth.example.com/oauth?state=xyz');
        });

        it('redirects to authorization URL for valid CUI without prefix', function () {
            $this->tokenService->shouldReceive('getAuthorizationUrl')
                ->with('18547290', null, null)
                ->once()
                ->andReturn('https://auth.example.com/oauth?state=xyz');

            $response = $this->controller->redirect(Request::create('/efactura/auth/18547290'), '18547290');

            expect($response->getTargetUrl())->toBe('https://auth.example.com/oauth?state=xyz');
        });

        it('redirects with error for invalid CUI format', function () {
            config(['efactura.routes.error_redirect' => '/error']);

            $response = $this->controller->redirect(Request::create('/efactura/auth/invalid'), 'invalid');

            expect($response->getTargetUrl())->toContain('/error');
            expect(session('efactura_error'))->toBeTrue();
            expect(session('efactura_message'))->toContain('Invalid CUI format');
        });

        it('redirects with error for CUI with invalid checksum', function () {
            config(['efactura.routes.error_redirect' => '/error']);

            // 12345678 has invalid checksum
            $response = $this->controller->redirect(Request::create('/efactura/auth/12345678'), '12345678');

            expect($response->getTargetUrl())->toContain('/error');
            expect(session('efactura_error'))->toBeTrue();
        });

        it('refuses a CUI with a leading zero', function () {
            $this->tokenService->shouldNotReceive('getAuthorizationUrl');

            $response = $this->controller->redirect(Request::create('/efactura/auth/0014399840'), '0014399840');

            expect($response->getTargetUrl())->toContain('/error')
                ->and(session('efactura_message'))->toContain('Invalid CUI format');
        });
    });

    describe('callback', function () {
        it('handles OAuth error response', function () {
            config(['efactura.routes.error_redirect' => '/error']);

            $this->tokenService->shouldReceive('forgetOAuthState')->once();

            $request = Request::create('/callback', 'GET', [
                'error' => 'access_denied',
                'error_description' => 'User cancelled',
            ]);

            $response = $this->controller->callback($request);

            expect($response->getTargetUrl())->toContain('/error');
            expect(session('efactura_error'))->toBeTrue();
            expect(session('efactura_message'))->toContain('Authorization was cancelled');
        });

        it("never shows the callback's own error text", function () {
            config(['efactura.routes.error_redirect' => '/error']);
            $this->tokenService->shouldReceive('forgetOAuthState')->once();

            $this->controller->callback(Request::create('/callback', 'GET', [
                'error' => 'x',
                'error_description' => 'Your access is suspended, call 07xx',
            ]));

            expect(session('efactura_message'))->toBe('Authorization failed. Please try again or contact support.');
        });

        it('treats a non-string query value as absent', function (array $query, ?string $state) {
            config(['efactura.routes.error_redirect' => '/error']);
            $this->tokenService->shouldReceive('forgetOAuthState')->with($state)->once();

            $this->controller->callback(Request::create('/callback', 'GET', $query));

            expect(session('efactura_message'))->toContain('Missing authorization code');
        })->with([
            'error[] and state[]' => [['error' => ['1'], 'state' => ['x']], null],
            'code[]' => [['code' => ['x'], 'state' => 's'], 's'],
        ]);

        it('handles missing authorization code', function () {
            config(['efactura.routes.error_redirect' => '/error']);

            $this->tokenService->shouldReceive('forgetOAuthState')->once();

            $request = Request::create('/callback', 'GET', []);

            $response = $this->controller->callback($request);

            expect($response->getTargetUrl())->toContain('/error');
            expect(session('efactura_message'))->toContain('Missing authorization code');
        });

        it('handles invalid state parameter', function () {
            config(['efactura.routes.error_redirect' => '/error']);

            $this->tokenService->shouldReceive('validateOAuthState')
                ->with('invalid_state', null, null)
                ->once()
                ->andReturn(null);

            $request = Request::create('/callback', 'GET', [
                'code' => 'auth_code',
                'state' => 'invalid_state',
            ]);

            $response = $this->controller->callback($request);

            expect($response->getTargetUrl())->toContain('/error');
            expect(session('efactura_message'))->toContain('Invalid or expired state');
        });

        it('exchanges the code with ANAF after validating state and stores the tokens', function () {
            config(['efactura.routes.success_redirect' => '/success']);
            Event::fake([TokenStored::class]);
            Http::fake(['logincert.anaf.ro/anaf-oauth2/v1/token' => Http::response([
                'access_token' => 'new-access',
                'refresh_token' => 'new-refresh',
                'expires_in' => 3600,
            ])]);

            $this->tokenService->shouldReceive('validateOAuthState')
                ->with('valid_state', null, null)
                ->once()
                ->andReturn('12345678');
            $this->tokenService->shouldReceive('storeToken')
                ->once()
                ->withArgs(fn (string $cui, OAuthTokensData $tokens) => $cui === '12345678'
                    && $tokens->accessToken === 'new-access'
                    && $tokens->refreshToken === 'new-refresh')
                ->andReturn(new EfacturaToken);

            $request = Request::create('/callback', 'GET', [
                'code' => 'auth_code',
                'state' => 'valid_state',
            ]);

            $response = $this->controller->callback($request);

            expect($response->getTargetUrl())->toContain('/success')
                ->and(session('efactura_success'))->toBeTrue();
            Http::assertSent(fn ($request) => $request->url() === 'https://logincert.anaf.ro/anaf-oauth2/v1/token'
                && $request['grant_type'] === 'authorization_code'
                && $request['code'] === 'auth_code');
            Event::assertDispatched(TokenStored::class);
        });

        it('redirects with ANAF\'s error when the token exchange is refused', function () {
            config(['efactura.routes.error_redirect' => '/error']);
            Http::fake(['logincert.anaf.ro/anaf-oauth2/v1/token' => Http::response([
                'error' => 'invalid_grant',
                'error_description' => 'Authorization code expired',
            ], 400)]);

            $this->tokenService->shouldReceive('validateOAuthState')
                ->with('valid_state', null, null)
                ->once()
                ->andReturn('12345678');
            $this->tokenService->shouldNotReceive('storeToken');

            $request = Request::create('/callback', 'GET', [
                'code' => 'auth_code',
                'state' => 'valid_state',
            ]);

            $response = $this->controller->callback($request);

            // The exact ANAF message: a blocked stray request or a missing config would read differently.
            expect($response->getTargetUrl())->toContain('/error')
                ->and(session('efactura_message'))->toBe(
                    'Failed to exchange authorization code: Token exchange failed: invalid_grant: Authorization code expired'
                );
            Http::assertSentCount(1);
        });

        it('keeps the authorization code out of the reported exception\'s trace', function () {
            config(['efactura.routes.error_redirect' => '/error']);
            Exceptions::fake();
            Http::fake(['logincert.anaf.ro/anaf-oauth2/v1/token' => fn ($request) => Create::rejectionFor(
                new ConnectException('cURL error 7: Failed to connect', new Psr7Request('POST', $request->url()))
            )]);
            $this->tokenService->shouldReceive('validateOAuthState')->once()->andReturn('12345678');
            $old = ini_set('zend.exception_ignore_args', '0');

            try {
                $this->controller->callback(Request::create('/callback', 'GET', [
                    'code' => 'secret-auth-code',
                    'state' => 'valid_state',
                ]));
            } finally {
                ini_set('zend.exception_ignore_args', (string) $old);
            }

            $reported = Exceptions::reported();
            expect($reported)->toHaveCount(1)
                ->and($reported[0])->toBeInstanceOf(AuthenticationException::class);
            // Only the reported exception's own trace: a chained ConnectionException's frames hold the form data.
            $found = false;
            $trace = $reported[0]->getTrace();
            array_walk_recursive($trace, function (mixed $value) use (&$found) {
                $found = $found || $value === 'secret-auth-code';
            });
            expect($found)->toBeFalse();
        });

        it('shows a fixed text, never the exception, when storing the grant fails', function () {
            Http::fake(['logincert.anaf.ro/anaf-oauth2/v1/token' => Http::response([
                'access_token' => 'new-access',
                'refresh_token' => 'new-refresh',
                'expires_in' => 3600,
            ])]);
            $this->tokenService->shouldReceive('validateOAuthState')->once()->andReturn('12345678');
            $this->tokenService->shouldReceive('storeToken')->once()->andThrow(new QueryException(
                'testing',
                'insert into "efactura_tokens" ("access_token") values (?)',
                ['SECRET_CIPHERTEXT'],
                new RuntimeException('SQLSTATE[HY000]: database is locked'),
            ));

            $this->controller->callback(Request::create('/callback', 'GET', ['code' => 'auth_code', 'state' => 'valid_state']));

            expect(session('efactura_message'))->toBe('Failed to complete the e-Factura authorization. Please try again.')
                ->not->toContain('SECRET_CIPHERTEXT')
                ->not->toContain('efactura_tokens');
        });
    });

    describe('getOAuthErrorMessage', function () {
        it('returns correct message for access_denied', function () {
            $reflection = new ReflectionMethod($this->controller, 'getOAuthErrorMessage');
            $reflection->setAccessible(true);

            $message = $reflection->invoke($this->controller, 'access_denied', '');

            expect($message)->toContain('Authorization was cancelled');
        });

        it('returns correct message for invalid_scope', function () {
            $reflection = new ReflectionMethod($this->controller, 'getOAuthErrorMessage');
            $reflection->setAccessible(true);

            $message = $reflection->invoke($this->controller, 'invalid_scope', '');

            expect($message)->toContain('requested permissions');
        });

        it('returns correct message for temporarily_unavailable', function () {
            $reflection = new ReflectionMethod($this->controller, 'getOAuthErrorMessage');
            $reflection->setAccessible(true);

            $message = $reflection->invoke($this->controller, 'temporarily_unavailable', '');

            expect($message)->toContain('temporarily unavailable');
        });

        it('returns correct message for server_error', function () {
            $reflection = new ReflectionMethod($this->controller, 'getOAuthErrorMessage');
            $reflection->setAccessible(true);

            $message = $reflection->invoke($this->controller, 'server_error', '');

            expect($message)->toContain('encountered an error');
        });

        it('returns generic message for unknown error', function () {
            $reflection = new ReflectionMethod($this->controller, 'getOAuthErrorMessage');
            $reflection->setAccessible(true);

            $message = $reflection->invoke($this->controller, 'unknown_error', 'Some description');

            expect($message)->toBe('Authorization failed. Please try again or contact support.')
                ->and($message)->not->toContain('Some description')
                ->and($message)->not->toContain('unknown_error');
        });
    });

    /**
     * Orphan-state cleanup now targets the CACHE, because that is where the OAuth
     * state lives (the session was never reachable from the console-initiated flow).
     * The controller delegates to TokenService, which owns the key; these tests
     * assert that delegation, and TokenServiceTest covers the removal itself.
     */
    describe('orphan state cleanup', function () {
        it('discards the pending state when ANAF reports an error', function () {
            config(['efactura.routes.error_redirect' => '/error']);

            $state = base64_encode(json_encode(['token' => 'abc', 'cui' => '12345678']));

            $this->tokenService->shouldReceive('forgetOAuthState')
                ->with($state)
                ->once();

            $this->controller->callback(Request::create('/callback', 'GET', [
                'error' => 'access_denied',
                'state' => $state,
            ]));
        });

        it('discards the pending state when the code is missing', function () {
            config(['efactura.routes.error_redirect' => '/error']);

            $state = base64_encode(json_encode(['token' => 'abc', 'cui' => '12345678']));

            $this->tokenService->shouldReceive('forgetOAuthState')
                ->with($state)
                ->once();

            $this->controller->callback(Request::create('/callback', 'GET', ['state' => $state]));
        });
    });
});
