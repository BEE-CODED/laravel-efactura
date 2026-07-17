# Laravel e-Factura

A Laravel package that wraps [bee-coded/laravel-efactura-sdk](https://packagist.org/packages/bee-coded/laravel-efactura-sdk) to provide token storage, job scheduling, and easy model integration for Romanian e-Factura (ANAF SPV) compliance.

## Upgrading to v3.0 — read before deploying

v3 encrypts the ANAF credentials already in your database and adds a unique index to
`efactura_uploads`. **This upgrade requires a maintenance window with your queue workers stopped.**
That is not a recommendation — a worker left running across the migration can permanently orphan a
company's credentials, and no re-run of the migration will fix it.

Read this whole section before running anything. The steps are ordered deliberately: the
prerequisites all come **before** the commands, because doing them afterwards is how the outages
happen.

### Step 0 — Prerequisites, before you run any command

#### 0a. Back up `APP_KEY`, somewhere other than the database it protects

From v3, `APP_KEY` decrypts your ANAF credentials. Lose it and **every connected company must redo
the OAuth flow** (`php artisan efactura:auth {cui}`). It was never load-bearing for e-Factura
before, so it may never have been treated as a secret worth backing up. It is now.

If you are rotating it, set `APP_PREVIOUS_KEYS` — see [`APP_KEY` obligations](#app_key-now-protects-your-anaf-credentials--this-is-new-in-v3).

#### 0b. Audit for writes that bypass the model

`Builder::update()` and raw SQL skip casts, so `DB::table('efactura_tokens')->update([...])` — or
`EfacturaToken::where(...)->update([...])` — silently writes a value nothing can decrypt. Load the
model instance and update it, so the cast runs. Fix these **before** deploying; they fail silently.

#### 0c. Size the unique-index build

The index migration runs `ALTER TABLE efactura_uploads ADD UNIQUE (uploadable_type, uploadable_id)`.
This is **not free on a large table**:

```sql
SELECT COUNT(*) FROM efactura_uploads;
```

- **Up to ~100k rows:** seconds. Run it inline.
- **Millions of rows:** expect **minutes**, during which the build takes a brief exclusive metadata
  lock. Under concurrent write load it can fail outright on `innodb_lock_wait_timeout`.

With workers stopped (Step 1) there is no concurrent write load, which is most of the risk gone. If
the table is large enough that the window itself is the problem, build the index out of band with
[`pt-online-schema-change`](https://docs.percona.com/percona-toolkit/pt-online-schema-change.html)
or [`gh-ost`](https://github.com/github/gh-ost) instead, then mark the migration as run.

### Step 1 — Stop the queue workers (MANDATORY)

**Do this before `composer update`, and keep them down until after `php artisan migrate`.**

```bash
php artisan down
```

`php artisan down` is a necessary start but **is not sufficient on its own**:

- A worker already **mid-job** when you run it finishes that job — including a token refresh.
- Workers started with `queue:work --force` ignore maintenance mode entirely.
- Horizon needs `php artisan horizon:pause` (or `horizon:terminate`).

So: run `down`, then actually stop the worker processes (`supervisorctl stop laravel-worker:*`, or
your orchestrator's equivalent) and **confirm nothing is in flight** before you migrate.

#### Why this is mandatory, and not a step-6 afterthought

A v2 worker that is still alive while the migration runs will:

1. **Refresh a token and write PLAINTEXT into an already-encrypted column.** `$token->update([...])`
   from v2 code has no cast. The encryption migration has by then already been **recorded as run**,
   so the usual remedy — "just re-run the migration" — **does nothing at all**. That company throws
   `DecryptException` on every operation until it re-authorises. This is the one failure in this
   upgrade with no clean recovery.
2. **Read an already-encrypted row and send the ciphertext to ANAF as a Bearer token.** ANAF answers
   `401`, and the invoices in that batch park as `Failed`.

Neither is a race you can win by being quick. Stop the workers.

### Step 2 — Check for duplicate uploads (inside the window, writes stopped)

Run this **now**, with workers down — not the day before:

```sql
SELECT uploadable_type, uploadable_id, COUNT(*) AS copies
FROM efactura_uploads
GROUP BY uploadable_type, uploadable_id
HAVING COUNT(*) > 1;
```

v3 adds a unique `(uploadable_type, uploadable_id)` index, and its migration **aborts** if
pre-3.0 duplicates exist. Duplicates can mean an invoice was filed at ANAF more than once, so the
migration does not resolve them for you: reconcile each against ANAF, keep the row reflecting the
real filing, remove the rest.

**Why inside the window:** v2's `queueUpload()` has no duplicate guard, so a check run while v2 is
still accepting writes is only true for as long as it takes the next request to reintroduce a
duplicate. With writes stopped the check is authoritative. (Since v3 now encrypts tokens **before**
touching this index, an abort here no longer takes e-Factura down — this check is advisory rather
than load-bearing. It still saves you a failed deploy.)

### Step 3 — Update and migrate

```bash
composer update bee-coded/laravel-efactura
php artisan migrate
```

Between those two commands, e-Factura operations for existing companies fail with
`DecryptException: The payload is invalid.` **This is by design** (see below). Keep the gap short —
this is exactly what the window is for.

Four migrations ship; three are new in v3 and run **in this order**:

| Order | Migration | What it does |
|-------|-----------|--------------|
| 1st | `..._000004_encrypt_efactura_token_credentials` | Encrypts the plaintext `access_token` / `refresh_token` already in `efactura_tokens`. **Runs first on purpose**: it is what restores service for the v3 code you just deployed, so nothing that can abort is allowed to precede it |
| 2nd | `..._000005_add_failure_reason_to_efactura_uploads_table` | Adds the indexed `failure_reason` column; backfills `rate_limited` for rows whose legacy `errors` text carries a `RATE_LIMIT_EXCEEDED:` marker. Anything unrecognised stays `NULL` rather than being guessed. **Re-entrant** — an interrupted backfill resumes on the next `migrate` |
| 3rd | `..._000006_add_unique_uploadable_index_to_efactura_uploads_table` | Adds the unique `(uploadable_type, uploadable_id)` index. **Aborts on pre-3.0 duplicates** (Step 2). Runs last because it is the only one expected to abort |
| 4th | `..._000007_add_response_attempt_tracking_to_efactura_uploads_table` | Adds `response_attempts` / `response_failed_at` so a poisoned `/descarcare` body (2xx that isn't a ZIP) is retried a bounded number of times rather than on every run forever. Additive columns only; cannot abort |

### Step 4 — Bring it back up

```bash
php artisan queue:restart   # workers must come back on the NEW code
php artisan up
```

Start your worker processes again (`supervisorctl start laravel-worker:*`). `queue:restart` is the
belt-and-braces for any worker that survived Step 1: a stale one holds pre-v3 code in memory and
will keep firing `InvoiceFailed` on rate limits and writing plaintext credentials.

### If `migrate` aborts: how to get out of it

An abort is **not** a no-op at the database level. Migrations that already ran are committed and
recorded; only the aborting one is not. What you have depends on where it stopped:

| Aborted at | State | Do this |
|------------|-------|---------|
| `..._000004` (encryption) | Tokens partly/not encrypted. e-Factura is **down** | The migration is idempotent — fix the cause and re-run `php artisan migrate`. It resumes safely |
| `..._000005` (failure_reason) | Tokens **encrypted, service is up**. Column may exist without the backfill finished | Re-run `php artisan migrate`. It is re-entrant: it skips the DDL it already did and resumes the backfill |
| `..._000006` (unique index) | Tokens **encrypted, service is up**. Only the index is missing | Resolve the duplicates (Step 2), then re-run `php artisan migrate`. No rush — but the duplicate race stays open until you do |

**Emergency lever — restore service immediately.** If you are down and need tokens working *now*
without waiting to resolve anything else, run the encryption migration on its own:

```bash
php artisan migrate --path=database/migrations/2024_01_01_000004_encrypt_efactura_token_credentials.php
```

That is safe to run at any point: it is idempotent, touches only `efactura_tokens`, and has no
dependency on the other two. (If you published the migrations into your app, point `--path` at your
copy instead.) Then sort out the rest at your own pace.

### OAuth tokens are now encrypted at rest

`efactura_tokens.access_token` and `refresh_token` are encrypted via Laravel's `encrypted` cast.
Before v3 they were stored in plaintext, so anyone with a database read, a backup, or a query log
held live ANAF credentials — enough to file and read legal tax documents for every connected
company. (The model's `$hidden` never protected against this; it only omits the fields from
`toArray()` / `toJson()` and has no bearing on what is written to disk.)

The cast has **no plaintext fallback**: a fallback would let a migration that never ran pass
unnoticed while your credentials stayed readable in the database. Encryption at rest has to be a
verifiable fact rather than a hope, so an unmigrated row fails loudly at the point of use instead of
quietly working.

The migration is **idempotent** — it skips values that are already ciphertext, so re-running it
cannot double-encrypt, and it is safe after a partial failure or on a table mixing migrated and
freshly-authorised rows. It is also **reversible**: `php artisan migrate:rollback` restores
plaintext, so downgrading to v2 (whose model has no cast) leaves no unreadable ciphertext behind.

A rollback attempted under the **wrong** `APP_KEY` (rotated key, or a production dump restored
elsewhere) **aborts loudly** rather than reporting success — it will not leave ciphertext sitting in
a column that a downgraded v2 would hand to ANAF as a Bearer token.

### `APP_KEY` now protects your ANAF credentials — this is new in v3

Your `APP_KEY` was never load-bearing for e-Factura before. It is now.

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
- **Restoring a production database to staging or local now requires the production `APP_KEY`.**
  A dump loaded into an environment with a different key leaves every token unreadable. This
  workflow silently worked before v3 because the values were plaintext — it will not anymore.
  Either carry the matching key across, or re-authorise in that environment. (Re-authorising is
  frequently the better answer: a staging box holding working production ANAF credentials is its
  own problem.)

> **Writes that bypass the model now store unreadable plaintext.** `Builder::update()` and raw SQL
> skip casts, so `DB::table('efactura_tokens')->update(['access_token' => $raw])` — or
> `EfacturaToken::where(...)->update([...])` — silently writes a value nothing can decrypt. Load the
> model instance and update it, so the cast runs. Audit for raw writes before deploying.

### Other v3 breaking changes

- **`InvoiceFailed` no longer fires on rate limits.** Transient rate-limit hits now fire
  `InvoiceRateLimited` and are retried automatically; `InvoiceFailed` is a terminal signal that is
  safe to alert on. **Silent** — a listener that reacted to rate limits simply stops running.
- **The `RATE_LIMIT_EXCEEDED:` error-text marker is gone.** Listeners that substring-matched it now
  match nothing. Use the indexed `failure_reason` column / `FailureReason` enum instead.
- **`efactura:upload` now queues** one `ProcessSingleUpload` per row instead of uploading inline, so
  it **requires a queue worker**. **Silent** — without a worker it prints
  `Queued N pending upload(s) for processing.` and nothing is filed. Use `--sync` to restore the old
  inline behaviour (which now stops at the first rate limit rather than failing the whole backlog).
- **`resetForRateLimit()` → `resetForRetry()`.** It now covers every retryable failure reason and
  refuses non-retryable ones.
- **`markUploadAsFailed()` gained a 4th `FailureReason $reason` parameter**, defaulting to
  `FailureReason::Validation` — existing calls still compile and are treated as terminal.
- **`processPendingUploads()` returns `int`** (was `void`) and stops at the first rate limit.
- **`syncMessages()` / `syncAllMessages()` return `int`** (was `void`) and now **throw** instead of
  swallowing errors. `syncAllMessages()` keeps per-token isolation but raises
  `MessageSyncFailedException` if any token failed, so a batch can no longer report false success.
- **`queueUpload()` is idempotent** — one row per model, recycled on re-upload. It throws
  `DuplicateUploadException` when the existing row's delivery is `indeterminate`.
- **Schedule the new `SweepStaleUploads` job.** Nothing else rescues an upload stranded in
  `uploading` by a SIGKILL'd worker.
- **New operational duty: `php artisan efactura:reconcile`.** Uploads whose delivery to ANAF cannot
  be proven are parked `Failed` / `indeterminate` and are never re-submitted automatically. See
  [Reconciliation](#reconciliation-indeterminate-uploads).
- **SDK v3 changes land in your `toEfacturaData()`**: `PartyData::$isVatPayer` is now **required and
  moved to the 4th constructor position** (use named arguments — a positional v2 call silently
  coerces your ONRC string to `isVatPayer: true`), and `InvoiceData::$taxAmountRon` is **required
  for non-RON invoices** and rejected for RON ones.
- **Queue workers must be stopped for the migration, not merely restarted after it** — see
  [Step 1](#step-1--stop-the-queue-workers-mandatory). A worker alive across the migration can
  permanently orphan a company's credentials, which no re-run of the migration will repair.

> **Full step-by-step upgrade guide:** ask the `efactura` MCP server for the `migration` topic
> (`get-wrapper-docs` → `topic: "migration"`). It covers each break, the symptom you will actually
> see, and the exact code change, in the order a real upgrade performs them.

## Features

- **Token Management** - OAuth token storage per CUI with automatic refresh
- **Background Jobs** - Ready-to-use jobs for invoice uploads, status checks, and message syncing
- **Model Integration** - Simple interface + trait pattern for your invoice models
- **Event-Driven** - Events for all key operations (uploads, failures, received invoices)
- **Minimal Setup** - Auto-discovery, publishable config and migrations

## Requirements

- PHP 8.4+
- Laravel 11.x, 12.x, or 13.x
- `bee-coded/laravel-efactura-sdk` ^3.0 (installed automatically)
- ANAF SPV OAuth credentials
- A stable, backed-up `APP_KEY` — it encrypts the stored ANAF credentials
- A shared, persistent cache store (redis, memcached, database — **not `array`**), for the OAuth
  state and token-refresh locks
- A running queue worker on `config('efactura.queue')` — uploads are dispatched, not inline

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
- Choose the queue e-Factura jobs are dispatched to
- Configure file storage for XML and ZIP files
- Tune rate-limit retry behaviour and periodic batch-job hardening
- Customize OAuth callback routes

> **Job schedules are not configured here.** There is no schedule or cron key in
> `config/efactura.php` — this package does not schedule anything for you. You register the
> schedules in your own application; see [Job Scheduling](#job-scheduling-required).

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

> **`isVatPayer` is required, and its position changed in SDK v3.0** — it is now the 4th `PartyData`
> constructor parameter, swapping places with `registrationNumber`, and has no default. **Always use
> named arguments, as above.** A v2-era *positional* call
> `new PartyData($name, $id, $address, 'J40/1234/2020')` now assigns the ONRC string to `isVatPayer`;
> from a caller without `declare(strict_types=1)` PHP silently coerces it to `true` and drops the
> registration number — no exception, and ANAF accepts the resulting document.

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

> **This client does not persist a token refresh — treat it as an escape hatch.** ANAF rotates
> refresh tokens, so if the SDK refreshes during one of these calls, the new grant exists only inside
> `$client`. The stored token is now spent, and once `$client` goes out of scope the company must
> re-authorise from scratch. This needs no concurrency to happen — a token within the SDK's 120-second
> expiry buffer is enough.
>
> For anything that files or fetches on a company's behalf, prefer `executeWithClient()`, which locks,
> refreshes and persists around your operation:
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
> If you must use `EFactura::client()`, persist afterwards yourself:
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
use BeeCoded\EFactura\Jobs\SweepStaleUploads;

->withSchedule(function (Schedule $schedule): void {
    // Upload pending invoices to ANAF
    $schedule->job(new ProcessPendingUploads)->everyFiveMinutes();

    // Check processing status at ANAF
    $schedule->job(new CheckUploadStatuses)->everyTenMinutes();

    // Download response ZIPs for completed uploads
    $schedule->job(new DownloadResponses)->everyFifteenMinutes();

    // Retry uploads that failed for a safe-to-resubmit reason (rate limit, auth blip)
    $schedule->job(new RetryRateLimitedUploads)->everyTenMinutes();

    // Rescue uploads stranded mid-flight by a dead worker (see Reconciliation)
    $schedule->job(new SweepStaleUploads)->everyFifteenMinutes();

    // Download received invoices (if feature enabled)
    $schedule->job(new DownloadReceivedInvoices)->everyFourHours();

    // Sync message list from ANAF
    $schedule->job(new SyncMessages)->hourly();
})
```

Adjust the schedules to fit your application's needs. All batch jobs **except `RetryRateLimitedUploads` and `SweepStaleUploads`** accept an optional `$cui` parameter to process only a specific CUI:

```php
Schedule::job(new ProcessPendingUploads('12345678'))->everyFiveMinutes();
```

> **`RetryRateLimitedUploads` and `SweepStaleUploads` take no arguments** and always scan every CUI.
> Because PHP silently discards extra constructor arguments, `new RetryRateLimitedUploads('12345678')`
> will **not** filter by CUI — it processes *all* companies, with no error to tell you otherwise.

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
| `RetryRateLimitedUploads` | Reset safe-to-resubmit failures back to pending | Every 10 minutes |
| `SweepStaleUploads` | Park uploads stranded in `uploading` for reconciliation | Every 15 minutes |

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

> **Exception — `RetryRateLimitedUploads` declares no `$tries`.** It falls back to your queue
> worker's default (`--tries=1` unless configured otherwise), so it runs **once** and is not
> retried; its `$backoff` and `$maxExceptions` are consequently inert. It does set a 120s timeout
> and `uniqueFor = 600`. This is largely harmless — the job is an idempotent scanner and the next
> scheduled run re-scans — but don't count on it retrying a transient failure.

**Upload jobs** (`ProcessSingleUpload`) have rate-limit-aware retry:
- **Timeout**: 120 seconds
- **Max Exceptions**: 3 (actual errors only — rate-limit releases don't count)
- **Retry Window**: 24 hours (configurable via `EFACTURA_RATE_LIMIT_RETRY_HOURS`)
- When the SDK's global rate limit quota is exhausted **before** the row is claimed, the job releases
  itself back to the queue with a delay matching the quota reset time, instead of failing. The upload
  row is untouched and no event fires
- If the limit is hit **during** the upload, the row is released back to `pending` /
  `failure_reason = rate_limited`, `InvoiceRateLimited` fires (**not** `InvoiceFailed`), and the job
  releases with a 60-second delay. The row is deliberately **not** marked `Failed`: it never left the
  pipeline, and `Failed` would make `isEfacturaProcessed()` report `true` for an upload that flips
  back to `pending` a minute later
- Transient failures (auth blips) are re-driven after `EFACTURA_JOB_TRANSIENT_RETRY_DELAY` (default
  30s), capped at `EFACTURA_JOB_MAX_TRANSIENT_ATTEMPTS` (default 5); once exhausted the row becomes
  `Failed` / `abandoned` and `InvoiceFailed` fires as a genuine terminal signal. The reason **must**
  change on give-up — while it stayed `transient`, `RetryRateLimitedUploads` resurrected the row it
  had just abandoned
- If the job dies permanently, `failed()` parks a row still stuck in `uploading` as `Failed` /
  `indeterminate` for reconciliation — it is never returned to `pending`, since the worker may have
  died after the POST reached ANAF. A row that was still `pending` at the deadline never reached
  ANAF, so it becomes `Failed` / `abandoned` instead and needs no reconciliation

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

# Queue pending uploads onto the rate-limit-aware pipeline (requires a queue worker)
php artisan efactura:upload
php artisan efactura:upload --cui=12345678

# Process inline instead of queueing (no rate-limit retry; stops at the first rate limit)
php artisan efactura:upload --sync

# List uploads whose delivery to ANAF is UNKNOWN, and resolve them
php artisan efactura:reconcile
php artisan efactura:reconcile --filed=12 --index=5001130255
php artisan efactura:reconcile --not-filed=12

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
| `InvoiceFailed` | Upload or processing failed **terminally** | `EfacturaUpload $upload`, `array $errors` |
| `InvoiceRateLimited` | Upload hit ANAF's rate limit and **will be retried** | `EfacturaUpload $upload`, `array $errors`, `?int $retryAfterSeconds` |
| `InvoiceReceived` | Received invoice downloaded | `EfacturaMessage $message` |

> **`InvoiceFailed` means the pipeline has given up.** It is safe to alert on. Transient
> rate-limit hits fire `InvoiceRateLimited` instead — the upload stays in the pipeline and is
> re-submitted automatically. Inspect `$upload->failure_reason` for the classification.

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

`access_token` and `refresh_token` are **encrypted at rest** (Laravel's `encrypted` cast) as of
v3.0.0, and are therefore readable only with the `APP_KEY` that wrote them — see
[Upgrading to v3.0](#upgrading-to-v30--read-before-deploying). Both columns stay `text`: no schema
change is needed, since Crypt inflates a value roughly 1.8x and ANAF's JWTs are a kilobyte or two.
Nothing queries these columns by value, so encryption costs no lookups.

### `efactura_uploads`
Tracks upload status for your invoices (polymorphic relationship).

A model has **at most one** upload row: `(uploadable_type, uploadable_id)` is unique. Re-uploading
after a failure recycles that row rather than inserting another, so `efacturaUpload` always points
at the live attempt.

The `failure_reason` column carries the authoritative classification of a `Failed` upload
(`rate_limited`, `transient`, `indeterminate`, `validation`, `configuration`). Only `rate_limited`
and `transient` are ever re-submitted automatically.

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
- **Failed**: Upload or processing failed — see `failure_reason` for whether it will be retried

## Reconciliation (indeterminate uploads)

Some failures leave delivery genuinely **unknown**: the worker died around the POST (job timeout,
SIGKILL, deploy), or the transport failed ambiguously (5xx, dropped connection). ANAF may or may not
have filed the document.

These are parked as `Failed` with `failure_reason = indeterminate` and are **never re-submitted
automatically** — re-sending would risk double-filing a legal invoice. `queueUpload()` also refuses
to recycle such a row, throwing `DuplicateUploadException`.

Resolving them requires a human, because ANAF's message listing carries no invoice number to match
on and a stranded upload never received an `index_incarcare`:

```bash
# What needs checking, with the CUI + claim time to search SPV around
php artisan efactura:reconcile

# You found it in SPV — hand it back to the normal status-check pipeline
php artisan efactura:reconcile --filed=12 --index=5001130255

# It never arrived — re-queue it for upload
php artisan efactura:reconcile --not-filed=12
```

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

Check the `failure_reason` and `errors` columns in the `efactura_uploads` table, or listen to the
`InvoiceFailed` event. `failure_reason` is the authoritative classification — never substring-match
`errors`:

```php
use BeeCoded\EFactura\Enums\FailureReason;

$upload->failure_reason?->isRetryable();        // will be re-submitted automatically
$upload->failure_reason?->needsReconciliation(); // a human must check ANAF first
```

Rows with `failure_reason = indeterminate` are never retried — resolve them with
`php artisan efactura:reconcile`.

### Nothing Uploads, But Nothing Errors

`efactura:upload` and every batch job only *dispatch* work as of v3. Confirm a queue worker is
running on `config('efactura.queue')`, or use `php artisan efactura:upload --sync`.

### `DecryptException: The payload is invalid.`

The token-encryption migration has not run. See
[Upgrading to v3.0](#upgrading-to-v30--read-before-deploying). If it *has* run, your `APP_KEY`
changed — restore it, or set `APP_PREVIOUS_KEYS`, or re-authorise each company.

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
| `get-wrapper-docs` | Documentation topics: overview, setup, upload-pipeline, token-management, commands, **migration** (the v2 → v3.0 upgrade guide) |
| `get-model-integration-guide` | Complete guide for integrating a model with e-Factura |
| `get-event-reference` | All 7 events: TokenStored, TokenRefreshed, InvoiceUploaded, InvoiceProcessed, InvoiceFailed, InvoiceRateLimited, InvoiceReceived |
| `get-job-reference` | All 9 background jobs with scheduling guidance |
| `get-wrapper-config-reference` | Full configuration schema with env vars and defaults |

The SDK MCP server (`efactura-sdk`) provides DTOs, enums, API reference, and SDK-level documentation.

## License

This package is open-sourced software licensed under the [Apache 2.0 License](LICENSE).

## Credits

- [BEE CODED](https://www.beecoded.io/)
