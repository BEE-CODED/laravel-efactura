<?php

/*
 * This file is part of the Laravel e-Factura package.
 *
 * (c) BEE-CODED <dev.ttl@beecoded.ro>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace BeeCoded\EFactura\Enums;

/**
 * Why an upload reached the Failed state.
 *
 * This is the authoritative failure classification. It lives in its own indexed
 * column rather than being encoded into the free-text `errors` payload, because:
 *
 *  1. `errors` is a `json` column. Postgres has no `json ~~ text` operator, so the
 *     old `where('errors', 'like', '%RATE_LIMIT_EXCEEDED:%')` threw on every run and
 *     rate-limited uploads were never retried on PG at all.
 *  2. Substring-matching ANAF's free text is fragile in both directions: a real
 *     Romanian 429 body ("S-au facut deja 1000 de apeluri...") matches no English
 *     "rate limit" pattern, while an unrelated validation error that merely mentions
 *     the phrase would be blindly re-submitted.
 *
 * The retry-safety of each reason is the load-bearing distinction. `isRetryable()`
 * is true ONLY where we can prove the document did not reach ANAF's filing pipeline —
 * re-driving anything else risks DOUBLE-FILING a legal invoice.
 */
enum FailureReason: string
{
    /**
     * ANAF's quota was exhausted (client-side pre-flight, or a real HTTP 429).
     * The document was rejected before filing — safe to re-submit.
     */
    case RateLimited = 'rate_limited';

    /**
     * A transient error that provably happened before ANAF accepted the document:
     * an authentication/token failure, or a pre-flight error. Safe to re-submit.
     */
    case Transient = 'transient';

    /**
     * We do not know whether ANAF filed the document. The process died after the
     * claim (job timeout / SIGKILL), or the transport failed in a way that cannot
     * distinguish "never arrived" from "arrived and the response was lost".
     *
     * NEVER auto-resubmitted. Requires human reconciliation against ANAF.
     */
    case Indeterminate = 'indeterminate';

    /**
     * ANAF rejected the document on its merits, or it never was valid: schema
     * violation, malformed XML, a 4xx client error. Re-submitting is pointless.
     */
    case Validation = 'validation';

    /**
     * The upload is unprocessable for a local/configuration reason (no token, the
     * model no longer implements the uploadable contract). Not ANAF's doing.
     */
    case Configuration = 'configuration';

    /**
     * A retryable failure was re-driven until the pipeline ran out of attempts, or ran
     * past its retry window, and gave up.
     *
     * The underlying failure was pre-transmission — `rate_limited` and `transient` both
     * prove the document never entered ANAF's filing pipeline — so nothing is at risk and
     * no reconciliation is needed. But it must NOT be re-driven automatically.
     *
     * That distinction is the whole reason this case exists. Giving up while leaving the
     * row `transient` left it matching RetryRateLimitedUploads' selection
     * (Failed + rate_limited|transient), so the scheduled job reset it to Pending with a
     * fresh attempts counter and a fresh retry window — the cap reset itself every ten
     * minutes, and InvoiceFailed fired every ten minutes for an upload that was
     * immediately retried.
     *
     * Re-queueing an abandoned upload is a deliberate act: queueUpload() recycles the row.
     */
    case Abandoned = 'abandoned';

    /**
     * Whether an upload with this reason may be automatically re-submitted to ANAF.
     *
     * Only reasons that PROVE the document never entered ANAF's pipeline qualify — and,
     * for `abandoned`, only where the pipeline has not already decided to stop.
     */
    public function isRetryable(): bool
    {
        return match ($this) {
            self::RateLimited, self::Transient => true,
            self::Indeterminate, self::Validation, self::Configuration, self::Abandoned => false,
        };
    }

    /**
     * Whether this reason requires a human to reconcile against ANAF before the
     * upload can be resolved either way.
     */
    public function needsReconciliation(): bool
    {
        return $this === self::Indeterminate;
    }

    /**
     * Every reason that may be automatically re-submitted. Handy for query scopes.
     *
     * @return array<int, self>
     */
    public static function retryable(): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $reason) => $reason->isRetryable(),
        ));
    }
}
