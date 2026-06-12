export const jobReferenceContent = `# Laravel e-Factura Wrapper — Job Reference

All jobs are in the \`BeeCoded\\EFactura\\Jobs\` namespace.

## Shared Behavior for Batch Jobs

All batch jobs (ProcessPendingUploads, CheckUploadStatuses, DownloadResponses, DownloadReceivedInvoices, SyncMessages, RetryRateLimitedUploads) share:
- \`tries = 3\` with backoff of [60, 180, 300] seconds
- \`timeout = 120s\`
- Run on the configured queue: \`config('efactura.queue')\`
- Check \`config('efactura.enabled')\` and their feature flag before executing — if either is false, the job exits immediately

The three high-frequency periodic jobs — **ProcessPendingUploads**, **CheckUploadStatuses**, **DownloadResponses** — additionally guard against a backlog that piles up while the queue worker is down and then drains all at once on recovery (which would re-scan the same rows and exhaust ANAF's per-message rate limits before processing can advance):
- They implement \`ShouldBeUniqueUntilProcessing\`, keyed per-CUI via \`uniqueId()\`, so the scheduler will not enqueue a duplicate while one is still queued unprocessed. Lock TTL: \`jobs.unique_for_seconds\` (default 3600).
- They self-discard when stale (\`DiscardsWhenStale\` trait): a job that waited in the queue longer than \`jobs.max_staleness_seconds\` (default 120) returns immediately without running. Safe because these jobs are idempotent all-rows scanners — the next scheduled run re-scans.

---

## Batch Jobs (for Scheduling)

### ProcessPendingUploads

\`\`\`php
new ProcessPendingUploads(?string $cui = null)
\`\`\`

Finds all \`EfacturaUpload\` records with status \`Pending\` and dispatches a \`ProcessSingleUpload\` job for each one.

**Feature flag:** \`features.upload_invoices\`

**Arguments:**
- \`$cui\` — Optional CUI filter to process only a specific company's uploads

**Recommended schedule:** Every 5 minutes
\`\`\`php
Schedule::job(new ProcessPendingUploads)->everyFiveMinutes();
\`\`\`

---

### CheckUploadStatuses

\`\`\`php
new CheckUploadStatuses(?string $cui = null)
\`\`\`

Checks ANAF processing status for all uploads currently in \`Processing\` state. For each, calls \`EFacturaClient::getStatusMessage()\` and transitions to \`Completed\` or \`Failed\` accordingly.

**Feature flag:** \`features.upload_invoices\`

**Recommended schedule:** Every 5 minutes
\`\`\`php
Schedule::job(new CheckUploadStatuses)->everyFiveMinutes();
\`\`\`

---

### DownloadResponses

\`\`\`php
new DownloadResponses(?string $cui = null)
\`\`\`

Downloads response ZIPs from ANAF for uploads that have a \`download_id\` but no \`response_path\` yet (applies to both Completed and Failed uploads that ANAF provided a response for).

**Feature flag:** \`features.upload_invoices\`

**Recommended schedule:** Every 5 minutes
\`\`\`php
Schedule::job(new DownloadResponses)->everyFiveMinutes();
\`\`\`

---

### DownloadReceivedInvoices

\`\`\`php
new DownloadReceivedInvoices(?string $cui = null)
\`\`\`

Downloads received invoice ZIPs for \`EfacturaMessage\` records of type "received" that have not yet been downloaded. Fires \`InvoiceReceived\` event for each download.

**Feature flag:** \`features.download_received\` (default: **false** — must be explicitly enabled)

**Recommended schedule:** Hourly
\`\`\`php
Schedule::job(new DownloadReceivedInvoices)->hourly();
\`\`\`

---

### SyncMessages

\`\`\`php
new SyncMessages(?string $cui = null)
\`\`\`

Syncs the ANAF message list for all active tokens (or filtered by CUI). Creates or updates \`EfacturaMessage\` records for each ANAF message (sent invoices, received invoices, processing errors, buyer messages).

**Feature flag:** \`features.sync_messages\`

**Recommended schedule:** Every 15 minutes
\`\`\`php
Schedule::job(new SyncMessages)->everyFifteenMinutes();
\`\`\`

---

### RetryRateLimitedUploads

\`\`\`php
new RetryRateLimitedUploads()
\`\`\`

Finds \`EfacturaUpload\` records that failed due to rate limiting (identified by error context), resets them to \`Pending\` status, and dispatches \`ProcessSingleUpload\` for each.

**Implements:** \`ShouldBeUniqueUntilProcessing\` with \`uniqueFor: 600\` seconds — only one instance runs at a time.

**Configuration:**
- \`rate_limit.retry_batch_size\` (env: \`EFACTURA_RATE_LIMIT_RETRY_BATCH\`, default: 250) — Max uploads to reset per run
- \`rate_limit.retry_max_age_days\` (env: \`EFACTURA_RATE_LIMIT_RETRY_MAX_DAYS\`, default: 7) — Ignore uploads older than this many days

**Recommended schedule:** Every 30 minutes
\`\`\`php
Schedule::job(new RetryRateLimitedUploads)->everyThirtyMinutes();
\`\`\`

---

## Single-Upload Jobs (Dispatched On-Demand)

### ProcessSingleUpload

\`\`\`php
new ProcessSingleUpload(EfacturaUpload $upload)
\`\`\`

Processes one upload end-to-end:

1. **Atomic claim** — Updates \`Pending\` → \`Uploading\` atomically (prevents double-processing)
2. **Pre-flight rate limit check** — Checks global ANAF quota via SDK's RateLimiter before attempting upload
3. **XML generation** — Generates UBL 2.1 XML from the uploadable model's \`toEfacturaData()\`
4. **Store XML** — Persists XML to storage disk
5. **Upload** — Calls \`EFacturaClient::uploadInvoice()\` via \`TokenService::executeWithClient()\` for concurrent-safe token handling
6. **Success** — Marks \`Processing\`, stores \`download_id\`; fires \`InvoiceUploaded\`

**Rate limit handling:** If \`RateLimitExceededException\` is caught, upload is reset to \`Pending\` for later retry by \`RetryRateLimitedUploads\`.

**Configuration:**
- \`timeout = 120s\`
- \`maxExceptions = 3\`
- \`retryUntil()\` — Based on \`rate_limit.retry_window_hours\` (default 24h); job keeps retrying within this window

---

### CheckSingleUploadStatus

\`\`\`php
new CheckSingleUploadStatus(EfacturaUpload $upload)
\`\`\`

Checks one specific upload's processing status at ANAF. Used for targeted status checks without running the full batch job.

- \`tries = 3\`
- \`timeout = 120s\`
- \`backoff = [60, 180, 300]\` seconds

---

## Recommended Scheduling

\`\`\`php
// In routes/console.php (Laravel 11+) or app/Console/Kernel.php

use BeeCoded\\EFactura\\Jobs\\ProcessPendingUploads;
use BeeCoded\\EFactura\\Jobs\\CheckUploadStatuses;
use BeeCoded\\EFactura\\Jobs\\DownloadResponses;
use BeeCoded\\EFactura\\Jobs\\SyncMessages;
use BeeCoded\\EFactura\\Jobs\\RetryRateLimitedUploads;
use BeeCoded\\EFactura\\Jobs\\DownloadReceivedInvoices;

Schedule::job(new ProcessPendingUploads)->everyFiveMinutes();
Schedule::job(new CheckUploadStatuses)->everyFiveMinutes();
Schedule::job(new DownloadResponses)->everyFiveMinutes();
Schedule::job(new SyncMessages)->everyFifteenMinutes();
Schedule::job(new RetryRateLimitedUploads)->everyThirtyMinutes();

// Optional — only if features.download_received is enabled:
Schedule::job(new DownloadReceivedInvoices)->hourly();
\`\`\`
`;
