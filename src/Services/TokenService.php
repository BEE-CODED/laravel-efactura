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
use BeeCoded\EFactura\Exceptions\RefreshCooldownException;
use BeeCoded\EFactura\Models\EfacturaToken;
use BeeCoded\EFacturaSdk\Contracts\AnafAuthenticatorInterface;
use BeeCoded\EFacturaSdk\Data\Auth\AuthUrlSettingsData;
use BeeCoded\EFacturaSdk\Data\Auth\OAuthTokensData;
use BeeCoded\EFacturaSdk\Exceptions\AuthenticationException;
use BeeCoded\EFacturaSdk\Exceptions\ValidationException;
use BeeCoded\EFacturaSdk\Facades\EFacturaSdkAuth;
use BeeCoded\EFacturaSdk\Services\ApiClients\EFacturaClient;
use BeeCoded\EFacturaSdk\Support\Validators\VatNumberValidator;
use Illuminate\Contracts\Cache\LockTimeoutException;
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
     * How long a grant is paused after the token endpoint refused its refresh (HTTP 400/401)
     * or a request made with its access token was refused with 401 (a request answered 400
     * is an ApiException and pauses nothing). Keyed by the refresh token, so a
     * re-authorisation is tried at once; a refused access token whose refresh token still
     * works is paused again every 15 minutes until the company re-authorises.
     */
    private const REFUSED_REFRESH_COOLDOWN_SECONDS = 900; // 15 minutes

    /**
     * How long a grant is paused after the token endpoint answered with any other failure
     * (429, 5xx, a malformed 2xx). No answer at all (code 0) pauses nothing.
     */
    private const FAILED_REFRESH_COOLDOWN_SECONDS = 60;

    /**
     * The token each client from createClient() was built from, so a hand-driven
     * handleClientTokenRefresh() compares against that instance rather than a later read.
     * Nullable: a partial mock skips the constructor.
     *
     * @var \WeakMap<EFacturaClient, EfacturaToken>|null
     */
    private ?\WeakMap $clientTokens = null;

    /**
     * The active token for a CUI, or null (also for a CUI that is not a canonical CUI or CNP).
     */
    public function getToken(string $cui): ?EfacturaToken
    {
        $cui = VatNumberValidator::canonical($cui);

        return $cui === null ? null : EfacturaToken::active()->forCui($cui)->first();
    }

    /**
     * The active token for a CUI, or null while its grant is paused.
     */
    public function usableToken(string $cui): ?EfacturaToken
    {
        return ($token = $this->getToken($cui)) !== null && $this->refreshCooldownSeconds($token) === 0 ? $token : null;
    }

    /**
     * Active tokens whose stored CUI is canonical and whose grant is not paused: the
     * companies a fan-out or a dispatch run works for. A legacy row with a non-canonical
     * CUI is unreachable by every per-CUI path, so it is skipped here too.
     *
     * @return Collection<int, EfacturaToken>
     */
    public function usableTokens(): Collection
    {
        return EfacturaToken::active()->get()
            ->filter(fn (EfacturaToken $token): bool => VatNumberValidator::canonical($token->cui) === $token->cui
                && $this->refreshCooldownSeconds($token) === 0)
            ->values();
    }

    /**
     * Whether a failure of an executeWithClient() operation concerns the company's whole
     * grant rather than the one row: anything thrown before the operation sent its request
     * ($sent false: a deactivated or unreadable token, a malformed CUI, a lock wait, a failed
     * save), and any AuthenticationException (a paused grant, a refused or failed refresh, a
     * request 401, an SDK lock timeout). A rate limit or an ApiException after sending is not.
     */
    public function isGrantFailure(\Throwable $e, bool $sent): bool
    {
        return !$sent || $e instanceof AuthenticationException;
    }

    /**
     * Store the grant of an authorisation for a CUI, replacing whatever the row held.
     *
     * One upsert: it neither reads nor decrypts the old credentials, so a row the current
     * APP_KEY cannot read is re-authorised in place. Fires no Eloquent model events.
     *
     * @throws \InvalidArgumentException When the CUI is not a CUI or CNP
     * @throws \RuntimeException When the grant cannot be saved (the message names only the CUI)
     */
    public function storeToken(string $cui, OAuthTokensData $tokens): EfacturaToken
    {
        $cui = self::requireCui($cui);
        $values = $this->storedForm($tokens) + ['cui' => $cui, 'is_active' => true];

        return $this->saving($cui, function () use ($values, $cui): EfacturaToken {
            EfacturaToken::upsert([$values], ['cui']);

            return EfacturaToken::query()->useWritePdo()->forCui($cui)->firstOrFail();
        });
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
     *
     * A web request passes its session nonce and user id: the state is then bound to
     * them, and validateOAuthState() refuses a callback from another session or user.
     * Otherwise whoever got a victim to open the ANAF link would store the victim's
     * grant under a CUI they chose. The `efactura:auth` command passes neither, so its
     * state stays valid in whichever browser opens the link.
     *
     * @throws \InvalidArgumentException When the CUI is not a CUI or CNP
     */
    public function getAuthorizationUrl(string $cui, #[\SensitiveParameter] ?string $sessionNonce = null, int|string|null $userId = null): string
    {
        $cui = self::requireCui($cui);

        // Generate cryptographically secure state token for CSRF protection.
        // 256 bits of entropy: unguessable, so possession of a valid state token is
        // itself the proof that this flow was started by us.
        $stateToken = bin2hex(random_bytes(32));

        Cache::put(
            self::OAUTH_STATE_CACHE_PREFIX.$stateToken,
            [
                'cui' => $cui,
                'created_at' => now()->timestamp,
                'nonce' => $sessionNonce === null ? null : hash('sha256', $sessionNonce),
                'user' => $userId === null ? null : (string) $userId,
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
     * Consumes the state with Cache::pull() (a get then a forget, not atomic). A callback
     * replayed after the first one finished is rejected inside the TTL. Two that arrive at
     * the same instant can both pass, and ANAF then refuses the second exchange of its
     * single-use code. A state bound to a session nonce or user (see getAuthorizationUrl())
     * needs the same ones back.
     */
    public function validateOAuthState(#[\SensitiveParameter] ?string $state, #[\SensitiveParameter] ?string $sessionNonce = null, int|string|null $userId = null): ?string
    {
        if (!$state) {
            return null;
        }

        try {
            $decoded = EFacturaSdkAuth::decodeState($state);

            if (!isset($decoded['token'], $decoded['cui'])) {
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

            $nonce = $storedState['nonce'] ?? null;

            if ($nonce !== null && ($sessionNonce === null || !hash_equals($nonce, hash('sha256', $sessionNonce)))) {
                return null;
            }

            $user = $storedState['user'] ?? null;

            if ($user !== null && $user !== ($userId === null ? null : (string) $userId)) {
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
    public function forgetOAuthState(#[\SensitiveParameter] ?string $state): void
    {
        if (!$state) {
            return;
        }

        try {
            $decoded = EFacturaSdkAuth::decodeState($state);

            if (isset($decoded['token']) && is_string($decoded['token'])) {
                Cache::forget(self::OAUTH_STATE_CACHE_PREFIX.$decoded['token']);
            }
        } catch (\Exception) {
            // Invalid state format, nothing to clean up.
        }
    }

    /**
     * Create an authenticated SDK client for a CUI.
     *
     * @throws \RuntimeException When the CUI has no active token
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
     *
     * The tokenPersister closure is the write side: the SDK calls it with the
     * grant it just minted while it still holds that lock, so the next worker to
     * take the lock reloads the new refresh token instead of spending the old one.
     *
     * Both closures move $token itself forward, and the client is remembered with it,
     * so handleClientTokenRefresh() compares against the refresh token this client spent.
     *
     * @throws ValidationException When the token's CUI is not a canonical CUI or CNP
     */
    public function createClient(EfacturaToken $token): EFacturaClient
    {
        $client = EFacturaClient::fromTokens(
            $token->getVatNumber(),
            $token->toOAuthTokensData(),
            tokenReloader: fn (): ?OAuthTokensData => $this->reloadTokensFromStore($token),
            tokenPersister: fn (OAuthTokensData $tokens) => $this->persistClientRefresh($token, $tokens),
        );

        $this->clientTokens ??= new \WeakMap;
        $this->clientTokens[$client] = $token;

        return $client;
    }

    /**
     * Save a grant the SDK refreshed, from inside the SDK's refresh lock.
     *
     * Never throws, so the call goes on: the save is tried again after it
     * (executeWithClient(), or handleClientTokenRefresh() for a hand-driven client),
     * and that attempt reports a failure.
     */
    protected function persistClientRefresh(EfacturaToken $token, OAuthTokensData $tokens): void
    {
        try {
            if (!$this->writeGrant($token, $tokens)) {
                return;
            }
        } catch (\Throwable $e) {
            Log::warning('EFactura: refreshed token could not be saved under the refresh lock; retried after the call', [
                'cui' => $token->cui,
                'exception' => $e::class,
            ]);

            return;
        }

        rescue(fn () => event(new TokenRefreshed($token)));
    }

    /**
     * Re-read this token's credentials from the primary store, moving $token forward.
     *
     * Model::refresh() reads through the write connection (no replica lag) and the
     * `encrypted` casts. A deleted row throws, which the SDK logs before keeping its own
     * tokens.
     */
    protected function reloadTokensFromStore(EfacturaToken $token): ?OAuthTokensData
    {
        return $token->refresh()->toOAuthTokensData();
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
     * 3. If the SDK refreshes during the call, it saves the grant under its own lock
     *    (createClient()'s tokenPersister). After the call (also when it threw) the same
     *    conditional write is tried again, a no-op once stored, then last_used_at is recorded
     *
     * This allows parallel API calls when tokens are fresh, while serializing only
     * the refresh itself. The lock is never held across the API operation, because
     * the SDK acquires the same cache key internally and would deadlock against us.
     *
     * Bookkeeping after the call never replaces the operation's result or exception:
     * its failures are logged (critical when a refreshed grant could not be saved),
     * never thrown.
     *
     * An AuthenticationException from the refresh or the operation pauses the grant
     * (see refreshCooldownSeconds()): 15 minutes for a 400/401, 60 s for any other
     * answered code, nothing for code 0.
     *
     * @template T
     *
     * @param  EfacturaToken  $token  The token to use
     * @param  callable(EFacturaClient): T  $operation  The API operation to execute
     * @return T The result of the operation
     *
     * @throws LockTimeoutException If the refresh lock cannot be acquired within 30 seconds
     * @throws \RuntimeException If the token has been deactivated, or a grant refreshed
     *                           before the operation cannot be saved (nothing was sent yet;
     *                           the message names only the CUI)
     * @throws RefreshCooldownException If the grant is paused (nothing is sent)
     * @throws ValidationException If the token's CUI is not a canonical CUI or CNP
     * @throws AuthenticationException If the refresh itself fails
     * @throws \Throwable Whatever the operation throws, unchanged
     */
    public function executeWithClient(EfacturaToken $token, callable $operation): mixed
    {
        // Only a paused instance is re-read: a re-authorisation mid-run changes the pause key.
        if ($this->refreshCooldownSeconds($token) > 0) {
            $token->refresh();
        }

        // Every call, not only the refresh path: a deactivated grant must not file or poll.
        if (!$token->is_active) {
            throw new \RuntimeException("Token for CUI {$token->cui} has been deactivated");
        }

        $this->guardRefreshCooldown($token);

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
        } catch (RefreshCooldownException $e) {
            throw $e;
        } catch (AuthenticationException $e) {
            // A request 401, or a refresh the SDK made during the call.
            $this->pauseGrant($token, $e);

            throw $e;
        } finally {
            // In finally so a refresh the SDK made during a call that then threw is still saved.
            $this->recordClientUse($token, $client);
        }
    }

    /**
     * Save a grant the SDK refreshed during the call, then record last_used_at.
     *
     * The tokenPersister has normally stored the grant already and moved $token to it, so
     * nothing is written. It is written here only when the persister's save failed, and
     * then only over the refresh token this client spent (writeGrant()).
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
                $tokens = $client->getTokens();

                if ($token->refresh_token !== $tokens->refreshToken) {
                    $tokenSaved = false;
                    $written = $this->writeGrant($token, $tokens);
                    $tokenSaved = true;

                    if ($written) {
                        rescue(fn () => event(new TokenRefreshed($token)));
                    }
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
     * very same lock (EFacturaClient::tokenRefreshLock(), "efactura:token_refresh:{vatNumber}",
     * the canonical CUI) whenever it decides a token needs refreshing. Laravel cache locks are NOT
     * re-entrant, so holding the key across $operation() made the SDK block on a lock
     * this same process owned until it timed out and threw
     * AuthenticationException('Token refresh lock timeout').
     *
     * That was deterministic rather than racy: both layers use a 120-second expiry
     * buffer, so the wrapper took the locked path in exactly the cases where the SDK
     * was going to refresh. (The SDK's buffer can grow past 120 s with a long timeout and
     * several retries; then the SDK refreshes during the call under its own lock, and a
     * failure there is paused only after that lock is released.)
     *
     * A refused or failed refresh pauses the grant before the lock is released, so a
     * worker that waited on the lock finds the pause.
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
        $lock = EFacturaClient::tokenRefreshLock($token->getVatNumber());

        try {
            $lock->block(30); // Wait up to 30 seconds for lock

            // Reload token from DB - another process might have refreshed it
            $token->refresh();
            // A worker that held the lock while we waited may have been refused: don't ask again.
            $this->guardRefreshCooldown($token);

            if (!$token->is_active) {
                throw new \RuntimeException("Token for CUI {$token->cui} has been deactivated");
            }

            // Re-check if token still needs refresh after reload.
            // If another process already refreshed it, there is nothing to do here.
            if ($token->isExpiringSoon(self::TOKEN_EXPIRY_BUFFER_SECONDS)) {
                try {
                    $this->refreshToken($token);
                } catch (AuthenticationException $e) {
                    // Not deactivated: it may be a passing fault.
                    $this->pauseGrant($token, $e);

                    throw $e;
                }
            }
        } finally {
            // Always release BEFORE running the operation - the SDK needs this key free. A
            // failing store must not replace the outcome; the lock then expires on its TTL.
            rescue(fn () => $lock->release(), report: false);
        }

        // Token is fresh now; run the operation without holding the shared key.
        return $this->executeWithoutLock($token, $operation);
    }

    /**
     * Seconds left of this grant's pause (0 when none). TokenService alone writes it
     * (pauseGrant()) and reads it here.
     *
     * Read from the token as given, without reloading it: a re-authorised grant read stale
     * only waits until the next run.
     */
    public function refreshCooldownSeconds(EfacturaToken $token): int
    {
        try {
            $until = Cache::get($this->refusedRefreshKey($token));
        } catch (\Throwable) {
            // An unreadable token or cache is no cooldown: the call itself reports the failure.
            return 0;
        }

        // Redis hands a stored int back as a numeric string.
        return is_numeric($until) ? max(0, (int) $until - now()->getTimestamp()) : 0;
    }

    /**
     * @throws RefreshCooldownException
     */
    private function guardRefreshCooldown(EfacturaToken $token): void
    {
        if (($seconds = $this->refreshCooldownSeconds($token)) > 0) {
            throw new RefreshCooldownException(
                "EFactura: the grant for CUI {$token->cui} is paused for {$seconds} more seconds after ANAF refused it "
                    .'or failed to refresh it. Re-authorise the company if it keeps failing.',
                401,
            );
        }
    }

    /**
     * Pause the grant after ANAF refused it or its token endpoint failed. Never throws: a
     * cache that cannot store the pause must not replace the refusal.
     */
    private function pauseGrant(EfacturaToken $token, AuthenticationException $e): void
    {
        $seconds = match (true) {
            in_array($e->getCode(), [400, 401], true) => self::REFUSED_REFRESH_COOLDOWN_SECONDS,
            $e->getCode() > 0 => self::FAILED_REFRESH_COOLDOWN_SECONDS,
            default => 0, // no answer from ANAF: nothing was refused
        };

        if ($seconds === 0) {
            return;
        }

        // The value is the expiry, so a waiting job can sleep exactly that long.
        rescue(fn () => Cache::put($this->refusedRefreshKey($token), now()->addSeconds($seconds)->getTimestamp(), $seconds), report: false);
    }

    private function refusedRefreshKey(EfacturaToken $token): string
    {
        return "efactura:token_refresh_refused:{$token->getKey()}:".sha1((string) $token->refresh_token);
    }

    /**
     * Refresh a token's credentials via the OAuth refresh flow and persist them.
     *
     * Caller MUST hold EFacturaClient::tokenRefreshLock() for the token's CUI: ANAF rotates
     * refresh tokens, so a concurrent double refresh would invalidate the good one.
     *
     * A failed save is rethrown: ANAF has already rotated the grant, so it is logged
     * critical, but nothing has been transmitted yet.
     */
    protected function refreshToken(EfacturaToken $token): void
    {
        $newTokens = app(AnafAuthenticatorInterface::class)
            ->refreshAccessToken($token->refresh_token);

        try {
            $written = $this->writeGrant($token, $newTokens);
        } catch (\Throwable $e) {
            Log::critical('EFactura: refreshed token could not be saved; re-authorisation may be needed', [
                'cui' => $token->cui,
                'exception' => $e::class,
            ]);

            throw $e;
        }

        $written ? rescue(fn () => event(new TokenRefreshed($token))) : $token->refresh();
    }

    /**
     * Store a refreshed grant over the refresh token it spent, and move $token to it.
     *
     * One compare-and-set on the stored refresh token (its ciphertext, as $token read it)
     * instead of a lock, so a lock timeout can no longer drop a grant ANAF has already
     * rotated. A row whose refresh token changed meanwhile (a sibling's refresh or a
     * re-authorisation) holds a later grant and is left alone; so is a deactivated one.
     *
     * @return bool Whether this grant was stored.
     *
     * @throws \RuntimeException When the save fails (the message names only the CUI)
     */
    private function writeGrant(EfacturaToken $token, OAuthTokensData $tokens): bool
    {
        $spent = $token->getRawOriginal('refresh_token');

        if (!is_string($spent)) {
            return false;
        }

        $values = $this->storedForm($tokens);

        $written = $this->saving($token->cui, fn (): bool => EfacturaToken::whereKey($token->getKey())
            ->where('is_active', true)
            ->where('refresh_token', $spent)
            ->update($values) > 0);

        if ($written) {
            $token->setRawAttributes(array_merge($token->getAttributes(), $values), true);
        }

        return $written;
    }

    /**
     * The credential columns as stored: set through a model so the encrypted and datetime
     * casts produce them.
     *
     * @return array<string, mixed>
     */
    private function storedForm(OAuthTokensData $tokens): array
    {
        // On PostgreSQL the stored form carries the offset (EfacturaToken::getDateFormat()):
        // never bind a Carbon for expires_at, the query grammar formats it without an offset
        // and the session time zone would decide the instant.
        return (new EfacturaToken)->forceFill([
            'access_token' => $tokens->accessToken,
            'refresh_token' => $tokens->refreshToken,
            // shortcut: ANAF omitted expires_in, refresh hourly instead of on every call
            'expires_at' => $tokens->expiresAt ?? now()->addHour(),
        ])->getAttributes();
    }

    /**
     * Run a credential write. Its exception can carry the ciphertext being written (a
     * QueryException quotes its bindings), so only the class and code are logged and the
     * caller gets a message naming the CUI, without the previous exception.
     *
     * @template T
     *
     * @param  \Closure(): T  $write
     * @return T
     *
     * @throws \RuntimeException
     */
    private function saving(string $cui, \Closure $write): mixed
    {
        try {
            return $write();
        } catch (\Throwable $e) {
            Log::warning('EFactura: the token for CUI could not be saved', [
                'cui' => $cui,
                'exception' => $e::class,
                'code' => $e->getCode(),
            ]);

            throw new \RuntimeException("EFactura: the token for CUI {$cui} could not be saved");
        }
    }

    /**
     * Handle token refresh after API call.
     * Call this after using a client to persist any refreshed tokens.
     *
     * For a client you drive yourself (EfacturaManager::client()); executeWithClient()
     * does not call this. A client from createClient() has normally saved the grant
     * under the SDK's lock already. This compares against the token instance that client
     * was built from (not $token, when it came from createClient()), stores the grant only
     * over the refresh token it spent (never on a deactivated token, never over a
     * re-authorisation) and fires TokenRefreshed only if it stored it. Its exceptions
     * propagate.
     *
     * @throws \RuntimeException When the grant cannot be saved (the message names only the CUI)
     */
    public function handleClientTokenRefresh(EFacturaClient $client, EfacturaToken $token): void
    {
        $token = $this->clientTokens[$client] ?? $token;

        if ($client->wasTokenRefreshed()) {
            $tokens = $client->getTokens();

            if ($token->refresh_token !== $tokens->refreshToken && $this->writeGrant($token, $tokens)) {
                rescue(fn () => event(new TokenRefreshed($token)));
            }
        }

        $token->update(['last_used_at' => now()]);
    }

    /**
     * Deactivate token for CUI. A CUI that is not a CUI or CNP changes nothing.
     */
    public function deactivateToken(string $cui): void
    {
        $cui = VatNumberValidator::canonical($cui);

        if ($cui === null) {
            return;
        }

        EfacturaToken::forCui($cui)->update(['is_active' => false]);
    }

    /**
     * @throws \InvalidArgumentException
     */
    private static function requireCui(string $cui): string
    {
        return VatNumberValidator::canonical($cui)
            ?? throw new \InvalidArgumentException('A CUI is 2 to 10 digits, or a 13-digit CNP, optionally prefixed with RO.');
    }
}
