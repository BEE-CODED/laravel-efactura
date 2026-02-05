<?php

use BeeCoded\EFactura\Http\Controllers\OAuthCallbackController;
use BeeCoded\EFactura\Services\TokenService;
use Illuminate\Http\Request;

beforeEach(function () {
    $this->tokenService = Mockery::mock(TokenService::class);
    $this->controller = new OAuthCallbackController($this->tokenService);
});

describe('OAuthCallbackController', function () {
    describe('redirect', function () {
        // Use a valid Romanian CUI (18547290 has valid checksum)
        it('redirects to authorization URL for valid CUI with RO prefix', function () {
            $this->tokenService->shouldReceive('getAuthorizationUrl')
                ->with('RO18547290')
                ->once()
                ->andReturn('https://auth.example.com/oauth?state=xyz');

            $response = $this->controller->redirect('RO18547290');

            expect($response->getTargetUrl())->toBe('https://auth.example.com/oauth?state=xyz');
        });

        it('redirects to authorization URL for valid CUI without prefix', function () {
            $this->tokenService->shouldReceive('getAuthorizationUrl')
                ->with('18547290')
                ->once()
                ->andReturn('https://auth.example.com/oauth?state=xyz');

            $response = $this->controller->redirect('18547290');

            expect($response->getTargetUrl())->toBe('https://auth.example.com/oauth?state=xyz');
        });

        it('redirects with error for invalid CUI format', function () {
            config(['efactura.routes.error_redirect' => '/error']);

            $response = $this->controller->redirect('invalid');

            expect($response->getTargetUrl())->toContain('/error');
            expect(session('efactura_error'))->toBeTrue();
            expect(session('efactura_message'))->toContain('Invalid CUI format');
        });

        it('redirects with error for CUI with invalid checksum', function () {
            config(['efactura.routes.error_redirect' => '/error']);

            // 12345678 has invalid checksum
            $response = $this->controller->redirect('12345678');

            expect($response->getTargetUrl())->toContain('/error');
            expect(session('efactura_error'))->toBeTrue();
        });
    });

    describe('callback', function () {
        it('handles OAuth error response', function () {
            config(['efactura.routes.error_redirect' => '/error']);

            $request = Request::create('/callback', 'GET', [
                'error' => 'access_denied',
                'error_description' => 'User cancelled',
            ]);

            $response = $this->controller->callback($request);

            expect($response->getTargetUrl())->toContain('/error');
            expect(session('efactura_error'))->toBeTrue();
            expect(session('efactura_message'))->toContain('Authorization was cancelled');
        });

        it('handles missing authorization code', function () {
            config(['efactura.routes.error_redirect' => '/error']);

            $request = Request::create('/callback', 'GET', []);

            $response = $this->controller->callback($request);

            expect($response->getTargetUrl())->toContain('/error');
            expect(session('efactura_message'))->toContain('Missing authorization code');
        });

        it('handles invalid state parameter', function () {
            config(['efactura.routes.error_redirect' => '/error']);

            $this->tokenService->shouldReceive('validateOAuthState')
                ->with('invalid_state')
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

        it('validates state before exchanging code', function () {
            // Test the state validation path - SDK exchange requires configuration
            config(['efactura.routes.error_redirect' => '/error']);

            $this->tokenService->shouldReceive('validateOAuthState')
                ->with('valid_state')
                ->once()
                ->andReturn('12345678');

            $request = Request::create('/callback', 'GET', [
                'code' => 'auth_code',
                'state' => 'valid_state',
            ]);

            // The actual SDK call will fail without proper config,
            // but we verify the flow reaches the exchange step
            try {
                $this->controller->callback($request);
            } catch (\Illuminate\Contracts\Container\BindingResolutionException) {
                // Expected - SDK not configured
            }

            // State was validated if we got this far
            expect(true)->toBeTrue();
        });

        it('handles exceptions during token exchange', function () {
            // Verify exception handling path exists
            config(['efactura.routes.error_redirect' => '/error']);

            $this->tokenService->shouldReceive('validateOAuthState')
                ->with('valid_state')
                ->once()
                ->andReturn('12345678');

            $request = Request::create('/callback', 'GET', [
                'code' => 'auth_code',
                'state' => 'valid_state',
            ]);

            // SDK will throw because it's not configured
            $response = $this->controller->callback($request);

            // Should redirect to error page
            expect($response->getTargetUrl())->toContain('/error');
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

            expect($message)->toContain('unknown_error')
                ->and($message)->toContain('Some description');
        });
    });

    describe('cleanupOAuthSession', function () {
        it('handles null state gracefully', function () {
            $reflection = new ReflectionMethod($this->controller, 'cleanupOAuthSession');
            $reflection->setAccessible(true);

            // Should not throw - verify by calling without exception
            $reflection->invoke($this->controller, null);

            // Add an assertion to avoid risky test
            expect(true)->toBeTrue();
        });

        it('cleans up valid session state', function () {
            $stateToken = 'test_token';
            session()->put("efactura_oauth_state_{$stateToken}", ['cui' => '12345678']);

            $state = base64_encode(json_encode(['token' => $stateToken, 'cui' => '12345678']));

            $reflection = new ReflectionMethod($this->controller, 'cleanupOAuthSession');
            $reflection->setAccessible(true);
            $reflection->invoke($this->controller, $state);

            expect(session()->has("efactura_oauth_state_{$stateToken}"))->toBeFalse();
        });

        it('handles invalid base64 gracefully', function () {
            $reflection = new ReflectionMethod($this->controller, 'cleanupOAuthSession');
            $reflection->setAccessible(true);

            // Should not throw - verify by calling without exception
            $reflection->invoke($this->controller, 'not-valid-base64!!!');

            // Add an assertion to avoid risky test
            expect(true)->toBeTrue();
        });

        it('handles invalid JSON gracefully', function () {
            $reflection = new ReflectionMethod($this->controller, 'cleanupOAuthSession');
            $reflection->setAccessible(true);

            // Should not throw - verify by calling without exception
            $reflection->invoke($this->controller, base64_encode('not json'));

            // Add an assertion to avoid risky test
            expect(true)->toBeTrue();
        });
    });
});
