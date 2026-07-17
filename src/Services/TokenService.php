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
use BeeCoded\EFacturaSdk\Contracts\AnafAuthenticatorInterface;
use BeeCoded\EFacturaSdk\Data\Auth\AuthUrlSettingsData;
use BeeCoded\EFacturaSdk\Data\Auth\OAuthTokensData;
use BeeCoded\EFacturaSdk\Facades\EFacturaSdkAuth;
use BeeCoded\EFacturaSdk\Services\ApiClients\EFacturaClient;
use BeeCoded\EFacturaSdk\Support\Validators\VatNumberValidator;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Collection;
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
     * Cache key prefix for pending OAuth state tokens.
     */
    private const OAUTH_STATE_CACHE_PREFIX = 'efactura:oauth_state:';

    /**
     * How long an issued authorization state stays valid.
     */
    private const OAUTH_STATE_TTL_SECONDS = 900; // 15 minutes

    /**
     * Generate OAuth authorization URL with CUI and CSRF token in state.
     *
     * The state token is recorded in the CACHE, not the session. The session was
     * unusable here: `efactura:auth` runs in a console process that boots no
     * StartSession middleware, so session()->put() wrote to an in-memory store that
     * was never persisted — and even in the web flow the value had to survive a
     * round trip through ANAF and come back on whatever session the browser presents.
     * The documented CLI flow could therefore never complete: validateOAuthState()
     * always found nothing and answered "Invalid or expired state parameter".
     *
     * Cache-backed state is verifiable from any process, which is what both the CLI
     * and web entry points actually need.
     *
     * NOTE: this requires a cache store shared across your web and console processes
     * (redis, memcached, database — anything but `array`). That was already true of
     * the session store this replaces, and is strictly less demanding.
     */
    public function getAuthorizationUrl(string $cui): string
    {
        $cui = VatNumberValidator::stripPrefix($cui);

        // Generate cryptographically secure state token for CSRF protection.
        // 256 bits of entropy: unguessable, so possession of a valid state token is
        // itself the proof that this flow was started by us.
        $stateToken = bin2hex(random_bytes(32));

        Cache::put(
            self::OAUTH_STATE_CACHE_PREFIX.$stateToken,
            [
                'cui' => $cui,
                'created_at' => now()->timestamp,
            ],
            self::OAUTH_STATE_TTL_SECONDS,
        );

        return EFacturaSdkAuth::getAuthorizationUrl(
            new AuthUrlSettingsData(
                state: [
                    'cui' => $cui,
                    'token' => $stateToken,
                ],
            )
        );
    }

    /**
     * Validate OAuth state token and return CUI if valid.
     *
     * Consumes the token: Cache::pull() makes the state strictly single-use, so a
     * replayed callback is rejected even inside the TTL.
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

            if (!is_string($decoded['token']) || !is_string($decoded['cui'])) {
                return null;
            }

            $storedState = Cache::pull(self::OAUTH_STATE_CACHE_PREFIX.$decoded['token']);

            if (!is_array($storedState)) {
                return null;
            }

            // Belt and braces alongside the cache TTL: a store configured without
            // eviction must not resurrect a stale state.
            if (now()->timestamp - $storedState['created_at'] > self::OAUTH_STATE_TTL_SECONDS) {
                return null;
            }

            // The CUI is carried in the (attacker-visible) state parameter, so it must
            // match what we recorded server-side when the flow was started.
            if ($storedState['cui'] !== $decoded['cui']) {
                return null;
            }

            return $decoded['cui'];
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Discard a pending OAuth state without validating it.
     *
     * Used when the callback arrives as an error (or without a code): the flow is
     * over, so the issued state should not linger until its TTL expires.
     */
    public function forgetOAuthState(?string $state): void
    {
        if (!$state) {
            return;
        }

        try {
            $decoded = json_decode(base64_decode($state), true);

            if (is_array($decoded) && isset($decoded['token']) && is_string($decoded['token'])) {
                Cache::forget(self::OAUTH_STATE_CACHE_PREFIX.$decoded['token']);
            }
        } catch (\Exception) {
            // Invalid state format, nothing to clean up.
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
     *
     * The tokenReloader closure is what makes that staleness survivable. ANAF
     * rotates refresh tokens, so spending one invalidates it. When the SDK
     * decides to refresh, it calls this closure under its own lock and re-reads
     * the store first — otherwise a worker holding a copy from before a sibling's
     * refresh would spend an already-spent token, which both fails AND can
     * invalidate the grant the sibling just obtained, forcing the company to
     * re-authorise. The SDK only adopts what we return if it is genuinely
     * fresher, so a client that minted its own tokens is not dragged backwards.
     */
    public function createClient(EfacturaToken $token): EFacturaClient
    {
        return EFacturaClient::fromTokens(
            $token->getVatNumber(),
            $token->toOAuthTokensData(),
            tokenReloader: fn (): ?OAuthTokensData => $this->reloadTokensFromStore($token),
        );
    }

    /**
     * Re-read this token's credentials from the store, or null if it is gone.
     *
     * Reads through the model so the `encrypted` casts apply — the raw columns
     * are ciphertext.
     */
    protected function reloadTokensFromStore(EfacturaToken $token): ?OAuthTokensData
    {
        return EfacturaToken::find($token->getKey())?->toOAuthTokensData();
    }

    /**
     * Is this in-memory grant newer than the one currently stored?
     *
     * Expiry is the only ordering signal ANAF gives us: each refresh mints a
     * token with a later expiry, so a later `expiresAt` means a later refresh.
     * Equal expiries lose deliberately — if two workers refreshed within the
     * same second, whoever already persisted wins, and ours is the spent one.
     *
     * Both sides are compared at whole-second precision, because that is all the
     * stored value has: `expires_at` is a `timestamp` column, so the database
     * truncates the microseconds an in-memory Carbon carries. Comparing raw, our
     * copy is strictly greater than an identical stored expiry for any sub-second
     * remainder — so the tie above would never happen, and this guard would do
     * nothing in the one window it exists for.
     */
    protected function isFresherThanStored(OAuthTokensData $tokens, EfacturaToken $token): bool
    {
        if ($tokens->expiresAt === null || $token->expires_at === null) {
            // Nothing to compare. ANAF always sends expires_in, so this is the
            // pathological case; keep the old always-save behaviour rather than
            // silently dropping a refresh we know succeeded.
            return true;
        }

        return $tokens->expiresAt->clone()->startOfSecond()
            ->greaterThan($token->expires_at->clone()->startOfSecond());
    }

    /**
     * Execute an API operation with concurrent-safe token handling.
     *
     * This method optimizes for parallel processing while preventing token refresh race conditions:
     *
     * 1. If token is NOT expiring soon (> 2 min): proceed without lock (parallel OK)
     * 2. If token IS expiring soon (< 2 min): acquire lock, reload from DB, then:
     *    - If still expiring: refresh it explicitly, then release the lock
     *    - If already refreshed by another process: just release the lock
     *    Either way the operation itself runs AFTER the lock is released.
     * 3. After API call: if SDK unexpectedly refreshed, persist with lock protection
     *
     * This allows parallel API calls when tokens are fresh, while serializing only
     * the refresh itself. The lock is never held across the API operation, because
     * the SDK acquires the same cache key internally and would deadlock against us.
     *
     * @template T
     *
     * @param  EfacturaToken  $token  The token to use
     * @param  callable(EFacturaClient): T  $operation  The API operation to execute
     * @return T The result of the operation
     *
     * @throws LockTimeoutException If the lock cannot be acquired within 30 seconds
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
     * Refresh an expiring token under lock, then run the operation UNLOCKED.
     *
     * The lock protects only the refresh itself, never the operation. This is
     * load-bearing: the SDK's EFacturaClient::getValidAccessToken() acquires the
     * very same cache key ("efactura:token_refresh:{vatNumber}", and cui == vatNumber)
     * whenever it decides a token needs refreshing. Laravel cache locks are NOT
     * re-entrant, so holding the key across $operation() made the SDK block on a lock
     * this same process owned until it timed out and threw
     * AuthenticationException('Token refresh lock timeout').
     *
     * That was deterministic rather than racy: both layers use a 120-second expiry
     * buffer, so the wrapper took the locked path in exactly the cases where the SDK
     * was going to refresh.
     *
     * By refreshing explicitly here and releasing before the call, the SDK sees a
     * valid token, never needs the lock, and never contends with us.
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

        try {
            $lock->block(30); // Wait up to 30 seconds for lock

            // Reload token from DB - another process might have refreshed it
            $token->refresh();

            if (!$token->is_active) {
                throw new \RuntimeException("Token for CUI {$token->cui} has been deactivated");
            }

            // Re-check if token still needs refresh after reload.
            // If another process already refreshed it, there is nothing to do here.
            if ($token->isExpiringSoon(self::TOKEN_EXPIRY_BUFFER_SECONDS)) {
                $this->refreshToken($token);
            }
        } finally {
            // Always release BEFORE running the operation - the SDK needs this key free.
            $lock->release();
        }

        // Token is fresh now; run the operation without holding the shared key.
        return $this->executeWithoutLock($token, $operation);
    }

    /**
     * Refresh a token's credentials via the OAuth refresh flow and persist them.
     *
     * Caller MUST hold the "efactura:token_refresh:{cui}" lock: ANAF rotates refresh
     * tokens, so a concurrent double refresh would invalidate the good one.
     */
    protected function refreshToken(EfacturaToken $token): void
    {
        $newTokens = app(AnafAuthenticatorInterface::class)
            ->refreshAccessToken($token->refresh_token);

        $this->updateTokenFromOAuth($token, $newTokens);

        event(new TokenRefreshed($token));
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

            $refreshed = $client->getTokens();

            // Only adopt our copy if it is genuinely newer than what is stored.
            //
            // This used to save unconditionally, reasoning that a successful
            // refresh means we hold THE valid tokens. That is only true if nobody
            // refreshed after us: we persist AFTER the operation, so a sibling
            // worker can mint and store a newer grant inside that window. ANAF
            // invalidated our refresh token when it issued theirs, so writing
            // ours over theirs leaves the DB holding a dead grant that no retry
            // can recover - the company would have to re-authorise.
            if ($this->isFresherThanStored($refreshed, $token)) {
                $this->updateTokenFromOAuth($token, $refreshed);
                event(new TokenRefreshed($token));
            }

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
     * @return Collection<int, EfacturaToken>
     */
    public function getActiveTokens(): Collection
    {
        return EfacturaToken::active()->get();
    }
}
