# Laravel e-Factura

A Laravel package that wraps [bee-coded/laravel-efactura-sdk](https://packagist.org/packages/bee-coded/laravel-efactura-sdk) to provide token storage, job scheduling, and easy model integration for Romanian e-Factura (ANAF SPV) compliance.

## Upgrading to v4.0

Work through `## [4.0.0]` in [CHANGELOG.md](CHANGELOG.md).

From 2.x, follow the v3.1.1 README first.

## Features

- **Token Management** - OAuth token storage per CUI with automatic refresh
- **Background Jobs** - Ready-to-use jobs for invoice uploads, status checks, and message syncing
- **Model Integration** - Simple interface + trait pattern for your invoice models
- **Event-Driven** - Events for all key operations (uploads, failures, received invoices)
- **Minimal Setup** - Auto-discovery, publishable config and migrations

## Requirements

- PHP 8.4+
- Laravel 12 (laravel/framework 12.27.1 or later) or Laravel 13
- `bee-coded/laravel-efactura-sdk` ^4.0.1 (installed automatically)
- ANAF SPV OAuth credentials
- A database: SQLite, PostgreSQL or MariaDB (all tested in CI). MySQL 8 is expected to work but is
  not tested in CI.
- A stable, backed-up `APP_KEY` — it encrypts the stored ANAF credentials
- A shared, persistent cache store (redis, memcached, database — **not `array`**), for the OAuth
  state and token-refresh locks
- A running queue worker on `config('efactura.queue')`, on the connection `config('efactura.connection')`
  names (the app's default connection when it is not set) — uploads are dispatched, not inline.
  Without a queue worker, schedule `php artisan efactura:upload --sync` every minute instead: it
  sends each company's due uploads inline, and a later run sends an upload that is waiting for a
  retry once it is due. When the package's connection (`EFACTURA_QUEUE_CONNECTION`, else
  `QUEUE_CONNECTION`) is `sync`, a batch job without a CUI runs its per-company
  jobs inline, inside itself, so one company's exception propagates through it and stops that run
  for the remaining companies. On a real queue connection with no worker, a scheduled
  `$schedule->job(...)` is only queued, so schedule the rest of the work inline too:
  `$schedule->job(new SweepStaleUploads, connection: 'sync')->everyFifteenMinutes()` (it parks an
  upload a killed `--sync` run left `uploading`, so `efactura:reconcile` lists it),
  `php artisan efactura:status` every ten minutes and, if you receive invoices or sync messages,
  `php artisan efactura:sync` hourly. A document whose preparation kills the process (memory
  exhaustion, `SIGKILL`) is not counted under `--sync`: every run dies at it, and the uploads after
  it (that company's and the later companies') are not sent until it is corrected or deleted
- The queue connection's `retry_after` must be greater than the jobs' 120-second timeout (for
  example 180). Laravel's default of 90 hands a running job to a second worker. Keep `retry_after`
  plus the time a job can wait in the queue under about 240 seconds: a worker killed while it
  prepares a document is counted only when the queue redelivers its job before the 300-second
  dispatch lease ends and the next `ProcessPendingUploads` run leases the upload again. Past 300
  seconds in the queue, an upload job finds its upload leased again by a later dispatch and does
  nothing, so behind a queue that keeps waiting that long no upload is sent: give the package's
  jobs their own queue (`EFACTURA_QUEUE`). If your default connection's `retry_after` is larger
  (for example 1020 for long-running jobs of your own), set `EFACTURA_QUEUE_CONNECTION` to a
  connection whose `retry_after` is about 180 (available from 4.1.0): every job the package
  dispatches then goes on that connection, and your own jobs keep the default one. A job's
  reservation time comes from the connection whose worker takes it, and two connections on the same
  Redis database or jobs table share a queue of the same name. So give the package jobs a queue no
  worker of another connection reads (`EFACTURA_QUEUE`, for example `efactura`), and run only
  workers of the package's connection on it.

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
# false = tokens, OAuth and the raw client only: no upload/message tables, pipeline refuses
EFACTURA_PIPELINE_TABLES=true
EFACTURA_UPLOAD_ENABLED=true
EFACTURA_DOWNLOAD_RECEIVED=false
EFACTURA_SYNC_MESSAGES=true

# Storage
EFACTURA_STORAGE_DISK=local
EFACTURA_STORAGE_PATH=efactura

# Queue (null = default queue)
EFACTURA_QUEUE=null
# Queue connection for every package job (null = the app's default connection)
EFACTURA_QUEUE_CONNECTION=null

# Routes
EFACTURA_ROUTES_ENABLED=true
EFACTURA_ROUTES_PREFIX=efactura
EFACTURA_SUCCESS_REDIRECT=/
EFACTURA_ERROR_REDIRECT=/
```

### Config File

The configuration file (`config/efactura.php`) allows you to:

- Stop the package's jobs and artisan commands (`enabled` false). Token storage (`TokenService`), the
  OAuth routes (see `routes.enabled`), the services and the raw client are unaffected
- Turn single features off: `features.upload_invoices` (uploads, status checks and responses),
  `features.sync_messages` and `features.download_received` (received invoices; off by default)
- Use tokens, OAuth and the raw client only (`pipeline_tables` false): only the token migrations
  are loaded and published, and the upload/message services, jobs and commands throw
  `PipelineTablesDisabledException`
- Choose the queue e-Factura jobs are dispatched to (`queue`) and the queue connection they go on
  (`connection`)
- Configure file storage for XML and ZIP files (`storage.disk`, `storage.path`)
- Set the company-lookup retries (`anaf_lookup.retry_attempts`)
- Customize OAuth callback routes

Retry timing, caps and job uniqueness are fixed in code, not configured: see
[Job Configuration](#job-configuration).

> **Job schedules are not configured here.** There is no schedule or cron key in
> `config/efactura.php` — this package does not schedule anything for you. You register the
> schedules in your own application; see [Job Scheduling](#job-scheduling-required).

## Model Integration

### 1. Implement the Interface

Your invoice model must be an Eloquent model implementing `EFacturaUploadableInterface` (the upload
row points at it polymorphically):

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
                    county: $this->company->county,
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
                    county: $this->customer->county,
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
            // BT-111 — the VAT total converted to RON. REQUIRED when currency is not RON,
            // and REJECTED when it is. Omit this line entirely if you only ever file in RON.
            taxAmountRon: $this->currency === 'RON' ? null : $this->vat_total_ron,
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

> **Dates are the calendar day in Romania.** The SDK files the day of the Carbon you pass, in its
> own timezone (SDK README, Date Helpers). A `date` cast is already that day; on a UTC app pass a
> `datetime` as `$this->issued_at->copy()->setTimezone(DateHelper::ANAF_TIMEZONE)`, and
> `now(DateHelper::ANAF_TIMEZONE)` for today.

> **SDK 4.0 checks more of the document before building it.** A Romanian address's `county` must not
> be blank (BR-RO-110/111; whitespace-only is now refused too), and in Bucharest it needs a sector
> the SDK can resolve (`bucharestSector: 1..6` on `AddressData`, or a sector in the city or
> county). An invoice with a positive total needs a
> `dueDate` (BR-CO-25), and currency and country codes must be uppercase ISO codes. Amounts with more
> decimals than filed are refused, not rounded: pass rounded values or decimal strings
> (`float|string`). The upload of a document the SDK refuses ends `Failed` / `validation`, with
> `InvoiceFailed`. The full list is in the 4.0 Breaking section of the CHANGELOG.

> **`isVatPayer` is required, and its position changed in SDK v3.0** — it is now the 4th `PartyData`
> constructor parameter, swapping places with `registrationNumber`, and has no default. **Always use
> named arguments, as above.** A v2-era *positional* call
> `new PartyData($name, $id, $address, 'J40/1234/2020')` now passes the ONRC string as `isVatPayer`,
> and the SDK throws `InvalidArgumentException` naming the moved parameter (only `'1'` and `'0'` are
> read as booleans), so nothing is filed: the upload ends `Failed` / `validation`, with
> `InvoiceFailed`.

> **`taxAmountRon` is required for non-RON invoices (SDK v3.0)** and rejected for RON ones — see the
> `taxAmountRon` line above. Pass the converted amount your ledger already holds (the BNR-rate
> figure), not an exchange rate: ANAF cannot verify the conversion, so a wrong value is accepted and
> filed as a true statement of VAT owed.

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
$invoice->getEfacturaXmlPath();      // ?string (null while an unsent upload waits to be retried)
$invoice->getEfacturaResponsePath(); // ?string
$invoice->getEfacturaErrors();       // ?array

// Query scopes
Invoice::notUploadedToEfactura()->get();           // Not yet queued
Invoice::efacturaPending()->get();                  // Queued, awaiting upload
Invoice::efacturaInProgress()->get();               // Currently uploading/processing
Invoice::efacturaCompleted()->get();                // Successfully processed
Invoice::efacturaFailed()->get();                   // Failed
Invoice::efacturaProcessed()->get();                // Terminal state (completed or failed)
Invoice::efacturaAwaitingResponse()->get();         // Completed, response not downloaded yet (processed within the last 60 days)
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
    'standard' => 'UBL',      // UBL (invoice) or CN (credit note); the queue always builds UBL XML
    'extern' => false,        // The buyer is not a Romanian entity (sends extern=DA)
    'self_billed' => false,   // Self-billed/autofactura
]);

// B2C upload
$upload = EFactura::queueB2CUpload($invoice);
$upload = EFactura::queueB2CUpload($creditNote, ['standard' => 'CN']);  // B2C takes the same options
```

A `standard` other than `UBL`, `CN`, `CII` or `RASP` throws `InvalidArgumentException` when the
upload is queued, and so does any option key other than `standard`, `extern` and `self_billed`. CII
invoices, RASP buyer messages and `executare` filings cannot go through the queue: upload them with
the SDK client directly.

A model has one upload row, so queueing it again depends on that row's state:

| Existing upload | `queueUpload()` |
|-----------------|-----------------|
| None | Creates it |
| `pending` | Takes the new options and the company's current token and returns it (a worker preparing the old options loses its claim) |
| `uploading`, `processing` or `completed` | Returns it; different options, or another company, throw `DuplicateUploadException` |
| `failed`, not `indeterminate` | Resets it to `pending` and returns it |
| `failed` / `indeterminate` | Throws `DuplicateUploadException`: resolve it with `efactura:reconcile` first |

A failed upload that another worker keeps changing while you queue it again also throws
`DuplicateUploadException` ("kept changing"): queue it again.

### Queue a Credit Note for Upload

Set `invoiceTypeCode` to `CreditNote`, reference the original invoice, and queue it with `'standard' => 'CN'`. The package sends the standard you queue (default `UBL`), whatever the document type:

```php
use BeeCoded\EFacturaSdk\Data\Invoice\InvoiceData;
use BeeCoded\EFacturaSdk\Data\Invoice\InvoiceLineData;
use BeeCoded\EFacturaSdk\Data\Invoice\PrecedingInvoiceData;
use BeeCoded\EFacturaSdk\Enums\InvoiceTypeCode;
use BeeCoded\EFacturaSdk\Support\DateHelper;

$creditNote = new InvoiceData(
    invoiceNumber: 'CN-2024-001',
    issueDate: now(DateHelper::ANAF_TIMEZONE),
    currency: 'RON',
    invoiceTypeCode: InvoiceTypeCode::CreditNote,
    precedingInvoices: [new PrecedingInvoiceData('INV-2024-001', '2024-01-15')],
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
$upload = EFactura::queueUpload($creditNoteModel, ['standard' => 'CN']);
$upload = EFactura::queueB2CUpload($creditNoteModel, ['standard' => 'CN']);  // a B2C credit note
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
// Queue and send it in this process, once
$upload = EFactura::queueUpload($invoice);
EFactura::processUpload($upload);
```

`processUpload()` makes one try, and only while the upload is `pending` and due (`next_poll_at` null
or past; a freshly queued upload is). An upload waiting for a retry, or one a queued job holds, is
left alone. Without a queue worker this is what `php artisan efactura:upload --sync` does for every
due upload.

### Access the SDK Client

For advanced operations, get an authenticated SDK client:

> **Treat this client as an escape hatch.** If the SDK refreshes during one of these calls, it saves
> the new grant under its refresh lock (v4.0), but a failed save is only logged as a warning and not
> retried: ANAF rotates refresh tokens, so the stored token is then spent, and once `$client` goes out
> of scope the company must re-authorise from scratch. It refuses a grant the package has paused
> (`RefreshCooldownException`), but a refusal during its own calls does not pause the grant: use
> `executeWithClient()` for that.
>
> For anything that files or fetches on a company's behalf, prefer `executeWithClient()`, which
> refreshes an expiring token before your operation and saves any refresh the SDK makes during it
> (see [Token refresh and bookkeeping](#token-refresh-and-bookkeeping)):
>
> ```php
> use BeeCoded\EFactura\Services\TokenService;
>
> app(TokenService::class)->executeWithClient(
>     EFactura::getToken('12345678'),
>     fn ($client) => $client->getMessages(new ListMessagesParamsData(cif: '12345678', days: 30)),
> );
> ```
>
> If you must use `EFactura::client()`, call this afterwards: it retries a failed save (letting its
> exception propagate), fires `TokenRefreshed` only if it stored the grant, and records `last_used_at`:
> `app(TokenService::class)->handleClientTokenRefresh($client, EFactura::getToken($cui));`

```php
use BeeCoded\EFacturaSdk\Data\Invoice\ListMessagesParamsData;
use BeeCoded\EFacturaSdk\Enums\DocumentStandardType;
use BeeCoded\EFacturaSdk\Enums\MessageFilter;

$client = EFactura::client('12345678'); // CUI

// Validate XML
$result = $client->validateXml($xml, DocumentStandardType::FACT1);

// Convert to PDF
$pdf = $client->convertXmlToPdf($xml, DocumentStandardType::FACT1);

// Get messages — requires a ListMessagesParamsData
$messages = $client->getMessages(new ListMessagesParamsData(
    cif: '12345678',
    days: 30,                            // lookback window in days (1-60)
    filter: MessageFilter::InvoiceSent,  // optional; null = all message types
));
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
enforces this client-side (it counts lookups over a 2-second window) and throws `RateLimitExceededException` when the limit
is exceeded — it does **not** retry. This wrapper decorates the SDK's
`AnafDetailsClientInterface` binding with `RetryingAnafDetailsClient`, which
**retries up to 5 total attempts** (configurable), sleeping the exception's
`retryAfterSeconds` (≥ 1s) between attempts, then re-throws if still limited.

The retry is **synchronous and blocking** — a fully rate-limited lookup can block
the request for up to about 2 × (attempts − 1) seconds of sleeps before re-throwing,
plus the SDK's own HTTP retries and timeouts per attempt during an outage, so the
consuming app can surface a "registry busy — retry" message. Only the SDK's own
limiter refusal triggers a retry: ANAF's own 429 (a `RateLimitExceededException`
with `fromProvider`) is re-thrown at once, since asking again within seconds only
spends more of the 1 request/second. A `failure()` result (invalid CUI, malformed
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

#### Cached, fail-closed check: `CachedAnafLookup`

To answer "does this CUI exist and is it active today?" for a form or a validation rule, use
`CachedAnafLookup` with a strict lookup client (not the retrying decorator, which sleeps).
Type-hinting `CachedAnafLookup` resolves it with
`new AnafDetailsClient(strict: true, tries: 1, timeoutSeconds: 2)`; a client you pass yourself must
be strict too, because every instance shares one cache key:

```php
use BeeCoded\EFactura\Enums\AnafLookupStatus;
use BeeCoded\EFactura\Services\CachedAnafLookup;
use BeeCoded\EFacturaSdk\Services\ApiClients\AnafDetailsClient;

$lookup = new CachedAnafLookup(new AnafDetailsClient(strict: true, tries: 1, timeoutSeconds: 2));

$result = $lookup->resolve('RO18547290'); // AnafLookupResult: status, cui, queryDate, active
if ($result->status === AnafLookupStatus::Found && $result->active) {
    // ...
}

// Today's cached verdict only, never calls ANAF (null when there is none): apply your own
// per-user throttle before calling resolve().
$cached = $lookup->cachedRead('18547290');
```

`Found` (with `active`) is cached for the rest of the day and `NotFound` for 15 minutes in the default cache
store, as plain arrays, under a key holding the policy version, the lookup URL, the ANAF date
(Europe/Bucharest) and the CUI, so a verdict never outlives its day. `Found` needs exactly one
company with the CUI asked; `NotFound` needs ANAF's `notFound` to be exactly that CUI. Anything
else, including an answer that arrives after midnight, is `Unavailable`; a rate limit (the SDK's
own or ANAF's 429) is `Busy`. Neither is cached, and `resolve()` never throws for them. A cache
that cannot be read or written does not change ANAF's answer; it only means ANAF is asked again
next time. A CUI that is not 2 to 10 digits with a valid control digit (an `RO` prefix and spaces
are allowed) throws `InvalidArgumentException`.

## OAuth Flow

### 1. Redirect to ANAF

The package provides routes for OAuth:

```
GET /efactura/auth/{cui}  → Redirects to ANAF OAuth
GET /efactura/callback    → Handles OAuth callback
```

**Gate the redirect route.** Both routes run the `routes.middleware` stack, `['web', 'auth']` by
default, so a guest is sent to your login page. `auth` does not check that the user may act for
that company: any logged-in user could authorise any CUI, and the grant that comes back is stored
for it. Restrict `auth/{cui}` to users allowed to act for that CUI, for example with a `can:`
middleware in `config/efactura.php`:

```php
'middleware' => ['web', 'auth', 'can:authorize-efactura,cui'],
```

The same stack also runs on `efactura/callback`, which has no `{cui}`, so there the gate receives
`null`. It must allow that, or no callback completes: a `string $cui` parameter throws a `TypeError`
(500), and a check for the CUI answers 403.

```php
Gate::define('authorize-efactura', fn ($user, ?string $cui = null) => $cui === null || $user->mayActFor($cui)); // mayActFor: your own check
```

The callback needs no CUI check. It only completes a state that the same session and user started
through `auth/{cui}` (below), or a URL from `efactura:auth`. Alternatively, set
`EFACTURA_ROUTES_ENABLED=false` and run the flow from your own controller with
`EFactura::tokenService()->getAuthorizationUrl()` and your own callback.

**The state is bound to who started it.** An authorization started through `efactura/auth/{cui}` is
tied to that browser session (the session's CSRF token) and to the logged-in user; the callback
refuses it from another session, for another user, or after the session was regenerated in between
(for example by a login). Nobody can get a user to complete a link they started and store that
user's grant. Two authorizations started in one session both complete. A URL from
`php artisan efactura:auth` is not bound and completes in any logged-in browser. From your own
controller, bind it the same way:

```php
// Starting it: bound to the session's CSRF token and the user
$url = EFactura::tokenService()->getAuthorizationUrl($cui, $request->session()->token(), $request->user()->getAuthIdentifier());

// In your callback: null unless the same session and user started it
$cui = EFactura::tokenService()->validateOAuthState(
    $request->query('state'),
    $request->session()->token(),
    $request->user()->getAuthIdentifier(),
);
```

In your application:

```php
// In a controller or Livewire component
return redirect()->route('efactura.auth', ['cui' => '12345674']);
```

Or generate the URL yourself, bound to the session and user as shown above. Without the session
token and user id the URL is unbound, like the one `php artisan efactura:auth` prints, and completes in any
logged-in browser: keep that form to the console.

```php
// From a web request: bound
$url = EFactura::tokenService()->getAuthorizationUrl('12345674', $request->session()->token(), $request->user()->getAuthIdentifier());

// From the console only: unbound
$url = EFactura::getAuthorizationUrl('12345674');
```

### 2. Handle the Callback

The package automatically:
- Validates the OAuth state (CSRF protection)
- Exchanges the code for tokens
- Stores the tokens in the database
- Fires the `TokenStored` event (a listener exception is reported and does not fail the callback)
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

## Credentials and APP_KEY

`efactura_tokens.access_token` and `refresh_token` are encrypted with Laravel's `encrypted` cast, so
`APP_KEY` protects your ANAF credentials. The cast has no plaintext fallback: a row the encryption
migration (`000004`) never reached throws `DecryptException` when it is read.

- **Losing or rotating `APP_KEY` orphans every token.** Without the old key present, nothing can be
  decrypted and **every connected company must complete the OAuth flow again**
  (`php artisan efactura:auth {cui}`). Back the key up somewhere other than the database it
  protects.
- **Rotate only with `APP_PREVIOUS_KEYS`:**

  ```dotenv
  APP_KEY=base64:<new key>
  APP_PREVIOUS_KEYS=base64:<previous key>
  ```

  Laravel then decrypts existing tokens with the old key and re-encrypts them with the new one as
  they are written.
- **Restoring a production database to staging or local requires the production `APP_KEY`.**
  A dump loaded into an environment with a different key leaves every token unreadable. Either
  carry the matching key across, or re-authorise in that environment. (Re-authorising is
  frequently the better answer: a staging box holding working production ANAF credentials is its
  own problem.)
- **If the old key is gone, the stored credentials cannot be recovered.** Overwrite them in place
  and deactivate the token:

  ```php
  DB::table('efactura_tokens')->where('cui', $cui)->update([
      'access_token' => 'revoked',
      'refresh_token' => 'revoked',
      'is_active' => false,
  ]);
  ```

  If you are rolling back the `000004` migration, re-run the rollback (it leaves values that are not
  encrypted as they are). Then have each company re-authorise
  (`php artisan efactura:auth {cui}`), which overwrites the same row and activates it again. Follow
  this, not the `000004` rollback message, which says to delete the rows.

> **Never delete an `efactura_tokens` row.** Its uploads and messages are deleted with it, and
> invoices already filed at ANAF can then be queued again.

> **Writes that bypass the model store unreadable plaintext.** `Builder::update()` and raw SQL
> skip casts, so `DB::table('efactura_tokens')->update(['access_token' => $raw])` — or
> `EfacturaToken::where(...)->update([...])` — silently writes a value nothing can decrypt. Load the
> model instance and update it, so the cast runs.

## Token Refresh and Bookkeeping

Every ANAF call the package makes goes through `TokenService::executeWithClient()`:

0. **A deactivated token is refused** with a `RuntimeException` ("Token for CUI … has been
   deactivated") before anything is sent. **A paused grant is refused too:** the call throws a
   `RefreshCooldownException` (an `AuthenticationException`, code 401, whose message names the
   seconds left) without asking ANAF, so one dead grant costs one refused request per pause
   instead of one per row. A grant is paused after these answers:

   | ANAF's answer | Pause |
   |---------------|-------|
   | The token endpoint refuses the refresh (HTTP 400/401), or a request is refused with 401 | 15 minutes |
   | The token endpoint answers 429, 5xx or an unreadable 2xx, or a refresh before the call gets no answer (code 0: a transport failure, missing OAuth configuration) | 60 seconds |
   | A request (or a refresh the SDK makes during it) gets no answer (code 0) | none |

   The pause belongs to the grant (the token id and its refresh token): after the company
   re-authorises, the new grant is tried at once. A refused access token whose refresh token still
   works is paused again every 15 minutes until the company re-authorises. While a grant is paused
   its uploads wait in `pending` and its status and download runs stop, without counting anything
   against a row. The token is never deactivated automatically.
1. **Before the call**, a token that expires within 120 seconds is refreshed under the SDK's refresh
   lock (`EFacturaClient::tokenRefreshLock()` on the canonical CUI; held for the refresh only, at
   most the SDK HTTP timeout plus 30 seconds, waiting up to 30 seconds for it). This is the only
   place a `LockTimeoutException` comes from, and when it is thrown nothing has been sent. A refused
   or failed refresh pauses the grant before the lock is released, so workers that waited on the lock
   find the pause and do not ask again. A lock that cannot be released is left to expire. If saving
   the refreshed grant fails, the call throws `RuntimeException("EFactura: the token for CUI … could
   not be saved")`, which names only the CUI (the cause's class and code are logged as a warning);
   nothing has been sent either.
2. **The call runs without any lock**, so calls for one company run in parallel.
3. **If the SDK refreshes during the call**, it saves the new grant through the package while it
   still holds that lock, so the next worker to take the lock reads the new refresh token instead of
   spending the old one. The save is a compare-and-set: it replaces the stored grant only while the
   stored refresh token is still the one this refresh spent, on an active token, so a stale or
   concurrent refresh never overwrites a newer grant or a re-authorisation; such a refresh is
   dropped silently. `TokenRefreshed` fires only when the write stored the grant.
4. **After the call**, whether it returned or threw, the grant is saved again only when the save
   under the lock did not store it, and `last_used_at` is recorded.

Make these calls outside a database transaction: a grant refreshed during the call is stored inside
it, the refresh lock is released before you commit, and a rollback loses the grant ANAF has already
rotated, so the company must re-authorise.

Re-authorising (the OAuth callback, `TokenService::storeToken()`) is one upsert of the company's row.
It needs no readable old credentials, so it also recovers a row the current `APP_KEY` cannot
decrypt, and it fires no Eloquent model events (the OAuth callback then fires `TokenStored`; a
direct `storeToken()` call fires no event).

This bookkeeping never replaces the call's result or exception: an upload index ANAF has assigned is
never lost to a failed `last_used_at` write. Its failures are logged, never thrown: as **critical**
("refreshed token could not be saved; re-authorisation may be needed") when the refreshed grant
could not be saved, because ANAF has already rotated it, and as a warning otherwise. Watch for that
critical entry: the company may have to re-authorise (`php artisan efactura:auth {cui}`).

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
use BeeCoded\EFactura\Jobs\SweepStaleUploads;

->withSchedule(function (Schedule $schedule): void {
    $schedule->job(new ProcessPendingUploads)->everyMinute();
    $schedule->job(new CheckUploadStatuses)->everyTenMinutes();
    $schedule->job(new DownloadResponses)->everyFifteenMinutes();
    $schedule->job(new SweepStaleUploads)->everyFifteenMinutes();
    $schedule->job(new SyncMessages)->hourly();
    $schedule->job(new DownloadReceivedInvoices)->hourly(); // only if features.download_received is on
})
```

### Recommended schedule

This is the one recommended cadence per job; every other place in these docs refers to it. The
quotas named are the SDK's daily per-message and per-CUI limits (`config/efactura-sdk.php`). A
*usable* company is one whose token is active, not paused and stored under a canonical CUI.

| Job | Recommended cadence | Why |
|-----|---------------------|-----|
| `ProcessPendingUploads` | Every minute | Queues a `ProcessSingleUpload` for each upload that is due, at most as many as the SDK's global per-minute quota has left (the rest wait for the next run): a new one, or one whose retry time (`next_poll_at`) has come. A retry is sent by the first run after that time, so this interval is the retry latency. Uploads count against the global per-minute limit (and, for RASP, a per-CUI daily one), not a per-document one. Without a CUI it dispatches one job per usable company with uploads due. The quota cap is per company job, not a reservation: each reads the same remaining count, so N companies due at once can queue up to N times it, and a job past the quota hands its upload back for a later run |
| `CheckUploadStatuses` | Every 10 minutes | Reads only uploads whose next read is due (`next_poll_at`, the SDK's `PollSchedule`: 1, 5, 15, then 60 minutes as the upload ages; a 10-minute run rounds the shorter ones up), so an old upload uses about 24 of its index's daily status reads (SDK `status_per_day_message`, default 50). An upload at its limit waits for its next slot; the others are still polled. Without a CUI it dispatches one job per usable company with uploads due |
| `DownloadResponses` | Every 15 minutes | Each try uses one of the download id's daily downloads (SDK `download_per_day_message`, default 5); a successful download happens once. A failed try is retried after an hour, doubling to a day, for 60 days after the upload was processed. Without a CUI it dispatches one job per usable company with responses due |
| `SweepStaleUploads` | Every 15 minutes | Uploads are stale after 30 minutes (`SweepStaleUploads::STALE_MINUTES`), so a stranded upload is parked within 45 minutes |
| `SyncMessages` | Hourly | Without a CUI it dispatches one job per usable company. Each pages through the last 60 days: one paginated-list call per 500 messages (SDK `paginated_list_per_day_cui`, default 50,000) |
| `DownloadReceivedInvoices` | Hourly | Downloads what `SyncMessages` listed; each try uses one of the message's 5 daily downloads, and a failed one is retried after an hour, doubling to a day, for 60 days. Without a CUI it dispatches one job per usable company with invoices due |

A faster cadence than this mainly spends those quotas sooner. Each of the five company jobs
(`ProcessPendingUploads`, `CheckUploadStatuses`, `DownloadResponses`, `DownloadReceivedInvoices`,
`SyncMessages`) is queued at most once per company until it starts (`ShouldBeUniqueUntilProcessing`,
for up to 3600 seconds; the job without a CUI, which only dispatches, for up to 120 seconds, so a
failed queue push holds the schedule for at most two minutes) and never runs twice at once for one
company (`WithoutOverlapping`; the second run is dropped, not delayed). A run that outlasts a short
interval does not read the same rows twice, and an outage does not pile up a company's copies in the
queue. A late job is not dropped: it finds only
what is still due.

Adjust the schedules to fit your application's needs. All batch jobs **except `SweepStaleUploads`**
accept an optional `$cui` parameter to process only a specific company:

```php
Schedule::job(new CheckUploadStatuses('12345678'))->everyTenMinutes();
```

The CUI is read in any spelling (`'RO12345678'` and `' 12345678 '` are the same company, and share
one lock). A value that is not a CUI or CNP (`''`, `'0'`, `'RO'`, a leading zero) fails the job when
it runs; it never means "every company". A company that is unknown, deactivated or paused gets one
warning in the log (`EFactura: no usable token for CUI`) and nothing else.

> **`SweepStaleUploads` takes no arguments** and always scans every company.

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

A `ProcessSingleUpload` you dispatch yourself sends the upload only while it is `pending` and due, so
it never cuts short the wait of an upload that is waiting for a retry. Both jobs are dropped when
their upload was deleted before they ran.

### Available Jobs

#### Batch Jobs (Scheduled)

Cadences: see [Recommended schedule](#recommended-schedule).

| Job | Purpose |
|-----|---------|
| `ProcessPendingUploads` | Queue a `ProcessSingleUpload` for each due pending upload |
| `CheckUploadStatuses` | Check processing status at ANAF (see [Status check outcomes](#status-check-outcomes)) |
| `DownloadResponses` | Download response ZIPs |
| `DownloadReceivedInvoices` | Download received invoices |
| `SyncMessages` | Sync message list from ANAF |
| `SweepStaleUploads` | Park uploads stranded in `uploading` for reconciliation |

#### Single-Model Jobs (On-Demand)

| Job | Purpose |
|-----|---------|
| `ProcessSingleUpload` | Make one try at sending a single upload |
| `CheckSingleUploadStatus` | Check status for a single upload |

### Job Configuration

These values are fixed in code; there is no config key for them.

**Batch jobs** (the five company jobs and `SweepStaleUploads`):
- **Tries**: 3
- **Timeout**: 120 seconds
- **Backoff**: 60s, 180s, 300s (progressive)
- **Unique** for up to 3600 seconds, per company (120 s for a company job without a CUI, 600 s for
  `SweepStaleUploads`)
- A status or download run reads never-read rows first, then the most overdue, at most 500 per
  company, and starts no new read after 90 seconds. A rate limit or a grant failure stops that
  company's run without failing the job; the next run carries on.

**Upload jobs** (`ProcessSingleUpload`) make one try each. The upload row, not the queue, owns the
retry schedule:
- **Tries**: 1
- **Timeout**: 120 seconds. The worst case for one upload is about 30 s waiting for the refresh lock
  \+ `EFACTURA_TIMEOUT` (the refresh) + (`EFACTURA_RETRY_TIMES` − 1) × (10 s connect +
  `EFACTURA_RETRY_DELAY`) + `EFACTURA_TIMEOUT` (the upload). Past 120 s a stopped worker leaves the
  upload indeterminate, so stay within it, or raise the job timeout and your queue's `retry_after`
  (see [Requirements](#requirements) for both of its bounds).
- `ProcessPendingUploads` leases each due upload for 300 seconds (its `next_poll_at`) before it
  queues the job, and the job works the upload only while it still holds that lease: a second
  dispatcher does not queue it again, and a job whose lease the upload no longer holds (another
  dispatch leased it again) does nothing. A job that never runs hands the upload back when the
  lease ends.
- Every outcome that is not final writes when the upload is due again (`next_poll_at`), and the
  first `ProcessPendingUploads` run after that queues a new job. While it waits, the upload is
  `pending`, `xml_path` is null and that attempt's XML file is deleted (nothing was sent):
  - **A rate limit** (the SDK's limiter, its global quota pre-check, or ANAF's 429): `pending` /
    `rate_limited` until the limiter's `retryAfterSeconds`, at least 60 seconds (up to 24 hours for
    the daily RASP limit). A limit hit during the call fires `InvoiceRateLimited` (**not**
    `InvoiceFailed`); the pre-check fires nothing. A rate-limited upload is never given up for
    waiting.
  - **A failure before the document was sent** (a database or storage error while preparing it, a
    transport failure the SDK proves never left): `pending` / `transient`, tried again after
    `UploadService::TRANSIENT_RETRY_DELAY` (30 seconds). The tries are counted in `status_failures`;
    the `UploadService::MAX_TRANSIENT_ATTEMPTS`th (5th) makes the upload `Failed` / `abandoned`, and
    `InvoiceFailed` fires once.
  - **A grant failure** (a refused, paused or unreadable grant, a deactivated company): `pending`,
    uncounted, no event. It is tried again once the grant is usable, normally after the company
    re-authorises.
- Nothing re-drives a failed upload. A `pending` upload with a `failure_reason` is one waiting for
  its retry.
- If the job throws or times out (`TimeoutExceededException`), `failed()` parks an upload still in
  `uploading` under the job's lease as `Failed` / `indeterminate` for reconciliation — it is never returned to `pending`,
  since the job may have stopped after the POST reached ANAF. A `pending` upload counts one
  `transient` attempt. When the queue gives up on a redelivered copy (`MaxAttemptsExceededException`),
  the first run's worker died: its `uploading` upload is parked by `SweepStaleUploads` (after 30
  minutes), and a `pending` one still holding the job's lease counts one `transient` attempt, so a
  document whose preparation kills the worker every time ends `abandoned` after 5 tries. That
  holds only while the copy is redelivered within the 300-second lease (see the `retry_after`
  requirement); a copy redelivered after the upload was leased again counts nothing, and the
  document is then tried, and kills a worker, every few minutes. A copy of a run that recorded an
  outcome changes nothing.
- While `enabled` or `features.upload_invoices` is off, the job does nothing and the upload keeps
  its state.

### Queue Configuration

Jobs are dispatched to the queue specified in `config/efactura.php`:

```php
'queue' => env('EFACTURA_QUEUE', null), // null = default queue
'connection' => env('EFACTURA_QUEUE_CONNECTION'), // null = the app's default connection
```

Set `EFACTURA_QUEUE=efactura` in your `.env` to use a dedicated queue. This allows you to run a separate worker for e-Factura jobs:

```bash
php artisan queue:work --queue=efactura
```

`connection` (from 4.1.0) puts every job the package dispatches on that queue connection: the
scheduled batch jobs, the per-company jobs they dispatch, `ProcessSingleUpload` and
`CheckSingleUploadStatus`. A null or blank value keeps the app's default connection. A
`connection:` you pass to `$schedule->job()` and a `dispatchSync()` still win. Set it when your
default connection's `retry_after` does not fit the package jobs (see
[Requirements](#requirements)): define a connection with `retry_after` about 180, for example a copy
of your `redis` connection, set `EFACTURA_QUEUE_CONNECTION` to its name and run a worker on it:

```bash
php artisan queue:work efactura-redis --queue=efactura
```

Keep that queue for the package: a worker of another connection reading the same queue name on the
same Redis database or jobs table would take the package jobs with its own `retry_after`. With
Horizon, add a supervisor whose `connection` is the package's connection and whose `queue` is
`EFACTURA_QUEUE`, and leave that queue out of your other supervisors.

### Rate Limit Configuration

The SDK provides client-side rate limiting to stay within ANAF quotas (`config/efactura-sdk.php`).
The package has no rate-limit settings of its own: an upload that hits a limit waits in `pending`
for its next try (see [Job Configuration](#job-configuration)), and a status or download run that
hits a global limit or ANAF's 429 stops for that company until its next run.

### Queue Worker

Ensure a queue worker runs on the package's connection and queue (a real connection such as
`redis` or `database`; for a `sync` connection see [Requirements](#requirements)). The connection is
`EFACTURA_QUEUE_CONNECTION`, else your default one; the queue is `EFACTURA_QUEUE`, else the
connection's default queue (see [Queue Configuration](#queue-configuration)):

```bash
php artisan queue:work            # default connection and queue
php artisan queue:work efactura-redis --queue=efactura   # with both set
```

### Known limitations

- **Message sync capacity.** Every `SyncMessages` run lists one CUI's last 60 days from page 1 and
  has the job's 120-second timeout for all its pages. At roughly 2–3 seconds a page (one ANAF call
  and 500 row writes) that is about 40 pages, some 20,000 messages in 60 days. A CUI with more times
  out every run and its later pages are never synced. The timeout is not raised, because your queue
  connection's `retry_after` must stay above it and, with the time a job waits in the queue, under
  about 240 seconds (see [Requirements](#requirements)). If your default connection's `retry_after`
  is larger, set `EFACTURA_QUEUE_CONNECTION` to a connection whose `retry_after` is about 180.
- **Read batch capacity.** A status or download run reads at most 500 rows per company and starts no
  new read after 90 seconds; the rest lead the next run. One slow read can still run past the job's
  120-second timeout.
- **60 days for downloads.** ANAF keeps a response or received invoice for 60 days. A response not
  downloaded within 60 days of the upload's processing, or a received invoice within 60 days of its
  message, is no longer tried; the upload stays `Completed` and no event fires.
- **A global rate limit, or a grant ANAF refuses, still costs one row per company.** After a global rate limit, each company's
  run in a command or a fan-out claims one row before it stops: that row's next try moves back, and
  a response or received invoice counts one try. This way one company's pause never stops the
  others.
- **Unreadable credentials.** An upload whose company's credentials cannot be decrypted (a changed
  `APP_KEY`) waits in `pending` and is tried again, uncounted, on every `ProcessPendingUploads` run,
  with a warning in the log, until the company re-authorises.
- **A refresh the SDK makes during a call.** When the SDK refreshes inside a call under its own lock
  (only when its refresh buffer grows past 120 seconds, with a long HTTP timeout and several
  retries), a refused refresh pauses the grant only after that lock is released, so a worker waiting
  on the lock may ask ANAF once more.
- **Wall-clock times.** `efactura_uploads` times (`next_poll_at`, the dispatch lease, the `updated_at`
  the sweep reads) are `app.timezone` wall time. In a zone with summer time, the repeated autumn hour
  can hold a lease, a retry or a status read back by up to an hour, and can make `SweepStaleUploads`
  park an upload that is still being sent (a false `InvoiceFailed`); ANAF's answer is still recorded
  through the `indeterminate` arm. `app.timezone = UTC` avoids both.
- **A re-queued failed upload keeps its old XML file.** Queueing a failed upload again leaves
  `xml_path` naming the failed attempt's file until the next attempt is claimed, which stores a new
  file and leaves the old one on the disk. The row cannot tell whether ANAF received that file (an
  ANAF refusal) or the SDK refused it before sending, so the package does not delete it. Re-queueing
  it, and `efactura:reconcile --filed`/`--not-filed`, also clear `response_path`; ANAF's response
  archive stays on the disk, named by no row. Clean these up with your own retention if they matter.

## Artisan Commands

```bash
# Display OAuth URL for a CUI
php artisan efactura:auth 12345674

# Queue pending uploads onto the rate-limit-aware pipeline (requires a queue worker)
php artisan efactura:upload
php artisan efactura:upload --cui=12345678

# Send each company's due uploads inline instead of queueing them (one try each; stops a company
# at its first rate limit or once its grant is paused, and a later run retries it once it is due)
php artisan efactura:upload --sync

# List uploads whose delivery to ANAF is UNKNOWN, and resolve them
php artisan efactura:reconcile
php artisan efactura:reconcile --filed=12 --index=5001130255
php artisan efactura:reconcile --not-filed=12
php artisan efactura:reconcile --not-filed=12 --despite-index   # not in SPV under its index; asks to confirm

# Check statuses and download responses
php artisan efactura:status
php artisan efactura:status --cui=12345678
php artisan efactura:status --upload=12   # fails, without "Done.", on a rate limit or a failed check;
                                         # warns when the response was not downloaded

# Sync messages from ANAF
php artisan efactura:sync
php artisan efactura:sync --cui=12345678
php artisan efactura:sync --download      # also download received invoices (features.download_received)
```

Every command exits 1 for bad input: an invalid, blank or bare `--cui`, a CUI with no active token
(unknown or deactivated), and a malformed, bare, zero or other-company `--upload`, `--filed`
or `--not-filed` id. `efactura:auth` refuses a CUI whose control digit is wrong, and exits 1 without a
URL when a token already exists and the run is non-interactive. `efactura:status` also exits 1 when a
company's run stopped ("Stopped for {cui} (rate limit or token unavailable); see the log.") and
carries on with the other companies; `efactura:sync` carries on past a company whose sync or
download failed, prints only the exception's class, and exits 1. `efactura:sync --download` with
`features.download_received` off prints a warning and downloads nothing. A paused company is not
bad input: `efactura:status` reports it as a stopped run and `efactura:sync` as a failed company
(exit 1), while `efactura:upload` prints "{cui}: skipped (cooling)." and exits 0. `efactura:upload`,
`efactura:status` and `efactura:sync` without `--cui` print "{cui}: skipped (non-canonical CUI)." for
a company stored under a non-canonical CUI and do not work it.
`efactura:reconcile --filed` with an index other than the one the upload already has asks you to
confirm; refused, or with `--no-interaction`, it changes nothing and exits 1.

## Events

Listen to these events for custom logic:

| Event | Fired When | Payload |
|-------|------------|---------|
| `TokenStored` | OAuth callback stores new token | `EfacturaToken $token` |
| `TokenRefreshed` | Token auto-refreshed | `EfacturaToken $token` |
| `InvoiceUploaded` | Invoice successfully uploaded, or resolved as filed with `efactura:reconcile --filed` | `EfacturaUpload $upload` |
| `InvoiceProcessed` | The response archive of a `Completed` upload is stored (a rejected or parked upload's archive is stored without an event). Status `Completed` already means filed at ANAF; this event means only that the archive is stored. Check `$upload->response_verification` — a `mismatch` archive is kept and still fires this | `EfacturaUpload $upload` |
| `InvoiceFailed` | Upload or processing failed **terminally**; for an `indeterminate` upload, ANAF's late answer can still follow with `InvoiceUploaded` | `EfacturaUpload $upload`, `array $errors` |
| `InvoiceRateLimited` | Upload hit a rate limit during the call and **will be retried**; `retryAfterSeconds` is the SDK limiter's wait, null after ANAF's own 429 | `EfacturaUpload $upload`, `array $errors`, `?int $retryAfterSeconds` |
| `InvoiceReceived` | Received invoice **or buyer message** (`MESAJ CUMPARATOR…`) downloaded; check `$message->details['tip']` (`FACTURA PRIMITA` for an invoice) before treating it as one | `EfacturaMessage $message` |

> **`InvoiceFailed` means the pipeline has given up.** It is safe to alert on. Transient
> rate-limit hits fire `InvoiceRateLimited` instead — the upload stays in the pipeline and is
> re-submitted automatically. Inspect `$upload->failure_reason` for the classification.

> **A throwing listener never changes the recorded state.** Every event is fired after its state
> is written, through `rescue()`: an exception from a synchronous listener is reported (`report()`)
> and not propagated, so the upload, message or token keeps the state it was given and a batch
> carries on. A throwing `TokenStored` listener leaves the OAuth callback redirecting to the success
> URL, without the exception's message.

> **Events are delivered at most once.** An event whose synchronous listener throws (later listeners
> are skipped), whose queued listener cannot be pushed, or whose worker dies after the write is not
> fired again. Keep listeners idempotent and catch up from the tables: received invoices from
> `EfacturaMessage::received()->where('is_downloaded', true)` (`xml_path`), uploads from their
> `status`.

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

The package creates three tables (only `efactura_tokens` when `pipeline_tables` is false):

### `efactura_tokens`
Stores OAuth tokens per CUI.

`access_token` and `refresh_token` are **encrypted at rest** (Laravel's `encrypted` cast), and are
therefore readable only with the `APP_KEY` that wrote them — see
[Credentials and APP_KEY](#credentials-and-app_key). Both columns stay `text`: no schema
change is needed, since Crypt inflates a value roughly 1.8x and ANAF's JWTs are a kilobyte or two.
Only the refresh compare-and-set matches `refresh_token` by value, against one row's stored
ciphertext, so encryption costs no lookups. Do not rewrite that column (for example re-encrypting it
under a new `APP_KEY`) while workers refresh tokens: a refresh that ANAF already rotated is then
dropped, and the company must re-authorise.

### `efactura_uploads`
Tracks upload status for your invoices (polymorphic relationship). `uploadable_type` is the model's
morph class: its morph map alias when your app defines one, otherwise its class name.

A model has **at most one** upload row: `(uploadable_type, uploadable_id)` is unique. Re-uploading
after a failure recycles that row rather than inserting another, so `efacturaUpload` always points
at the live attempt.

The `failure_reason` column carries the authoritative classification (`rate_limited`, `transient`,
`abandoned`, `indeterminate`, `validation`, `configuration`). On a `Failed` upload it says why the
pipeline stopped. `rate_limited` and `transient` mark a `pending` upload that is
waiting for its retry.

`next_poll_at` (v4.0) is when the upload is next due (null = now), one phase at a time:
- `pending`: when its next send try is due (a retry's wait, or the 300-second dispatch lease).
- `uploading`: the dispatch lease of the job sending it (null for a run without one); that job's
  `failed()` parks only the upload it holds.
- `processing`: when the next status read is due. Each read sets it from the SDK's `PollSchedule`
  (1, 5, 15, then 60 minutes as the upload ages), less a minute so the read is due on the scheduled
  run it aims at, but never under 60 seconds (the 1-minute step stays 1 minute).
- `completed` / `failed` with a download id: when the next response download try is due.

`status_failures` (v4.0) counts one phase at a time: while the upload is `pending`, send tries that
failed before anything was sent (`UploadService::MAX_TRANSIENT_ATTEMPTS`, 5, abandons it); while it
is `processing`, status reads ANAF refused (`DownloadService::MAX_STATUS_FAILURES`, 5, parks it as
indeterminate). A status answer, or ANAF accepting the upload, resets it.

`response_attempts` counts response-download tries made, with no cap; `response_failed_at` is when
ANAF last refused one while the response was missing, cleared when a later try stores it, and
`errors` keeps each refusal's text. `response_verification` (v4.0) says how the stored
response archive was checked: `verified`, `limits_only` or `mismatch` (see the `ResponseVerification`
enum).

### `efactura_messages`
Stores synced messages from ANAF (sent, received, errors). A listed message without a usable id is
skipped with a warning, never stored. `download_attempts` (v4.0) counts a received invoice's
download tries, with no cap, and `next_download_at` (v4.0) is when the next one is due (null = now):
the tries back off from an hour, doubling to a day, for 60 days.

## Upload Status Flow

```
Pending → Uploading → Processing → Completed
                   ↘            ↘
                    → Failed ←────┘
```

- **Pending**: Queued, waiting to be uploaded
- **Uploading**: Currently being uploaded to ANAF
- **Processing**: Uploaded, waiting for ANAF to process
- **Completed**: Filed at ANAF; its response is downloaded when ANAF gave a download id (see
  [Status Check Outcomes](#status-check-outcomes))
- **Failed**: Upload or processing failed; see `failure_reason` for why. Final, except
  `indeterminate`: ANAF's late answer to the original send can still move it to `Processing`
  (`InvoiceUploaded` after its `InvoiceFailed`), and `efactura:reconcile` resolves it. An upload that
  will be retried stays **Pending**: after a rate limit or a failure before sending, with a
  `failure_reason` and a future `next_poll_at`; after a grant failure (refused, paused, unreadable or
  deactivated), with a null `next_poll_at` until the grant is usable (a send that was tried also
  records the grant error in `errors`).

## Status Check Outcomes

`CheckUploadStatuses` (and `efactura:status` without `--upload`) polls every `Processing` upload
whose `next_poll_at` is due (null or past); `CheckSingleUploadStatus` and `efactura:status --upload`
read the one upload they are given straight away. Each read goes through
`DownloadService::checkStatus()`, which reads each answer through the SDK's
`StatusResponseData::verdict()`. A download id is ANAF's digits (1 to 64); any other id cannot be
downloaded and counts as no download id.

| ANAF answer or failure | Result |
|------------------------|--------|
| `in prelucrare` | Stays `Processing`; `next_poll_at` is set from the SDK's `PollSchedule` (1, 5, 15, then 60 minutes as the upload ages) and `CheckUploadStatuses` skips it until then |
| `ok` with a download id | `Completed`; `DownloadResponses` then downloads the response and fires `InvoiceProcessed`, unless the archive cannot be downloaded within 60 days of processing: the upload stays `Completed` and no event fires |
| `ok` without a download id | `Completed` with `download_id = null` and a note in `errors`. Filed, but no response can be downloaded, so `InvoiceProcessed` never fires for it |
| `nok` with a download id | `Failed` / `validation` + `InvoiceFailed`; the error report is downloaded later; no event fires when it is stored |
| `nok` without a download id | `Failed` / `indeterminate` + `InvoiceFailed`, keeping the upload index: no verdict on the document |
| Only an error (an XML `<Errors/>` header or a JSON `eroare`: an unknown index, "Nu aveti dreptul") | `Failed` / `indeterminate` + `InvoiceFailed`, keeping the upload index and ANAF's errors |
| "XML cu erori nepreluat de sistem" | `Failed` / `validation` + `InvoiceFailed`; nothing to download |
| No recognisable status | `Failed` / `indeterminate` + `InvoiceFailed` |
| ANAF refuses the read: a 4xx (not 408), an unreadable 2xx answer, an index the SDK refuses | Counted in `status_failures`; at `DownloadService::MAX_STATUS_FAILURES` (5) `Failed` / `indeterminate` + `InvoiceFailed` |
| A 5xx, a 408, a network error, or a local database, cache or configuration error | Read again at its next slot, not counted |
| The upload's per-message status limit (SDK `RateLimitScope::Status`) | This upload waits for its next slot, not counted; the batch carries on |
| A grant failure (refused, paused or unreadable grant, a deactivated company), a global or per-CUI SDK limit, or ANAF's 429 | That company's batch stops, nothing is counted; the next run carries on |

Every read first claims its upload by writing when it is due again, so no run reads it again before
that slot, whatever the outcome; a refusal by the SDK's own limiter still spends that try. An answer
is recorded only while the upload is still `Processing` under the index that was asked about, and its
event fires only for the run that recorded it, so overlapping runs never announce one answer twice.
A batch reads never-read uploads first, then the most overdue. `CheckSingleUploadStatus` and
`efactura:status --upload` read their upload whatever its slot.

Response and received-invoice downloads follow the same rules. Each try is claimed and counted
(`response_attempts`, `download_attempts`) and the next one waits an hour, doubling to a day, for 60
days. ANAF's refusal (a 4xx, an unreadable answer, an id the SDK refuses, an archive outside the
limits) is recorded on the row; anything else is just tried again at the next slot. A per-message
download limit (`RateLimitScope::Download`) skips that row, and a grant failure, any other rate limit
or ANAF's 429 stops the company's batch. A downloaded file that cannot be stored or recorded is
deleted again, and tried at the next slot.

## Reconciliation (indeterminate uploads)

Some failures leave delivery genuinely **unknown**: the worker died around the POST (job timeout,
SIGKILL, deploy), the transport failed ambiguously (5xx, dropped connection), ANAF sent an
unreadable 2xx upload answer, or its answer had no usable index and status (a non-numeric index,
success without an index, no recognised status), or its status check gave no verdict (`nok` without
a download id, an error-only answer, no recognisable status, `DownloadService::MAX_STATUS_FAILURES`
refused reads). ANAF may or
may not have filed the document. An upload answer with a numeric index is not one of these: it is
polled like any other upload.

These are parked as `Failed` with `failure_reason = indeterminate` and are **never re-submitted
automatically** — re-sending would risk double-filing a legal invoice. `queueUpload()` also refuses
to recycle such a row, throwing `DuplicateUploadException`.

Resolving them requires a human, because ANAF's message listing carries no invoice number to match
on, and the index an indeterminate upload has (if any) did not give a verdict:

```bash
# What needs checking: CUI, upload index, upload time and parked time
php artisan efactura:reconcile

# You found it in SPV — hand it back to the normal status-check pipeline
php artisan efactura:reconcile --filed=12 --index=5001130255

# It never arrived — re-queue it for upload (an upload WITHOUT an upload index)
php artisan efactura:reconcile --not-filed=12

# SPV has no invoice under its upload index — re-queue it anyway (asks you to confirm)
php artisan efactura:reconcile --not-filed=12 --despite-index
```

An upload **with** an upload index was parked by a status check that gave no verdict. ANAF decides
that upload's outcome under its index, so look that index up in SPV and resolve it as filed with it;
that also has its status checked again, which records a rejection if that is the outcome. `--filed`
only returns it to status checks: if ANAF keeps answering its index without a verdict, it is parked
again (and `InvoiceFailed` fires again) even though SPV shows the invoice. Leave such an upload
`indeterminate`; never use `--despite-index` for it, which files it twice. `--not-filed` refuses it, changing nothing: the command
prints why, suggests `--filed=<id> --index=<its index>`, and exits with a failure code. From code,
`UploadService::resolveIndeterminateAsNotFiled()` throws `InvalidArgumentException` for it. An
upload **without** an index was parked before ANAF answered: search SPV around its parked time.

When ANAF keeps answering an index with an error and SPV has no invoice under it, `--filed` only
parks the upload again. Add `--despite-index` to `--not-filed`: the command asks you to confirm that
you checked SPV under that index and the invoice is not there, then re-queues it. It refuses with
`--no-interaction`, and changes nothing if you answer no. The old index is cleared from the row,
kept in its `errors` and logged as a warning. From code, pass `despiteIndex: true` to
`resolveIndeterminateAsNotFiled()`. If the invoice is filed after all, it is filed twice.

`--index` is trimmed and must be 1 to 64 digits: otherwise the command prints the error and exits
with a failure code, changing nothing. From code, `UploadService::resolveIndeterminateAsFiled()`
throws `InvalidArgumentException` for such an index, and returns `false` when the upload is not
awaiting reconciliation.

Schedule `SweepStaleUploads` so rows stranded by a hard worker death are surfaced rather than sitting
in `uploading` forever. Listen for `InvoiceFailed` to be alerted when one appears.

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

The SDK's and the wrapper's log entries (`vatNumber`, `cui`, the request route) and transport-failure
messages, which `efactura_uploads.errors` also keeps, name the filer's CIF; for a natural person that
is their CNP, so set log retention and access accordingly.

## Testing

For testing, use the SDK's sandbox mode:

```env
EFACTURA_SANDBOX=true
```

## Troubleshooting

### Token Not Found

Ensure you've completed the OAuth flow for the CUI:

```bash
php artisan efactura:auth 12345674
```

### Jobs Not Running

1. Verify the scheduler is registered
2. Check `config('efactura.enabled')` is `true`
3. Check feature flags are enabled
4. Review Laravel queue worker logs

### Upload Failures

Check the `failure_reason` and `errors` columns in the `efactura_uploads` table, or listen to the
`InvoiceFailed` event. `failure_reason` is the authoritative classification — never substring-match
`errors`:

```php
use BeeCoded\EFactura\Enums\FailureReason;

$upload->failure_reason?->isRetryable();        // a pending upload waiting for its retry
$upload->failure_reason?->needsReconciliation(); // a human must check ANAF first
```

Rows with `failure_reason = indeterminate` are never retried — resolve them with
`php artisan efactura:reconcile`.

### Nothing Uploads, But Nothing Errors

`efactura:upload` and every batch job only *dispatch* work. Confirm a queue worker is running on
`config('efactura.queue')` of the connection `config('efactura.connection')` names (the default
connection when it is not set), or use `php artisan efactura:upload --sync`. A company whose grant is
paused, deactivated or stored under a non-canonical CUI is skipped: its uploads wait in `pending`,
and a job run for that CUI logs `EFactura: no usable token for CUI`.

### `DecryptException: The payload is invalid.`

The token-encryption migration (`000004`) has not run: run `php artisan migrate`. If it *has* run,
your `APP_KEY` changed — restore it, or set `APP_PREVIOUS_KEYS`, or re-authorise each company. See
[Credentials and APP_KEY](#credentials-and-app_key).

## AI Assistant Integration (MCP)

This package and its SDK dependency both include MCP servers for AI coding assistants. Each server
has no content of its own: it reads its package's README.md, CHANGELOG.md and `config/*.php` from
`vendor/` when it is asked, so it always matches the installed version.

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
| `get-wrapper-docs` | One README section by its heading slug (for example `oauth-flow`), or `changelog` for CHANGELOG.md. An unknown topic returns the list of slugs |
| `get-model-integration-guide` | The [Model Integration](#model-integration) section |
| `get-event-reference` | The [Events](#events) section |
| `get-job-reference` | The [Job Scheduling](#job-scheduling-required) section |
| `get-wrapper-config-reference` | `config/efactura.php`, with its comments |

The SDK MCP server (`efactura-sdk`) has its own tools. `get-sdk-docs` serves one SDK README section by its slug. `get-changelog` serves the SDK CHANGELOG, or one section of it. `get-config-reference` serves the SDK config files.

## License

This package is open-sourced software licensed under the [Apache 2.0 License](LICENSE).

## Credits

- [BEE CODED](https://www.beecoded.io/)
