<?php

/*
 * This file is part of the Laravel e-Factura package.
 *
 * (c) BEE-CODED <dev.ttl@beecoded.ro>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace BeeCoded\EFactura\Jobs\Concerns;

/**
 * Lets a periodically-scheduled, idempotent batch job drop itself when it has
 * waited in the queue longer than it should have — e.g. a backlog that built up
 * while the queue worker was down and is now draining all at once. Without this,
 * every stale copy re-runs its full body in the same moment, hammering ANAF's
 * per-message rate limits before processing can actually advance.
 *
 * The timestamp is captured at construction (the scheduler builds-then-dispatches
 * immediately) and survives serialization, so it reflects dispatch time, not the
 * moment the worker finally picks the job up.
 */
trait DiscardsWhenStale
{
    /** Unix timestamp captured when the job was dispatched. */
    public int $enqueuedAt = 0;

    public function markEnqueued(): void
    {
        $this->enqueuedAt = now()->getTimestamp();
    }

    public function isStale(): bool
    {
        $maxAge = (int) config('efactura.jobs.max_staleness_seconds', 120);

        if ($maxAge <= 0 || $this->enqueuedAt <= 0) {
            return false;
        }

        return (now()->getTimestamp() - $this->enqueuedAt) > $maxAge;
    }
}
