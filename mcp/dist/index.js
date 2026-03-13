// src/index.ts
import { McpServer } from "@modelcontextprotocol/sdk/server/mcp.js";
import { StdioServerTransport } from "@modelcontextprotocol/sdk/server/stdio.js";
import { z } from "zod";

// src/content/wrapper-docs.ts
var wrapperDocsContent = {
  overview: `# Laravel e-Factura Wrapper \u2014 Overview

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
| \`UploadService\` | Queue and process invoices \u2014 XML generation, upload to ANAF, status tracking |
| \`DownloadService\` | Status polling and response ZIP downloads |
| \`MessageSyncService\` | Sync ANAF message list, download received invoices |

### 3 Eloquent Models

| Model | Purpose |
|-------|---------|
| \`EfacturaToken\` | Encrypted OAuth tokens per CUI (company), with \`is_active\` flag and \`last_used_at\` tracking |
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
- \`features.upload_invoices\` \u2014 Enable/disable upload pipeline
- \`features.download_received\` \u2014 Enable/disable received invoice downloads (default: off)
- \`features.sync_messages\` \u2014 Enable/disable ANAF message sync
`,
  setup: `# Laravel e-Factura Wrapper \u2014 Setup & Installation

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
- \`efactura_tokens\` \u2014 OAuth tokens per CUI
- \`efactura_uploads\` \u2014 Upload tracking (polymorphic)
- \`efactura_messages\` \u2014 Synced ANAF messages

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
  "upload-pipeline": `# Laravel e-Factura Wrapper \u2014 Upload Pipeline

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

1. **Atomic claim** \u2014 Updates status from \`Pending\` \u2192 \`Uploading\` using an atomic DB query to prevent double-processing
2. **XML generation** \u2014 Calls \`UblBuilder\` to generate UBL 2.1 XML from \`InvoiceData\`
3. **Store XML** \u2014 Saves generated XML to configured storage disk/path
4. **Upload to ANAF** \u2014 Calls \`EFacturaClient::uploadInvoice()\` with concurrent-safe token handling via \`TokenService::executeWithClient()\`
5. **Success** \u2014 Marks upload as \`Processing\`, stores the ANAF \`download_id\`
6. Fires \`InvoiceUploaded\` event

### 4. CheckUploadStatuses (scheduled)

Runs every 5 minutes. For each upload in \`Processing\` state, calls ANAF's \`getStatusMessage()\` API. Marks \`Completed\` or \`Failed\` based on response.

### 5. DownloadResponses (scheduled)

Runs every 5 minutes. For uploads with a \`download_id\` but no \`response_path\`, downloads the response ZIP from ANAF and stores it to disk.

Fires \`InvoiceProcessed\` event for Completed uploads after download.
Fires \`InvoiceFailed\` event for Failed uploads.

## Upload Status State Machine

\`\`\`
Pending \u2192 Uploading \u2192 Processing \u2192 Completed
                                 \u2192 Failed
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
| \`InvoiceUploaded\` | After successful ANAF upload (status \u2192 Processing) |
| \`InvoiceProcessed\` | After response ZIP downloaded for Completed upload |
| \`InvoiceFailed\` | When upload or processing fails terminally |
`,
  "token-management": `# Laravel e-Factura Wrapper \u2014 Token Management

## OAuth Authorization Flow

### Option A: Built-in Routes

When \`routes.enabled = true\` (default), the package registers two routes:

\`\`\`
GET /efactura/auth/{cui}      \u2192 Generate and redirect to ANAF OAuth URL
GET /efactura/callback         \u2192 Handle OAuth callback, store token
\`\`\`

\`OAuthCallbackController\` handles the code exchange, stores encrypted tokens via \`TokenService::storeToken()\`, and fires \`TokenStored\` event.

### Option B: Manual URL Generation

\`\`\`php
$url = EFactura::getAuthorizationUrl($cui);
// Redirect user to $url
\`\`\`

### CSRF Protection

State validation uses a 15-minute expiry. The state parameter contains a base64-encoded JSON object with \`cui\` and a cryptographically secure \`token\`. The token is stored in session for verification on callback. Expired or mismatched states are rejected silently.

## TokenService::executeWithClient() \u2014 Concurrent-Safe Pattern

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
- \`cui\` \u2014 Company identifier (without RO prefix)
- \`access_token\` \u2014 Encrypted via Laravel's \`Crypt\`
- \`refresh_token\` \u2014 Encrypted via Laravel's \`Crypt\`
- \`expires_at\` \u2014 Token expiry timestamp
- \`is_active\` \u2014 Boolean flag (deactivation doesn't delete)
- \`last_used_at\` \u2014 Updated on every API call

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
  commands: `# Laravel e-Factura Wrapper \u2014 Artisan Commands

## efactura:auth

Generate an OAuth authorization URL for a CUI.

\`\`\`bash
php artisan efactura:auth {cui}
\`\`\`

Displays the ANAF OAuth authorization URL that the user must visit in a browser to grant access. Useful for initial setup or re-authorization.

**Arguments:**
- \`{cui}\` \u2014 The company's CUI (VAT number, without RO prefix)

## efactura:upload

Process all pending uploads or filter by CUI.

\`\`\`bash
php artisan efactura:upload [--cui=]
\`\`\`

Processes pending \`EfacturaUpload\` records by dispatching \`ProcessSingleUpload\` jobs. Respects the \`features.upload_invoices\` feature flag.

**Options:**
- \`--cui=\` \u2014 Optional CUI filter to process only uploads for a specific company

## efactura:status

Check upload statuses and download responses.

\`\`\`bash
php artisan efactura:status [--cui=] [--upload=]
\`\`\`

Checks ANAF processing status for uploads in \`Processing\` state, updates their status to \`Completed\` or \`Failed\`, and downloads response ZIPs for completed uploads.

**Options:**
- \`--cui=\` \u2014 Optional CUI filter
- \`--upload=\` \u2014 Optional upload ID to check a single specific upload

## efactura:sync

Sync messages from ANAF.

\`\`\`bash
php artisan efactura:sync [--cui=] [--download]
\`\`\`

Syncs the ANAF message list into \`EfacturaMessage\` records. With \`--download\`, also downloads the XML content for received invoices.

**Options:**
- \`--cui=\` \u2014 Optional CUI filter to sync only a specific company
- \`--download\` \u2014 Also download received invoice ZIPs after syncing messages
`
};

// src/content/model-integration.ts
var modelIntegrationContent = `# Model Integration Guide

## Step 1: Implement EFacturaUploadableInterface

Your invoice model must implement two methods:

\`\`\`php
use BeeCoded\\EFactura\\Contracts\\EFacturaUploadableInterface;
use BeeCoded\\EFacturaSdk\\Data\\Invoice\\InvoiceData;
use BeeCoded\\EFacturaSdk\\Data\\Invoice\\InvoiceLineData;
use BeeCoded\\EFacturaSdk\\Data\\Invoice\\PartyData;
use BeeCoded\\EFacturaSdk\\Data\\Invoice\\AddressData;

class Invoice extends Model implements EFacturaUploadableInterface
{
    public function toEfacturaData(): InvoiceData
    {
        return new InvoiceData(
            invoiceNumber: $this->number,
            issueDate: $this->issue_date,
            dueDate: $this->due_date,
            supplier: new PartyData(
                registrationName: $this->company->name,
                companyId: $this->company->cui,
                isVatPayer: $this->company->is_vat_payer,
                address: new AddressData(
                    street: $this->company->street,
                    city: $this->company->city,
                    county: $this->company->county,  // ISO 3166-2:RO code
                    countryCode: 'RO',
                ),
            ),
            customer: new PartyData(
                registrationName: $this->client->name,
                companyId: $this->client->cui,
                isVatPayer: $this->client->is_vat_payer,
                address: new AddressData(
                    street: $this->client->street,
                    city: $this->client->city,
                    county: $this->client->county,
                    countryCode: 'RO',
                ),
            ),
            lines: $this->items->map(fn ($item) => new InvoiceLineData(
                name: $item->name,
                quantity: $item->quantity,
                unitPrice: $item->unit_price,
                taxPercent: $item->vat_rate,
                taxAmount: $item->tax_amount,  // Required (SDK v2.0+), pre-computed
            ))->toArray(),
        );
    }

    public function getEfacturaCui(): string
    {
        return $this->company->cui;  // Without RO prefix
    }
}
\`\`\`

## Step 2: Add HasEfacturaUpload Trait

\`\`\`php
use BeeCoded\\EFactura\\Traits\\HasEfacturaUpload;

class Invoice extends Model implements EFacturaUploadableInterface
{
    use HasEfacturaUpload;
    // ...
}
\`\`\`

This trait provides:

### Relationship
- \`efacturaUpload(): MorphOne\` \u2014 The upload record for this model

### Status Checks
- \`isUploadedToEfactura(): bool\` \u2014 Has an upload record
- \`getEfacturaStatus(): ?UploadStatus\` \u2014 Current upload status
- \`isEfacturaProcessed(): bool\` \u2014 Terminal state (Completed or Failed)

### File Paths
- \`getEfacturaResponsePath(): ?string\` \u2014 Path to response ZIP
- \`getEfacturaXmlPath(): ?string\` \u2014 Path to generated XML
- \`getEfacturaErrors(): ?array\` \u2014 Upload errors if failed

### Scopes
- \`notUploadedToEfactura()\` \u2014 No upload record
- \`withEfacturaStatus(UploadStatus $status)\` \u2014 Filter by status
- \`efacturaPending()\` \u2014 Status = Pending
- \`efacturaInProgress()\` \u2014 Status IN (Uploading, Processing)
- \`efacturaCompleted()\` \u2014 Status = Completed
- \`efacturaFailed()\` \u2014 Status = Failed
- \`efacturaProcessed()\` \u2014 Status IN (Completed, Failed)
- \`efacturaAwaitingResponse()\` \u2014 Completed but no response_path yet

## Step 3: Queue Upload

\`\`\`php
use BeeCoded\\EFactura\\Facades\\EFactura;

// Standard B2B upload
$upload = EFactura::queueUpload($invoice);

// B2C upload (consumer invoices)
$upload = EFactura::queueB2CUpload($invoice);

// With options
$upload = EFactura::queueUpload($invoice, [
    'is_extern' => true,
    'is_self_billed' => true,
]);
\`\`\`

## Credit Notes

For credit notes, set InvoiceTypeCode to '381' and provide precedingInvoiceNumber. Quantities must be negative for credit notes to satisfy ANAF validation. The taxAmount field is required (SDK v2.0+) and must be pre-computed.

\`\`\`php
public function toEfacturaData(): InvoiceData
{
    return new InvoiceData(
        invoiceNumber: $this->number,
        invoiceTypeCode: '381',  // credit note type code
        precedingInvoiceNumber: $this->original_invoice_number,
        // ... same structure, quantities are negative
        lines: $this->items->map(fn ($item) => new InvoiceLineData(
            name: $item->name,
            quantity: -abs($item->quantity),  // Negative for credit notes
            unitPrice: $item->unit_price,
            taxPercent: $item->vat_rate,
            taxAmount: $item->tax_amount,
        ))->toArray(),
    );
}
\`\`\`

### Credit Note Notes
- \`invoiceTypeCode: '381'\` identifies this as a credit note in the UBL XML
- \`precedingInvoiceNumber\` references the original invoice being reversed
- Quantities **must be negative** \u2014 the SDK and ANAF both require this for proper credit note processing
- This is a standard B2B or B2C upload \u2014 use \`EFactura::queueUpload()\` as normal
`;

// src/content/event-reference.ts
var eventReferenceContent = `# Laravel e-Factura Wrapper \u2014 Event Reference

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

**Trigger:** Fired after a token is automatically refreshed during an API call (via \`TokenService::handleClientTokenRefresh\`). This is transparent to the caller.

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

**Trigger:** Fired after a successful upload to ANAF. At this point the upload status is \`Processing\` and the ANAF \`download_id\` is stored.

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

**Trigger:** Fired when an upload or processing fails terminally. The \`errors\` array contains error details from ANAF or the upload process.

**Use Cases:**
- Alert admin immediately on invoice failure
- Implement custom retry logic beyond the built-in rate-limit retry
- Log detailed errors for debugging
- Notify the user their invoice was rejected with error details

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

// src/content/job-reference.ts
var jobReferenceContent = `# Laravel e-Factura Wrapper \u2014 Job Reference

All jobs are in the \`BeeCoded\\EFactura\\Jobs\` namespace.

## Shared Behavior for Batch Jobs

All batch jobs (ProcessPendingUploads, CheckUploadStatuses, DownloadResponses, DownloadReceivedInvoices, SyncMessages, RetryRateLimitedUploads) share:
- \`tries = 3\` with backoff of [60, 180, 300] seconds
- \`timeout = 120s\`
- Run on the configured queue: \`config('efactura.queue')\`
- Check \`config('efactura.enabled')\` and their feature flag before executing \u2014 if either is false, the job exits immediately

---

## Batch Jobs (for Scheduling)

### ProcessPendingUploads

\`\`\`php
new ProcessPendingUploads(?string $cui = null)
\`\`\`

Finds all \`EfacturaUpload\` records with status \`Pending\` and dispatches a \`ProcessSingleUpload\` job for each one.

**Feature flag:** \`features.upload_invoices\`

**Arguments:**
- \`$cui\` \u2014 Optional CUI filter to process only a specific company's uploads

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

**Feature flag:** \`features.download_received\` (default: **false** \u2014 must be explicitly enabled)

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

**Implements:** \`ShouldBeUniqueUntilProcessing\` with \`uniqueFor: 600\` seconds \u2014 only one instance runs at a time.

**Configuration:**
- \`rate_limit.retry_batch_size\` (env: \`EFACTURA_RATE_LIMIT_RETRY_BATCH\`, default: 250) \u2014 Max uploads to reset per run
- \`rate_limit.retry_max_age_days\` (env: \`EFACTURA_RATE_LIMIT_RETRY_MAX_DAYS\`, default: 7) \u2014 Ignore uploads older than this many days

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

1. **Atomic claim** \u2014 Updates \`Pending\` \u2192 \`Uploading\` atomically (prevents double-processing)
2. **Pre-flight rate limit check** \u2014 Checks global ANAF quota via SDK's RateLimiter before attempting upload
3. **XML generation** \u2014 Generates UBL 2.1 XML from the uploadable model's \`toEfacturaData()\`
4. **Store XML** \u2014 Persists XML to storage disk
5. **Upload** \u2014 Calls \`EFacturaClient::uploadInvoice()\` via \`TokenService::executeWithClient()\` for concurrent-safe token handling
6. **Success** \u2014 Marks \`Processing\`, stores \`download_id\`; fires \`InvoiceUploaded\`

**Rate limit handling:** If \`RateLimitExceededException\` is caught, upload is reset to \`Pending\` for later retry by \`RetryRateLimitedUploads\`.

**Configuration:**
- \`timeout = 120s\`
- \`maxExceptions = 3\`
- \`retryUntil()\` \u2014 Based on \`rate_limit.retry_window_hours\` (default 24h); job keeps retrying within this window

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

// Optional \u2014 only if features.download_received is enabled:
Schedule::job(new DownloadReceivedInvoices)->hourly();
\`\`\`
`;

// src/content/wrapper-config.ts
var wrapperConfigContent = `# Laravel e-Factura Wrapper \u2014 Configuration Reference

Published to \`config/efactura.php\` via:
\`\`\`bash
php artisan vendor:publish --tag=efactura-config
\`\`\`

---

## Master Switch

| Config Key | Env Var | Type | Default | Description |
|-----------|---------|------|---------|-------------|
| \`enabled\` | \`EFACTURA_ENABLED\` | bool | \`true\` | Master switch \u2014 when \`false\`, all jobs exit immediately without processing |

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
| \`queue\` | \`EFACTURA_QUEUE\` | string|null | \`null\` | Queue name for all e-Factura jobs. \`null\` uses the application's default queue. Recommended: use a dedicated queue like \`'efactura'\` for isolation. |

---

## rate_limit

Configuration for handling ANAF's daily upload quotas and retry behavior.

| Config Key | Env Var | Type | Default | Description |
|-----------|---------|------|---------|-------------|
| \`rate_limit.retry_window_hours\` | \`EFACTURA_RATE_LIMIT_RETRY_HOURS\` | int | \`24\` | How long (in hours) a single \`ProcessSingleUpload\` job keeps retrying via \`retryUntil()\` before failing permanently. After this window, the upload is marked Failed. |
| \`rate_limit.retry_batch_size\` | \`EFACTURA_RATE_LIMIT_RETRY_BATCH\` | int | \`250\` | Maximum number of rate-limited failed uploads to reset to Pending per \`RetryRateLimitedUploads\` job run. Prevents overwhelming the queue on large backlogs. |
| \`rate_limit.retry_max_age_days\` | \`EFACTURA_RATE_LIMIT_RETRY_MAX_DAYS\` | int | \`7\` | \`RetryRateLimitedUploads\` ignores failed uploads older than this many days. Prevents retrying very stale uploads indefinitely. |

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

// src/index.ts
var server = new McpServer({
  name: "efactura",
  version: "1.0.0"
});
var VALID_TOPICS = [
  "overview",
  "setup",
  "upload-pipeline",
  "token-management",
  "commands"
];
server.tool(
  "get-wrapper-docs",
  "Get documentation about the Laravel e-Factura wrapper package for a specific topic",
  { topic: z.enum(VALID_TOPICS).describe("Documentation topic") },
  async ({ topic }) => {
    const content = wrapperDocsContent[topic];
    if (!content) {
      return {
        isError: true,
        content: [{ type: "text", text: `Unknown topic "${topic}". Valid topics: ${VALID_TOPICS.join(", ")}` }]
      };
    }
    return { content: [{ type: "text", text: content }] };
  }
);
server.tool(
  "get-model-integration-guide",
  "Get the complete guide for integrating a Laravel model with e-Factura",
  {},
  async () => ({
    content: [{ type: "text", text: modelIntegrationContent }]
  })
);
server.tool(
  "get-event-reference",
  "Get all events fired by the Laravel e-Factura wrapper package",
  {},
  async () => ({
    content: [{ type: "text", text: eventReferenceContent }]
  })
);
server.tool(
  "get-job-reference",
  "Get all background jobs and scheduling guidance for Laravel e-Factura",
  {},
  async () => ({
    content: [{ type: "text", text: jobReferenceContent }]
  })
);
server.tool(
  "get-wrapper-config-reference",
  "Get the full configuration schema for the Laravel e-Factura wrapper package",
  {},
  async () => ({
    content: [{ type: "text", text: wrapperConfigContent }]
  })
);
async function main() {
  const transport = new StdioServerTransport();
  await server.connect(transport);
  console.error("efactura MCP server running on stdio");
}
main().catch((error) => {
  console.error("Fatal error:", error);
  process.exit(1);
});
