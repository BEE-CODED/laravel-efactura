export const eventReferenceContent = `# Laravel e-Factura Wrapper — Event Reference

All events are in the \`BeeCoded\\EFactura\\Events\` namespace.

## TokenStored

**Class:** \`BeeCoded\\EFactura\\Events\\TokenStored\`

**Constructor:**
\`\`\`php
public function __construct(public EfacturaToken $token)
\`\`\`

**Trigger:** Fired after the OAuth callback successfully stores or updates a token for a CUI. This happens when a user completes the ANAF authorization flow.

**Use Cases:**
- Notify admin that a new company has been authorized
- Log authorization events for audit trail
- Sync token metadata to an external system
- Trigger initial data sync for the newly authorized CUI

---

## TokenRefreshed

**Class:** \`BeeCoded\\EFactura\\Events\\TokenRefreshed\`

**Constructor:**
\`\`\`php
public function __construct(public EfacturaToken $token)
\`\`\`

**Trigger:** Fired after a token is refreshed during an API call. This is transparent to the caller.

\`TokenService\` fires it from **three** places (**changed in v3.0.0**):
- \`refreshToken()\` — the token was expiring, so \`executeWithRefreshLock()\` refreshed it **explicitly, under the lock, before running the operation**. This is the normal path
- \`persistTokenRefreshWithLock()\` — the token looked fresh so the operation ran unlocked, but the SDK refreshed anyway; the lock is acquired after the fact to persist it
- \`handleClientTokenRefresh()\` — a **public** helper for callers driving an \`EFacturaClient\` by hand. \`executeWithClient()\` does **not** call it

> **Changed in v3.0.0.** Pre-v3, the expiring-token path held the refresh lock across the operation
> and persisted via \`handleClientTokenRefresh()\`. That deadlocked: the SDK acquires the *same* cache
> key internally and Laravel locks are not re-entrant, so refresh threw
> \`AuthenticationException('Token refresh lock timeout')\` deterministically — meaning
> \`TokenRefreshed\` effectively never fired on that path. If your listener seemed dead, this is why.

Listeners must handle all firing paths — the event is identical in each case.

**Use Cases:**
- Audit logging for token lifecycle monitoring
- Monitoring token health and refresh frequency
- Detecting tokens that refresh too often (may indicate clock skew)

---

## InvoiceUploaded

**Class:** \`BeeCoded\\EFactura\\Events\\InvoiceUploaded\`

**Constructor:**
\`\`\`php
public function __construct(public EfacturaUpload $upload)
\`\`\`

**Trigger:** Fired after a successful upload to ANAF. At this point the upload status is \`Processing\` and the ANAF **\`upload_index\`** (\`indexIncarcare\`) is stored.

> **\`download_id\` is null in this listener.** It is not part of the upload response — it is only
> set later, by \`CheckUploadStatuses\`, once ANAF reports the document ready. A listener reading
> \`$event->upload->download_id\` here gets \`null\`. Use \`upload_index\` to correlate with ANAF at this
> stage, and listen for \`InvoiceProcessed\` if you need the \`download_id\` or the response ZIP.

**Use Cases:**
- Notify user that their invoice was successfully submitted
- Update UI to show "Processing" state
- Log successful upload metrics

---

## InvoiceProcessed

**Class:** \`BeeCoded\\EFactura\\Events\\InvoiceProcessed\`

**Constructor:**
\`\`\`php
public function __construct(public EfacturaUpload $upload)
\`\`\`

**Trigger:** Fired after the response ZIP has been downloaded for a \`Completed\` upload. The \`response_path\` on the upload record is populated at this point.

**Use Cases:**
- Parse the ANAF response ZIP to extract the invoice ID assigned by ANAF
- Update your invoice model's status to "accepted"
- Notify the user that their invoice has been accepted
- Trigger downstream workflows (accounting, etc.)

---

## InvoiceFailed

**Class:** \`BeeCoded\\EFactura\\Events\\InvoiceFailed\`

**Constructor:**
\`\`\`php
public function __construct(public EfacturaUpload $upload, public array $errors = [])
\`\`\`

**Trigger:** Fired when an upload reaches a **terminal** \`Failed\` state. The \`errors\` array contains error details from ANAF or the upload process. It fires from four places:

| Source | Situation | \`failure_reason\` |
|--------|-----------|------------------|
| \`UploadService::failTerminally()\` | ANAF rejected the document, XML/model invalid, no token, 4xx, or an ambiguous 5xx/transport loss | \`validation\`, \`configuration\`, \`indeterminate\` |
| \`DownloadService::checkStatus()\` (i.e. \`CheckUploadStatuses\`) | ANAF processed the document and rejected it on its merits | \`validation\` |
| \`ProcessSingleUpload::failed()\` / \`SweepStaleUploads\` (via \`parkStrandedUploadAsIndeterminate()\`) | The worker died around the POST — delivery UNKNOWN | \`indeterminate\` |
| \`ProcessSingleUpload::failed()\` (via \`abandonUnsentUpload()\`) | The \`retryUntil\` deadline expired while the row was still waiting to be sent — it never reached ANAF | \`abandoned\` |
| \`ProcessSingleUpload\` transient cap exhausted (via \`abandonFailure()\`) | A \`transient\` failure did not clear within \`jobs.max_transient_attempts\` | \`abandoned\` |

> **This IS a terminal signal — safe to alert on.** As of v3.0.0 transient rate-limit hits fire
> \`InvoiceRateLimited\` instead, so \`InvoiceFailed\` no longer produces a false alarm followed by a
> successful upload. Before v3 it fired on rate-limit failures too, and listeners had to
> substring-match a \`RATE_LIMIT_EXCEEDED:\` marker in the error text to tell the two apart. That
> marker is gone; use \`$upload->failure_reason\` instead.

### Reading the failure classification

\`$event->upload->failure_reason\` is a \`FailureReason\` enum carrying the authoritative reason:

| Reason | Meaning | Auto-retried |
|--------|---------|--------------|
| \`validation\` | ANAF rejected the document on its merits, or it was never valid | No |
| \`configuration\` | Local problem (no token, model no longer uploadable) | No |
| \`indeterminate\` | **Delivery to ANAF is UNKNOWN** — needs human reconciliation | No |
| \`transient\` | Auth/pre-flight blip; only terminal once retries are exhausted | Yes, until the cap |
| \`abandoned\` | Gave up retrying a \`transient\` or never-sent row. The document did **not** reach ANAF, so no reconciliation is needed — unlike \`indeterminate\` | No |
| \`rate_limited\` | Quota exhausted — fires \`InvoiceRateLimited\`, not this event. The row stays \`Pending\` | Yes |

> \`abandoned\` exists because the give-up transition has to leave a **non-retryable** reason. While
> a given-up row kept \`failure_reason = transient\`, \`RetryRateLimitedUploads\` — which selects on
> \`rate_limited\` and \`transient\` — resurrected the row it had just abandoned, re-firing
> \`InvoiceFailed\` every ~10 minutes for an upload that was immediately retried.

\`\`\`php
use BeeCoded\\EFactura\\Enums\\FailureReason;

Event::listen(InvoiceFailed::class, function (InvoiceFailed $event) {
    if ($event->upload->failure_reason === FailureReason::Indeterminate) {
        // We do not know whether ANAF filed this. It will NOT be re-submitted.
        // Escalate: someone must reconcile it (php artisan efactura:reconcile).
        return;
    }

    // genuine rejection or error — safe to alert
});
\`\`\`

---

## InvoiceRateLimited

**Class:** \`BeeCoded\\EFactura\\Events\\InvoiceRateLimited\`

**Constructor:**
\`\`\`php
public function __construct(
    public EfacturaUpload $upload,
    public array $errors = [],
    public ?int $retryAfterSeconds = null,
)
\`\`\`

**Trigger:** Fired when an upload hits ANAF's rate limit — either the SDK's client-side pre-flight
limiter (\`RateLimitExceededException\`) or a genuine HTTP 429 (\`ApiException\` with
\`statusCode === 429\`; the SDK's \`isRetryable()\` excludes 429, so it arrives as a plain
\`ApiException\`). The event itself only means: the upload hit the quota and was released back to
\`Pending\` with \`failure_reason = rate_limited\`. **What re-submits it depends on the caller:**

| Path | Who re-submits it |
|------|-------------------|
| \`ProcessSingleUpload\` (the normal queued path) | The job itself — \`release(60)\`, then it picks the \`Pending\` row straight back up |
| \`efactura:upload --sync\` (inline) | **Nobody, immediately.** There is no job to release, so \`processPendingUploads()\` stops the batch and the row waits at \`Pending\` / \`rate_limited\` for the scheduled \`ProcessPendingUploads\` or \`RetryRateLimitedUploads\` |

**Non-terminal — do NOT treat this as a failure.** Either way the upload is still going to be
re-submitted; it is not a verdict on the document. For a terminal signal, listen to
\`InvoiceFailed\`, which as of v3.0.0 means exactly that.

> **The persisted state agrees with that, as of v3.0.0.** A rate-limited row stays \`Pending\` with
> \`processed_at = null\`, so \`isFailed()\`, \`status->isTerminal()\` and \`isEfacturaProcessed()\` all
> correctly report that nothing has been decided yet. Earlier v3 pre-releases parked it as
> \`Failed\` / \`rate_limited\`, which made \`isEfacturaProcessed()\` return \`true\` for an upload that
> was still in the pipeline and about to flip back to \`Pending\` 60 seconds later — the event said
> "not a failure" while the model said "failed". \`failure_reason\` is retained as the signal the
> retry paths select on.

> **Not fired by the pre-flight release path.** When \`ProcessSingleUpload\` finds the quota already
> exhausted *before* claiming the row, it simply \`release()\`s itself back to the queue. The upload
> row is untouched and **no event fires**. This event only fires when the limit is hit *during* the
> API call.

\`retryAfterSeconds\` is populated only on the SDK client-side-limiter path (from
\`RateLimitExceededException::$retryAfterSeconds\`). On a genuine ANAF **HTTP 429** it is \`null\` —
ANAF's response carries no such hint. Do not assume it is set; the wrapper's own retry uses a fixed
60-second release regardless.

**Use Cases:**
- Backpressure metrics — how often you are hitting ANAF's ceiling
- Dashboards showing "delayed, retrying" rather than "failed"
- Feeding \`$event->retryAfterSeconds\` into your own throughput shaping
- **Not** for alerting: these clear themselves

---

## InvoiceReceived

**Class:** \`BeeCoded\\EFactura\\Events\\InvoiceReceived\`

**Constructor:**
\`\`\`php
public function __construct(public EfacturaMessage $message)
\`\`\`

**Trigger:** Fired after a received invoice has been downloaded from ANAF (triggered by \`DownloadReceivedInvoices\` job or \`efactura:sync --download\`).

**Use Cases:**
- Process incoming invoices from suppliers
- Auto-match received invoices with existing purchase orders
- Trigger accounts payable workflows
- Notify procurement team of new supplier invoices

---

## Listener Registration

Register listeners in your \`EventServiceProvider\`:

\`\`\`php
use BeeCoded\\EFactura\\Events\\TokenStored;
use BeeCoded\\EFactura\\Events\\TokenRefreshed;
use BeeCoded\\EFactura\\Events\\InvoiceUploaded;
use BeeCoded\\EFactura\\Events\\InvoiceProcessed;
use BeeCoded\\EFactura\\Events\\InvoiceFailed;
use BeeCoded\\EFactura\\Events\\InvoiceRateLimited;
use BeeCoded\\EFactura\\Events\\InvoiceReceived;

protected $listen = [
    TokenStored::class => [
        \\App\\Listeners\\HandleEfacturaTokenStored::class,
    ],
    TokenRefreshed::class => [
        \\App\\Listeners\\LogTokenRefresh::class,
    ],
    InvoiceUploaded::class => [
        \\App\\Listeners\\NotifyInvoiceUploaded::class,
    ],
    InvoiceProcessed::class => [
        \\App\\Listeners\\ProcessAnafResponse::class,
        \\App\\Listeners\\UpdateInvoiceStatus::class,
    ],
    InvoiceFailed::class => [
        \\App\\Listeners\\AlertOnInvoiceFailure::class,
    ],
    InvoiceRateLimited::class => [
        \\App\\Listeners\\RecordEfacturaBackpressure::class,
    ],
    InvoiceReceived::class => [
        \\App\\Listeners\\ProcessReceivedInvoice::class,
    ],
];
\`\`\`

Or use closures in \`AppServiceProvider\`:

\`\`\`php
use BeeCoded\\EFactura\\Events\\InvoiceProcessed;
use Illuminate\\Support\\Facades\\Event;

Event::listen(InvoiceProcessed::class, function (InvoiceProcessed $event) {
    $upload = $event->upload;
    $invoice = $upload->uploadable; // your model instance
    // process response...
});
\`\`\`
`;
