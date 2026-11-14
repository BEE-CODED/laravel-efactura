export const wrapperDocsContent: Record<string, string> = {
  overview: `# Laravel e-Factura Wrapper — Overview

## What the Wrapper Adds on Top of the SDK

The Laravel e-Factura wrapper (\`bee-coded/laravel-efactura\`) is a full-featured Laravel package that sits on top of the \`bee-coded/laravel-efactura-sdk\` and provides automated, database-backed invoice management for Romania's ANAF e-Factura system.

Full namespace: \`BeeCoded\\EFactura\`

## Architecture

### EfacturaManager Orchestrator

The central \`EfacturaManager\` class is the orchestration layer, exposed via the \`EFactura\` facade. It wires together all four services and provides the primary public API:

\`\`\`php
use BeeCoded\\EFactura\\Facades\\EFactura;

EFactura::queueUpload($invoice);
EFactura::getAuthorizationUrl($cui, $sessionNonce, $userId); // bound; unbound (no nonce/user) only from the console
EFactura::client($cui); // raw SDK client with token
\`\`\`

### 4 Core Services

| Service | Responsibility |
|---------|---------------|
| \`TokenService\` | Concurrent-safe OAuth token storage, refresh, and client creation |
| \`UploadService\` | Queue and process invoices — XML generation, upload to ANAF, status tracking |
| \`DownloadService\` | Status polling and response ZIP downloads |
| \`MessageSyncService\` | Sync ANAF message list, download received invoices |

### 3 Eloquent Models

| Model | Purpose |
|-------|---------|
| \`EfacturaToken\` | OAuth tokens per CUI (company), with \`is_active\` flag and \`last_used_at\` tracking. Credentials are **encrypted at rest** since v3.0.0 — see Token Model |
| \`EfacturaUpload\` | Polymorphic upload record tracking status, XML path, download ID, response path, errors |
| \`EfacturaMessage\` | Synced ANAF messages (sent/received invoices, errors, buyer messages) |

### Polymorphic Upload Design

Any Eloquent model can be uploaded to e-Factura by implementing \`EFacturaUploadableInterface\`. The \`EfacturaUpload\` record uses Laravel's polymorphic morph relationship, so a single uploads table tracks invoices from any model class. \`uploadable_type\` is the model's \`getMorphClass()\` (a morph map alias when the app defines one).

### Event-Driven

7 domain events are fired at key lifecycle points, enabling loose coupling between the package and your application code. Use standard Laravel event listeners or subscribers.

Since v3.0.0 \`InvoiceFailed\` is **terminal-only**; the new \`InvoiceRateLimited\` carries transient rate-limit hits. See the Event Reference.

Since v4.0 every event, \`TokenStored\` included, is fired through \`rescue()\` after its state is written: a listener exception is reported, never propagated, and never changes that state.

### 9 Queue Jobs

Background processing is handled by 9 queue jobs:
- **Batch jobs** (for scheduling): ProcessPendingUploads, CheckUploadStatuses, DownloadResponses, SyncMessages, RetryRateLimitedUploads, DownloadReceivedInvoices, SweepStaleUploads
- **Single-upload jobs** (dispatched on-demand): ProcessSingleUpload, CheckSingleUploadStatus

\`SweepStaleUploads\` is new in v3.0.0 and must be scheduled — it is the only thing that rescues uploads stranded in \`uploading\` by a hard worker death. The package schedules nothing itself; the one recommended cadence per job is the table in the Job Reference ("Recommended Scheduling").

### 5 Artisan Commands

Manual operation commands: \`efactura:auth\`, \`efactura:upload\`, \`efactura:status\`, \`efactura:sync\`, \`efactura:reconcile\`

### Failure Classification (v3.0.0)

A \`Failed\` upload carries an authoritative \`failure_reason\` (\`FailureReason\` enum) in its own indexed column. Only \`rate_limited\` and \`transient\` are ever re-submitted automatically; \`indeterminate\` means delivery to ANAF is UNKNOWN and requires \`efactura:reconcile\`. Never substring-match the \`errors\` text to classify a failure.

### Feature Flags

Three independent feature flags for granular control:
- \`features.upload_invoices\` — Enable/disable upload pipeline
- \`features.download_received\` — Enable/disable received invoice downloads (default: off)
- \`features.sync_messages\` — Enable/disable ANAF message sync

### ANAF Lookup Retry Decorator

The wrapper decorates the SDK's \`AnafDetailsClientInterface\` binding with \`RetryingAnafDetailsClient\`. ANAF's public company-lookup endpoint is capped at 1 request/second and the SDK throws \`RateLimitExceededException\` without retrying; the decorator retries up to \`anaf_lookup.retry_attempts\` total attempts (default 5), sleeping the exception's \`retryAfterSeconds\` (>= 1s) between attempts, then re-throws. Synchronous/blocking; only the SDK's own limiter refusal is retried — ANAF's own 429 (\`fromProvider\`) is re-thrown at once, and a \`failure()\` result passes through. Resolving the interface or the \`AnafDetails\` facade yields the resilient client transparently — no app code change.

### Cached Company Check (v4.0)

\`new CachedAnafLookup(new AnafDetailsClient(strict: true, tries: 1, timeoutSeconds: 2))\` (\`BeeCoded\\EFactura\\Services\\CachedAnafLookup\`; pass the client explicitly, not the sleeping decorator). \`resolve($cui)\` returns an \`AnafLookupResult\` (\`status\`, \`cui\`, \`queryDate\`, \`active\`) with \`AnafLookupStatus\` \`Found\` (cached for the rest of the day), \`NotFound\` (cached 15 min), \`Unavailable\` or \`Busy\` (never cached, never thrown; a failed cache write does not change the answer); \`cachedRead($cui)\` returns today's cached verdict or null without calling ANAF, so the host can throttle before \`resolve()\`. Cached as plain arrays in the default store, keyed by policy version, lookup URL, ANAF date (Europe/Bucharest) and CUI. Found needs exactly one company with that CUI, NotFound exactly that CUI in \`notFound\`; an answer arriving after midnight is Unavailable. A CUI that is not 2–10 digits (optional \`RO\`) throws \`InvalidArgumentException\`.

### Response Archive Check and Poll Schedule (v4.0)

Before a response ZIP is stored, \`DownloadService\` checks it with the SDK's \`ArchiveInspector\`: against the uploaded XML when the upload is \`Completed\` and its \`xml_path\` file exists (\`response_verification\` = \`verified\`), else limits only (\`limits_only\`). A ZIP without the uploaded invoice is stored as received, marked \`mismatch\` and logged. An archive outside the limits (not a ZIP, empty, encrypted, too large, too many members) counts toward \`EfacturaUpload::MAX_RESPONSE_ATTEMPTS\`; a local temp-file failure is retried uncounted. A received-invoice ZIP gets the same limits-only check before it is stored: one outside the limits counts toward \`EfacturaMessage::MAX_DOWNLOAD_ATTEMPTS\` and fires no \`InvoiceReceived\`. After each "in progress" status answer \`next_poll_at\` is set by the SDK's \`PollSchedule\` (1, 5, 15, then 60 min by age since \`uploaded_at\`), and \`needsStatusCheck()\` takes only due rows.
`,

  setup: `# Laravel e-Factura Wrapper — Setup & Installation

## Step 1: Install via Composer

\`\`\`bash
composer require bee-coded/laravel-efactura
\`\`\`

Laravel's auto-discovery will register \`EfacturaServiceProvider\` automatically.

## Step 2: Publish Configuration

\`\`\`bash
php artisan vendor:publish --tag=efactura-config
\`\`\`

This publishes \`config/efactura.php\`.

## Step 3: Publish Migrations

\`\`\`bash
php artisan vendor:publish --tag=efactura-migrations
\`\`\`

This publishes **11** migration files (3 create the tables, 4 were added in v3.x, and \`..._000008\` to \`..._000011\` in v4.0). The package also loads them itself, so publishing is optional. With \`pipeline_tables\` false only the 3 token migrations (\`000001\`, \`000004\`, \`000009\`) are loaded and published.

## Step 4: Run Migrations

\`\`\`bash
php artisan migrate
\`\`\`

Creates three tables:
- \`efactura_tokens\` — OAuth tokens per CUI (credentials encrypted at rest)
- \`efactura_uploads\` — Upload tracking (polymorphic, unique per model)
- \`efactura_messages\` — Synced ANAF messages, one row per token and ANAF message id

The v3.0.0 migrations are \`..._000004_encrypt_efactura_token_credentials\`,
\`..._000005_add_failure_reason_to_efactura_uploads_table\` and
\`..._000006_add_unique_uploadable_index_to_efactura_uploads_table\`. On a **fresh** install they are
no-ops beyond schema. **Upgrading an existing v2 install? Read the \`migration\` topic first** — that
upgrade requires a maintenance window with the queue workers stopped, \`..._000004\` must run before
any e-Factura operation or existing companies throw \`DecryptException\` (which is why it is ordered
first), and \`..._000006\` aborts if pre-3.0 duplicate uploads exist.

> **\`APP_KEY\` is load-bearing from v3.0.0.** It encrypts the ANAF credentials. Losing or rotating
> it without \`APP_PREVIOUS_KEYS\` forces a fresh OAuth flow for every connected company. Back it
> up somewhere other than the database it protects.

## Step 5: Configure Environment Variables

### SDK Variables (required for API access)
\`\`\`env
EFACTURA_CLIENT_ID=your-anaf-client-id
EFACTURA_CLIENT_SECRET=your-anaf-client-secret
EFACTURA_REDIRECT_URI=https://yourapp.com/efactura/callback
EFACTURA_SANDBOX=true   # false for production
\`\`\`

### Wrapper-Specific Variables
\`\`\`env
# Master switch — this wrapper's own flag, read from config/efactura.php.
# Not an SDK variable: when false, this package's jobs exit immediately,
# but the SDK client itself is unaffected.
EFACTURA_ENABLED=true

# Feature flags
EFACTURA_UPLOAD_ENABLED=true
EFACTURA_DOWNLOAD_RECEIVED=false
EFACTURA_SYNC_MESSAGES=true

# Queue
EFACTURA_QUEUE=efactura         # null = default queue

# File storage
EFACTURA_STORAGE_DISK=local
EFACTURA_STORAGE_PATH=efactura

# OAuth routes
EFACTURA_ROUTES_ENABLED=true
EFACTURA_ROUTES_PREFIX=efactura
EFACTURA_SUCCESS_REDIRECT=/dashboard
EFACTURA_ERROR_REDIRECT=/

# Rate limit handling
EFACTURA_RATE_LIMIT_RETRY_HOURS=24
EFACTURA_RATE_LIMIT_RETRY_BATCH=250
EFACTURA_RATE_LIMIT_RETRY_MAX_DAYS=7

# ANAF company-lookup retry (1 req/sec endpoint)
EFACTURA_ANAF_LOOKUP_RETRY_ATTEMPTS=5

# Periodic job hardening
EFACTURA_JOB_MAX_STALENESS=120
EFACTURA_JOB_UNIQUE_FOR=3600
EFACTURA_JOB_TRANSIENT_RETRY_DELAY=30
EFACTURA_JOB_MAX_TRANSIENT_ATTEMPTS=5
EFACTURA_JOB_STALE_UPLOADING_MINUTES=30

# Status polling
EFACTURA_POLLING_MAX_STATUS_FAILURES=5
\`\`\`

> **A shared cache store is required** (redis, memcached, database — anything but \`array\`). The
> OAuth state lives in the cache as of v3.0.0, so the web and console processes must see the same
> store, and \`TokenService\` uses cache locks to serialise token refresh.

## Step 6: Schedule Background Jobs

Add to \`routes/console.php\` (Laravel 11+) or \`app/Console/Kernel.php\`:

\`\`\`php
use BeeCoded\\EFactura\\Jobs\\ProcessPendingUploads;
use BeeCoded\\EFactura\\Jobs\\CheckUploadStatuses;
use BeeCoded\\EFactura\\Jobs\\DownloadResponses;
use BeeCoded\\EFactura\\Jobs\\SyncMessages;
use BeeCoded\\EFactura\\Jobs\\RetryRateLimitedUploads;
use BeeCoded\\EFactura\\Jobs\\SweepStaleUploads;

Schedule::job(new ProcessPendingUploads)->everyFiveMinutes();
Schedule::job(new CheckUploadStatuses)->everyTenMinutes();
Schedule::job(new DownloadResponses)->everyFifteenMinutes();
Schedule::job(new RetryRateLimitedUploads)->everyThirtyMinutes();
Schedule::job(new SyncMessages)->hourly();

// New in v3.0.0 — rescues uploads stranded mid-flight by a dead worker.
Schedule::job(new SweepStaleUploads)->everyFifteenMinutes();
\`\`\`

These are the cadences of the Job Reference's "Recommended Scheduling" table, which explains each
one against the SDK's quotas.

**A queue worker is required.** These jobs dispatch \`ProcessSingleUpload\` per row; nothing uploads
without a worker consuming \`config('efactura.queue')\`.

## Step 7: Implement EFacturaUploadableInterface

Your invoice model must implement the interface:

\`\`\`php
use BeeCoded\\EFactura\\Contracts\\EFacturaUploadableInterface;
use BeeCoded\\EFactura\\Traits\\HasEfacturaUpload;

class Invoice extends Model implements EFacturaUploadableInterface
{
    use HasEfacturaUpload;

    public function toEfacturaData(): InvoiceData { /* ... */ }
    public function getEfacturaCui(): string { return $this->company->cui; }
}
\`\`\`

\`toEfacturaData()\` builds the **SDK's** \`InvoiceData\`, so SDK requirements apply here. Two are
mandatory as of SDK v3.0 — see the \`get-model-integration-guide\` tool and the \`migration\` topic:
- \`PartyData::$isVatPayer\` is **required** (and moved to the 4th constructor position — **always use named arguments**)
- \`InvoiceData::$taxAmountRon\` is **required** when \`currency\` is not \`RON\`, and **rejected** when it is

## Step 8: MCP Setup (for AI tools)

Add both MCP servers to your AI tool configuration (\`claude_desktop_config.json\` or similar):

\`\`\`json
{
  "mcpServers": {
    "efactura-sdk": {
      "command": "node",
      "args": ["/path/to/laravel-efactura-sdk/mcp/dist/index.js"]
    },
    "efactura": {
      "command": "node",
      "args": ["/path/to/laravel-efactura/mcp/dist/index.js"]
    }
  }
}
\`\`\`
`,

  "upload-pipeline": `# Laravel e-Factura Wrapper — Upload Pipeline

## End-to-End Upload Flow

### 1. Queue an Upload

\`\`\`php
use BeeCoded\\EFactura\\Facades\\EFactura;

$upload = EFactura::queueUpload($invoice);          // B2B
$upload = EFactura::queueB2CUpload($invoice);       // B2C (consumer invoices)
\`\`\`

This creates an \`EfacturaUpload\` record with status \`Pending\`.

> **Idempotent since v3.0.0.** A model has **at most one** upload row —
> \`(uploadable_type, uploadable_id)\` is unique at the database, matching the 1:1 \`morphOne\` the
> trait exposes. Calling \`queueUpload()\` twice does **not** create a second row:
>
> - \`Pending\` / \`Uploading\` / \`Processing\` → returns the existing row (already in the pipeline)
> - \`Completed\` → returns the existing row (ANAF accepted it; never re-file)
> - \`Failed\` (retryable **or** terminal) → recycles that row, resetting it to \`Pending\` with fresh
>   \`response_attempts\`, \`response_failed_at\` and \`status_failures\`. The reset is guarded on the
>   status and reason it read (v4.0): if another worker re-drove or re-classified the row meanwhile,
>   it decides again on what the row is now
> - \`Failed\` + \`failure_reason = indeterminate\` → **throws \`DuplicateUploadException\`**
>
> Before v3 it created a row unconditionally, so a double-clicked button filed the invoice at ANAF
> twice while \`morphOne\` only ever showed one of them.

### 2. ProcessPendingUploads (scheduled)

The \`ProcessPendingUploads\` batch job runs on schedule (cadence: Job Reference, "Recommended Scheduling"). For each pending upload, it dispatches a \`ProcessSingleUpload\` job onto the configured queue.

### 3. ProcessSingleUpload (per-upload)

This job handles the full upload for one invoice:

1. **Atomic claim** — Updates status from \`Pending\` → \`Uploading\` using an atomic DB query to prevent double-processing
2. **XML generation** — Calls \`UblBuilder\` to generate UBL 2.1 XML from \`InvoiceData\`
3. **Store XML** — Saves generated XML to configured storage disk/path
4. **Upload to ANAF** — Calls \`EFacturaClient::uploadDocument()\` (or \`uploadB2CDocument()\` when the upload is B2C) with concurrent-safe token handling via \`TokenService::executeWithClient()\`
5. **Success** — When the answer carries a numeric \`indexIncarcare\`, marks upload as \`Processing\` and stores it as the ANAF **\`upload_index\`**, whatever \`ExecutionStatus\` says (ANAF assigns an index only to a document it received; the status check reads the outcome, which can still be a rejection or "nepreluat", a file it did not take in). The \`download_id\` is **not** set yet — it arrives later, from \`CheckUploadStatuses\`. \`ExecutionStatus=1\` without an index is \`Failed\` / \`validation\`; any other answer without a usable index is \`Failed\` / \`indeterminate\`
6. Fires \`InvoiceUploaded\` event

### 4. CheckUploadStatuses (scheduled)

For each upload in \`Processing\` state, \`DownloadService::checkStatus()\` calls ANAF's \`getStatusMessage()\`:

| ANAF answer or failure | Result |
|------------------------|--------|
| \`in prelucrare\` | Nothing changes; polled again next run |
| \`ok\` with a download id | \`Completed\` |
| \`ok\` without a download id (none, or one that is not 1–64 digits: SDK \`verdict()\`) | \`Completed\` with \`download_id = null\` and a note in \`errors\` (ANAF said it is filed). No response is downloaded, so \`InvoiceProcessed\` never fires for it |
| \`nok\` with a download id | \`Failed\` / \`validation\` + \`InvoiceFailed\` |
| \`nok\` without a download id (none, or not 1–64 digits) | \`Failed\` / \`indeterminate\` + \`InvoiceFailed\`, keeping \`upload_index\`. **Changed in v4.0** (was \`validation\`): it is no verdict on the document |
| Only an error (SDK \`hasAnafError()\`: an XML \`<Errors/>\` header or a JSON \`eroare\` — an unknown index, "Nu aveti dreptul") | \`Failed\` / \`indeterminate\` + \`InvoiceFailed\`, keeping \`upload_index\` and ANAF's errors (v4.0) |
| "XML cu erori nepreluat de sistem" | \`Failed\` / \`validation\` + \`InvoiceFailed\`; nothing to download |
| No recognisable status | \`Failed\` / \`indeterminate\` + \`InvoiceFailed\` |
| Permanent failure: a 4xx, an unreadable 2xx answer, an index the SDK refuses (\`ValidationException\`), any other error | \`status_failures\` + 1; at \`polling.max_status_failures\` (default 5) \`Failed\` / \`indeterminate\` + \`InvoiceFailed\` |
| Transient failure: thrown before the request (token read/refresh), \`AuthenticationException\`, a 5xx or transport error | Retried next run, not counted |
| \`RateLimitExceededException\` with \`$scope === RateLimitScope::Status\` (this upload's per-message limit) | This upload is skipped, not counted; the batch carries on |
| Any other \`RateLimitExceededException\` (global, or no scope), or ANAF's \`ApiException(429)\` | Rethrown: the batch stops |

Any status answer resets \`status_failures\` to 0, and so do \`markUploadAsProcessing()\` and \`resolveIndeterminateAsFiled()\`. Code that re-arms a parked upload itself (a raw update back to \`Processing\`) must reset it too, or the next permanent failure parks it again. An upload parked after an unreadable 2xx answer keeps its \`upload_index\`: recover it with \`efactura:reconcile --filed=<id> --index=<its upload index>\`.

\`checkStatus()\` returns a \`StatusCheckOutcome\` (v4.0): \`Checked\` (ANAF answered, including "in prelucrare"), \`NotPollable\` (no index, already final, or no token), \`RateLimited\` (skipped at its per-message limit) or \`Failed\` (logged; counted when permanent).

### 5. DownloadResponses (scheduled)

For uploads with a \`download_id\` but no \`response_path\`, downloads the response ZIP from ANAF and stores it to disk. A permanent failure (a 2xx body that is not a ZIP, a 4xx, an id the SDK refuses, any other error) increments \`response_attempts\`, up to \`EfacturaUpload::MAX_RESPONSE_ATTEMPTS\` (3); a transient one is retried without counting. A \`RateLimitExceededException\` with \`RateLimitScope::Download\` skips that upload; any other rate limit stops the batch. \`DownloadReceivedInvoices\` applies the same rules to received invoices, counting in \`efactura_messages.download_attempts\` up to \`EfacturaMessage::MAX_DOWNLOAD_ATTEMPTS\` (3).

Fires \`InvoiceProcessed\` after download, **only** when the upload's status is \`Completed\` — a Failed upload's error-report ZIP is downloaded and stored, but fires no event here.

\`DownloadResponses\` never fires \`InvoiceFailed\`; that event has already been fired earlier. The full list of sources is in the Event Reference ("InvoiceFailed").

## Upload Status State Machine

\`\`\`
Pending → Uploading → Processing → Completed
   ↑          │            │
   │          ↓            ↓
   └──────  Failed  ←──────┘
\`\`\`

| Status | Meaning |
|--------|---------|
| \`Pending\` | Queued, not yet processed |
| \`Uploading\` | Claimed by a job, about to be / being sent to ANAF |
| \`Processing\` | Uploaded to ANAF, awaiting ANAF processing |
| \`Completed\` | ANAF accepted and processed the invoice |
| \`Failed\` | Upload or processing failed — read \`failure_reason\` for what happens next |

\`Failed\` → \`Pending\` is the automatic retry path, taken only for a **retryable** \`failure_reason\`.

> **\`Uploading\` is no longer a dead end (v3.0.0).** \`processUpload()\` claims a row into \`Uploading\`
> immediately before the POST. If the worker dies there, \`ProcessSingleUpload::failed()\` transitions
> it to \`Failed\` / \`indeterminate\`; a SIGKILL (which never runs \`failed()\`) is caught by the
> \`SweepStaleUploads\` job. Before v3 such rows sat in \`Uploading\` forever, because the queue's retry
> re-ran \`processUpload()\`, whose claim (\`WHERE status = pending\`) matched zero rows and silently
> reported success.

## Failure Classification — \`failure_reason\`

Every \`Failed\` upload carries a \`FailureReason\` enum in the indexed \`failure_reason\` column. This is
the **authoritative** classification — never substring-match \`errors\`.

| Reason | Meaning | Auto-retried |
|--------|---------|--------------|
| \`rate_limited\` | ANAF quota exhausted (pre-flight limiter or a real HTTP 429). Rejected before filing. The row stays **\`Pending\`**, not \`Failed\` | Yes |
| \`transient\` | Auth/token failure or pre-flight error, provably before ANAF accepted anything | Yes, up to \`jobs.max_transient_attempts\` |
| \`abandoned\` | Gave up: a \`transient\` failure outlived \`jobs.max_transient_attempts\`, or the \`retryUntil\` window expired while the row was still unsent. Provably never filed, so **no reconciliation needed** | No |
| \`indeterminate\` | **Delivery UNKNOWN** — worker died around the POST, 5xx/transport loss, an unreadable 2xx upload answer, an upload answer without a usable index and status (non-numeric index, success without an index, no recognised \`ExecutionStatus\`), or a status check without a verdict (\`nok\` without a download id, no recognisable status, \`polling.max_status_failures\` permanent failures). May already be filed | **Never** — \`efactura:reconcile\` |
| \`validation\` | ANAF rejected the document on its merits, or it was never valid (4xx, \`ExecutionStatus=1\` without an upload index, bad XML) | No |
| \`configuration\` | Local problem — no token, model no longer implements the contract | No |

\`FailureReason::isRetryable()\` is true **only** where the document provably never entered ANAF's
filing pipeline. Re-driving anything else risks double-filing a legal invoice.

> **Why \`abandoned\` is distinct from \`transient\`.** Giving up has to *change* the reason, because
> \`RetryRateLimitedUploads\` selects on \`rate_limited\` and \`transient\`. A given-up row that kept
> \`transient\` was resurrected by that job on its next run — re-firing \`InvoiceFailed\`, resetting
> the attempt counter and the retry window, and hammering ANAF for the full retry period. The cap
> only exists because the give-up transition is non-retryable.

## Rate Limit Handling

Before each upload attempt \`ProcessSingleUpload\` pre-flight-checks the SDK \`RateLimiter\`'s **\`global\`** bucket — a **per-minute** ceiling (\`global_per_minute\`, default **500/min**), not a daily quota. If the remaining quota is 0 it releases the job back to the queue with a delay matching the bucket reset, **before** the upload row is claimed, so the row stays \`Pending\` (only \`updated_at\` is touched, v4.0, so the scheduler adds no second job) and no event fires. (ANAF does also enforce per-day quotas, but they are separate buckets and are not what this pre-flight checks.)

If the limit is instead hit **during** the API call, the ordering is:

1. \`UploadService\` releases the claim: the row goes back to **\`Pending\`** with \`failure_reason = rate_limited\` and \`processed_at = null\`. It is deliberately **not** marked \`Failed\` — it never left the pipeline, and a \`Failed\` row would make \`isEfacturaProcessed()\` report \`true\` for an upload that flips back to \`Pending\` 60 seconds later
2. **\`InvoiceRateLimited\` fires** — *not* \`InvoiceFailed\`. The upload is still in the pipeline
3. Back in \`ProcessSingleUpload\`, the row is re-read; seeing \`Pending\` + \`rate_limited\` it \`release(60)\`s itself without a reset

Two distinct exceptions land here, and v3 treats them identically:
- \`RateLimitExceededException\` — the SDK's **client-side** pre-flight limiter refused the call
- \`ApiException\` with \`statusCode === 429\` — a **genuine ANAF 429**. The SDK's \`isRetryable()\` excludes 429, so it surfaces as a plain \`ApiException\`. Before v3 this became a permanent \`Failed\`

So \`ProcessSingleUpload\` normally recovers its **own** rate-limited upload. \`RetryRateLimitedUploads\` is only the **safety net**, for rows nothing else will re-drive: a \`Failed\` row with a retryable reason (the worker died between steps 1 and 3, or the migration backfilled it), and a live \`Pending\` / \`rate_limited\` row whose released job was lost.

> **\`ProcessPendingUploads\` deliberately skips \`Pending\` / \`rate_limited\` rows.** A rate-limited row is Pending, but its own \`ProcessSingleUpload\` is already queued on a 60-second release, and \`ProcessSingleUpload\` carries no uniqueness guard — so dispatching it again would stack another job on the same row every scheduled tick. A genuinely queued row has \`failure_reason\` **NULL** (\`resetForRetry()\` clears it), which is what separates "nobody is on this" from "a job is waiting one out". Recovery for the latter is \`RetryRateLimitedUploads\`, and the atomic \`WHERE status = pending\` claim means a duplicate job can never reach ANAF twice.

\`ProcessSingleUpload\` uses \`retryUntil()\` based on \`rate_limit.retry_window_hours\` (default 24h). Once that window passes the **job** stops retrying and \`failed()\` resolves the row by where it actually got to:

| Row state at the deadline | Outcome |
|---|---|
| \`Uploading\` | \`Failed\` / \`indeterminate\` — the worker died around the POST, so delivery is unknown. Needs \`efactura:reconcile\` |
| \`Pending\`, or \`Failed\` with a retryable reason | \`Failed\` / \`abandoned\` + \`InvoiceFailed\` — it was still queued and never reached ANAF |

Both transitions are guarded conditional updates, so a row that reached a terminal state in the meantime is left alone. Before v3.0.0 the second case matched nothing: the row stayed \`Pending\`, **no event fired at all**, and the scheduled \`ProcessPendingUploads\` handed it a brand-new 24h window — so \`rate_limit.retry_window_hours\` never actually terminated anything.

## Indeterminate Uploads — the human path

An upload whose delivery cannot be proven is parked \`Failed\` / \`indeterminate\` and is **never
re-submitted automatically**: re-sending might double-file a legal invoice, writing it off might
leave a statutory filing undone. Resolve with:

\`\`\`bash
php artisan efactura:reconcile                              # list what needs checking
php artisan efactura:reconcile --filed=12 --index=5001130255 # confirmed filed at ANAF
php artisan efactura:reconcile --not-filed=12               # confirmed absent — re-queues it
php artisan efactura:reconcile --not-filed=12 --despite-index # not in SPV under its index; asks to confirm
\`\`\`

An upload that has an \`upload_index\` was taken in by ANAF (it issues an index for nothing else), so
\`--not-filed\` alone refuses it; resolve it \`--filed\` with that index. Only if SPV has no invoice under
that index, \`--not-filed --despite-index\` re-queues it after an interactive confirmation (refused with
\`--no-interaction\`); the old index is kept in \`errors\` and logged.

## Events Fired

| Event | When |
|-------|------|
| \`InvoiceUploaded\` | After successful ANAF upload (status → Processing) |
| \`InvoiceProcessed\` | After response ZIP downloaded for a Completed upload |
| \`InvoiceRateLimited\` | Transient rate-limit hit — upload stays in the pipeline and is retried |
| \`InvoiceFailed\` | The upload failed **terminally** — safe to alert on. Since v3.0.0 it does **not** fire for rate limits, nor for a \`transient\` failure until its retries are exhausted |
`,

  "token-management": `# Laravel e-Factura Wrapper — Token Management

## OAuth Authorization Flow

### Option A: Built-in Routes

When \`routes.enabled = true\` (default), the package registers two routes:

\`\`\`
GET /efactura/auth/{cui}      → Generate and redirect to ANAF OAuth URL
GET /efactura/callback         → Handle OAuth callback, store token
\`\`\`

\`OAuthCallbackController\` handles the code exchange, stores the tokens via \`TokenService::storeToken()\`, and fires the \`TokenStored\` event through \`rescue()\`: a listener exception is reported and the callback still redirects to the success URL.

The state of a flow started through this route is bound to the browser session (a random nonce
kept in the session) and the logged-in user: the callback refuses it from another session or for
another user (v4.0).

### Option B: Manual URL Generation

\`\`\`php
$url = EFactura::getAuthorizationUrl($cui, $sessionNonce, $request->user()->getAuthIdentifier());
// Redirect user to $url; in your callback:
$cui = EFactura::tokenService()->validateOAuthState($state, $sessionNonce, $request->user()->getAuthIdentifier());
\`\`\`

Both binding arguments are optional; without them the state is valid in any logged-in browser, as for the CLI. From a web request always pass them.

### Option C: CLI

\`\`\`bash
php artisan efactura:auth {cui}
\`\`\`

Prints the authorization URL to open in a browser. The callback then completes in the web process.

> **This actually works as of v3.0.0.** The state used to be written to the console process's
> session, which boots no \`StartSession\` middleware — so it went to an in-memory store that was
> never persisted, and the callback always answered
> \`Invalid or expired state parameter. Please try again.\` The CLI flow was structurally impossible
> to complete. State now lives in the cache, which any process can verify.

### CSRF Protection

State validation uses a 15-minute expiry (\`OAUTH_STATE_TTL_SECONDS = 900\`). The state parameter is a base64-encoded JSON object carrying \`cui\` and a cryptographically secure \`token\` (256 bits, \`bin2hex(random_bytes(32))\`).

**Changed in v3.0.0:** the token is recorded in the **cache** (\`efactura:oauth_state:{token}\`), not the session. Validation uses \`Cache::pull()\`, making the state strictly **single-use** — a replayed callback is rejected even inside the TTL. The \`cui\` in the (attacker-visible) state must also match what was recorded server-side. Expired, replayed, or mismatched states are rejected silently.

> **Requires a cache store shared across web and console processes** (redis, memcached, database — anything but \`array\`). This is strictly less demanding than the session store it replaces.

## TokenService::executeWithClient() — Concurrent-Safe Pattern

The core concurrent-safe method for all API operations. It optimizes for parallel processing while preventing token refresh race conditions.

### How It Works

**Case 1: Token NOT expiring soon (> 2 min to expiry)**
- Proceed immediately without acquiring any lock
- This allows parallel API calls to run concurrently

**Case 2: Token IS expiring soon (< 2 min to expiry)**
- Acquire the \`efactura:token_refresh:{cui}\` cache lock (held at most the SDK HTTP timeout, rounded up, plus 30 seconds; 30-second block wait)
- Reload the token from DB (another process may have already refreshed it)
- Re-check expiry: if it still needs refreshing, **refresh it explicitly now**, under the lock
- **Release the lock**, then run the operation — Case 1 from here on

> **The lock is never held across the operation, and this is load-bearing (fixed in v3.0.0).**
> The SDK's \`EFacturaClient::getValidAccessToken()\` acquires the **same** cache key
> (\`efactura:token_refresh:{vatNumber}\`, and \`cui == vatNumber\`) whenever it decides a token needs
> refreshing. Laravel cache locks are **not re-entrant**, so the pre-v3 code — which held the key
> across \`$operation()\` — made the SDK block on a lock this same process already owned, until it
> timed out and threw \`AuthenticationException('Token refresh lock timeout')\`.
>
> That was **deterministic, not racy**: both layers use a 120-second expiry buffer, so the wrapper
> took the locked path in exactly the cases where the SDK was going to refresh. Token refresh could
> not succeed. By refreshing explicitly and releasing first, the SDK sees a valid token and never
> needs the lock.

**After Operation**

The operation always runs via \`executeWithoutLock()\`, which does its bookkeeping in a \`finally\`, so it also runs when the operation threw:

- **Refreshed** (\`$client->wasTokenRefreshed()\`): the SDK has normally saved the grant already, inside its refresh lock, through \`createClient()\`'s \`tokenPersister\` (same write, \`TokenRefreshed\` when stored); this retry then matches no row. \`saveRefreshedTokens()\` stores the new grant with one conditional \`UPDATE\` (\`WHERE id = ? AND is_active AND (expires_at IS NULL OR expires_at < :new)\`). No lock: the database orders concurrent refreshes, so a stale or equal-expiry grant matches no row, and a deactivated token is never written. \`TokenRefreshed\` fires (through \`rescue()\`) only when the write stored the grant
- **Then, always:** \`last_used_at\` is updated

**The bookkeeping never replaces the operation's result or exception** (changed in v4.0): an upload index ANAF has already assigned is never lost to a failed \`last_used_at\` write. Its failures are logged, never thrown, with the CUI and exception class only: \`Log::critical\` ("refreshed token could not be saved; re-authorisation may be needed") when the refreshed grant could not be saved, because ANAF has already rotated it; \`Log::warning\` for anything after the save.

\`persistTokenRefreshWithLock()\` is **gone** (v4.0). A \`LockTimeoutException\` now comes **only** from the pre-call refresh lock (Case 2), when nothing has been sent. A failed save of that pre-call refresh (\`refreshToken()\`) is logged critical and rethrown, also before anything is sent.

**Deactivated and refused grants (v4.0).** \`executeWithClient()\` refuses a deactivated token with \`RuntimeException("Token for CUI … has been deactivated")\` before every call, not only on the refresh path. When ANAF refuses a refresh with HTTP 400/401, the grant is remembered for 15 minutes under \`efactura:token_refresh_refused:{token id}:{sha1(refresh_token)}\`, checked before the refresh lock and again once it is taken (so workers that waited on the lock do not ask either): later calls throw \`RefreshCooldownException\` (an \`AuthenticationException\`, code 401) without asking ANAF. The key's value is the cooldown's expiry; \`TokenService::refreshCooldownSeconds($token)\` returns the seconds left. An upload stays \`pending\` and its job sleeps the rest of the cooldown without building its XML or spending a transient attempt (the dispatchers add no job meanwhile). A grant that stays refused gets its uploads abandoned with \`InvoiceFailed\` after at most \`rate_limit.retry_window_hours\` (\`failed()\`), usually sooner: after each cooldown the next caller asks ANAF again, and an upload job's refusal spends one transient attempt, so a lone upload is abandoned after about (\`jobs.max_transient_attempts\` − 1) × the 15-minute cooldown (about an hour at the defaults). The cooldown also refuses calls while the access token still has its last 120 seconds or less to run. \`checkAllStatuses()\` skips the grant's rows and logs one warning per grant; status checks are not counted. A re-authorised company has a new refresh token, so its grant is tried at once. The token is not deactivated automatically.

\`handleClientTokenRefresh()\` remains a **public** method for callers driving a client by hand; \`executeWithClient()\` does not route through it. Since v4.0 it uses the same conditional write (it wrote unconditionally), fires \`TokenRefreshed\` only when it stored the grant, and lets its exceptions propagate.

### Usage Pattern

\`\`\`php
// In UploadService or your own service:
$token = EFactura::tokenService()->getToken($cui);

$result = EFactura::tokenService()->executeWithClient(
    $token,
    function (EFacturaClient $client) use ($xmlContent, $options) {
        // B2C invoices go through uploadB2CDocument() instead
        return $client->uploadDocument($xmlContent, $options);
    }
);
\`\`\`

## Token Model

The \`EfacturaToken\` model stores:
- \`cui\` — Company identifier (without RO prefix)
- \`access_token\` — Encrypted at rest (\`encrypted\` cast) since v3.0.0; \`text\` column
- \`refresh_token\` — Encrypted at rest (\`encrypted\` cast) since v3.0.0; \`text\` column
- \`expires_at\` — Token expiry timestamp
- \`is_active\` — Boolean flag (deactivation doesn't delete)
- \`last_used_at\` — Updated on every API call

> **Changed in v3.0.0.** Both credentials are now encrypted at rest via Laravel's \`encrypted\`
> cast. Before v3 they were stored verbatim in plain \`text\` columns, so a database read, a
> backup, or a query log yielded live ANAF credentials. (\`$hidden\` is still set on both fields,
> but it only filters \`toArray()\` / \`toJson()\` — it never affected what was written to disk.)
>
> Reads and writes are transparent: \`$token->access_token\` returns the plaintext credential, and
> \`TokenService\` needs no changes. Nothing queries these columns by value, so no lookups break.

## Upgrading to v4.0

See the \`migration\` topic, "Upgrading v3.1 → v4.0", for every behaviour change, including this
token bookkeeping.

## Upgrading an existing install to v3.0 (token encryption)

Migration \`2024_01_01_000004_encrypt_efactura_token_credentials\` converts pre-v3 plaintext rows.
It is ordered **first** of the v3 migrations precisely because it is the one that restores service.

**The queue workers MUST be stopped before \`php artisan migrate\`, and \`migrate\` must run
immediately after \`composer update\`.**

\`\`\`bash
php artisan down          # then actually stop the worker processes
composer update bee-coded/laravel-efactura
php artisan migrate
php artisan queue:restart # then start the workers again
php artisan up
\`\`\`

Stopping the workers is not optional: a v2 worker alive across the migration can refresh a token and
write **plaintext into the already-encrypted column**. The migration is by then recorded as run, so
re-running it does nothing, and that company throws \`DecryptException\` until it re-authorises. Point
anyone upgrading at the \`migration\` topic before they run a single command.

Between \`composer update\` and \`migrate\`, e-Factura operations for existing companies fail with
\`DecryptException: The payload is invalid.\` This is **by design** — the cast has no plaintext
fallback, because a fallback would hide a migration that never ran while credentials stayed
readable in the database. An unmigrated row fails loudly rather than quietly working.

The migration is idempotent (values that are already ciphertext are skipped, so re-running cannot
double-encrypt) and reversible (\`migrate:rollback\` restores plaintext for a v2 downgrade). A
rollback attempted under the **wrong** \`APP_KEY\` aborts loudly rather than silently leaving
ciphertext behind for a cast-less v2 model to send to ANAF as a Bearer token.

> **\`APP_KEY\` now protects ANAF credentials — new in v3.** Surface this when advising on any
> upgrade, key rotation, or database copy:
>
> - Losing or rotating \`APP_KEY\` without \`APP_PREVIOUS_KEYS\` makes every token undecryptable,
>   and **each connected company must redo the OAuth flow** (\`php artisan efactura:auth {cui}\`).
>   Rotate by setting \`APP_PREVIOUS_KEYS=base64:<previous key>\` alongside the new \`APP_KEY\`.
> - Restoring a production database into staging or local now requires the production \`APP_KEY\`;
>   with a different key the tokens are unreadable. This workflow silently worked before v3
>   because the values were plaintext.
> - \`APP_KEY\` should be backed up somewhere other than the database it protects.

## Events

| Event | Trigger |
|-------|---------|
| \`TokenStored\` | After OAuth callback successfully stores/updates token |
| \`TokenRefreshed\` | After automatic token refresh during an API call |

## Token Deactivation

\`\`\`php
EFactura::tokenService()->deactivateToken($cui);
// Sets is_active = false, does not delete the record
\`\`\`
`,

  commands: `# Laravel e-Factura Wrapper — Artisan Commands

## efactura:auth

Generate an OAuth authorization URL for a CUI.

\`\`\`bash
php artisan efactura:auth {cui}
\`\`\`

Displays the ANAF OAuth authorization URL that the user must visit in a browser to grant access. Useful for initial setup or re-authorization. If a token already exists for the CUI it warns and asks for confirmation before issuing a new URL.

**Arguments:**
- \`{cui}\` — The company's CUI (VAT number, without RO prefix)

> **This CLI flow works as of v3.0.0** — the OAuth state moved from the (unreachable) console
> session to the cache. It requires a shared cache store, since the callback completes in the web
> process. Previously it could never complete.

## efactura:upload

Queue all pending uploads onto the rate-limit-aware pipeline, or filter by CUI.

\`\`\`bash
php artisan efactura:upload [--cui=] [--sync]
\`\`\`

**Changed in v3.0.0: this now QUEUES.** It calls \`UploadService::dispatchPendingUploads()\`, which dispatches one \`ProcessSingleUpload\` job per pending row. **Nothing uploads until a queue worker consumes them** — the command prints \`Queued N pending upload(s) for processing.\` and reminds you which queue to run. Since v4.0 it also prints \`Left out M pending upload(s): X recently queued or rate-limited (…), Y on a company whose token refresh ANAF refused recently …\` when it skipped any (\`UploadService::pendingLeftOut()\`). Respects the \`features.upload_invoices\` feature flag (exits \`FAILURE\` with \`e-Factura upload feature is disabled.\` when off).

> **Why it changed.** It used to call \`processPendingUploads()\` inline, bypassing
> \`ProcessSingleUpload\` entirely — and with it the rate-limit pre-flight and the \`release()\`-based
> retry. Run against a backlog larger than ANAF's remaining quota, it marked every invoice past the
> limit permanently \`Failed\` and fired an \`InvoiceFailed\` storm, for a purely transient condition.

**Options:**
- \`--cui=\` — Optional CUI filter (RO prefix stripped automatically). On the **default (queueing)** path an unknown CUI exits \`FAILURE\` with \`No active token found for CUI: {cui}\`
- \`--sync\` — Process **inline** instead of queueing, for deployments with no queue worker. Warns \`Running inline: no rate-limit retry. Uploads stop at the first rate limit.\` and prints \`Processed N upload(s).\` Unlike the pre-v3 inline path, it now **stops at the first rate limit** rather than burning down the backlog. It takes every \`pending\` row, fresh or rate-limited (its next run retries a row it left rate-limited), and skips a grant in its refresh cooldown, printing \`Skipped N upload(s) of a company whose token refresh ANAF refused recently …\` separately (v4.0)

> **\`--sync\` swallows an unknown \`--cui\`.** The \`--sync\` branch returns *before* the unknown-CUI
> check, and \`processPendingUploads()\` only writes a \`Log::warning\` and returns \`0\` for a CUI with no
> token. So \`efactura:upload --sync --cui=99999999\` prints \`Processed 0 upload(s).\` and exits
> **SUCCESS** — indistinguishable from "nothing was pending". The \`FAILURE\` above applies to the
> queueing path only. Verify the CUI yourself if you script against this.

## efactura:reconcile

Resolve uploads whose delivery to ANAF is **unknown**. New in v3.0.0.

\`\`\`bash
php artisan efactura:reconcile [--filed= --index=] [--not-filed= [--despite-index]]
\`\`\`

With no options, lists every upload parked \`Failed\` / \`indeterminate\` in a table (ID, CUI, Model, Model ID, Upload index, Uploaded at, Parked at, XML) so an operator can look the upload index up in ANAF's SPV, or, without one, search SPV around the parked time. These uploads are **never re-submitted automatically**.

**Options:**
- \`--filed=<id> --index=<index_incarcare>\` — Operator confirmed the document **is** filed at ANAF. Hands the row back to the normal status-check pipeline as \`Processing\`. \`--index\` is required; without it: \`--index is required with --filed: supply the index_incarcare ANAF assigned.\` It is trimmed and must be 1 to 64 digits: otherwise \`UploadService::resolveIndeterminateAsFiled()\` throws \`InvalidArgumentException\` (v4.0) and the command prints \`The ANAF upload index must be 1 to 64 digits.\` and exits \`FAILURE\`, changing nothing
- \`--not-filed=<id>\` — Operator confirmed the document is **not** at ANAF. Returns the row to \`Pending\` for a normal re-upload. Refused for a row with an \`upload_index\` (v4.0): ANAF decides that upload's outcome under its index, so \`UploadService::resolveIndeterminateAsNotFiled()\` throws \`InvalidArgumentException\` and changes nothing, and the command prints why, suggests \`--filed=<id> --index=<its upload index>\` and exits \`FAILURE\`
- \`--despite-index\` (with \`--not-filed\`, v4.0) — the operator checked SPV under the upload's index and the invoice is not there (ANAF keeps answering the index with an error, so \`--filed\` only parks it again). Asks for confirmation; refused with \`--no-interaction\` or a "no", changing nothing. Re-queues the row as \`Pending\`, clears \`upload_index\` and keeps it in \`errors\`, and logs a warning (\`resolveIndeterminateAsNotFiled($upload, despiteIndex: true)\`)

Both refuse rows that are not awaiting reconciliation: \`Upload {id} is not awaiting reconciliation (status: {status}).\`

> **Not automated on purpose.** An upload parked before ANAF answered has no \`index_incarcare\`,
> one parked by a status check has an index that gave no verdict, and ANAF's message listing exposes
> no invoice number to match on. Guessing would mean spending ANAF quota to answer a legal question wrongly in one of two
> directions: double-filing, or leaving a required filing undone.

## efactura:status

Check upload statuses and download responses.

\`\`\`bash
php artisan efactura:status [--cui=] [--upload=]
\`\`\`

Checks ANAF processing status for uploads in \`Processing\` state, updates their status to \`Completed\` or \`Failed\`, and downloads response ZIPs.

> **ZIPs are downloaded for \`Failed\` uploads too**, not just \`Completed\` ones — a rejected document's
> ZIP is ANAF's error report, which is exactly what you need. The scope is
> \`whereIn('status', [Completed, Failed])\` + has \`download_id\` + no \`response_path\` yet. Only the
> \`InvoiceProcessed\` **event** is restricted to \`Completed\`.

**Options:**
- \`--cui=\` — Optional CUI filter (RO prefix stripped). Exits \`FAILURE\` with \`No active token found for CUI: {cui}\` if unknown
- \`--upload=\` — Optional upload ID to check a single specific upload. Exits \`FAILURE\` with \`Upload #{id} not found.\` if it does not exist. Prints \`Done.\` only when ANAF answered (v4.0): a check skipped at the per-message status limit (\`Skipped: the per-message status limit of upload #{id} is reached, try again later.\`) or a failed one (\`The status check of upload #{id} failed; see the log.\`) exits \`FAILURE\`, and an upload with nothing to poll says why (\`Upload #{id} was not polled: ...\`). When it then downloads the response and that download is skipped (per-message download limit) or fails, it warns \`The response of upload #{id} was not downloaded (limit reached or error; see the log).\` followed by \`DownloadResponses retries it.\`, or, once the upload has used up \`EfacturaUpload::MAX_RESPONSE_ATTEMPTS\`, \`It failed {n} times and is no longer downloaded automatically.\`

## efactura:sync

Sync messages from ANAF.

\`\`\`bash
php artisan efactura:sync [--cui=] [--download]
\`\`\`

Syncs the ANAF message list into \`EfacturaMessage\` records and prints \`Synced N message(s).\` With \`--download\`, also downloads received invoices — as **ZIP archives** (stored under \`{storage.path}/received/{cui}/\`), not raw XML.

> **Sync failures are now reported (v3.0.0).** \`syncMessages()\` / \`syncAllMessages()\` return an
> \`int\` count and **throw** on failure instead of swallowing errors. The command catches
> \`MessageSyncFailedException\` (and any other \`\\Throwable\`, which it also \`report()\`s), prints the
> message, and exits \`FAILURE\` — so a broken sync no longer looks like a clean run. Previously every
> error was caught and logged as one line, which is exactly how a wire-contract bug survived for
> months: every run reported success while syncing **zero** messages.

> **\`--download\` is a silent no-op unless \`features.download_received\` is enabled.** The flag is
> AND-ed with \`config('efactura.features.download_received')\`, which defaults to **false**. With the
> default config the command syncs messages, prints \`Done.\`, exits \`SUCCESS\`, and downloads nothing —
> no warning is emitted. Set \`EFACTURA_DOWNLOAD_RECEIVED=true\` to actually download.

**Options:**
- \`--cui=\` — Optional CUI filter to sync only a specific company
- \`--download\` — Also download received invoice ZIPs after syncing messages. Requires \`features.download_received\` (default: false)
`,

  migration: `# Laravel e-Factura — Upgrading

Two guides: **v3.1 → v4.0** first, then **v2 → v3.0**. Upgrading from v2, do v3.0 first.

## Upgrading v3.1 → v4.0

Every behaviour change since v3.1.1 (also in \`CHANGELOG.md\`):

1. **Requires \`bee-coded/laravel-efactura-sdk\` ^4.0.** It reports an upload \`ExecutionStatus\` that
   is missing or not exactly 0/1 as unknown, returns \`null\` for an ANAF value it cannot use (for
   example a missing or non-scalar id) instead of throwing a \`TypeError\`, and its
   \`RateLimitExceededException\` carries a \`$scope\` (\`RateLimitScope\`).
2. **Run \`php artisan migrate\`.** \`..._000008_add_failure_counters_to_efactura_tables\` adds
   \`efactura_uploads.status_failures\` and \`efactura_messages.download_attempts\` (default 0).
   Additive, plus one data update (item 15), and re-entrant; it cannot abort. Run migrate before
   queue workers start the 4.0 code (keep workers stopped during the deploy): 4.0 writes the new
   columns right after ANAF accepts an upload.
3. **Upload answers** (previously \`Failed\` / \`validation\`): a digit-only \`index_incarcare\` (trimmed
   by the SDK) is \`Processing\` with that index and fires \`InvoiceUploaded\`, whatever
   \`ExecutionStatus\` says. An answer that does not say whether ANAF took the document in (an
   unreadable 2xx body, a non-digit index, success without an index, no recognised status) is
   \`Failed\` / \`indeterminate\` + \`InvoiceFailed\`. Only \`ExecutionStatus=1\` without an index stays
   \`validation\`.
4. **Status answers:** \`ok\` without a download id is \`Completed\` with \`download_id = null\` and a note
   (no response download, so no \`InvoiceProcessed\`). **\`nok\` without a download id is
   \`indeterminate\`, keeping the index (was \`validation\`).** "XML cu erori nepreluat de sistem" is
   \`validation\` with no download. Permanent status-check failures are counted in
   \`status_failures\` and park the upload \`indeterminate\` at \`polling.max_status_failures\`
   (\`EFACTURA_POLLING_MAX_STATUS_FAILURES\`, default 5); transient ones are retried uncounted. A
   per-message status limit skips that upload; a global limit or ANAF's 429 still stops the batch.
   An unreadable 2xx status answer is a permanent failure; the parked upload keeps its index, so
   recover it with \`efactura:reconcile --filed=<id> --index=<its upload index>\`. **Code that re-arms
   a parked upload itself** (a raw update back to \`Processing\`) must also reset \`status_failures\`
   to 0, or the next permanent failure parks it again. \`checkStatus()\` returns a
   \`StatusCheckOutcome\` instead of \`void\`; \`efactura:status --upload\` prints \`Done.\` only when ANAF
   answered and exits \`FAILURE\` for a skipped or failed check.
   See the \`upload-pipeline\` topic for the full table.
5. **Downloads:** permanent response / received-invoice download failures (now also a 4xx or a
   refused id) count against \`EfacturaUpload::MAX_RESPONSE_ATTEMPTS\` /
   \`EfacturaMessage::MAX_DOWNLOAD_ATTEMPTS\` (3 each); transient ones are not counted; a per-message
   download limit skips the row.
6. **\`queueUpload()\` refuses every \`indeterminate\` row** with \`DuplicateUploadException\`, so the new
   \`indeterminate\` outcomes need \`php artisan efactura:reconcile\`, not re-queueing.
7. **New exceptions:** \`UploadService::resolveIndeterminateAsFiled()\` throws
   \`InvalidArgumentException\` for an index that is not 1 to 64 digits after trimming;
   \`efactura:reconcile --filed\` prints it and exits \`FAILURE\`.
   \`UploadService::resolveIndeterminateAsNotFiled()\` throws \`InvalidArgumentException\` for an
   upload with an \`upload_index\` (ANAF took it in; re-queueing would double-file it);
   \`efactura:reconcile --not-filed\` prints why, suggests \`--filed=<id> --index=<its index>\` (or
   \`--not-filed=<id> --despite-index\` when SPV has nothing under it) and exits \`FAILURE\`. The reconcile list shows Upload index, Uploaded at and Parked at (was Claimed at).
8. **Listeners:** an exception from a listener of \`TokenStored\`, \`TokenRefreshed\`,
   \`InvoiceUploaded\`, \`InvoiceProcessed\`, \`InvoiceFailed\`, \`InvoiceRateLimited\` or
   \`InvoiceReceived\` is reported, never propagated, and never changes the state recorded before the
   event. A throwing \`TokenStored\` listener no longer sends the OAuth callback to the error URL.
9. **Token bookkeeping** after a call never replaces its result or exception; a refresh the SDK makes
   during a call is saved inside the SDK's refresh lock (\`tokenPersister\`) and again after the call,
   even one that threw, by a conditional write. \`handleClientTokenRefresh()\` uses the same write and
   fires \`TokenRefreshed\` only when it stored the grant.
   \`persistTokenRefreshWithLock()\` is gone; \`LockTimeoutException\` comes only from the pre-call
   refresh lock. See the \`token-management\` topic.
10. **Message sync** skips (with a warning) a listed message without a usable id instead of
    upserting it; such messages used to merge into one row.
11. **New translation keys:** \`completed_without_download_id\`, \`status_nok_without_download_id\`,
    \`status_poll_failures_exhausted\`, \`not_processed_at_anaf\`, \`upload_outcome_unknown\`.
12. **Recommended schedule:** one cadence per job, in the Job Reference's "Recommended Scheduling"
    table.
13. **Laravel 11 is no longer supported:** \`illuminate/*\` is now \`^12.0|^13.0\`.
14. **OAuth routes:** \`routes.middleware\` defaults to \`['web', 'auth']\` (was \`['web']\`). Add a
    check that the user may act for the CUI (for example \`can:\`), or disable the routes.
15. **Migration \`000008\` reclassifies** pre-4.0 \`failed\` rows with \`validation\` or no
    \`failure_reason\` (pre-3.0, left unclassified by \`000005\`) with an \`upload_index\` and
    no \`download_id\` (3.x \`nok\` answers without a download id) as \`indeterminate\` (review with
    \`efactura:reconcile\`). 3.x never stored the index on a failed upload answer. Idempotent before 4.0
    writes rows; re-run after a rollback it also moves 4.0's "nepreluat" rows (the safe direction). A 3.x \`validation\` row
    without an index cannot be told apart from a real rejection: check SPV before re-queueing it.
16. **Tokens:** a deactivated token is refused before every call; a grant ANAF refused to refresh
    (400/401) is not retried for 15 minutes (\`RefreshCooldownException\`, re-checked after the refresh
    lock). An upload refused this way stays \`pending\` and its job sleeps the rest of the cooldown
    without building its XML or spending a transient attempt; a grant that stays refused leaves its
    uploads \`pending\` for at most \`rate_limit.retry_window_hours\`, then \`failed()\` abandons them with
    \`InvoiceFailed\` (usually after about (\`jobs.max_transient_attempts\` − 1) × the 15-minute cooldown, about an hour at the defaults: each ask after a
    cooldown spends an upload's transient attempt). The cooldown also refuses calls in the access
    token's last 120 s. A status run logs the cooldown once per grant. See the \`token-management\` topic.
17. **Uploads:** \`ProcessPendingUploads\` and \`dispatchPendingUploads()\` skip
    a \`pending\` row with a \`failure_reason\`, or changed within \`jobs.transient_retry_delay_seconds\`
    + 60 s, including a freshly queued row (\`EfacturaUpload::awaitingDispatch()\`): scheduler-only hosts
    send a new upload up to ~90 s later, so dispatch \`ProcessSingleUpload\` yourself for an immediate
    send. The two queue dispatchers also skip a row whose grant is in its refresh cooldown, and
    \`efactura:upload\` prints how many rows it left out. The synchronous \`processPendingUploads()\`
    (\`--sync\`) takes every \`pending\` row (no job owns one there) except a cooling grant's;
    \`RetryRateLimitedUploads\` re-dispatches a \`pending\` / \`rate_limited\` row only after
    \`rate_limit.retry_window_hours\` without change, whatever its age. A job that finds uploads
    disabled hands its rate-limited row back to \`ProcessPendingUploads\`. \`queueUpload()\` recycles a failed row
    with a guarded write and fresh counters. The transient cap counts transient failures per queued
    job in the cache, not \`attempts()\`. An XML file that cannot be stored is \`transient\`, without
    \`InvoiceFailed\`.
18. **Status checks and downloads** write only to a row still \`Processing\` (a response only when none
    is stored) and fire their event only for the run that wrote it; the \`nok\` \`InvoiceFailed\`
    carries ANAF's errors. An error-only status answer keeps ANAF's errors on the parked row. A
    download that cannot be stored is retried uncounted. A received invoice is marked downloaded only
    while \`is_downloaded\` is false (\`markMessageAsDownloaded()\` returns \`bool\`), \`InvoiceReceived\`
    fires once, and a run that loses a download write deletes the file it stored.
19. **Smaller:** \`efacturaAwaitingResponse()\` respects \`MAX_RESPONSE_ATTEMPTS\`; the
    \`DuplicateUploadException\` hint for an indexed upload offers \`--filed\` or \`--not-filed --despite-index\`; non-digit message ids
    are skipped; every \`MESAJ CUMPARATOR…\` tip is received; \`markUploadAsUploading()\` (\`UploadService\`)
    is removed.
20. **No Eloquent model events** for the guarded writes: status answers, response paths, recycled
    failed rows (\`EfacturaUpload\`), received-invoice downloads (\`EfacturaMessage\`) and refreshed
    grants (\`EfacturaToken\`) are query-builder updates, so observers and \`updating\` / \`updated\` /
    \`saving\` / \`saved\` listeners do not see them. Listen to \`InvoiceProcessed\`, \`InvoiceFailed\`,
    \`InvoiceReceived\` and \`TokenRefreshed\` instead.
21. **OAuth state is bound to who started it:** a flow started through \`efactura/auth/{cui}\` completes
    only in the same browser session for the same user (otherwise "Invalid or expired state
    parameter"). Your own controller binds it with \`EFactura::getAuthorizationUrl($cui, $nonce, $userId)\`
    and \`validateOAuthState($state, $nonce, $userId)\`; \`efactura:auth\` URLs stay unbound.
    \`OAuthCallbackController::redirect()\` now takes the \`Request\` first.
22. **Removed:** \`UploadService::markUploadAsCompleted()\` and \`TokenService::updateTokenFromOAuth()\`
    (unguarded writes nothing called; store a grant with \`TokenService::storeToken()\`), and the
    protected \`SyncMessages::failBatch()\`.
23. **Message sync is paginated:** one fixed window per run (60 days minus 70 minutes ago to a minute
    ago, counted in UTC so an \`app.timezone\` with summer time cannot stretch it past ANAF's limit),
    one \`paginated_list_per_day_cui\` call per page of 500, so a CUI with over 500 messages no
    longer fails. \`MessageSyncService::reportsNoMessages()\` takes a \`PaginatedMessagesResponseData\`.
    Known limit: a run restarts at page 1 within the job's 120 s timeout, about 40 pages (~20,000
    messages in 60 days) per CUI; a CUI with more times out every run.
24. **Batch jobs without a CUI fan out:** \`SyncMessages\`, \`CheckUploadStatuses\`, \`DownloadResponses\`
    and \`DownloadReceivedInvoices\` only dispatch one job per active CUI (with due work, for the last
    three). \`SyncMessages\` is \`ShouldBeUnique\` per CUI and discards itself when stale, except as a
    child of a run without a CUI (children wait behind each other and are never stale); the three
    upload/download jobs run behind \`WithoutOverlapping\` per CUI. Their loops (and the sweep and
    pending dispatchers) use \`lazyById()\`, so rows past the first 1,000 are no longer skipped.
25. **\`RetryRateLimitedUploads\`** counts \`retry_max_age_days\` from \`processed_at\` (when the row
    failed) and casts both retry limits, which threw a \`TypeError\` when set from \`.env\`.
26. **Unsent XML files** are deleted and \`xml_path\` is null while an upload waits after a rate limit,
    a refresh cooldown or a transient failure; a run that loses the claim deletes its file.
27. **Morph maps:** \`uploadable_type\` is the model's \`getMorphClass()\`; \`queueUpload()\` also finds a
    row stored under the class name.
28. **Upload outcomes come from the SDK's \`UploadOutcome\`:** a \`RequestNotSentException\` (provably
    never sent) is \`transient\` and retried, and an HTTP 408 is \`indeterminate\` (was \`validation\`).
    \`UploadService::classifyApiException()\` is gone. The cooldown hand-back and the "thrown before
    the client = transient" rule still apply first.
29. **Company lookup:** \`RetryingAnafDetailsClient\` no longer retries ANAF's own 429
    (\`RateLimitExceededException::$fromProvider\`), only the SDK's local limiter.
30. **\`QUEUE_CONNECTION=sync\`:** a null-CUI batch job runs its per-CUI jobs inline, so one CUI's
    exception propagates through the parent and stops that run.
31. **PostgreSQL token expiry:** migration \`000009\` converts \`efactura_tokens.expires_at\` to
    \`timestamp with time zone\` \`AT TIME ZONE\` \`app.timezone\` (run it with the zone that wrote the
    values); \`EfacturaToken\` writes dates with their offset there. Update \`expires_at\` through the
    model, not a query-builder Carbon binding (read in the session time zone). No-op elsewhere.
32. **\`pipeline_tables\`** (default \`true\`): \`false\` loads only the token migrations and makes the
    upload/message pipeline throw \`PipelineTablesDisabledException\`. \`efactura:auth\` and
    \`efactura:reconcile\` now refuse while \`enabled\` is false.
33. **Migration \`000010\`** adds \`efactura_uploads.response_verification\` (\`ResponseVerification\`:
    \`verified\`, \`limits_only\`, \`mismatch\`) and \`next_poll_at\` (index \`(status, next_poll_at)\`).
    Response and received-invoice ZIPs are checked by the SDK's \`ArchiveInspector\` (received ones
    against the limits only) (needs \`ext-zip\`, \`ext-dom\`); status
    reads follow \`PollSchedule\`, so \`CheckUploadStatuses\` reads and fans out to due rows only.
    \`efactura:status --upload=<id>\` still checks at once.
34. **\`CachedAnafLookup\`:** a cached, fail-closed company check (see the overview topic).
35. **Migration \`000011\`** makes \`efactura_messages\` unique per \`(efactura_token_id, message_id)\`;
    sync looks a message up by both, so two connected companies that list the same ANAF id each keep
    their own row.

## Upgrading v2 → v3.0

Follow these steps **in order**. Each says what breaks, the symptom you will actually see, and the
exact change. Steps 0–5 are ordered deliberately: getting them out of order leaves the app in a
state where **no company can file an invoice** — and in one case (Step 1) leaves a company's
credentials permanently orphaned, which no re-run of the migration will repair.

**This upgrade requires a maintenance window with your queue workers stopped.** That is a hard
requirement, not a recommendation. See Step 1.

v3.0.0 requires **SDK ^3.0** (\`bee-coded/laravel-efactura-sdk\`). The SDK has its own breaking
changes that land in **your** \`toEfacturaData()\` — see Step 8. Do not skip it.

---

## Step 0 — Prerequisites (on the OLD version, before any command)

### 0a. Back up \`APP_KEY\`, somewhere other than the database

From v3.0.0 \`APP_KEY\` decrypts your ANAF OAuth credentials. Lose it and **every connected company
must redo the OAuth flow**. It was never load-bearing for e-Factura before, so it may never have
been treated as a secret worth backing up. It is now.

### 0b. Confirm your cache store is shared and persistent

Not \`array\`. Redis, memcached or database. The OAuth state moved into the cache (Step 9) and token
refresh uses cache locks.

### 0c. Audit for writes that bypass the model

\`Builder::update()\` and raw SQL skip casts, so any query-builder write to
\`efactura_tokens.access_token\` / \`refresh_token\` will silently store unreadable plaintext from v3
on. Fix these before deploying — details in Step 4.

### 0d. Size the unique-index build

\`\`\`sql
SELECT COUNT(*) FROM efactura_uploads;
\`\`\`

Step 4 runs \`ALTER TABLE efactura_uploads ADD UNIQUE (uploadable_type, uploadable_id)\`. Up to
~100k rows that is seconds. On **millions** of rows expect **minutes**, holding a brief exclusive
metadata lock, and it can fail outright on \`innodb_lock_wait_timeout\` under concurrent writes.
Stopping the workers (Step 1) removes most of that risk. If the table is large enough that the
window itself is the problem, build the index out of band with \`pt-online-schema-change\` or
\`gh-ost\` and mark the migration as run instead.

### 0e. Plan the maintenance window

It must cover Steps 1–5. Between \`composer update\` and \`php artisan migrate\`, e-Factura is down by
design (Step 4).

---

## Step 1 — Stop the queue workers (MANDATORY)

\`\`\`bash
php artisan down
\`\`\`

Then **actually stop the worker processes** (\`supervisorctl stop laravel-worker:*\`, or your
orchestrator's equivalent) and confirm nothing is in flight.

\`php artisan down\` is a necessary start but **is not sufficient**:

- \`queue:work\` does respect maintenance mode, but a worker already **mid-job** finishes that job —
  including a token refresh.
- Workers started with \`queue:work --force\` ignore maintenance mode entirely.
- Horizon needs \`php artisan horizon:pause\` (or \`horizon:terminate\`).

### Why this is mandatory

A v2 worker still alive while Step 4 runs will do one of two things, both bad:

1. **Refresh a token and write PLAINTEXT into an already-encrypted column.** v2's
   \`$token->update([...])\` has no cast. By then the encryption migration is already **recorded as
   run**, so the remedy you would reach for — "re-run the migration" — **does nothing**. That
   company throws \`DecryptException\` on every operation until it re-authorises
   (\`php artisan efactura:auth {cui}\`). This is the only failure in this upgrade with no clean
   recovery.
2. **Read an already-encrypted row and send the ciphertext to ANAF as a Bearer token.** ANAF answers
   \`401\` and that batch of invoices parks as \`Failed\`.

Neither is a race you can win by being quick.

---

## Step 2 — Resolve duplicate uploads (inside the window, writes stopped)

\`\`\`sql
SELECT uploadable_type, uploadable_id, COUNT(*) AS copies
FROM efactura_uploads
GROUP BY uploadable_type, uploadable_id
HAVING COUNT(*) > 1;
\`\`\`

If this returns rows, resolve them before Step 4. Migration \`..._000006\` adds a unique
\`(uploadable_type, uploadable_id)\` index and **aborts** when duplicates exist:

\`\`\`
RuntimeException: Cannot add the unique (uploadable_type, uploadable_id) index: efactura_uploads
already contains duplicate uploads for the same model.
\`\`\`

For each duplicate: check ANAF (SPV), keep the row reflecting the real filing, delete the rest. The
abort is deliberate — a duplicate can mean an invoice was filed at ANAF twice, and a migration does
not get to silently delete legal records.

**Run it here, not the day before.** v2's \`queueUpload()\` has no duplicate guard, so a check run
while v2 is still serving traffic is only true until the next request reintroduces a duplicate. With
writes stopped (Step 1) the result is authoritative.

**It is no longer catastrophic if you miss one.** Token encryption is ordered **first** (Step 4), so
an abort here no longer leaves tokens unreadable. It still stops the run: the migrations after it
(\`000007\` onwards) do not run until it succeeds, so keep the workers stopped and the app down until
it does. This step saves you that failed deploy.

---

## Step 3 — composer update

\`\`\`bash
composer update bee-coded/laravel-efactura
\`\`\`

This brings wrapper v3.0.0 and SDK ^3.0.

---

## Step 4 — php artisan migrate (IMMEDIATELY after Step 3)

\`\`\`bash
php artisan migrate
\`\`\`

Three new migrations run, **in this order**:

| Order | Migration | What it does |
|-------|-----------|--------------|
| 1st | \`..._000004_encrypt_efactura_token_credentials\` | Encrypts the plaintext \`access_token\` / \`refresh_token\` already in \`efactura_tokens\`. **Ordered first deliberately**: it is the migration that restores service for the v3 code you just deployed, so nothing that can abort is allowed to run ahead of it |
| 2nd | \`..._000005_add_failure_reason_to_efactura_uploads_table\` | Adds the indexed \`failure_reason\` column; backfills \`rate_limited\` for rows whose legacy \`errors\` text carries a \`RATE_LIMIT_EXCEEDED:\` marker. Anything unrecognised stays \`NULL\` rather than being guessed. **Re-entrant** — an interrupted backfill resumes on the next \`migrate\` instead of wedging on a duplicate-column error |
| 3rd | \`..._000006_add_unique_uploadable_index_to_efactura_uploads_table\` | Adds the unique \`(uploadable_type, uploadable_id)\` index. **Aborts on pre-3.0 duplicates** before touching this table's schema (Step 2). Ordered after encryption because it is the only one expected to abort; an abort stops the run, so the migrations after it have not run either |
| 4th | \`..._000007_add_response_attempt_tracking_to_efactura_uploads_table\` | Adds \`response_attempts\` and \`response_failed_at\` so a poisoned \`/descarcare\` body (2xx but not a ZIP) is retried a bounded number of times instead of on every scheduled run forever. Additive columns only; cannot abort |

### What breaks

**Symptom:** between Step 1 and Step 2, every e-Factura operation for an existing company throws:

\`\`\`
Illuminate\\Contracts\\Encryption\\DecryptException: The payload is invalid.
\`\`\`

**Why:** \`EfacturaToken\` now casts both credentials to \`encrypted\`. The cast only governs values
written **through the model**, so every row written by v2 is still plaintext. There is deliberately
**no plaintext fallback** — a fallback would let a migration that never ran pass unnoticed while your
credentials stayed readable in the database. It fails loudly at the point of use instead.

**Fix:** run the migration. It is **idempotent** (values that already decrypt are skipped, so
re-running cannot double-encrypt — safe after a partial failure, and safe on a table mixing migrated
and freshly-authorised rows) and **reversible** (\`migrate:rollback\` restores plaintext for a v2
downgrade). No schema change: both columns are already \`text\`.

### New \`APP_KEY\` obligations

- **Rotating \`APP_KEY\`** without \`APP_PREVIOUS_KEYS\` orphans every token → each company must rerun
  \`php artisan efactura:auth {cui}\`. Rotate like this instead:
  \`\`\`dotenv
  APP_KEY=base64:<new key>
  APP_PREVIOUS_KEYS=base64:<previous key>
  \`\`\`
- **Restoring a production database into staging/local** now needs the production \`APP_KEY\`, or the
  tokens are unreadable. This silently worked before v3 because the values were plaintext.
  (Re-authorising in staging is often the better answer than copying the key.)

### One new hazard: writes that bypass the model

\`Builder::update()\` bypasses casts. Any query-builder or raw-SQL write to these columns now stores
**unreadable plaintext**, with no error:

\`\`\`php
// BROKEN as of v3.0.0 — writes plaintext into an encrypted column, silently.
DB::table('efactura_tokens')->where('cui', $cui)->update(['access_token' => $raw]);
EfacturaToken::where('cui', $cui)->update(['access_token' => $raw]); // also bypasses casts

// Correct — go through the model instance so the cast runs.
$token = EfacturaToken::forCui($cui)->first();
$token->update(['access_token' => $raw]);
\`\`\`

Audit your app for raw writes to \`efactura_tokens\` before deploying.

### If \`migrate\` aborts: how to get out of it

An abort is **not** a no-op at the database level, whatever a migration's own docblock may imply
about leaving things "untouched". Migrations that already ran are committed and **recorded**; only
the aborting one is not. What you are left with depends on where it stopped:

| Aborted at | State | Do this |
|------------|-------|---------|
| \`..._000004\` (encryption) | Tokens partly or not encrypted. e-Factura is **down** | Idempotent — fix the cause and re-run \`php artisan migrate\`. It resumes safely and cannot double-encrypt |
| \`..._000005\` (failure_reason) | Tokens **encrypted, service is up**. Column may exist with the backfill unfinished | Re-entrant — re-run \`php artisan migrate\`. It skips the DDL it already did and resumes the backfill |
| \`..._000006\` (unique index) | Tokens **encrypted**. The index is missing **and \`000007\` onwards have not run** (Laravel stops at the first failure). The code writes their columns, from queue workers and web requests alike, so with the app up every upload ANAF accepts is parked for reconciliation | Do not run Step 5: keep the queue workers stopped and the app down until \`php artisan migrate\` finishes. Resolve the duplicates (Step 2), then re-run \`php artisan migrate\`. If service must resume first, run each later migration (\`000007\` onwards) with \`php artisan migrate --path=…\` (one \`--path\` per file, or your published copies), then run Step 5. The pre-3.0 duplicate race stays open until the index exists |

**Emergency lever — restore service immediately.** If you are down and need tokens working *now*,
without first resolving duplicates or anything else, run the encryption migration on its own:

\`\`\`bash
php artisan migrate --path=database/migrations/2024_01_01_000004_encrypt_efactura_token_credentials.php
\`\`\`

Safe at any point: idempotent, touches only \`efactura_tokens\`, and has no dependency on the other
two. (If you published the migrations into your app, point \`--path\` at your copy.) Then deal with
the rest at your own pace.

---

## Step 5 — Bring it back up

\`\`\`bash
php artisan queue:restart
php artisan up
\`\`\`

Start your worker processes again (\`supervisorctl start laravel-worker:*\`). They come back on the
new code because they are new processes — \`queue:restart\` is the belt-and-braces for any worker
that survived Step 1, since a stale one holds pre-v3 code in memory and will keep firing
\`InvoiceFailed\` on rate limits and writing plaintext credentials.

---

## Step 6 — Wrapper API changes (code)

### 6a. \`resetForRateLimit()\` → \`resetForRetry()\`

**Symptom:** \`Error: Call to undefined method BeeCoded\\EFactura\\Services\\UploadService::resetForRateLimit()\`

\`\`\`php
// Before
$uploadService->resetForRateLimit($upload);

// After
$uploadService->resetForRetry($upload);   // returns bool
\`\`\`

It now covers every retryable reason (not just rate limits) and **refuses non-retryable ones**: the
atomic reset filters on \`whereIn('failure_reason', FailureReason::retryable())\`, so an
\`indeterminate\` upload can never be re-driven from here. Returns \`true\` only if the reset applied.

### 6b. \`markUploadAsFailed()\` gained a 4th parameter

\`\`\`php
public function markUploadAsFailed(
    EfacturaUpload $upload,
    array $errors,
    ?string $downloadId = null,
    FailureReason $reason = FailureReason::Validation,   // NEW
): void
\`\`\`

It **defaults to \`FailureReason::Validation\`**, so existing 2- and 3-argument calls keep compiling
and are classified as terminal/non-retryable. That is usually right — but if you were marking a
failure you expected to be retried, pass the reason explicitly:

\`\`\`php
$uploadService->markUploadAsFailed($upload, [$e->getMessage()], reason: FailureReason::Transient);
\`\`\`

### 6c. \`processPendingUploads()\`: \`void\` → \`int\`

Returns how many uploads it processed. It now **stops at the first rate limit** instead of ploughing
through the backlog and marking every invoice past ANAF's quota permanently \`Failed\`.

Prefer the new \`dispatchPendingUploads(?string $cui = null): ?int\` — it dispatches
\`ProcessSingleUpload\` per row and so keeps the quota pre-flight and release-based retry.
(It returns \`null\` when the CUI is unknown.)

### 6d. \`syncMessages()\` / \`syncAllMessages()\`: \`void\` → \`int\`, and they THROW

\`\`\`php
public function syncMessages(EfacturaToken $token, ?MessageFilter $filter = null): int  // throws \\Throwable
public function syncAllMessages(?MessageFilter $filter = null): int                     // throws MessageSyncFailedException
\`\`\`

Both return a synced-message count. Errors now **propagate** instead of being logged and swallowed.

- \`syncMessages()\` re-throws whatever the SDK/transport raised — it does **not** wrap it.
- \`syncAllMessages()\` keeps per-token isolation (one company's revoked token must not stop the
  others) but no longer reports false success: once every token has had its turn, it throws
  \`MessageSyncFailedException\` carrying a \`failures\` map of \`CUI => error\`.

**Symptom if you do nothing:** \`SyncMessages\` jobs that used to "succeed" silently now fail loudly
and land in \`failed_jobs\`. That is the point — it is surfacing breakage that was always there.

\`\`\`php
use BeeCoded\\EFactura\\Exceptions\\MessageSyncFailedException;

try {
    $count = $messageSyncService->syncAllMessages();
} catch (MessageSyncFailedException $e) {
    // $e->failures — ['12345678' => 'Token refresh failed', ...]
    report($e);
}
\`\`\`

### 6e. \`queueUpload()\` is now idempotent — and can throw

**Symptom:** \`BeeCoded\\EFactura\\Exceptions\\DuplicateUploadException\`, message:
\`Cannot re-queue upload {id}: its delivery to ANAF is indeterminate. Re-submitting could double-file this invoice. Reconcile it first with ...\`

A model now has **at most one** upload row, enforced by the unique index. Re-queueing recycles that
row instead of inserting another, and the race the index closes is handled for you: if a concurrent
request wins the insert, \`queueUpload()\` catches the unique violation, re-reads, and returns that
row — it does **not** surface a \`QueryException\`.

Two situations throw \`DuplicateUploadException\`:

| Existing row | Re-queued with… | Result |
|---|---|---|
| \`Failed\` / \`indeterminate\` | anything | **Throws** — delivery is unknown, re-sending could double-file |
| \`Uploading\` / \`Processing\` / \`Completed\` | **different** options (\`b2c\`, \`standard\`, \`extern\`, \`self_billed\`) | **Throws** — the options describe what was already sent; rewriting them would make the row lie |
| \`Uploading\` / \`Processing\` / \`Completed\` | the same options | Returns the existing row (idempotent) |
| \`Pending\` | different options | Applies them to the row (atomic; if it loses the race to a claim, falls back to the conflict check above) |
| \`Failed\` (any other reason) | anything | Recycles the row for a fresh attempt |

That fourth row matters: before v3.0.0, \`queueB2CUpload()\` on a model that already had a \`Pending\`
B2B row returned the **B2B row unchanged, with no signal**, and the invoice was then filed to ANAF
in the wrong mode.

\`\`\`php
use BeeCoded\\EFactura\\Exceptions\\DuplicateUploadException;

try {
    $upload = EFactura::queueUpload($invoice);
} catch (DuplicateUploadException $e) {
    // $e->existingUpload — needs a human: php artisan efactura:reconcile
    return back()->withErrors('This invoice needs manual reconciliation with ANAF.');
}
\`\`\`

**Behaviour change to note:** if your code assumed \`queueUpload()\` always returns a *new* \`Pending\`
row, it does not. A \`Completed\` model returns its existing \`Completed\` row — re-filing is precisely
what must never happen.

---

## Step 7 — Event changes (silent — nothing will error)

### 7a. \`InvoiceFailed\` no longer fires on rate limits

This is the change most likely to break your app **without any error**.

- \`InvoiceFailed\` is now **terminal-only** — safe to alert on. It fires for \`validation\`,
  \`configuration\` and \`indeterminate\` failures, and for a \`transient\` failure only once its retries
  are exhausted.
- A transient rate-limit hit now fires the new **\`InvoiceRateLimited\`** instead.

**Symptom:** a listener that reacted to rate limits (e.g. custom backoff, or counting failures)
silently stops running. Nothing throws.

\`\`\`php
use BeeCoded\\EFactura\\Events\\InvoiceRateLimited;

Event::listen(InvoiceRateLimited::class, function (InvoiceRateLimited $event) {
    $event->upload;              // EfacturaUpload — still in the pipeline, will be retried
    $event->errors;              // array<int, string>
    $event->retryAfterSeconds;   // ?int — hint from the SDK/ANAF when available
});
\`\`\`

**The upside:** if you previously skipped rate-limit noise inside an \`InvoiceFailed\` listener, delete
that guard. \`InvoiceFailed\` no longer produces a false alarm followed by a successful upload.

### 7b. The \`RATE_LIMIT_EXCEEDED:\` error-text marker is GONE

Any listener that substring-matched the marker to classify a failure now matches **nothing** — and
therefore treats every transient hit as a real failure (or vice versa). Switch to the indexed
\`failure_reason\` column / \`FailureReason\` enum:

\`\`\`php
use BeeCoded\\EFactura\\Enums\\FailureReason;

// Before — fragile, and now always false
if (str_contains(json_encode($upload->errors), 'RATE_LIMIT_EXCEEDED:')) { /* ... */ }

// After — authoritative
if ($upload->failure_reason === FailureReason::RateLimited) { /* ... */ }
if ($upload->failure_reason?->isRetryable()) { /* will be re-submitted automatically */ }
if ($upload->failure_reason?->needsReconciliation()) { /* human must check ANAF */ }
\`\`\`

Text-matching was wrong in both directions anyway: it missed ANAF's Romanian 429 bodies
(\`S-au facut deja 1000 de apeluri...\`) and would have re-submitted any unrelated failure whose text
merely mentioned a rate limit.

---

## Step 8 — SDK v3 changes inside \`toEfacturaData()\`

\`toEfacturaData()\` builds the **SDK's** \`InvoiceData\`, so SDK v3's breaking changes land in your
model. This is the wrapper's primary extension point — expect to touch it.

### 8a. \`PartyData::$isVatPayer\` is REQUIRED — and the parameter ORDER CHANGED

\`\`\`php
// SDK v2 signature
__construct(string $registrationName, string $companyId, AddressData $address,
            ?string $registrationNumber = null, bool $isVatPayer = false)

// SDK v3 signature — isVatPayer is 4th and has NO default
__construct(string $registrationName, string $companyId, AddressData $address,
            bool $isVatPayer, ?string $registrationNumber = null)
\`\`\`

**Symptom A — omitted entirely:**
\`\`\`
ArgumentCountError: Too few arguments to function
BeeCoded\\EFacturaSdk\\Data\\Invoice\\PartyData::__construct(), 3 passed and at least 4 expected
\`\`\`
Via \`PartyData::from()\` you get \`CannotCreateData\` instead, or a clean 422 from the \`#[Required]\`
rule when validating.

**Symptom B — THERE IS NO SYMPTOM. This is the dangerous one.**

If you passed arguments **positionally**, the 4th used to be \`registrationNumber\` and is now
\`isVatPayer\`. In a caller **without \`declare(strict_types=1)\`** — which is most Laravel app models —
PHP coerces the string in **silently**:

\`\`\`php
// v2 code, unchanged, running against SDK v3, from a file with no strict_types:
new PartyData('ACME SRL', '12345678', $address, 'J40/1234/2020');
// → isVatPayer         = true    (the ONRC string coerced to bool)
// → registrationNumber = null    (silently lost)
// → NO exception. NO warning.
\`\`\`

A non-VAT-payer previously filed with \`isVatPayer = false\` now files as \`true\`. That inverts the
document: when the supplier is not a VAT payer, the SDK resolves every line to VAT category **"O" (Not
subject to VAT)** and rejects an explicit line category incompatible with that (reverse-charge "AE"
included). A party flagged as a non-payer also has its VAT identifier omitted (BT-31 seller / BT-48
buyer).
The resulting document is internally consistent, so **ANAF accepts it** — there is no error to
notice. (From a file *with* \`strict_types=1\` you get a \`TypeError\` instead, which is the lucky case.)

**Fix — always use named arguments:**

\`\`\`php
new PartyData(
    registrationName: $this->company->name,
    companyId: $this->company->vat_number,
    address: $address,
    isVatPayer: $this->company->is_vat_payer,   // REQUIRED — a real declaration, not a preference
    registrationNumber: $this->company->onrc,   // optional
);
\`\`\`

Grep your codebase for \`new PartyData(\` and confirm **every** call is named.

### 8b. \`InvoiceData::$taxAmountRon\` — required for non-RON, rejected for RON

\`\`\`php
public ?float $taxAmountRon = null;   // 11th constructor parameter
\`\`\`

**Symptom:** the upload is marked \`Failed\` with \`failure_reason = validation\` and \`InvoiceFailed\`
fires, carrying the SDK's message. XML generation happens in \`UploadService\`'s preparation phase,
which catches \`\\Throwable\` and fails the upload terminally — you will see this in the \`errors\`
column, not as an unhandled exception:

- Non-RON invoice, \`taxAmountRon\` missing → \`ValidationException\`:
  \`A EUR invoice must declare its total VAT in RON: set taxAmountRon to the converted amount (BT-111). ANAF cannot verify the conversion, so an incorrect value would be filed as a true statement of VAT owed (BR-RO-030, BR-53).\`
- RON invoice, \`taxAmountRon\` set → \`ValidationException\`:
  \`taxAmountRon must not be set on a RON invoice: the VAT total is already stated in RON, and a second RON tax total is not permitted (BR-CO-15).\`
- \`taxAmountRon\` and the invoice VAT total have opposite nonzero signs → \`ValidationException\`
  (either amount may round to zero; SDK 3.0/3.1 also rejected a zero against a nonzero total):
  \`taxAmountRon must have the same sign as the invoice VAT total: a credited amount cannot be declared as collected VAT, or vice versa (BT-111).\`

**Fix — set it only when the currency is not RON:**

\`\`\`php
public function toEfacturaData(): InvoiceData
{
    return new InvoiceData(
        invoiceNumber: $this->number,
        issueDate: $this->issued_at,
        supplier: $supplier,
        customer: $customer,
        lines: $lines,
        currency: $this->currency,
        // Required when currency !== 'RON', and MUST be null when it is.
        taxAmountRon: $this->currency === 'RON' ? null : $this->vat_total_ron,
    );
}
\`\`\`

It carries the converted **amount**, not an exchange rate: the rate is not part of the filed document
(EN 16931 defines no business term for it, and UBL-CR-490 warns against \`cac:TaxExchangeRate\`). Pass
the BNR-rate figure your ledger already holds — ANAF cannot verify the conversion, so a wrong value
is **accepted and filed** as a true statement of VAT owed. For a credit note, supply it in the same
positive sense as your line tax amounts; the builder sign-flips it alongside them.

**If every invoice you file is in RON, you have nothing to do here** — leave \`taxAmountRon\` unset.

---

## Step 9 — Scheduler, workers, and operations

### 9a. \`efactura:upload\` now QUEUES — it needs a worker

**Symptom:** the command prints \`Queued N pending upload(s) for processing.\` and **nothing uploads**.
No error. If you ran this from cron on a box with no worker, invoices silently stop being filed.

\`\`\`bash
# v3: dispatches one ProcessSingleUpload per row. Requires a queue worker.
php artisan efactura:upload

# Restores the old inline behaviour (now stops at the first rate limit instead of
# marking the whole backlog Failed).
php artisan efactura:upload --sync
\`\`\`

Either run a worker on \`config('efactura.queue')\`, or add \`--sync\`.

### 9b. Schedule the new \`SweepStaleUploads\` job

Without it, an upload stranded in \`uploading\` by a **SIGKILL'd** worker is never noticed — that
worker never gets to run \`failed()\`.

\`\`\`php
use BeeCoded\\EFactura\\Jobs\\SweepStaleUploads;

Schedule::job(new SweepStaleUploads)->everyFifteenMinutes();
\`\`\`

Takes no arguments. Parks rows older than \`jobs.stale_uploading_minutes\` (default 30) as
\`Failed\` / \`indeterminate\`.

### 9c. New operational duty — \`efactura:reconcile\`

v3 introduces uploads that **no automation will ever resolve**. When delivery to ANAF cannot be
proven (worker died around the POST; 5xx or transport loss), the row is parked
\`Failed\` / \`indeterminate\` rather than retried or written off, because re-sending may double-file a
legal invoice and writing it off may leave a statutory filing undone.

\`\`\`bash
php artisan efactura:reconcile                                # list what needs a human
php artisan efactura:reconcile --filed=12 --index=5001130255  # you found it in SPV
php artisan efactura:reconcile --not-filed=12                 # it never arrived — re-queues it
php artisan efactura:reconcile --not-filed=12 --despite-index # not in SPV under its index (confirm)
\`\`\`

**Put this in a runbook and alert on it.** Listen for \`InvoiceFailed\` with
\`failure_reason === FailureReason::Indeterminate\`; nothing else will tell you these exist, and they
will sit there indefinitely.

Related v3 change: a real ANAF **HTTP 429** is now treated as a rate limit (retried) rather than
becoming a permanent \`Failed\`. And only errors that **prove** the document never reached ANAF are
retried — 5xx, transport loss and unknown exceptions become \`indeterminate\` instead of being
silently re-sent.

---

## Step 10 — Two things that start working (no action needed)

These were broken in v2 and are fixed in v3. Expect **new** traffic and **new** rows.

- **Message sync actually runs.** It read \`$response->messages\`, a property the SDK never declared,
  and the exception was swallowed — so every run "succeeded" while syncing **zero** messages. It now
  reads \`$response->mesaje\`. Your first sync after upgrading may create a large batch of
  \`EfacturaMessage\` rows (60-day lookback) and fire \`InvoiceReceived\` for each downloaded invoice or buyer message.
  Make sure those listeners can cope with a burst.
- **Token refresh actually works.** The wrapper held the same cache lock the SDK acquires internally;
  since Laravel locks are not re-entrant, refresh deadlocked against itself and threw
  \`AuthenticationException('Token refresh lock timeout')\` — deterministically, not racily.
- **The CLI OAuth flow completes.** \`efactura:auth\` state moved from the console session (never
  persisted — no \`StartSession\` middleware in a console process) to the cache: 15-minute TTL,
  single-use via \`Cache::pull()\`.

---

## Rollback

\`\`\`bash
php artisan down               # stop the workers first, exactly as in Step 1
php artisan migrate:rollback   # restores plaintext tokens, drops the unique index + failure_reason
composer require bee-coded/laravel-efactura:^2.3
php artisan queue:restart
php artisan up
\`\`\`

Roll back the **migration first, while the v3 code is still deployed** — \`..._000004\`'s \`down()\` needs
\`APP_KEY\` to decrypt, and leaving ciphertext behind would hand v2's cast-less model an unusable
credential.

**The rollback aborts if \`APP_KEY\` is not the key the tokens were encrypted with** (rotated key, or
a production dump restored elsewhere):

\`\`\`
RuntimeException: Cannot roll back the e-Factura token encryption: a stored credential is
encrypted, but APP_KEY cannot decrypt it.
\`\`\`

That is deliberate. A wrong-key MAC failure is indistinguishable from plaintext to a trial decrypt,
so skipping it would report a successful rollback while leaving ciphertext in a column v2 reads
verbatim — and v2 would send it to ANAF as a Bearer token. Restore the original key (or set
\`APP_PREVIOUS_KEYS\` to it) and re-run. If the key is gone the tokens are unrecoverable: delete the
affected \`efactura_tokens\` rows and have each company redo the OAuth flow.

---

## Checklist

**Before any command**
- [ ] \`APP_KEY\` backed up outside the database; \`APP_PREVIOUS_KEYS\` set if rotating
- [ ] Cache store is shared and not \`array\`
- [ ] No raw/query-builder writes to \`efactura_tokens.access_token\` / \`refresh_token\`
- [ ] \`efactura_uploads\` row count checked; index build sized (Step 0d)
- [ ] Maintenance window planned to cover Steps 1–5

**In the window, in this order**
- [ ] **Queue workers STOPPED** — \`php artisan down\` *and* the processes actually stopped (Step 1)
- [ ] Duplicate \`efactura_uploads\` rows resolved, with writes stopped (Step 2)
- [ ] \`composer update\` → \`php artisan migrate\` run back-to-back (Steps 3–4)
- [ ] Workers restarted on the new code; \`php artisan up\` (Step 5)

**Code changes**
- [ ] \`resetForRateLimit()\` → \`resetForRetry()\`
- [ ] \`syncMessages()\` / \`syncAllMessages()\` callers handle throws
- [ ] \`queueUpload()\` callers handle \`DuplicateUploadException\`
- [ ] Listeners no longer match \`RATE_LIMIT_EXCEEDED:\`; use \`failure_reason\`
- [ ] Rate-limit logic moved from \`InvoiceFailed\` to \`InvoiceRateLimited\`
- [ ] Every \`new PartyData(...)\` uses **named** arguments and passes \`isVatPayer\`
- [ ] \`taxAmountRon\` set for non-RON invoices, and **not** set for RON ones

**Operations**
- [ ] \`efactura:upload\` has a queue worker, or uses \`--sync\`
- [ ] \`SweepStaleUploads\` scheduled
- [ ] \`efactura:reconcile\` in the runbook, with an alert on \`indeterminate\`
`,
};
