<?php

/*
 * This file is part of the Laravel e-Factura package.
 *
 * (c) BEE-CODED <dev.ttl@beecoded.ro>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace BeeCoded\EFactura\Http\Controllers;

use BeeCoded\EFactura\Events\TokenStored;
use BeeCoded\EFactura\Services\TokenService;
use BeeCoded\EFacturaSdk\Facades\EFactura as EFacturaSdk;
use BeeCoded\EFacturaSdk\Support\Validators\VatNumberValidator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class OAuthCallbackController extends Controller
{
    public function __construct(
        protected TokenService $tokenService,
    ) {}

    /**
     * Redirect to ANAF OAuth authorization.
     */
    public function redirect(string $cui): RedirectResponse
    {
        if (!VatNumberValidator::isValid($cui)) {
            return $this->errorRedirect('Invalid CUI format provided.');
        }

        $authUrl = $this->tokenService->getAuthorizationUrl($cui);

        return redirect()->away($authUrl);
    }

    /**
     * Handle the OAuth callback from ANAF.
     */
    public function callback(Request $request): RedirectResponse
    {
        // Check for OAuth error response first
        $error = $request->get('error');

        if ($error) {
            $errorDescription = $request->get('error_description', '');

            report(new \RuntimeException(
                "OAuth authorization failed: {$error} - {$errorDescription}"
            ));

            // Clean up any orphan session state from the state parameter
            $this->cleanupOAuthSession($request->get('state'));

            $message = $this->getOAuthErrorMessage($error, $errorDescription);

            return $this->errorRedirect($message);
        }

        $code = $request->get('code');
        $state = $request->get('state');

        if (!$code) {
            // Clean up any orphan session state
            $this->cleanupOAuthSession($request->get('state'));

            return $this->errorRedirect('Missing authorization code from ANAF.');
        }

        // Validate state token (CSRF protection)
        $cui = $this->tokenService->validateOAuthState($state);

        if (!$cui) {
            return $this->errorRedirect('Invalid or expired state parameter. Please try again.');
        }

        try {
            // Exchange code for tokens using SDK
            $tokens = EFacturaSdk::exchangeCodeForTokens($code);

            // Store tokens
            $token = $this->tokenService->storeToken($cui, $tokens);

            // Fire event
            event(new TokenStored($token));

            return $this->successRedirect($cui);
        } catch (\Exception $e) {
            report($e);

            return $this->errorRedirect('Failed to exchange authorization code: '.$e->getMessage());
        }
    }

    /**
     * Get user-friendly message for OAuth error.
     */
    protected function getOAuthErrorMessage(string $error, string $description): string
    {
        return match ($error) {
            'access_denied' => 'Authorization was cancelled. To use e-Factura integration, you must approve access.',
            'invalid_scope' => 'The requested permissions are not available. Please contact support.',
            'temporarily_unavailable' => 'ANAF authorization service is temporarily unavailable. Please try again later.',
            'server_error' => 'ANAF authorization service encountered an error. Please try again.',
            default => "Authorization failed: {$error}. ".($description ?: 'Please try again or contact support.'),
        };
    }

    /**
     * Redirect on success.
     */
    protected function successRedirect(string $cui): RedirectResponse
    {
        $redirectUrl = config('efactura.routes.success_redirect', '/');

        return redirect($redirectUrl)
            ->with('efactura_success', true)
            ->with('efactura_cui', $cui)
            ->with('efactura_message', "e-Factura authorization successful for CUI: {$cui}");
    }

    /**
     * Redirect on error.
     */
    protected function errorRedirect(string $message): RedirectResponse
    {
        $redirectUrl = config('efactura.routes.error_redirect', '/');

        return redirect($redirectUrl)
            ->with('efactura_error', true)
            ->with('efactura_message', $message);
    }

    /**
     * Clean up orphan OAuth session state.
     */
    protected function cleanupOAuthSession(?string $state): void
    {
        if (!$state) {
            return;
        }

        try {
            $decoded = json_decode(base64_decode($state), true);

            if (is_array($decoded) && isset($decoded['token'])) {
                session()->forget("efactura_oauth_state_{$decoded['token']}");
            }
        } catch (\Exception) {
            // Invalid state format, nothing to clean up
        }
    }
}
