# Laravel e-Factura

A Laravel package that wraps [bee-coded/laravel-efactura-sdk](https://packagist.org/packages/bee-coded/laravel-efactura-sdk) to provide token storage, job scheduling, and easy model integration for Romanian e-Factura (ANAF SPV) compliance.

## Features

- **Token Management** - OAuth token storage per CUI with automatic refresh
- **Job Scheduling** - Configurable cron schedules for invoice uploads, status checks, and message syncing
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

# Schedule (cron expressions)
EFACTURA_SCHEDULE_UPLOAD="*/5 * * * *"
EFACTURA_SCHEDULE_STATUS="*/10 * * * *"
EFACTURA_SCHEDULE_RESPONSES="*/15 * * * *"
EFACTURA_SCHEDULE_RECEIVED="0 */4 * * *"
EFACTURA_SCHEDULE_SYNC="0 * * * *"

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
                    streetName: $this->company->address,
                    cityName: $this->company->city,
                    postalZone: $this->company->postal_code,
                    countryCode: $this->company->country_code,
                ),
                isVatPayer: $this->company->is_vat_payer,
            ),
            customer: new PartyData(
                registrationName: $this->customer->name,
                companyId: $this->customer->vat_number,
                address: new AddressData(
                    streetName: $this->customer->address,
                    cityName: $this->customer->city,
                    postalZone: $this->customer->postal_code,
                    countryCode: $this->customer->country_code,
                ),
                isVatPayer: $this->customer->is_vat_payer,
            ),
            lines: $this->lines->map(fn ($line) => new InvoiceLineData(
                name: $line->description,
                quantity: $line->quantity,
                unitPrice: $line->unit_price,
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
Invoice::notUploadedToEfactura()->get();
Invoice::withEfacturaStatus(UploadStatus::Completed)->get();
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

## Scheduled Jobs

### Register the Scheduler

In your `routes/console.php` (Laravel 11+):

```php
use BeeCoded\EFactura\Console\Commands\EfacturaScheduleCommand;
use Illuminate\Support\Facades\Schedule;

Schedule::command(EfacturaScheduleCommand::class)->everyMinute();
```

The package will dispatch jobs based on your configured cron expressions.

### Available Jobs

| Job | Purpose | Default Schedule |
|-----|---------|------------------|
| `ProcessPendingUploads` | Upload pending invoices to ANAF | Every 5 minutes |
| `CheckUploadStatuses` | Check processing status at ANAF | Every 10 minutes |
| `DownloadResponses` | Download response ZIPs | Every 15 minutes |
| `DownloadReceivedInvoices` | Download received invoices | Every 4 hours |
| `SyncMessages` | Sync message list from ANAF | Every hour |

### Job Configuration

All jobs have built-in retry logic:
- **Tries**: 3
- **Timeout**: 120 seconds
- **Backoff**: 60s, 180s, 300s (progressive)

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

## License

This package is open-sourced software licensed under the [Apache 2.0 License](LICENSE).

## Credits

- [BEE CODED](https://www.beecoded.io/)
