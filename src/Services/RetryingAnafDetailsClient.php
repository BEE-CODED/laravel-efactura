<?php

declare(strict_types=1);

namespace BeeCoded\EFactura\Services;

use BeeCoded\EFacturaSdk\Contracts\AnafDetailsClientInterface;
use BeeCoded\EFacturaSdk\Data\Company\CompanyLookupResultData;
use BeeCoded\EFacturaSdk\Exceptions\RateLimitExceededException;
use Illuminate\Support\Sleep;

/**
 * Decorates the SDK's AnafDetailsClient with synchronous retry on ANAF's
 * 1 req/sec company-lookup rate limit. The SDK throws RateLimitExceededException
 * (mechanism); this layer owns the retry policy (up to N total attempts, backoff
 * = retryAfterSeconds), then re-throws so the consuming app can present it.
 *
 * Only the rate-limit exception triggers a retry — a failure() result
 * (invalid CUI, malformed response) passes straight through, and the SDK
 * already retries transient 5xx/connection errors internally.
 */
final class RetryingAnafDetailsClient implements AnafDetailsClientInterface
{
    public function __construct(
        private readonly AnafDetailsClientInterface $inner,
        private readonly int $maxAttempts = 5,
    ) {}

    public function getCompanyData(string $vatCode): CompanyLookupResultData
    {
        return $this->withRetry(fn () => $this->inner->getCompanyData($vatCode));
    }

    /**
     * {@inheritdoc}
     */
    public function batchGetCompanyData(array $vatCodes): CompanyLookupResultData
    {
        return $this->withRetry(fn () => $this->inner->batchGetCompanyData($vatCodes));
    }

    public function isValidVatCode(string $vatCode): bool
    {
        return $this->inner->isValidVatCode($vatCode);
    }

    /**
     * @param  callable(): CompanyLookupResultData  $operation
     */
    private function withRetry(callable $operation): CompanyLookupResultData
    {
        $attempts = max(1, $this->maxAttempts);

        for ($attempt = 1; ; $attempt++) {
            try {
                return $operation();
            } catch (RateLimitExceededException $e) {
                if ($attempt >= $attempts) {
                    throw $e;
                }

                Sleep::for(max($e->retryAfterSeconds, 1))->seconds();
            }
        }
    }
}
