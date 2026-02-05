<?php

/*
 * This file is part of the Laravel e-Factura package.
 *
 * (c) BEE-CODED <dev.ttl@beecoded.ro>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace BeeCoded\EFactura\Services;

use BeeCoded\EFactura\Events\TokenRefreshed;
use BeeCoded\EFactura\Models\EfacturaToken;
use BeeCoded\EFacturaSdk\Data\Auth\OAuthTokensData;
use BeeCoded\EFacturaSdk\Facades\EFactura as EFacturaSdk;
use BeeCoded\EFacturaSdk\Services\ApiClients\EFacturaClient;
use BeeCoded\EFacturaSdk\Support\Validators\VatNumberValidator;
use Illuminate\Support\Facades\Cache;

class TokenService
{
    /**
     * Buffer time (in seconds) before token expiry to trigger locked refresh.
     * If a token expires within this window, operations acquire a lock to safely handle refresh.
     */
    private const TOKEN_EXPIRY_BUFFER_SECONDS = 120; // 2 minutes

    /**
     * Get active token for CUI.
     */
    public function getToken(string $cui): ?EfacturaToken
    {
        $cui = VatNumberValidator::stripPrefix($cui);

        return EfacturaToken::active()->forCui($cui)->first();
    }

    /**
     * Store or update token for CUI.
     */
    public function storeToken(string $cui, OAuthTokensData $tokens): EfacturaToken
    {
        $cui = VatNumberValidator::stripPrefix($cui);

        return EfacturaToken::updateOrCreate(
            ['cui' => $cui],
            [
                'access_token' => $tokens->accessToken,
                'refresh_token' => $tokens->refreshToken,
                'expires_at' => $tokens->expiresAt,
                'is_active' => true,
            ]
        );
    }

    /**
     * Generate OAuth authorization URL with CUI and CSRF token in state.
     */
    public function getAuthorizationUrl(string $cui): string
    {
        $cui = VatNumberValidator::stripPrefix($cui);

        // Generate cryptographically secure state token for CSRF protection
        $stateToken = bin2hex(random_bytes(32));

        // Store state token in session for validation on callback
        session()->put("efactura_oauth_state_{$stateToken}", [
            'cui' => $cui,
            'created_at' => now()->timestamp,
        ]);

        return EFacturaSdk::getAuthorizationUrl(
            new \BeeCoded\EFacturaSdk\Data\Auth\AuthUrlSettingsData(
                state: [
                    'cui' => $cui,
                    'token' => $stateToken,
                ],
            )
        );
    }

    /**
     * Validate OAuth state token and return CUI if valid.
     */
    public function validateOAuthState(?string $state): ?string
    {
        if (!$state) {
            return null;
        }

        try {
            $decoded = json_decode(base64_decode($state), true);

            if (!is_array($decoded) || !isset($decoded['token'], $decoded['cui'])) {
                return null;
            }

            $stateToken = $decoded['token'];
            $sessionKey = "efactura_oauth_state_{$stateToken}";
            $storedState = session()->pull($sessionKey);

            if (!$storedState) {
                return null;
            }

            // Check if state has expired (15 minutes)
            if (now()->timestamp - $storedState['created_at'] > 900) {
                return null;
            }

            // Verify CUI matches
            if ($storedState['cui'] !== $decoded['cui']) {
                return null;
            }

            return $decoded['cui'];
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Create an authenticated SDK client for a CUI.
     */
    public function createClientForCui(string $cui): EFacturaClient
    {
        $token = $this->getToken($cui);

        if (!$token) {
            throw new \RuntimeException("No active token found for CUI: {$cui}");
        }

        return $this->createClient($token);
    }

    /**
     * Create an authenticated SDK client from token.
     *
     * WARNING: For concurrent-safe operations, use executeWithClient() instead.
     * This method creates a client with the token's current state, which may be
     * stale if another process refreshed the token.
     */
    public function createClient(EfacturaToken $token): EFacturaClient
    {
        return EFacturaClient::fromTokens(
            $token->getVatNumber(),
            $token->toOAuthTokensData()
        );
    }

    /**
     * Execute an API operation with concurrent-safe token handling.
     *
     * This method optimizes for parallel processing while preventing token refresh race conditions:
     *
     * 1. If token is NOT expiring soon (> 2 min): proceed without lock (parallel OK)
     * 2. If token IS expiring soon (< 2 min): acquire lock, reload from DB, then:
     *    - If still expiring: proceed with lock held (we'll handle refresh)
     *    - If already refreshed by another process: release lock, proceed normally
     * 3. After API call: if SDK unexpectedly refreshed, persist with lock protection
     *
     * This allows parallel API calls when tokens are fresh, while serializing only
     * when refresh is actually needed.
     *
     * @template T
     *
     * @param  EfacturaToken  $token  The token to use
     * @param  callable(EFacturaClient): T  $operation  The API operation to execute
     * @return T The result of the operation
     *
     * @throws \Illuminate\Contracts\Cache\LockTimeoutException If the lock cannot be acquired within 30 seconds
     * @throws \RuntimeException If the token has been deactivated
     */
    public function executeWithClient(EfacturaToken $token, callable $operation): mixed
    {
        // Check if token is expiring soon and needs protected refresh
        if ($token->isExpiringSoon(self::TOKEN_EXPIRY_BUFFER_SECONDS)) {
            return $this->executeWithRefreshLock($token, $operation);
        }

        // Token is fresh - proceed without lock (parallel processing OK)
        return $this->executeWithoutLock($token, $operation);
    }

    /**
     * Execute operation without lock - used when token is fresh.
     *
     * @template T
     *
     * @param  callable(EFacturaClient): T  $operation
     * @return T
     */
    protected function executeWithoutLock(EfacturaToken $token, callable $operation): mixed
    {
        $client = $this->createClient($token);
        $result = $operation($client);

        // Handle any refresh that occurred during the operation
        if ($client->wasTokenRefreshed()) {
            // Edge case: SDK detected invalid token and refreshed
            // Persist with lock protection to avoid race conditions
            $this->persistTokenRefreshWithLock($token, $client);
        } else {
            $token->update(['last_used_at' => now()]);
        }

        return $result;
    }

    /**
     * Execute operation with lock - used when token refresh is likely needed.
     *
     * @template T
     *
     * @param  callable(EFacturaClient): T  $operation
     * @return T
     */
    protected function executeWithRefreshLock(EfacturaToken $token, callable $operation): mixed
    {
        $lockKey = "efactura:token_refresh:{$token->cui}";
        $lock = Cache::lock($lockKey, 120); // 2 minute lock timeout
        $lockReleased = false;

        try {
            $lock->block(30); // Wait up to 30 seconds for lock

            // Reload token from DB - another process might have refreshed it
            $token->refresh();

            if (!$token->is_active) {
                throw new \RuntimeException("Token for CUI {$token->cui} has been deactivated");
            }

            // Re-check if token still needs refresh after reload
            if (!$token->isExpiringSoon(self::TOKEN_EXPIRY_BUFFER_SECONDS)) {
                // Another process refreshed it - release lock and proceed without it
                $lock->release();
                $lockReleased = true;

                return $this->executeWithoutLock($token, $operation);
            }

            // Token still needs refresh - proceed with lock held
            $client = $this->createClient($token);
            $result = $operation($client);

            // Persist any refresh that occurred
            $this->handleClientTokenRefresh($client, $token);

            return $result;
        } finally {
            // Release lock if still held (may have been released early)
            if (!$lockReleased) {
                $lock->release();
            }
        }
    }

    /**
     * Persist token refresh with lock protection.
     *
     * Used when SDK unexpectedly refreshed during a non-locked operation.
     * Acquires lock, reloads token, and only updates if still needed.
     */
    protected function persistTokenRefreshWithLock(EfacturaToken $token, EFacturaClient $client): void
    {
        $lockKey = "efactura:token_refresh:{$token->cui}";
        $lock = Cache::lock($lockKey, 30);

        try {
            $lock->block(10);

            // Reload token to check it's still active
            $token->refresh();

            if (!$token->is_active) {
                // Token was deactivated - don't update it with new credentials
                return;
            }

            // Always save - if SDK refresh succeeded, we have the valid tokens
            // (ANAF's rotating refresh tokens invalidate old ones, so a successful
            // refresh means these are THE valid tokens)
            $this->updateTokenFromOAuth($token, $client->getTokens());
            event(new TokenRefreshed($token));

            $token->update(['last_used_at' => now()]);
        } finally {
            $lock->release();
        }
    }

    /**
     * Handle token refresh after API call.
     * Call this after using a client to persist any refreshed tokens.
     *
     * Note: When using executeWithClient(), this is called automatically.
     */
    public function handleClientTokenRefresh(EFacturaClient $client, EfacturaToken $token): void
    {
        if ($client->wasTokenRefreshed()) {
            $newTokens = $client->getTokens();
            $this->updateTokenFromOAuth($token, $newTokens);

            event(new TokenRefreshed($token));
        }

        $token->update(['last_used_at' => now()]);
    }

    /**
     * Update token with OAuth tokens data.
     */
    public function updateTokenFromOAuth(EfacturaToken $token, OAuthTokensData $tokens): void
    {
        $token->update([
            'access_token' => $tokens->accessToken,
            'refresh_token' => $tokens->refreshToken,
            'expires_at' => $tokens->expiresAt,
        ]);
    }

    /**
     * Deactivate token for CUI.
     */
    public function deactivateToken(string $cui): void
    {
        $cui = VatNumberValidator::stripPrefix($cui);

        EfacturaToken::forCui($cui)->update(['is_active' => false]);
    }

    /**
     * Get all active tokens.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, EfacturaToken>
     */
    public function getActiveTokens(): \Illuminate\Database\Eloquent\Collection
    {
        return EfacturaToken::active()->get();
    }
}
