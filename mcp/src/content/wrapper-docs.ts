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
EFactura::getAuthorizationUrl($cui);
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
| \`EfacturaToken\` | OAuth tokens per CUI (company), with \`is_active\` flag and \`last_used_at\` tracking. Tokens are stored **unencrypted** — see Token Model |
| \`EfacturaUpload\` | Polymorphic upload record tracking status, XML path, download ID, response path, errors |
| \`EfacturaMessage\` | Synced ANAF messages (sent/received invoices, errors, buyer messages) |

### Polymorphic Upload Design

Any Eloquent model can be uploaded to e-Factura by implementing \`EFacturaUploadableInterface\`. The \`EfacturaUpload\` record uses Laravel's polymorphic morph relationship, so a single uploads table tracks invoices from any model class.

### Event-Driven

6 domain events are fired at key lifecycle points, enabling loose coupling between the package and your application code. Use standard Laravel event listeners or subscribers.

### 8 Queue Jobs

Background processing is handled by 8 queue jobs:
- **Batch jobs** (for scheduling): ProcessPendingUploads, CheckUploadStatuses, DownloadResponses, SyncMessages, RetryRateLimitedUploads, DownloadReceivedInvoices
- **Single-upload jobs** (dispatched on-demand): ProcessSingleUpload, CheckSingleUploadStatus

### 4 Artisan Commands

Manual operation commands: \`efactura:auth\`, \`efactura:upload\`, \`efactura:status\`, \`efactura:sync\`

### Feature Flags

Three independent feature flags for granular control:
- \`features.upload_invoices\` — Enable/disable upload pipeline
- \`features.download_received\` — Enable/disable received invoice downloads (default: off)
- \`features.sync_messages\` — Enable/disable ANAF message sync

### ANAF Lookup Retry Decorator

The wrapper decorates the SDK's \`AnafDetailsClientInterface\` binding with \`RetryingAnafDetailsClient\`. ANAF's public company-lookup endpoint is capped at 1 request/second and the SDK throws \`RateLimitExceededException\` without retrying; the decorator retries up to \`anaf_lookup.retry_attempts\` total attempts (default 5), sleeping the exception's \`retryAfterSeconds\` (>= 1s) between attempts, then re-throws. Synchronous/blocking; only the rate-limit exception is retried (a \`failure()\` result passes through). Resolving the interface or the \`AnafDetails\` facade yields the resilient client transparently — no app code change.
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

This publishes 3 migration files for the wrapper tables.

## Step 4: Run Migrations

\`\`\`bash
php artisan migrate
\`\`\`

Creates three tables:
- \`efactura_tokens\` — OAuth tokens per CUI
- \`efactura_uploads\` — Upload tracking (polymorphic)
- \`efactura_messages\` — Synced ANAF messages

## Step 5: Configure Environment Variables

### SDK Variables (required for API access)
\`\`\`env
EFACTURA_ENABLED=true
EFACTURA_CLIENT_ID=your-anaf-client-id
EFACTURA_CLIENT_SECRET=your-anaf-client-secret
EFACTURA_REDIRECT_URI=https://yourapp.com/efactura/callback
EFACTURA_SANDBOX=true   # false for production
\`\`\`

### Wrapper-Specific Variables
\`\`\`env
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
\`\`\`

## Step 6: Schedule Background Jobs

Add to \`routes/console.php\` (Laravel 11+) or \`app/Console/Kernel.php\`:

\`\`\`php
use BeeCoded\\EFactura\\Jobs\\ProcessPendingUploads;
use BeeCoded\\EFactura\\Jobs\\CheckUploadStatuses;
use BeeCoded\\EFactura\\Jobs\\DownloadResponses;
use BeeCoded\\EFactura\\Jobs\\SyncMessages;
use BeeCoded\\EFactura\\Jobs\\RetryRateLimitedUploads;

Schedule::job(new ProcessPendingUploads)->everyFiveMinutes();
Schedule::job(new CheckUploadStatuses)->everyFiveMinutes();
Schedule::job(new DownloadResponses)->everyFiveMinutes();
Schedule::job(new SyncMessages)->everyFifteenMinutes();
Schedule::job(new RetryRateLimitedUploads)->everyThirtyMinutes();
\`\`\`

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

### 2. ProcessPendingUploads (scheduled)

The \`ProcessPendingUploads\` batch job runs on schedule (every 5 minutes recommended). For each pending upload, it dispatches a \`ProcessSingleUpload\` job onto the configured queue.

### 3. ProcessSingleUpload (per-upload)

This job handles the full upload for one invoice:

1. **Atomic claim** — Updates status from \`Pending\` → \`Uploading\` using an atomic DB query to prevent double-processing
2. **XML generation** — Calls \`UblBuilder\` to generate UBL 2.1 XML from \`InvoiceData\`
3. **Store XML** — Saves generated XML to configured storage disk/path
4. **Upload to ANAF** — Calls \`EFacturaClient::uploadInvoice()\` with concurrent-safe token handling via \`TokenService::executeWithClient()\`
5. **Success** — Marks upload as \`Processing\`, stores the ANAF \`download_id\`
6. Fires \`InvoiceUploaded\` event

### 4. CheckUploadStatuses (scheduled)

Runs every 5 minutes. For each upload in \`Processing\` state, calls ANAF's \`getStatusMessage()\` API. Marks \`Completed\` or \`Failed\` based on response.

### 5. DownloadResponses (scheduled)

Runs every 5 minutes. For uploads with a \`download_id\` but no \`response_path\`, downloads the response ZIP from ANAF and stores it to disk.

Fires \`InvoiceProcessed\` event for Completed uploads after download.
Fires \`InvoiceFailed\` event for Failed uploads.

## Upload Status State Machine

\`\`\`
Pending → Uploading → Processing → Completed
                                 → Failed
\`\`\`

| Status | Meaning |
|--------|---------|
| \`Pending\` | Queued, not yet processed |
| \`Uploading\` | Currently being uploaded by a job |
| \`Processing\` | Uploaded to ANAF, awaiting ANAF processing |
| \`Completed\` | ANAF accepted and processed the invoice |
| \`Failed\` | Upload or processing failed |

## Rate Limit Handling

ANAF enforces daily upload quotas. The SDK's \`RateLimiter\` performs client-side pre-flight checks before each upload attempt.

When \`RateLimitExceededException\` is caught:
1. Upload status is reset to \`Pending\`
2. The failure is recorded with rate-limit context
3. \`RetryRateLimitedUploads\` job (runs every 30 min) finds these failed uploads and re-queues them
4. \`ProcessSingleUpload\` uses \`retryUntil()\` based on \`rate_limit.retry_window_hours\` (default 24h) before failing permanently

## Events Fired

| Event | When |
|-------|------|
| \`InvoiceUploaded\` | After successful ANAF upload (status → Processing) |
| \`InvoiceProcessed\` | After response ZIP downloaded for Completed upload |
| \`InvoiceFailed\` | When upload or processing fails terminally |
`,

  "token-management": `# Laravel e-Factura Wrapper — Token Management

## OAuth Authorization Flow

### Option A: Built-in Routes

When \`routes.enabled = true\` (default), the package registers two routes:

\`\`\`
GET /efactura/auth/{cui}      → Generate and redirect to ANAF OAuth URL
GET /efactura/callback         → Handle OAuth callback, store token
\`\`\`

\`OAuthCallbackController\` handles the code exchange, stores the tokens via \`TokenService::storeToken()\`, and fires \`TokenStored\` event.

### Option B: Manual URL Generation

\`\`\`php
$url = EFactura::getAuthorizationUrl($cui);
// Redirect user to $url
\`\`\`

### CSRF Protection

State validation uses a 15-minute expiry. The state parameter contains a base64-encoded JSON object with \`cui\` and a cryptographically secure \`token\`. The token is stored in session for verification on callback. Expired or mismatched states are rejected silently.

## TokenService::executeWithClient() — Concurrent-Safe Pattern

The core concurrent-safe method for all API operations. It optimizes for parallel processing while preventing token refresh race conditions.

### How It Works

**Case 1: Token NOT expiring soon (> 2 min to expiry)**
- Proceed immediately without acquiring any lock
- This allows parallel API calls to run concurrently
- After operation: if SDK unexpectedly refreshed, persist with lock protection

**Case 2: Token IS expiring soon (< 2 min to expiry)**
- Acquire a distributed cache lock (2-minute timeout, 30-second block wait)
- Reload token from DB (another process may have already refreshed it)
- Re-check expiry:
  - If another process refreshed it: release lock, proceed as Case 1
  - If still expiring: proceed with lock held, handle refresh after operation

**After Operation**
- \`handleClientTokenRefresh(client, token)\` is called automatically
- If \`client.wasTokenRefreshed()\` is true: persist new tokens, fire \`TokenRefreshed\` event
- Always updates \`last_used_at\`

### Usage Pattern

\`\`\`php
// In UploadService or your own service:
$token = EFactura::tokenService()->getToken($cui);

$result = EFactura::tokenService()->executeWithClient(
    $token,
    function (EFacturaClient $client) use ($upload) {
        return $client->uploadInvoice($xmlContent, $options);
    }
);
\`\`\`

## Token Model

The \`EfacturaToken\` model stores:
- \`cui\` — Company identifier (without RO prefix)
- \`access_token\` — Stored as-is, in a plain \`text\` column
- \`refresh_token\` — Stored as-is, in a plain \`text\` column
- \`expires_at\` — Token expiry timestamp
- \`is_active\` — Boolean flag (deactivation doesn't delete)
- \`last_used_at\` — Updated on every API call

> **Tokens are NOT encrypted at rest.** Earlier revisions of this document claimed they were
> encrypted via Laravel's \`Crypt\`; that was never true — no encryption exists anywhere in the
> package. Both columns are plain \`text\` and \`TokenService::storeToken()\` writes the values
> verbatim.
>
> The model does set \`$hidden\` for both fields, but that only keeps them out of \`toArray()\` /
> \`toJson()\` output — it has no effect on what is written to the database. Anyone with read
> access to the \`efactura_tokens\` table, a database backup, or a query log holds live ANAF
> credentials.
>
> Treat the table as secret material: restrict database access, and consider adding
> \`'access_token' => 'encrypted'\` / \`'refresh_token' => 'encrypted'\` to the model's \`$casts\`
> in your own application if you need encryption at rest. Note that doing so requires migrating
> any existing rows, since previously stored values are plaintext.

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

Displays the ANAF OAuth authorization URL that the user must visit in a browser to grant access. Useful for initial setup or re-authorization.

**Arguments:**
- \`{cui}\` — The company's CUI (VAT number, without RO prefix)

## efactura:upload

Process all pending uploads or filter by CUI.

\`\`\`bash
php artisan efactura:upload [--cui=]
\`\`\`

Processes pending \`EfacturaUpload\` records by dispatching \`ProcessSingleUpload\` jobs. Respects the \`features.upload_invoices\` feature flag.

**Options:**
- \`--cui=\` — Optional CUI filter to process only uploads for a specific company

## efactura:status

Check upload statuses and download responses.

\`\`\`bash
php artisan efactura:status [--cui=] [--upload=]
\`\`\`

Checks ANAF processing status for uploads in \`Processing\` state, updates their status to \`Completed\` or \`Failed\`, and downloads response ZIPs for completed uploads.

**Options:**
- \`--cui=\` — Optional CUI filter
- \`--upload=\` — Optional upload ID to check a single specific upload

## efactura:sync

Sync messages from ANAF.

\`\`\`bash
php artisan efactura:sync [--cui=] [--download]
\`\`\`

Syncs the ANAF message list into \`EfacturaMessage\` records. With \`--download\`, also downloads the XML content for received invoices.

**Options:**
- \`--cui=\` — Optional CUI filter to sync only a specific company
- \`--download\` — Also download received invoice ZIPs after syncing messages
`,
};
