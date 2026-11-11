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

use BeeCoded\EFactura\Enums\AnafLookupStatus;
use BeeCoded\EFacturaSdk\Data\Company\CompanyLookupResultData;
use BeeCoded\EFacturaSdk\Exceptions\RateLimitExceededException;
use BeeCoded\EFacturaSdk\Services\ApiClients\AnafDetailsClient;
use BeeCoded\EFacturaSdk\Support\DateHelper;
use BeeCoded\EFacturaSdk\Support\Validators\VatNumberValidator;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use Throwable;

/**
 * A cached, fail-closed "does this CUI exist and is it active today?" check.
 *
 * You pass the client explicitly, normally `new AnafDetailsClient(strict: true, ...)`:
 * not the container's AnafDetailsClientInterface, whose RetryingAnafDetailsClient sleeps.
 * Found means ANAF answered with exactly one company whose CUI is the one asked; NotFound
 * means its notFound list is exactly that CUI. Anything else is Unavailable, a rate limit
 * is Busy, and neither is cached. Verdicts are cached in the default cache store as plain
 * arrays under a key holding the policy version, the lookup URL, the ANAF date and the CUI,
 * so a cached verdict never outlives its day.
 */
final class CachedAnafLookup
{
    /** Bump when the meaning of a cached verdict changes, so older entries are never read. */
    private const POLICY_VERSION = 'v1';

    private const FOUND_TTL_SECONDS = 86400;

    private const NOT_FOUND_TTL_SECONDS = 900;

    public function __construct(private readonly AnafDetailsClient $client) {}

    /**
     * Today's cached verdict, or null when there is none (or it is malformed, or the cache
     * fails). Never calls ANAF, so a host can apply its own throttle before resolve().
     *
     * @throws InvalidArgumentException When the CUI is not 2-10 digits (an RO prefix is allowed)
     */
    public function cachedRead(string $cui): ?AnafLookupResult
    {
        $cui = self::normalize($cui);
        $date = DateHelper::getCurrentDateForAnaf();

        try {
            $cached = Cache::get($this->key($cui, $date));
        } catch (Throwable) {
            return null;
        }

        if (!is_array($cached) || ($cached['cui'] ?? null) !== $cui || ($cached['queryDate'] ?? null) !== $date
            || !array_key_exists('active', $cached)) {
            return null;
        }

        return match (true) {
            ($cached['status'] ?? null) === AnafLookupStatus::Found->value && is_bool($cached['active']) => new AnafLookupResult(AnafLookupStatus::Found, $cui, $date, $cached['active']),
            ($cached['status'] ?? null) === AnafLookupStatus::NotFound->value && $cached['active'] === null => new AnafLookupResult(AnafLookupStatus::NotFound, $cui, $date),
            default => null,
        };
    }

    /**
     * The cached verdict, or a live lookup whose Found / NotFound verdict is cached.
     * Never throws for ANAF or cache failures: an ANAF failure is Unavailable (or Busy); a
     * cache that cannot be read or written only means ANAF is asked (again).
     *
     * @throws InvalidArgumentException When the CUI is not 2-10 digits (an RO prefix is allowed)
     */
    public function resolve(string $cui): AnafLookupResult
    {
        $cui = self::normalize($cui);
        $cached = $this->cachedRead($cui);
        if ($cached !== null) {
            return $cached;
        }

        $date = DateHelper::getCurrentDateForAnaf();

        try {
            $answer = $this->client->getCompanyData($cui);

            // ANAF answered for a day that has ended: that is not evidence for today.
            if (DateHelper::getCurrentDateForAnaf() !== $date) {
                return new AnafLookupResult(AnafLookupStatus::Unavailable, $cui, $date);
            }

            $result = $this->verdict($answer, $cui, $date);

            if ($result->status === AnafLookupStatus::Found || $result->status === AnafLookupStatus::NotFound) {
                // ANAF has answered: a cache that cannot store it costs the next call, not this answer.
                rescue(fn () => Cache::put($this->key($cui, $date), [
                    'status' => $result->status->value,
                    'cui' => $cui,
                    'queryDate' => $date,
                    'active' => $result->active,
                ], $result->status === AnafLookupStatus::Found ? self::FOUND_TTL_SECONDS : self::NOT_FOUND_TTL_SECONDS), report: false);
            }

            return $result;
        } catch (RateLimitExceededException) {
            return new AnafLookupResult(AnafLookupStatus::Busy, $cui, $date);
        } catch (Throwable) {
            return new AnafLookupResult(AnafLookupStatus::Unavailable, $cui, $date);
        }
    }

    private function verdict(CompanyLookupResultData $answer, string $cui, string $date): AnafLookupResult
    {
        if ($answer->success && $answer->invalidCodes === []) {
            $company = $answer->first();

            if (count($answer->companies) === 1 && $answer->notFound === [] && $company?->cui === $cui) {
                return new AnafLookupResult(AnafLookupStatus::Found, $cui, $date, $company->isActive());
            }

            if ($answer->companies === [] && $answer->notFound === [(int) $cui]) {
                return new AnafLookupResult(AnafLookupStatus::NotFound, $cui, $date);
            }
        }

        return new AnafLookupResult(AnafLookupStatus::Unavailable, $cui, $date);
    }

    private function key(string $cui, string $date): string
    {
        return 'efactura:anaf-lookup:'.hash('sha256', self::POLICY_VERSION.'|'.$this->client::getBaseUrl()).':'.$date.':'.$cui;
    }

    private static function normalize(string $cui): string
    {
        $cui = VatNumberValidator::stripPrefix($cui);

        if (preg_match('/\A[1-9][0-9]{1,9}\z/', $cui) !== 1) {
            throw new InvalidArgumentException('A CUI is 2 to 10 digits, optionally prefixed with RO.');
        }

        return $cui;
    }
}
