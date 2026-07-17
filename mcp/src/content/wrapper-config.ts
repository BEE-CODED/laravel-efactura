export const wrapperConfigContent = `# Laravel e-Factura Wrapper — Configuration Reference

Published to \`config/efactura.php\` via:
\`\`\`bash
php artisan vendor:publish --tag=efactura-config
\`\`\`

---

## Master Switch

| Config Key | Env Var | Type | Default | Description |
|-----------|---------|------|---------|-------------|
| \`enabled\` | \`EFACTURA_ENABLED\` | bool | \`true\` | Master switch — when \`false\`, all jobs exit immediately without processing |

---

## Framework settings this package now depends on (v3.0.0)

Not keys in \`config/efactura.php\`, but the package will not work correctly without them.

| Setting | Requirement | Why |
|---------|-------------|-----|
| \`APP_KEY\` | Must be stable and backed up **outside** the database | Encrypts \`efactura_tokens.access_token\` / \`refresh_token\`. Rotating without \`APP_PREVIOUS_KEYS\` orphans every token and forces a fresh OAuth flow per company. Restoring a prod DB elsewhere needs the matching key. |
| \`APP_PREVIOUS_KEYS\` | Set when rotating \`APP_KEY\` | Lets Laravel decrypt existing tokens with the old key and re-encrypt on write. |
| \`cache.default\` | Any shared, persistent store — **not \`array\`** | The OAuth state lives in the cache (15-min TTL, single-use), so web and console processes must see the same store. \`TokenService\` also uses cache locks (\`efactura:token_refresh:{cui}\`) to serialise token refresh. |
| A running queue worker | Consuming \`config('efactura.queue')\` | v3's \`efactura:upload\` and every batch job only *dispatch* \`ProcessSingleUpload\`. Without a worker nothing is filed, and **nothing errors**. |

---

## features

Feature flags for granular control over which subsystems are active.

| Config Key | Env Var | Type | Default | Description | Affects |
|-----------|---------|------|---------|-------------|---------|
| \`features.upload_invoices\` | \`EFACTURA_UPLOAD_ENABLED\` | bool | \`true\` | Enable the upload pipeline | ProcessPendingUploads, ProcessSingleUpload, CheckUploadStatuses, DownloadResponses, RetryRateLimitedUploads, SweepStaleUploads jobs; \`efactura:upload\` |
| \`features.download_received\` | \`EFACTURA_DOWNLOAD_RECEIVED\` | bool | \`false\` | Enable downloading received invoices from ANAF | DownloadReceivedInvoices job |
| \`features.sync_messages\` | \`EFACTURA_SYNC_MESSAGES\` | bool | \`true\` | Enable syncing ANAF message list | SyncMessages job |

---

## queue

| Config Key | Env Var | Type | Default | Description |
|-----------|---------|------|---------|-------------|
| \`queue\` | \`EFACTURA_QUEUE\` | \`string\` / \`null\` | \`null\` | Queue name for all e-Factura jobs. \`null\` uses the application's default queue. Recommended: use a dedicated queue like \`'efactura'\` for isolation. |

---

## rate_limit

Configuration for handling ANAF rate limits and retry behavior.

\`ProcessSingleUpload\`'s pre-flight check reads the SDK \`RateLimiter\`'s **\`global\`** bucket — a **per-minute** ceiling (SDK \`global_per_minute\`, env \`EFACTURA_RATE_LIMIT_GLOBAL\`, default **500/min**). It is not a daily quota. ANAF does enforce per-day quotas too (per-CUI upload, per-message status/download, list endpoints), but those are separate SDK buckets and are not what this pre-flight consults.

| Config Key | Env Var | Type | Default | Description |
|-----------|---------|------|---------|-------------|
| \`rate_limit.retry_window_hours\` | \`EFACTURA_RATE_LIMIT_RETRY_HOURS\` | int | \`24\` | How long (in hours) a single \`ProcessSingleUpload\` job keeps retrying via \`retryUntil()\` before the **job** stops being retried and is handed to the failed-job handler. |
| \`rate_limit.retry_batch_size\` | \`EFACTURA_RATE_LIMIT_RETRY_BATCH\` | int | \`250\` | Maximum number of rate-limited failed uploads to reset to Pending per \`RetryRateLimitedUploads\` job run. Prevents overwhelming the queue on large backlogs. |
| \`rate_limit.retry_max_age_days\` | \`EFACTURA_RATE_LIMIT_RETRY_MAX_DAYS\` | int | \`7\` | \`RetryRateLimitedUploads\` ignores failed uploads older than this many days. Prevents retrying very stale uploads indefinitely. |

> **Expiry of \`retry_window_hours\` governs the queue job, not the database row.** When
> \`retryUntil()\` passes, Laravel stops retrying and calls \`ProcessSingleUpload::failed()\`.
>
> **Changed in v3.0.0:** \`failed()\` now transitions the row. A row still stuck in \`Uploading\` is
> parked as \`Failed\` / \`indeterminate\` (via \`parkStrandedUploadAsIndeterminate()\`) and
> \`InvoiceFailed\` fires — it is **not** returned to \`Pending\`, because the job may have died after
> the POST reached ANAF and re-driving would double-file a legal invoice. Resolve with
> \`php artisan efactura:reconcile\`. A row left \`Pending\` (e.g. after a rate-limit reset) is
> untouched and picked up again by \`ProcessPendingUploads\`.
>
> Previously \`failed()\` only wrote a log line and performed no status transition, so rows left in
> \`Uploading\` **stalled indefinitely** with nothing to surface them.

---

## anaf_lookup

Synchronous retry policy for the public ANAF **company-lookup** endpoint (capped at 1 request/second). The wrapper decorates the SDK's \`AnafDetailsClientInterface\` with \`RetryingAnafDetailsClient\`, which retries on \`RateLimitExceededException\`.

| Config Key | Env Var | Type | Default | Description |
|-----------|---------|------|---------|-------------|
| \`anaf_lookup.retry_attempts\` | \`EFACTURA_ANAF_LOOKUP_RETRY_ATTEMPTS\` | int | \`5\` | Total attempts (1 initial + N−1 retries) before re-throwing \`RateLimitExceededException\`. Between attempts the decorator sleeps the exception's \`retryAfterSeconds\` (≥ 1s) — synchronous/blocking, so a fully rate-limited lookup blocks up to ~\`(attempts − 1)\` seconds. Only the rate-limit exception is retried; \`failure()\` results pass straight through. |

---

## jobs

Hardening for the periodic batch jobs so a backlog that accumulates while the queue worker is down does not drain all at once on recovery and overwhelm ANAF's per-message rate limits, plus the v3.0.0 retry/stranding controls.

| Config Key | Env Var | Type | Default | Description |
|-----------|---------|------|---------|-------------|
| \`jobs.max_staleness_seconds\` | \`EFACTURA_JOB_MAX_STALENESS\` | int | \`120\` | A periodic batch job (\`ProcessPendingUploads\`, \`CheckUploadStatuses\`, \`DownloadResponses\`) that has waited in the queue longer than this self-discards instead of running — the next scheduled run re-scans. Safe because these jobs are idempotent all-rows scanners. Set to \`0\` to disable. Default is 2x the typical 1-minute cadence. |
| \`jobs.unique_for_seconds\` | \`EFACTURA_JOB_UNIQUE_FOR\` | int | \`3600\` | Lock TTL (seconds) for \`ShouldBeUniqueUntilProcessing\` on those three batch jobs and on \`SweepStaleUploads\` — the ceiling after which a job stuck unprocessed in the queue stops blocking a fresh dispatch. (\`RetryRateLimitedUploads\` hardcodes its own \`uniqueFor = 600\` and ignores this key.) |
| \`jobs.transient_retry_delay_seconds\` | \`EFACTURA_JOB_TRANSIENT_RETRY_DELAY\` | int | \`30\` | How long \`ProcessSingleUpload\` waits before re-driving an upload that failed transiently (auth / pre-flight blip). Rate-limited retries use a fixed 60s instead. **New in v3.0.0.** |
| \`jobs.max_transient_attempts\` | \`EFACTURA_JOB_MAX_TRANSIENT_ATTEMPTS\` | int | \`5\` | How many times a single upload job may be re-driven for a \`transient\` failure before it is declared terminal and \`InvoiceFailed\` fires. Stops a durable fault (e.g. a revoked token answering 401) from hammering ANAF for the whole retry window. Set to \`0\` to disable the cap. **New in v3.0.0.** |
| \`jobs.stale_uploading_minutes\` | \`EFACTURA_JOB_STALE_UPLOADING_MINUTES\` | int | \`30\` | An upload left in \`uploading\` longer than this had its worker die mid-flight. \`SweepStaleUploads\` parks it as \`Failed\` / \`indeterminate\` for human reconciliation — it is **never** re-submitted, because it may already be filed. \`<= 0\` makes the sweep a no-op. **New in v3.0.0.** |

---

## storage

Where generated XML files and ANAF response ZIPs are stored.

| Config Key | Env Var | Type | Default | Description |
|-----------|---------|------|---------|-------------|
| \`storage.disk\` | \`EFACTURA_STORAGE_DISK\` | string | \`'local'\` | Laravel filesystem disk name. Use \`'s3'\` or any configured disk for cloud storage. |
| \`storage.path\` | \`EFACTURA_STORAGE_PATH\` | string | \`'efactura'\` | Base directory path within the disk. Files are organised **per CUI** under three subdirectories: generated XML at \`{path}/uploads/{cui}/{upload_id}_{Ymd_His}.xml\`, ANAF response ZIPs at \`{path}/responses/{cui}/{upload_id}_{Ymd_His}.zip\`, and received invoice ZIPs at \`{path}/received/{cui}/{message_id}_{Ymd_His}.zip\`. |

---

## routes

OAuth callback routes configuration.

| Config Key | Env Var | Type | Default | Description |
|-----------|---------|------|---------|-------------|
| \`routes.enabled\` | \`EFACTURA_ROUTES_ENABLED\` | bool | \`true\` | Register the built-in OAuth routes (\`GET /{prefix}/auth/{cui}\` and \`GET /{prefix}/callback\`). Set to \`false\` if you handle OAuth manually. |
| \`routes.prefix\` | \`EFACTURA_ROUTES_PREFIX\` | string | \`'efactura'\` | URL prefix for OAuth routes. Default routes are \`/efactura/auth/{cui}\` and \`/efactura/callback\`. |
| \`routes.middleware\` | *(not env)* | array | \`['web']\` | Laravel middleware applied to OAuth routes. Modify in \`config/efactura.php\` directly. \`'web'\` is the default because the callback redirects with flashed session data (\`efactura_success\`, \`efactura_cui\`, \`efactura_message\`). **As of v3.0.0 the OAuth state itself no longer needs the session** — it lives in the cache — so a session-less stack works if you also replace the redirect handling. |
| \`routes.success_redirect\` | \`EFACTURA_SUCCESS_REDIRECT\` | string | \`'/'\` | URL to redirect to after successful OAuth authorization. |
| \`routes.error_redirect\` | \`EFACTURA_ERROR_REDIRECT\` | string | \`'/'\` | URL to redirect to if OAuth authorization fails or state validation fails. |

---

## Example .env Configuration

\`\`\`env
# Master switch
EFACTURA_ENABLED=true

# Feature flags
EFACTURA_UPLOAD_ENABLED=true
EFACTURA_DOWNLOAD_RECEIVED=false
EFACTURA_SYNC_MESSAGES=true

# Queue
EFACTURA_QUEUE=efactura

# Rate limiting
EFACTURA_RATE_LIMIT_RETRY_HOURS=24
EFACTURA_RATE_LIMIT_RETRY_BATCH=250
EFACTURA_RATE_LIMIT_RETRY_MAX_DAYS=7

# ANAF company-lookup retry (1 req/sec endpoint)
EFACTURA_ANAF_LOOKUP_RETRY_ATTEMPTS=5

# Periodic job hardening
EFACTURA_JOB_MAX_STALENESS=120
EFACTURA_JOB_UNIQUE_FOR=3600

# Upload retry / stranding controls (v3.0.0)
EFACTURA_JOB_TRANSIENT_RETRY_DELAY=30
EFACTURA_JOB_MAX_TRANSIENT_ATTEMPTS=5
EFACTURA_JOB_STALE_UPLOADING_MINUTES=30

# Storage
EFACTURA_STORAGE_DISK=local
EFACTURA_STORAGE_PATH=efactura

# Routes
EFACTURA_ROUTES_ENABLED=true
EFACTURA_ROUTES_PREFIX=efactura
EFACTURA_SUCCESS_REDIRECT=/dashboard
EFACTURA_ERROR_REDIRECT=/dashboard/error
\`\`\`
`;
