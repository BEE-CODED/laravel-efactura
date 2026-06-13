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

## features

Feature flags for granular control over which subsystems are active.

| Config Key | Env Var | Type | Default | Description | Affects |
|-----------|---------|------|---------|-------------|---------|
| \`features.upload_invoices\` | \`EFACTURA_UPLOAD_ENABLED\` | bool | \`true\` | Enable the upload pipeline | ProcessPendingUploads, CheckUploadStatuses, DownloadResponses jobs |
| \`features.download_received\` | \`EFACTURA_DOWNLOAD_RECEIVED\` | bool | \`false\` | Enable downloading received invoices from ANAF | DownloadReceivedInvoices job |
| \`features.sync_messages\` | \`EFACTURA_SYNC_MESSAGES\` | bool | \`true\` | Enable syncing ANAF message list | SyncMessages job |

---

## queue

| Config Key | Env Var | Type | Default | Description |
|-----------|---------|------|---------|-------------|
| \`queue\` | \`EFACTURA_QUEUE\` | string\|null | \`null\` | Queue name for all e-Factura jobs. \`null\` uses the application's default queue. Recommended: use a dedicated queue like \`'efactura'\` for isolation. |

---

## rate_limit

Configuration for handling ANAF's daily upload quotas and retry behavior.

| Config Key | Env Var | Type | Default | Description |
|-----------|---------|------|---------|-------------|
| \`rate_limit.retry_window_hours\` | \`EFACTURA_RATE_LIMIT_RETRY_HOURS\` | int | \`24\` | How long (in hours) a single \`ProcessSingleUpload\` job keeps retrying via \`retryUntil()\` before failing permanently. After this window, the upload is marked Failed. |
| \`rate_limit.retry_batch_size\` | \`EFACTURA_RATE_LIMIT_RETRY_BATCH\` | int | \`250\` | Maximum number of rate-limited failed uploads to reset to Pending per \`RetryRateLimitedUploads\` job run. Prevents overwhelming the queue on large backlogs. |
| \`rate_limit.retry_max_age_days\` | \`EFACTURA_RATE_LIMIT_RETRY_MAX_DAYS\` | int | \`7\` | \`RetryRateLimitedUploads\` ignores failed uploads older than this many days. Prevents retrying very stale uploads indefinitely. |

---

## anaf_lookup

Synchronous retry policy for the public ANAF **company-lookup** endpoint (capped at 1 request/second). The wrapper decorates the SDK's \`AnafDetailsClientInterface\` with \`RetryingAnafDetailsClient\`, which retries on \`RateLimitExceededException\`.

| Config Key | Env Var | Type | Default | Description |
|-----------|---------|------|---------|-------------|
| \`anaf_lookup.retry_attempts\` | \`EFACTURA_ANAF_LOOKUP_RETRY_ATTEMPTS\` | int | \`5\` | Total attempts (1 initial + N−1 retries) before re-throwing \`RateLimitExceededException\`. Between attempts the decorator sleeps the exception's \`retryAfterSeconds\` (≥ 1s) — synchronous/blocking, so a fully rate-limited lookup blocks up to ~\`(attempts − 1)\` seconds. Only the rate-limit exception is retried; \`failure()\` results pass straight through. |

---

## jobs

Hardening for the periodic batch jobs so a backlog that accumulates while the queue worker is down does not drain all at once on recovery and overwhelm ANAF's per-message rate limits.

| Config Key | Env Var | Type | Default | Description |
|-----------|---------|------|---------|-------------|
| \`jobs.max_staleness_seconds\` | \`EFACTURA_JOB_MAX_STALENESS\` | int | \`120\` | A periodic batch job (\`ProcessPendingUploads\`, \`CheckUploadStatuses\`, \`DownloadResponses\`) that has waited in the queue longer than this self-discards instead of running — the next scheduled run re-scans. Safe because these jobs are idempotent all-rows scanners. Set to \`0\` to disable. Default is 2x the typical 1-minute cadence. |
| \`jobs.unique_for_seconds\` | \`EFACTURA_JOB_UNIQUE_FOR\` | int | \`3600\` | Lock TTL (seconds) for \`ShouldBeUniqueUntilProcessing\` on those three batch jobs — the ceiling after which a job stuck unprocessed in the queue stops blocking a fresh dispatch. |

---

## storage

Where generated XML files and ANAF response ZIPs are stored.

| Config Key | Env Var | Type | Default | Description |
|-----------|---------|------|---------|-------------|
| \`storage.disk\` | \`EFACTURA_STORAGE_DISK\` | string | \`'local'\` | Laravel filesystem disk name. Use \`'s3'\` or any configured disk for cloud storage. |
| \`storage.path\` | \`EFACTURA_STORAGE_PATH\` | string | \`'efactura'\` | Base directory path within the disk. Files are organized in subdirectories: \`{path}/xml/\`, \`{path}/responses/\`, etc. |

---

## routes

OAuth callback routes configuration.

| Config Key | Env Var | Type | Default | Description |
|-----------|---------|------|---------|-------------|
| \`routes.enabled\` | \`EFACTURA_ROUTES_ENABLED\` | bool | \`true\` | Register the built-in OAuth routes (\`GET /{prefix}/auth/{cui}\` and \`GET /{prefix}/callback\`). Set to \`false\` if you handle OAuth manually. |
| \`routes.prefix\` | \`EFACTURA_ROUTES_PREFIX\` | string | \`'efactura'\` | URL prefix for OAuth routes. Default routes are \`/efactura/auth/{cui}\` and \`/efactura/callback\`. |
| \`routes.middleware\` | *(not env)* | array | \`['web']\` | Laravel middleware applied to OAuth routes. Modify in \`config/efactura.php\` directly. Requires \`'web'\` for session support. |
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
