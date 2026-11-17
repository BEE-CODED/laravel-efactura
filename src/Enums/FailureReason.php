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
     * ANAF's quota was exhausted (client-side pre-flight, or a real HTTP 429), before
     * anything was filed. Only on a Pending row, waiting for its next_poll_at; it is never
     * given up for waiting.
     */
    case RateLimited = 'rate_limited';

    /**
     * An error that provably happened before the document was sent: a database or
     * storage error while preparing it, or a transport failure the SDK proves never left.
     * Only on a Pending row, waiting for its next_poll_at; status_failures counts these.
     */
    case Transient = 'transient';

    /**
     * We do not know whether ANAF filed the document. The process died after the
     * claim (job timeout / SIGKILL), the transport failed in a way that cannot
     * distinguish "never arrived" from "arrived and the response was lost", or ANAF's
     * answer did not say (an unreadable 2xx body, an index or status that cannot be used).
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
     * The upload failed UploadService::MAX_TRANSIENT_ATTEMPTS times before it was sent.
     *
     * Nothing was sent, so nothing is at risk and no reconciliation is needed. But it is
     * final: Failed is never re-driven automatically. Re-queueing an abandoned upload is a
     * deliberate act: queueUpload() recycles the row.
     */
    case Abandoned = 'abandoned';

    /**
     * Whether an upload with this reason is tried again automatically: it waits in
     * Pending, never Failed.
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
}
