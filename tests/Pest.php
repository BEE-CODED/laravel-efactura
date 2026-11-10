<?php

use BeeCoded\EFactura\Models\EfacturaToken;
use BeeCoded\EFactura\Tests\TestCase;
use Illuminate\Support\Facades\Cache;

pest()->extend(TestCase::class)->in('Feature', 'Unit');

/**
 * Start the refresh cooldown TokenService records after ANAF refuses a grant, stored the
 * way Redis hands it back: the expiry as a numeric string.
 */
function coolDownGrant(EfacturaToken $token, int $seconds): void
{
    Cache::put(
        "efactura:token_refresh_refused:{$token->id}:".sha1((string) $token->refresh_token),
        (string) now()->addSeconds($seconds)->getTimestamp(),
        $seconds,
    );
}
