export const jobReferenceContent = `# Laravel e-Factura Wrapper — Job Reference

All jobs are in the \`BeeCoded\\EFactura\\Jobs\` namespace.

## Shared Behavior for Batch Jobs

All batch jobs (ProcessPendingUploads, CheckUploadStatuses, DownloadResponses, DownloadReceivedInvoices, SyncMessages, RetryRateLimitedUploads, SweepStaleUploads) share:
- \`timeout = 120s\`
- Run on the configured queue: \`config('efactura.queue')\`
- Check \`config('efactura.enabled')\` and their feature flag before executing — if either is false, the job exits immediately

All of them **except \`RetryRateLimitedUploads\`** additionally declare \`tries = 3\` with \`backoff = [60, 180, 300]\` seconds and \`maxExceptions = 3\`.

> **\`RetryRateLimitedUploads\` declares no \`$tries\`.** It falls back to the worker's default
> (\`--tries=1\` unless your worker is configured otherwise), so it gets **one attempt** and is not
> retried. Its \`$backoff\` and \`$maxExceptions = 3\` are therefore inert — both only take effect
> across retries that never happen. It does set \`timeout = 120\` and \`uniqueFor = 600\`. In practice
> this is tolerable: the job is an idempotent scanner and the next scheduled run re-scans anyway —
> but do not rely on it retrying a transient failure.

The three high-frequency periodic jobs — **ProcessPendingUploads**, **CheckUploadStatuses**, **DownloadResponses** — additionally guard against a backlog that piles up while the queue worker is down and then drains all at once on recovery (which would re-scan the same rows and exhaust ANAF's per-message rate limits before processing can advance):
- They implement \`ShouldBeUniqueUntilProcessing\`, keyed per-CUI via \`uniqueId()\`, so the scheduler will not enqueue a duplicate while one is still queued unprocessed. Lock TTL: \`jobs.unique_for_seconds\` (default 3600).
- They self-discard when stale (\`DiscardsWhenStale\` trait): a job that waited in the queue longer than \`jobs.max_staleness_seconds\` (default 120) returns immediately without running. Safe because these jobs are idempotent all-rows scanners — the next scheduled run re-scans. **Only these three use \`DiscardsWhenStale\`.**

**Uniqueness at a glance** — \`ShouldBeUniqueUntilProcessing\` is implemented by **five** jobs, not just those three:

| Job | \`uniqueFor\` | \`uniqueId()\` |
|-----|-------------|---------------|
| \`ProcessPendingUploads\` | \`jobs.unique_for_seconds\` (3600) | per-CUI (\`$cui ?? 'all'\`) |
| \`CheckUploadStatuses\` | \`jobs.unique_for_seconds\` (3600) | per-CUI (\`$cui ?? 'all'\`) |
| \`DownloadResponses\` | \`jobs.unique_for_seconds\` (3600) | per-CUI (\`$cui ?? 'all'\`) |
| \`RetryRateLimitedUploads\` | **hardcoded 600** (ignores the config key) | none — keyed on the class |
| \`SweepStaleUploads\` | \`jobs.unique_for_seconds\` (3600) | none — keyed on the class |

\`DownloadReceivedInvoices\`, \`SyncMessages\`, \`ProcessSingleUpload\` and \`CheckSingleUploadStatus\` are **not** unique-constrained.

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

**Recommended schedule:** see [Recommended Scheduling](#recommended-scheduling).

---

### CheckUploadStatuses

\`\`\`php
new CheckUploadStatuses(?string $cui = null)
\`\`\`

Checks ANAF processing status for all uploads currently in \`Processing\` state. For each, calls \`EFacturaClient::getStatusMessage()\` and transitions to \`Completed\` or \`Failed\` accordingly; the full outcome table is in the \`upload-pipeline\` topic.

- \`ok\` without a download id is \`Completed\` (no response to download); \`nok\` without a download id is \`Failed\` / \`indeterminate\`, keeping the index
- Permanent failures (4xx, unreadable 2xx, an index the SDK refuses) are counted in \`status_failures\` and park the upload \`indeterminate\` at \`polling.max_status_failures\` (default 5); transient ones (5xx, network, token) are retried uncounted
- An upload at its per-message status limit (\`RateLimitScope::Status\`) is skipped and the batch carries on; a global limit or ANAF's 429 stops the batch

**Feature flag:** \`features.upload_invoices\`

**Recommended schedule:** see [Recommended Scheduling](#recommended-scheduling).

---

### DownloadResponses

\`\`\`php
new DownloadResponses(?string $cui = null)
\`\`\`

Downloads response ZIPs from ANAF for uploads that have a \`download_id\` but no \`response_path\` yet (applies to both Completed and Failed uploads that ANAF provided a response for).

**Permanent failures are bounded.** ANAF can answer \`/descarcare\` with a 2xx whose body is not a ZIP (typically a JSON \`eroare\`). The SDK's \`guardDownloadBody()\` throws, so \`response_path\` stays null — and without a guard the row would be re-selected here on every run, forever. Each permanent failure (that, a 4xx, an id the SDK refuses, any other non-transient error) increments \`response_attempts\` and stamps \`response_failed_at\`; a transient one (5xx, network, token) is not counted; once \`response_attempts\` reaches \`EfacturaUpload::MAX_RESPONSE_ATTEMPTS\` (3) the row drops out of \`needsResponseDownload()\` and is left for \`efactura:reconcile\`. The upload's own \`status\` is untouched — ANAF accepted the invoice; only the receipt is missing.

A download at its per-message download limit (\`RateLimitScope::Download\`) is skipped and the batch carries on; any other rate limit stops the batch.

**Feature flag:** \`features.upload_invoices\`

**Recommended schedule:** see [Recommended Scheduling](#recommended-scheduling).

---

### DownloadReceivedInvoices

\`\`\`php
new DownloadReceivedInvoices(?string $cui = null)
\`\`\`

Downloads received invoice ZIPs for \`EfacturaMessage\` records of type "received" that have not yet been downloaded. Fires \`InvoiceReceived\` event for each download.

A permanent download failure increments \`download_attempts\`; at \`EfacturaMessage::MAX_DOWNLOAD_ATTEMPTS\` (3) the message is no longer selected. Transient failures are not counted, a per-message download limit skips the message, and any other rate limit stops the batch.

**Feature flag:** \`features.download_received\` (default: **false** — must be explicitly enabled)

**Recommended schedule:** see [Recommended Scheduling](#recommended-scheduling).

---

### SyncMessages

\`\`\`php
new SyncMessages(?string $cui = null)
\`\`\`

Syncs the ANAF message list for all active tokens (or filtered by CUI). Creates or updates \`EfacturaMessage\` records for each ANAF message (sent invoices, received invoices, processing errors, buyer messages). A listed message without a usable id is skipped with a warning and not counted.

**Feature flag:** \`features.sync_messages\`

**Recommended schedule:** see [Recommended Scheduling](#recommended-scheduling).

---

### SweepStaleUploads

\`\`\`php
new SweepStaleUploads()
\`\`\`

Rescues uploads stranded in the \`uploading\` state. \`processUpload()\` claims a row into \`Uploading\` immediately before sending it to ANAF; if the worker then dies hard (timeout, SIGKILL, OOM, deploy), nothing transitions it out — and the queue's retry of that job re-runs \`processUpload()\`, whose claim (\`WHERE status = pending\`) matches zero rows and silently reports success. Before v3.0.0 such rows sat in \`Uploading\` forever.

Rows older than \`efactura.jobs.stale_uploading_minutes\` (default 30) are parked as \`Failed\` / \`indeterminate\` — **not** returned to \`Pending\`, because the crash may have happened after the POST reached ANAF. Resolve them with \`php artisan efactura:reconcile\`.

**Takes no arguments** — \`__construct()\` has no \`$cui\` parameter; it always scans every CUI.

**Feature flag:** \`features.upload_invoices\`

**Implements:** \`ShouldBeUniqueUntilProcessing\` with \`uniqueFor\` from \`jobs.unique_for_seconds\` (default 3600). It has **no** \`uniqueId()\`, so the lock is global to the job. It does **not** use \`DiscardsWhenStale\` — a late sweep is still a useful sweep.

Set \`jobs.stale_uploading_minutes\` to \`0\` or less to make it a no-op.

**Recommended schedule:** see [Recommended Scheduling](#recommended-scheduling).

---

### RetryRateLimitedUploads

\`\`\`php
new RetryRateLimitedUploads()
\`\`\`

Safety net for uploads left stranded in \`Failed\` by a safe-to-resubmit error — normally \`ProcessSingleUpload\` resets and retries its own upload, so this job only picks up rows where that did not happen (worker died mid-sequence, retry window expired). Finds \`Failed\` \`EfacturaUpload\` records whose \`failure_reason\` is retryable (\`rate_limited\` or \`transient\`), atomically resets them to \`Pending\`, and dispatches \`ProcessSingleUpload\` for each.

> **Uploads marked \`indeterminate\` are deliberately never picked up here.** Their original attempt
> may already have reached ANAF, so re-sending would double-file a legal invoice. They require human
> reconciliation via \`php artisan efactura:reconcile\`.

> **Changed in v3.0.0.** This previously selected rows with \`where('errors', 'like', '%RATE_LIMIT_EXCEEDED:%')\`.
> \`errors\` is a \`json\` column and Postgres has no \`json ~~ text\` operator, so the job **threw on every
> run under Postgres** and rate-limited uploads were never retried at all. Selection is now an indexed
> \`failure_reason\` column.

**Takes no arguments** — unlike the other batch jobs, \`__construct()\` has **no \`$cui\` parameter**. It always scans every CUI. PHP silently discards extra constructor args, so \`new RetryRateLimitedUploads('12345678')\` does **not** filter — it quietly retries all companies.

**Implements:** \`ShouldBeUniqueUntilProcessing\` with \`uniqueFor: 600\` seconds — only one instance runs at a time.

**No \`$tries\`** — see Shared Behavior above; this job is not retried on failure.

**Configuration:**
- \`rate_limit.retry_batch_size\` (env: \`EFACTURA_RATE_LIMIT_RETRY_BATCH\`, default: 250) — Max uploads to reset per run
- \`rate_limit.retry_max_age_days\` (env: \`EFACTURA_RATE_LIMIT_RETRY_MAX_DAYS\`, default: 7) — Ignore uploads older than this many days

**Recommended schedule:** see [Recommended Scheduling](#recommended-scheduling).

---

## Single-Upload Jobs (Dispatched On-Demand)

### ProcessSingleUpload

\`\`\`php
new ProcessSingleUpload(EfacturaUpload $upload)
\`\`\`

Processes one upload end-to-end:

1. **Pre-flight rate limit check** — Checks the SDK \`RateLimiter\`'s \`global\` quota **before** the row is claimed. If exhausted, the job \`release()\`s and returns, leaving the row \`Pending\`
2. **Atomic claim** — Updates \`Pending\` → \`Uploading\` atomically (prevents double-processing)
3. **XML generation** — Generates UBL 2.1 XML from the uploadable model's \`toEfacturaData()\`
4. **Store XML** — Persists XML to storage disk
5. **Upload** — Calls \`EFacturaClient::uploadDocument()\` — or \`uploadB2CDocument()\` when \`is_b2c\` is set — via \`TokenService::executeWithClient()\` for concurrent-safe token handling
6. **Success** — A numeric \`indexIncarcare\` in the answer marks \`Processing\` and stores it as \`upload_index\`, whatever \`ExecutionStatus\` says; fires \`InvoiceUploaded\`. Without a usable index, \`ExecutionStatus=1\` is \`Failed\` / \`validation\` and anything else (including an unreadable 2xx body) is \`Failed\` / \`indeterminate\`. **\`download_id\` is still null here** — it is only set later, by \`CheckUploadStatuses\`, when ANAF reports the document ready

Steps 1–2 are in that order: the pre-flight runs in \`handle()\`, *before* \`UploadService::processUpload()\` claims the row. A quota-exhausted release therefore never touches the upload.

Preparation (steps 3–4) is strictly **before** anything is transmitted, so a failure there proves nothing was filed — it is terminal (\`validation\` / \`configuration\`), never retried.

**Rate limit handling:** two distinct mechanisms, depending on *when* the limit is hit:

- **Pre-flight (step 1)** — quota already exhausted: the job \`release()\`s itself with a delay matching the bucket reset (min 10s) and returns. The upload row is untouched (stays \`Pending\`), and **no event fires**.
- **Mid-call** — the limit is hit during the API call. \`UploadService\` releases the row back to \`Pending\` with \`failure_reason = rate_limited\` and fires **\`InvoiceRateLimited\`** (*not* \`InvoiceFailed\`). \`ProcessSingleUpload\` re-reads the row, sees \`Pending\` / \`rate_limited\`, and \`release(60)\`s itself.

> **Changed in v3.0.0.** The mid-call path used to write a \`RATE_LIMIT_EXCEEDED:\` marker into the
> free-text \`errors\` payload and fire \`InvoiceFailed\` — a false alarm followed by a successful
> upload. Classification now lives in the indexed \`failure_reason\` column; the marker is gone.
> Also new: a genuine ANAF **HTTP 429** arrives as \`ApiException(429)\` (the SDK's \`isRetryable()\`
> excludes 429) and is now treated as a rate limit. Before v3 it became a permanent \`Failed\`.

So the job recovers its **own** rate-limited upload. \`RetryRateLimitedUploads\` is the **safety net** for rows stranded in \`Failed\` — e.g. the worker died between the mark and the reset, or the retry window expired.

**Transient failures** (auth/token blip) are re-driven after \`jobs.transient_retry_delay_seconds\` (default 30), capped at \`jobs.max_transient_attempts\` (default 5; \`0\` disables the cap). The cap stops a durable fault — a revoked token answering 401 forever — from hammering ANAF for the whole retry window. Once exhausted, the row stays \`Failed\` and \`InvoiceFailed\` fires as a genuine terminal signal.

**\`failed()\` — new in v3.0.0.** When the job dies permanently (timeout, retry window expiry, uncaught error), \`failed()\` calls \`parkStrandedUploadAsIndeterminate()\`, transitioning a row still stuck in \`Uploading\` to \`Failed\` / \`indeterminate\` and firing \`InvoiceFailed\`. It deliberately does **not** reset to \`Pending\`: the process may have died *after* the POST reached ANAF, so re-driving would double-file a legal invoice. Rows are resolved by \`php artisan efactura:reconcile\`.

Before v3 \`failed()\` only wrote a log line, so such rows sat in \`Uploading\` forever. A SIGKILL never runs \`failed()\` at all — that is what \`SweepStaleUploads\` is for.

**Configuration:**
- \`timeout = 120s\`
- \`maxExceptions = 3\`
- \`retryUntil()\` — Based on \`rate_limit.retry_window_hours\` (default 24h), fixed from job **creation**, so rate-limit releases don't count toward a retry cap; job keeps retrying within this window

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

The package schedules nothing itself. This is the one recommended cadence per job (the README's "Recommended schedule" table states the same); the quotas are the SDK's daily per-message and per-CUI limits.

| Job | Recommended cadence | Why |
|-----|---------------------|-----|
| \`ProcessPendingUploads\` | Every 5 minutes | Only dispatches queued uploads. Uploads count against the global per-minute limit (and, for RASP, a per-CUI daily one), not a per-document one |
| \`CheckUploadStatuses\` | Every 10 minutes | Each poll uses one of the upload index's daily status reads (SDK \`status_per_day_message\`, default 50): about 8 hours of polling before the limit. An upload at its limit is skipped until it resets; the others are still polled |
| \`DownloadResponses\` | Every 15 minutes | Each attempt uses one of the download id's daily downloads (SDK \`download_per_day_message\`, default 5); a successful download happens once |
| \`RetryRateLimitedUploads\` | Every 30 minutes | A safety net: \`ProcessSingleUpload\` normally re-drives its own rate-limited upload. The interval gives an exhausted quota time to recover |
| \`SweepStaleUploads\` | Every 15 minutes | With \`jobs.stale_uploading_minutes\` at 30, a stranded upload is parked within 45 minutes |
| \`SyncMessages\` | Hourly | One message-list call per CUI per run (SDK \`simple_list_per_day_cui\`, default 750) |
| \`DownloadReceivedInvoices\` | Hourly | Downloads what \`SyncMessages\` listed; each message's download uses one of its 5 daily downloads |

\`jobs.max_staleness_seconds\` (120) is shorter than every interval, so a run delayed in the queue is dropped instead of overlapping the next one.

\`\`\`php
// In routes/console.php (Laravel 11+) or app/Console/Kernel.php

use BeeCoded\\EFactura\\Jobs\\ProcessPendingUploads;
use BeeCoded\\EFactura\\Jobs\\CheckUploadStatuses;
use BeeCoded\\EFactura\\Jobs\\DownloadResponses;
use BeeCoded\\EFactura\\Jobs\\SyncMessages;
use BeeCoded\\EFactura\\Jobs\\RetryRateLimitedUploads;
use BeeCoded\\EFactura\\Jobs\\SweepStaleUploads;
use BeeCoded\\EFactura\\Jobs\\DownloadReceivedInvoices;

Schedule::job(new ProcessPendingUploads)->everyFiveMinutes();
Schedule::job(new CheckUploadStatuses)->everyTenMinutes();
Schedule::job(new DownloadResponses)->everyFifteenMinutes();
Schedule::job(new RetryRateLimitedUploads)->everyThirtyMinutes();
Schedule::job(new SyncMessages)->hourly();

// New in v3.0.0 — do not omit. Nothing else rescues an upload stranded in
// "uploading" by a SIGKILL'd worker (which never gets to run failed()).
Schedule::job(new SweepStaleUploads)->everyFifteenMinutes();

// Optional — only if features.download_received is enabled:
Schedule::job(new DownloadReceivedInvoices)->hourly();
\`\`\`

**A queue worker is required.** Every batch job above only *dispatches* work; nothing is uploaded
without a worker consuming \`config('efactura.queue')\`.
`;
