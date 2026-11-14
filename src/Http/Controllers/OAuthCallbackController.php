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
use BeeCoded\EFacturaSdk\Facades\EFacturaSdkAuth;
use BeeCoded\EFacturaSdk\Support\Validators\VatNumberValidator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

class OAuthCallbackController extends Controller
{
    public function __construct(
        protected TokenService $tokenService,
    ) {}

    /**
     * Session key of the nonce an authorization started here is bound to.
     */
    private const SESSION_NONCE_KEY = 'efactura_oauth_nonce';

    /**
     * Redirect to ANAF OAuth authorization.
     *
     * The state is bound to this browser session and the logged-in user, so only they can
     * complete it.
     */
    public function redirect(Request $request, string $cui): RedirectResponse
    {
        if (!VatNumberValidator::isValid($cui)) {
            return $this->errorRedirect('Invalid CUI format provided.');
        }

        $authUrl = $this->tokenService->getAuthorizationUrl($cui, $this->sessionNonce($request), $request->user()?->getAuthIdentifier());

        return redirect()->away($authUrl);
    }

    /**
     * Handle the OAuth callback from ANAF.
     */
    public function callback(Request $request): RedirectResponse
    {
        $state = $this->queryString($request, 'state');

        // Check for OAuth error response first
        $error = $this->queryString($request, 'error');

        if ($error) {
            $errorDescription = $this->queryString($request, 'error_description') ?? '';

            report(new \RuntimeException(
                'OAuth authorization failed: '.Str::limit($error, 100).' - '.Str::limit($errorDescription, 300)
            ));

            // Clean up any orphan session state from the state parameter
            $this->cleanupOAuthSession($state);

            $message = $this->getOAuthErrorMessage($error, $errorDescription);

            return $this->errorRedirect($message);
        }

        $code = $this->queryString($request, 'code');

        if (!$code) {
            // Clean up any orphan session state
            $this->cleanupOAuthSession($state);

            return $this->errorRedirect('Missing authorization code from ANAF.');
        }

        // Validate state token (CSRF protection)
        $nonce = $request->hasSession() ? $request->session()->get(self::SESSION_NONCE_KEY) : null;
        $cui = $this->tokenService->validateOAuthState($state, is_string($nonce) ? $nonce : null, $request->user()?->getAuthIdentifier());

        if (!$cui) {
            return $this->errorRedirect('Invalid or expired state parameter. Please try again.');
        }

        try {
            // Exchange code for tokens using SDK
            $tokens = EFacturaSdkAuth::exchangeCodeForToken($code);

            // Store tokens
            $token = $this->tokenService->storeToken($cui, $tokens);

            // The token is stored: a listener failure is reported, never shown as a failed authorisation.
            rescue(fn () => event(new TokenStored($token)));

            return $this->successRedirect($cui);
        } catch (\Exception $e) {
            report($e);

            return $this->errorRedirect('Failed to exchange authorization code: '.$e->getMessage());
        }
    }

    /**
     * The nonce of this browser session, created once; null without a session.
     *
     * One per session rather than per flow, so two tabs authorizing two CUIs both complete.
     */
    protected function sessionNonce(Request $request): ?string
    {
        if (!$request->hasSession()) {
            return null;
        }

        $session = $request->session();

        if (!is_string($nonce = $session->get(self::SESSION_NONCE_KEY))) {
            $session->put(self::SESSION_NONCE_KEY, $nonce = Str::random(40));
        }

        return $nonce;
    }

    /**
     * A query value, or null when absent or not a string (e.g. error[]=1).
     */
    private function queryString(Request $request, string $key): ?string
    {
        $value = $request->get($key);

        return is_string($value) ? $value : null;
    }

    /**
     * Get user-friendly message for OAuth error.
     *
     * Both values come from the callback URL, which anyone can craft: never show them.
     */
    protected function getOAuthErrorMessage(string $error, string $description): string
    {
        return match ($error) {
            'access_denied' => 'Authorization was cancelled. To use e-Factura integration, you must approve access.',
            'invalid_scope' => 'The requested permissions are not available. Please contact support.',
            'temporarily_unavailable' => 'ANAF authorization service is temporarily unavailable. Please try again later.',
            'server_error' => 'ANAF authorization service encountered an error. Please try again.',
            default => 'Authorization failed. Please try again or contact support.',
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
     * Clean up an orphan pending OAuth state.
     *
     * The state lives in the cache rather than the session (the session was never
     * reachable from the console-initiated flow), so cleanup delegates to the service
     * that owns it.
     */
    protected function cleanupOAuthSession(?string $state): void
    {
        $this->tokenService->forgetOAuthState($state);
    }
}
