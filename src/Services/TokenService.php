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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

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
     * Execute an API operation with concurrent-safe token handling.
     *
     * This method optimizes for parallel processing while preventing token refresh race conditions:
     *
     * 1. If token is NOT expiring soon (> 2 min): proceed without lock (parallel OK)
     * 2. If token IS expiring soon (< 2 min): acquire lock, reload from DB, then:
     *    - If still expiring: refresh it explicitly, then release the lock
     *    - If already refreshed by another process: just release the lock
     *    Either way the operation itself runs AFTER the lock is released.
     * 3. After the API call (also when it threw): if the SDK refreshed, persist the new
     *    grant with a conditional write, then record last_used_at
     *
     * This allows parallel API calls when tokens are fresh, while serializing only
     * the refresh itself. The lock is never held across the API operation, because
     * the SDK acquires the same cache key internally and would deadlock against us.
     *
     * Bookkeeping after the call never replaces the operation's result or exception:
     * its failures are logged (critical when a refreshed grant could not be saved),
     * never thrown.
     *
     * @template T
     *
     * @param  EfacturaToken  $token  The token to use
     * @param  callable(EFacturaClient): T  $operation  The API operation to execute
     * @return T The result of the operation
     *
     * @throws LockTimeoutException If the refresh lock cannot be acquired within 30 seconds
     * @throws \RuntimeException If the token has been deactivated
     * @throws \Throwable Whatever the operation throws, unchanged; or a failed save of a
     *                    token refreshed before the operation (nothing was sent yet)
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

        try {
            return $operation($client);
        } finally {
            // In finally so a refresh the SDK made during a call that then threw is still saved.
            $this->recordClientUse($token, $client);
        }
    }

    /**
     * Save a grant the SDK refreshed during the call, then record last_used_at.
     *
     * Never throws: this runs after ANAF has answered (or failed), and a bookkeeping
     * failure that replaced the result would lose, for example, an upload index ANAF
     * has already assigned. Only the exception class is logged, because a message can
     * carry the ciphertext being written.
     */
    private function recordClientUse(EfacturaToken $token, EFacturaClient $client): void
    {
        $tokenSaved = true;

        try {
            if ($client->wasTokenRefreshed()) {
                $tokenSaved = false;
                $written = $this->saveRefreshedTokens($token, $client->getTokens());
                $tokenSaved = true;

                $token->refresh();

                if ($written) {
                    rescue(fn () => event(new TokenRefreshed($token)));
                }
            }

            $token->update(['last_used_at' => now()]);
        } catch (\Throwable $e) {
            if (!$tokenSaved) {
                Log::critical('EFactura: refreshed token could not be saved; re-authorisation may be needed', [
                    'cui' => $token->cui,
                    'exception' => $e::class,
                ]);

                return;
            }

            Log::warning('EFactura: token bookkeeping after an ANAF call failed', [
                'cui' => $token->cui,
                'exception' => $e::class,
            ]);
        }
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
     *
     * A failed save is rethrown: ANAF has already rotated the grant, so it is logged
     * critical, but nothing has been transmitted yet.
     */
    protected function refreshToken(EfacturaToken $token): void
    {
        $newTokens = app(AnafAuthenticatorInterface::class)
            ->refreshAccessToken($token->refresh_token);

        try {
            $written = $this->saveRefreshedTokens($token, $newTokens);
        } catch (\Throwable $e) {
            Log::critical('EFactura: refreshed token could not be saved; re-authorisation may be needed', [
                'cui' => $token->cui,
                'exception' => $e::class,
            ]);

            throw $e;
        }

        $token->refresh();

        if ($written) {
            rescue(fn () => event(new TokenRefreshed($token)));
        }
    }

    /**
     * Persist a refreshed grant only if it is newer than the stored one.
     *
     * One conditional UPDATE instead of a lock, so a lock timeout can no longer drop a
     * grant ANAF has already rotated. Expiry is the only ordering signal ANAF gives:
     * each refresh mints a later one. The database evaluates the condition against the
     * row as it stands when the write lands, so whichever order concurrent refreshes
     * commit in, the later expiry ends up stored and a stale write matches no row. An
     * equal expiry loses: whoever persisted first wins, and ours is the spent one. The
     * bound value has the column's whole-second precision, so that tie is real.
     *
     * A deactivated token is never written.
     *
     * @return bool Whether this grant was stored.
     */
    protected function saveRefreshedTokens(EfacturaToken $token, OAuthTokensData $tokens): bool
    {
        // Set through a model so the encrypted and datetime casts produce the stored form.
        $attributes = (new EfacturaToken)->forceFill([
            'access_token' => $tokens->accessToken,
            'refresh_token' => $tokens->refreshToken,
            'expires_at' => $tokens->expiresAt,
        ])->getAttributes();

        $query = EfacturaToken::whereKey($token->getKey())->where('is_active', true);

        // ANAF always sends expires_in; without one there is nothing to order by, so the
        // refresh we know succeeded is kept rather than dropped.
        if ($attributes['expires_at'] !== null) {
            $query->where(fn (Builder $stored) => $stored
                ->whereNull('expires_at')
                ->orWhere('expires_at', '<', $attributes['expires_at']));
        }

        return $query->update($attributes) > 0;
    }

    /**
     * Handle token refresh after API call.
     * Call this after using a client to persist any refreshed tokens.
     *
     * For a client you drive yourself (EfacturaManager::client()). executeWithClient()
     * does not call this: it saves a refresh conditionally, see saveRefreshedTokens().
     * This method writes unconditionally and lets its exceptions propagate.
     */
    public function handleClientTokenRefresh(EFacturaClient $client, EfacturaToken $token): void
    {
        if ($client->wasTokenRefreshed()) {
            $newTokens = $client->getTokens();
            $this->updateTokenFromOAuth($token, $newTokens);

            rescue(fn () => event(new TokenRefreshed($token)));
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
