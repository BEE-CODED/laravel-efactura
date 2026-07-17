# Laravel e-Factura

A Laravel package that wraps [bee-coded/laravel-efactura-sdk](https://packagist.org/packages/bee-coded/laravel-efactura-sdk) to provide token storage, job scheduling, and easy model integration for Romanian e-Factura (ANAF SPV) compliance.

## Features

- **Token Management** - OAuth token storage per CUI with automatic refresh
- **Background Jobs** - Ready-to-use jobs for invoice uploads, status checks, and message syncing
- **Model Integration** - Simple interface + trait pattern for your invoice models
- **Event-Driven** - Events for all key operations (uploads, failures, received invoices)
- **Minimal Setup** - Auto-discovery, publishable config and migrations

## Requirements

- PHP 8.4+
- Laravel 11.x or 12.x
- ANAF SPV OAuth credentials

## Installation

```bash
composer require bee-coded/laravel-efactura
```

Publish the configuration and migrations:

```bash
php artisan vendor:publish --tag=efactura-config
php artisan vendor:publish --tag=efactura-migrations
php artisan migrate
```

## Configuration

### Environment Variables

```env
# SDK Configuration (required)
EFACTURA_SANDBOX=true
EFACTURA_CLIENT_ID=your-anaf-client-id
EFACTURA_CLIENT_SECRET=your-anaf-client-secret
EFACTURA_REDIRECT_URI=https://your-app.com/efactura/callback

# Package Configuration
EFACTURA_ENABLED=true
EFACTURA_UPLOAD_ENABLED=true
EFACTURA_DOWNLOAD_RECEIVED=false
EFACTURA_SYNC_MESSAGES=true

# Storage
EFACTURA_STORAGE_DISK=local
EFACTURA_STORAGE_PATH=efactura

# Queue (null = default queue)
EFACTURA_QUEUE=null

# Rate Limit Handling
EFACTURA_RATE_LIMIT_RETRY_HOURS=24
EFACTURA_RATE_LIMIT_RETRY_BATCH=250
EFACTURA_RATE_LIMIT_RETRY_MAX_DAYS=7

# Routes
EFACTURA_ROUTES_ENABLED=true
EFACTURA_ROUTES_PREFIX=efactura
EFACTURA_SUCCESS_REDIRECT=/
EFACTURA_ERROR_REDIRECT=/
```

### Config File

The configuration file (`config/efactura.php`) allows you to:

- Enable/disable the entire package or specific features
- Configure file storage for XML and ZIP files
- Set job schedules using cron expressions
- Customize OAuth callback routes

## Model Integration

### 1. Implement the Interface

Your invoice model must implement `EFacturaUploadableInterface`:

```php
<?php

namespace App\Models;

use BeeCoded\EFactura\Contracts\EFacturaUploadableInterface;
use BeeCoded\EFactura\Traits\HasEfacturaUpload;
use BeeCoded\EFacturaSdk\Data\Invoice\InvoiceData;
use BeeCoded\EFacturaSdk\Data\Invoice\PartyData;
use BeeCoded\EFacturaSdk\Data\Invoice\AddressData;
use BeeCoded\EFacturaSdk\Data\Invoice\InvoiceLineData;
use Illuminate\Database\Eloquent\Model;

class Invoice extends Model implements EFacturaUploadableInterface
{
    use HasEfacturaUpload;

    /**
     * Transform this model into SDK's InvoiceData DTO.
     */
    public function toEfacturaData(): InvoiceData
    {
        return new InvoiceData(
            invoiceNumber: $this->number,
            issueDate: $this->issued_at,
            dueDate: $this->due_at,
            currency: $this->currency,
            supplier: new PartyData(
                registrationName: $this->company->name,
                companyId: $this->company->vat_number,
                address: new AddressData(
                    street: $this->company->address,
                    city: $this->company->city,
                    postalZone: $this->company->postal_code,
                    countryCode: $this->company->country_code,
                ),
                isVatPayer: $this->company->is_vat_payer,
            ),
            customer: new PartyData(
                registrationName: $this->customer->name,
                companyId: $this->customer->vat_number,
                address: new AddressData(
                    street: $this->customer->address,
                    city: $this->customer->city,
                    postalZone: $this->customer->postal_code,
                    countryCode: $this->customer->country_code,
                ),
                isVatPayer: $this->customer->is_vat_payer,
            ),
            lines: $this->lines->map(fn ($line) => new InvoiceLineData(
                name: $line->description,
                quantity: $line->quantity,
                unitPrice: $line->unit_price,
                taxAmount: $line->vat_amount,  // Pre-computed VAT for this line
                taxPercent: $line->vat_rate,
            ))->all(),
            paymentIban: $this->company->iban,
        );
    }

    /**
     * Get the CUI for this invoice (determines which token to use).
     */
    public function getEfacturaCui(): string
    {
        return $this->company->cui; // Without RO prefix
    }
}
```

> **Using `Date::use(CarbonImmutable::class)`?** Pass your `datetime` casts straight in, as above.
> `CarbonImmutable` is not a `Carbon` subclass, so this required **SDK v2.3.0+** — on earlier
> versions `issueDate: $this->issued_at` threw a `TypeError` (or, from a caller without
> `declare(strict_types=1)`, silently stringified the date and lost its timezone). The SDK
> normalises immutable dates to a mutable `Carbon` internally; nothing changes for apps on
> mutable `Carbon`.

### 2. Available Trait Methods

The `HasEfacturaUpload` trait provides:

```php
// Relationship
$invoice->efacturaUpload; // The EfacturaUpload model

// Status checks
$invoice->isUploadedToEfactura();  // bool
$invoice->getEfacturaStatus();     // ?UploadStatus enum
$invoice->isEfacturaProcessed();   // bool (completed or failed)

// File paths
$invoice->getEfacturaXmlPath();      // ?string
$invoice->getEfacturaResponsePath(); // ?string
$invoice->getEfacturaErrors();       // ?array

// Query scopes
Invoice::notUploadedToEfactura()->get();           // Not yet queued
Invoice::efacturaPending()->get();                  // Queued, awaiting upload
Invoice::efacturaInProgress()->get();               // Currently uploading/processing
Invoice::efacturaCompleted()->get();                // Successfully processed
Invoice::efacturaFailed()->get();                   // Failed
Invoice::efacturaProcessed()->get();                // Terminal state (completed or failed)
Invoice::efacturaAwaitingResponse()->get();         // Completed but response not downloaded
Invoice::withEfacturaStatus(UploadStatus::Pending)->get(); // Specific status
```

## Usage

### Queue an Invoice for Upload

```php
use BeeCoded\EFactura\Facades\EFactura;

// Standard B2B upload
$upload = EFactura::queueUpload($invoice);

// With options
$upload = EFactura::queueUpload($invoice, [
    'standard' => 'UBL',      // UBL, CN, CII, RASP
    'extern' => false,        // External/non-Romanian supplier
    'self_billed' => false,   // Self-billed/autofactura
]);

// B2C upload
$upload = EFactura::queueB2CUpload($invoice);
```

### Queue a Credit Note for Upload

Credit notes work the same way as invoices — just set `invoiceTypeCode` to `CreditNote` and reference the original invoice:

```php
use BeeCoded\EFacturaSdk\Data\Invoice\InvoiceData;
use BeeCoded\EFacturaSdk\Data\Invoice\InvoiceLineData;
use BeeCoded\EFacturaSdk\Enums\InvoiceTypeCode;

$creditNote = new InvoiceData(
    invoiceNumber: 'CN-2024-001',
    issueDate: now(),
    currency: 'RON',
    invoiceTypeCode: InvoiceTypeCode::CreditNote,
    precedingInvoiceNumber: 'INV-2024-001',
    supplier: $supplier,
    customer: $customer,
    lines: [
        new InvoiceLineData(
            name: 'Returned product',
            quantity: -3,        // negative = items being credited
            unitPrice: 150.00,
            taxAmount: -85.50,   // sign follows quantity: -3 * 150.00 * 0.19
            taxPercent: 19,
        ),
    ],
);
```

> **Note:** The SDK (v1.1+) automatically negates credit note line quantities before sending to ANAF (which expects positive values in `<CreditNote>` documents). Pass **negative** quantities for items being credited and **positive** for debit-back lines (e.g., discount reversals).

```php
$upload = EFactura::queueUpload($creditNoteModel);
```

### The `taxAmount` Parameter (Required since SDK v2.0)

Every `InvoiceLineData` requires a `taxAmount` — the pre-computed VAT amount for that line. The SDK uses this value directly in the XML instead of recalculating VAT internally.

**Why this matters:** In v1.x, the SDK grouped lines by tax rate and recalculated VAT as `sum_of_bases × rate`. This caused rounding discrepancies (typically 0.01 RON) when your application used tax-included pricing, because extracting VAT by subtraction (`gross - net`) can produce different results than multiplying (`net × rate`) after rounding. By passing your pre-computed `taxAmount`, the XML total matches your application's total exactly.

**How to compute it:**

```php
// Tax-exclusive pricing (you store the net unit price):
$taxAmount = round(round($quantity * $unitPrice, 2) * $vatRate / 100, 2);

// Tax-inclusive pricing (you store the gross price and extract the net):
$basePrice = round($grossPrice / (1 + $vatRate / 100), 2);
$taxAmount = $grossPrice - $basePrice; // This is what you should pass
```

**Sign convention:** The `taxAmount` sign follows the quantity — negative for credit note lines (negative qty), positive for regular lines.

> **Upgrading from SDK v1.x:** Add `taxAmount` to every `InvoiceLineData` in your `toEfacturaData()` method. Pass the VAT amount your application already computes for each line item.

### Process Upload Immediately

```php
// Queue and process immediately
$upload = EFactura::queueUpload($invoice);
EFactura::processUpload($upload);
```

### Access the SDK Client

For advanced operations, get an authenticated SDK client:

```php
use BeeCoded\EFacturaSdk\Enums\DocumentStandardType;

$client = EFactura::client('12345678'); // CUI

// Validate XML
$result = $client->validateXml($xml, DocumentStandardType::FACT1);

// Convert to PDF
$pdf = $client->convertXmlToPdf($xml, DocumentStandardType::FACT1);

// Get messages
$messages = $client->getMessages($params);
```

### Company Lookup (No Auth Required)

Use the SDK directly for company lookups:

```php
use BeeCoded\EFacturaSdk\Facades\AnafDetails;

$company = AnafDetails::getCompanyData('12345678');
$companies = AnafDetails::batchGetCompanyData(['12345678', '87654321']);
```

#### Automatic Retry on ANAF's Rate Limit

ANAF's public company-lookup endpoint is capped at **1 request/second**. The SDK
enforces this client-side and throws `RateLimitExceededException` when the limit
is exceeded — it does **not** retry. This wrapper decorates the SDK's
`AnafDetailsClientInterface` binding with `RetryingAnafDetailsClient`, which
**retries up to 5 total attempts** (configurable), sleeping the exception's
`retryAfterSeconds` (≥ 1s) between attempts, then re-throws if still limited.

The retry is **synchronous and blocking** — a fully rate-limited lookup can block
the request for up to ~`(attempts − 1)` seconds before re-throwing, so the
consuming app can surface a "registry busy — retry" message. Only the rate-limit
exception triggers a retry; a `failure()` result (invalid CUI, malformed
response) passes straight through, and the SDK already retries transient
5xx/connection errors internally.

Because the binding is decorated, **no code change is needed** — anything
resolving `AnafDetailsClientInterface` (or the `AnafDetails` facade) gets the
resilient client transparently.

```env
# Total attempts before re-throwing RateLimitExceededException (default: 5)
EFACTURA_ANAF_LOOKUP_RETRY_ATTEMPTS=5
```

```php
// config/efactura.php
'anaf_lookup' => [
    'retry_attempts' => env('EFACTURA_ANAF_LOOKUP_RETRY_ATTEMPTS', 5),
],
```

## OAuth Flow

### 1. Redirect to ANAF

The package provides routes for OAuth:

```
GET /efactura/auth/{cui}  → Redirects to ANAF OAuth
GET /efactura/callback    → Handles OAuth callback
```

In your application:

```php
// In a controller or Livewire component
return redirect()->route('efactura.auth', ['cui' => '12345678']);
```

Or generate the URL manually:

```php
$url = EFactura::getAuthorizationUrl('12345678');
```

### 2. Handle the Callback

The package automatically:
- Validates the OAuth state (CSRF protection)
- Exchanges the code for tokens
- Stores the tokens in the database
- Fires the `TokenStored` event
- Redirects to your configured success/error URL

### 3. Listen to Events

```php
// In EventServiceProvider or a listener
use BeeCoded\EFactura\Events\TokenStored;

Event::listen(TokenStored::class, function (TokenStored $event) {
    $token = $event->token;

    // Notify user, log, etc.
    Log::info("e-Factura authorized for CUI: {$token->cui}");
});
```

## Job Scheduling (Required)

**Important:** This package provides jobs but does NOT schedule them automatically. You must register the job schedules in your application.

### Register Jobs in Your Scheduler

Add the following to your `bootstrap/app.php`:

```php
use BeeCoded\EFactura\Jobs\ProcessPendingUploads;
use BeeCoded\EFactura\Jobs\CheckUploadStatuses;
use BeeCoded\EFactura\Jobs\DownloadResponses;
use BeeCoded\EFactura\Jobs\DownloadReceivedInvoices;
use BeeCoded\EFactura\Jobs\SyncMessages;
use BeeCoded\EFactura\Jobs\RetryRateLimitedUploads;

->withSchedule(function (Schedule $schedule): void {
    // Upload pending invoices to ANAF
    $schedule->job(new ProcessPendingUploads)->everyFiveMinutes();

    // Check processing status at ANAF
    $schedule->job(new CheckUploadStatuses)->everyTenMinutes();

    // Download response ZIPs for completed uploads
    $schedule->job(new DownloadResponses)->everyFifteenMinutes();

    // Retry uploads that failed due to rate limiting
    $schedule->job(new RetryRateLimitedUploads)->everyTenMinutes();

    // Download received invoices (if feature enabled)
    $schedule->job(new DownloadReceivedInvoices)->everyFourHours();

    // Sync message list from ANAF
    $schedule->job(new SyncMessages)->hourly();
})
```

Adjust the schedules to fit your application's needs. All jobs accept an optional `$cui` parameter to process only a specific CUI:

```php
Schedule::job(new ProcessPendingUploads('12345678'))->everyFiveMinutes();
```

### Dispatch a Single Upload or Status Check

For immediate processing of a single upload, dispatch the single-model jobs:

```php
use BeeCoded\EFactura\Jobs\ProcessSingleUpload;
use BeeCoded\EFactura\Jobs\CheckSingleUploadStatus;

// Queue and dispatch a single upload immediately
$upload = EFactura::queueUpload($invoice);
ProcessSingleUpload::dispatch($upload);

// Check status for a specific upload
CheckSingleUploadStatus::dispatch($upload);
```

### Available Jobs

#### Batch Jobs (Scheduled)

| Job | Purpose | Suggested Schedule |
|-----|---------|-------------------|
| `ProcessPendingUploads` | Upload all pending invoices to ANAF | Every 5 minutes |
| `CheckUploadStatuses` | Check processing status at ANAF | Every 10 minutes |
| `DownloadResponses` | Download response ZIPs | Every 15 minutes |
| `DownloadReceivedInvoices` | Download received invoices | Every 4 hours |
| `SyncMessages` | Sync message list from ANAF | Every hour |
| `RetryRateLimitedUploads` | Reset rate-limited failures back to pending | Every 10 minutes |

#### Single-Model Jobs (On-Demand)

| Job | Purpose |
|-----|---------|
| `ProcessSingleUpload` | Process a single upload immediately |
| `CheckSingleUploadStatus` | Check status for a single upload |

### Job Configuration

**Standard jobs** (batch processing, status checks, downloads):
- **Tries**: 3
- **Timeout**: 120 seconds
- **Backoff**: 60s, 180s, 300s (progressive)

**Upload jobs** (`ProcessSingleUpload`) have rate-limit-aware retry:
- **Timeout**: 120 seconds
- **Max Exceptions**: 3 (actual errors only — rate-limit releases don't count)
- **Retry Window**: 24 hours (configurable via `EFACTURA_RATE_LIMIT_RETRY_HOURS`)
- When the SDK's global rate limit quota is exhausted, the job releases itself
  back to the queue with a delay matching the quota reset time, instead of failing
- If a race condition causes a rate-limit failure during upload, the job resets
  the upload to pending and releases with a 60-second delay

### Queue Configuration

Jobs are dispatched to the queue specified in `config/efactura.php`:

```php
'queue' => env('EFACTURA_QUEUE', null), // null = default queue
```

Set `EFACTURA_QUEUE=efactura` in your `.env` to use a dedicated queue. This allows you to run a separate worker for e-Factura jobs:

```bash
php artisan queue:work --queue=efactura
```

### Rate Limit Configuration

The SDK provides client-side rate limiting to stay within ANAF quotas. Upload
jobs are rate-limit-aware and will delay instead of failing when quotas are
exhausted. For uploads that do fail due to rate limiting (e.g., race conditions),
schedule `RetryRateLimitedUploads` to automatically reset them.

```env
# How long upload jobs can keep retrying (hours, default: 24)
EFACTURA_RATE_LIMIT_RETRY_HOURS=24

# Max failed uploads to reset per retry run (default: 250)
EFACTURA_RATE_LIMIT_RETRY_BATCH=250

# Don't retry uploads older than this many days (default: 7)
EFACTURA_RATE_LIMIT_RETRY_MAX_DAYS=7
```

### Queue Worker

Ensure your queue worker is running:

```bash
php artisan queue:work
```

## Artisan Commands

```bash
# Display OAuth URL for a CUI
php artisan efactura:auth 12345678

# Process pending uploads
php artisan efactura:upload
php artisan efactura:upload --cui=12345678

# Check statuses and download responses
php artisan efactura:status
php artisan efactura:status --cui=12345678

# Sync messages from ANAF
php artisan efactura:sync
php artisan efactura:sync --cui=12345678
```

## Events

Listen to these events for custom logic:

| Event | Fired When | Payload |
|-------|------------|---------|
| `TokenStored` | OAuth callback stores new token | `EfacturaToken $token` |
| `TokenRefreshed` | Token auto-refreshed | `EfacturaToken $token` |
| `InvoiceUploaded` | Invoice successfully uploaded | `EfacturaUpload $upload` |
| `InvoiceProcessed` | Response downloaded (success) | `EfacturaUpload $upload` |
| `InvoiceFailed` | Upload or processing failed | `EfacturaUpload $upload`, `array $errors` |
| `InvoiceReceived` | Received invoice downloaded | `EfacturaMessage $message` |

### Example Listener

```php
<?php

namespace App\Listeners;

use BeeCoded\EFactura\Events\InvoiceFailed;
use App\Notifications\EfacturaFailedNotification;

class HandleFailedInvoice
{
    public function handle(InvoiceFailed $event): void
    {
        $upload = $event->upload;
        $errors = $event->errors;

        // Get the original invoice
        $invoice = $upload->uploadable;

        // Notify someone
        $invoice->company->owner->notify(
            new EfacturaFailedNotification($invoice, $errors)
        );
    }
}
```

## Database Schema

The package creates three tables:

### `efactura_tokens`
Stores OAuth tokens per CUI.

### `efactura_uploads`
Tracks upload status for your invoices (polymorphic relationship).

### `efactura_messages`
Stores synced messages from ANAF (sent, received, errors).

## Upload Status Flow

```
Pending → Uploading → Processing → Completed
                   ↘            ↘
                    → Failed ←────┘
```

- **Pending**: Queued, waiting to be uploaded
- **Uploading**: Currently being uploaded to ANAF
- **Processing**: Uploaded, waiting for ANAF to process
- **Completed**: Successfully processed, response available
- **Failed**: Upload or processing failed

## Logging

The SDK logs all API calls to a dedicated logging channel. Add the following channel to your `config/logging.php`:

```php
'channels' => [
    // ... other channels

    'efactura-sdk' => [
        'driver' => 'daily',
        'path' => storage_path('logs/efactura-sdk.log'),
        'level' => 'debug',
        'days' => 30,
    ],
],
```

You can customize the channel name via the `EFACTURA_LOG_CHANNEL` environment variable (defaults to `efactura-sdk`).

## Testing

For testing, use the SDK's sandbox mode:

```env
EFACTURA_SANDBOX=true
```

## Troubleshooting

### Token Not Found

Ensure you've completed the OAuth flow for the CUI:

```bash
php artisan efactura:auth 12345678
```

### Jobs Not Running

1. Verify the scheduler is registered
2. Check `config('efactura.enabled')` is `true`
3. Check feature flags are enabled
4. Review Laravel queue worker logs

### Upload Failures

Check the `errors` column in `efactura_uploads` table or listen to the `InvoiceFailed` event.

## AI Assistant Integration (MCP)

This package and its SDK dependency both include MCP servers that help AI coding assistants understand the full e-Factura integration.

**Setup:** Add both to your AI tool's MCP configuration:

```json
{
  "mcpServers": {
    "efactura-sdk": {
      "command": "node",
      "args": ["vendor/bee-coded/laravel-efactura-sdk/mcp/dist/index.js"]
    },
    "efactura": {
      "command": "node",
      "args": ["vendor/bee-coded/laravel-efactura/mcp/dist/index.js"]
    }
  }
}
```

Requires Node.js 18+.

The wrapper MCP server provides these tools:

| Tool | Description |
|------|-------------|
| `get-wrapper-docs` | Documentation topics: overview, setup, upload-pipeline, token-management, commands |
| `get-model-integration-guide` | Complete guide for integrating a model with e-Factura |
| `get-event-reference` | All events: TokenStored, TokenRefreshed, InvoiceUploaded, InvoiceProcessed, InvoiceFailed, InvoiceReceived |
| `get-job-reference` | All 8 background jobs with scheduling guidance |
| `get-wrapper-config-reference` | Full configuration schema with env vars and defaults |

The SDK MCP server (`efactura-sdk`) provides DTOs, enums, API reference, and SDK-level documentation.

## License

This package is open-sourced software licensed under the [Apache 2.0 License](LICENSE).

## Credits

- [BEE CODED](https://www.beecoded.io/)
