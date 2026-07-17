export const modelIntegrationContent = `# Model Integration Guide

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

> **\`isVatPayer\` is REQUIRED, and its constructor position CHANGED in SDK v3.0.** It moved from 5th
> to **4th**, swapping places with \`registrationNumber\`, and lost its \`false\` default.
> **Always use named arguments**, as above. A v2-era *positional* call
> \`new PartyData($name, $id, $address, 'J40/1234/2020')\` now assigns the ONRC string to
> \`isVatPayer\` — and from a caller without \`declare(strict_types=1)\` (i.e. most app models) PHP
> **silently coerces it to \`true\`** and drops the registration number. No exception, no warning; the
> invoice files with an inverted VAT status that ANAF accepts. See the \`migration\` topic.

> **\`taxAmountRon\` is required for non-RON invoices (SDK v3.0).** \`InvoiceData::$taxAmountRon\`
> (BT-111, the VAT total converted to RON) **must** be set when \`currency\` is not \`'RON'\`, and must
> **not** be set when it is. Getting it wrong throws \`ValidationException\` during XML generation,
> which the wrapper turns into a \`Failed\` upload with \`failure_reason = validation\`:
>
> \`\`\`php
> taxAmountRon: $this->currency === 'RON' ? null : $this->vat_total_ron,
> \`\`\`
>
> Pass the converted **amount** from your ledger (the BNR-rate figure), not an exchange rate. ANAF
> cannot verify the conversion, so a wrong value is **accepted and filed** as a true statement of
> VAT owed. If you only ever file in RON, leave it unset.

> **Immutable dates:** apps calling \`Date::use(CarbonImmutable::class)\` can pass their \`datetime\`
> casts (\`$this->issue_date\`, \`$this->due_date\`) straight into \`InvoiceData\` as shown. This
> requires **SDK v2.3.0+**, because \`CarbonImmutable\` is not a \`Carbon\` subclass. On earlier
> versions this model code — which, like most app code, has no \`declare(strict_types=1)\` — did
> **not** throw: PHP coerced the date to a string via \`__toString()\`, silently dropping its
> timezone and microseconds. Under \`strict_types\` it threw a \`TypeError\` instead. From v2.3.0
> the SDK normalises immutable dates to a mutable \`Carbon\`, preserving the instant.

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
- \`efacturaUpload(): MorphOne\` — The upload record for this model

### Status Checks
- \`isUploadedToEfactura(): bool\` — Has an upload record
- \`getEfacturaStatus(): ?UploadStatus\` — Current upload status
- \`isEfacturaProcessed(): bool\` — Terminal state (Completed or Failed)

### File Paths
- \`getEfacturaResponsePath(): ?string\` — Path to response ZIP
- \`getEfacturaXmlPath(): ?string\` — Path to generated XML
- \`getEfacturaErrors(): ?array\` — Upload errors if failed

### Scopes
- \`notUploadedToEfactura()\` — No upload record
- \`withEfacturaStatus(UploadStatus $status)\` — Filter by status
- \`efacturaPending()\` — Status = Pending
- \`efacturaInProgress()\` — Status IN (Uploading, Processing)
- \`efacturaCompleted()\` — Status = Completed
- \`efacturaFailed()\` — Status = Failed
- \`efacturaProcessed()\` — Status IN (Completed, Failed)
- \`efacturaAwaitingResponse()\` — Completed, has a \`download_id\`, but no \`response_path\` yet

## Step 3: Queue Upload

\`\`\`php
use BeeCoded\\EFactura\\Facades\\EFactura;

// Standard B2B upload
$upload = EFactura::queueUpload($invoice);

// B2C upload (consumer invoices)
$upload = EFactura::queueB2CUpload($invoice);

// With options
$upload = EFactura::queueUpload($invoice, [
    'standard' => 'UBL',      // UBL, CN, CII, RASP
    'extern' => true,         // External/non-Romanian supplier
    'self_billed' => true,    // Self-billed/autofactura
]);
\`\`\`

> **Option keys are \`extern\` / \`self_billed\`, not \`is_extern\` / \`is_self_billed\`.** \`UploadService\`
> reads \`$options['extern']\` and \`$options['self_billed']\`; the \`is_\` prefix belongs to the
> \`efactura_uploads\` **columns**, not the options array. Unrecognised keys are silently ignored —
> pass \`is_extern\` and the flag defaults to \`false\`, so the invoice is filed without it and
> nothing warns you.

### Idempotency (v3.0.0)

\`queueUpload()\` is idempotent. A model has **at most one** upload row —
\`(uploadable_type, uploadable_id)\` is unique at the database, matching the 1:1 \`morphOne\` the trait
exposes. Calling it twice (double-clicked button, application retry) does **not** file the invoice
twice:

| Existing row | Result |
|--------------|--------|
| \`Pending\` / \`Uploading\` / \`Processing\` | Returns it — already in the pipeline |
| \`Completed\` | Returns it — ANAF accepted this document; re-filing is what must never happen |
| \`Failed\` (retryable or terminal) | Recycles the row, resetting it to \`Pending\` |
| \`Failed\` + \`failure_reason = indeterminate\` | **Throws \`DuplicateUploadException\`** |

\`\`\`php
use BeeCoded\\EFactura\\Exceptions\\DuplicateUploadException;

try {
    $upload = EFactura::queueUpload($invoice);
} catch (DuplicateUploadException $e) {
    // $e->existingUpload — delivery to ANAF is UNKNOWN; re-sending could double-file it.
    // A human must reconcile: php artisan efactura:reconcile
    return back()->withErrors('This invoice needs manual reconciliation with ANAF.');
}
\`\`\`

Note it does **not** necessarily return a fresh \`Pending\` row — check \`$upload->status\` if you care.
Before v3 it inserted unconditionally, so two calls produced two \`Pending\` rows, both passed the
per-row atomic claim, and the invoice was filed at ANAF twice while \`morphOne\` only ever showed one.

## Credit Notes

For credit notes, set \`invoiceTypeCode\` to the enum case \`InvoiceTypeCode::CreditNote\` and provide \`precedingInvoiceNumber\`. Pass **negative** quantities for the items being credited. The taxAmount field is required (SDK v2.0+) and must be pre-computed.

> **Pass the enum, not the string.** \`InvoiceData::$invoiceTypeCode\` is typed \`?InvoiceTypeCode\`.
> The SDK declares \`strict_types=1\`, so a string like \`'381'\` is **not** coerced — it throws a
> \`TypeError\` at construction.

> **\`taxAmount\`'s sign must follow \`quantity\`'s.** The SDK negates *both* for credit notes. Pass a
> negative \`quantity\` with a **positive** \`taxAmount\` and you get a positive taxable base against a
> negative tax — the XML totals will not reconcile and ANAF rejects the document. \`unitPrice\` is the
> exception: it is never negated and must stay **positive** (a negative one is a \`ValidationException\`).

\`\`\`php
use BeeCoded\\EFacturaSdk\\Enums\\InvoiceTypeCode;

public function toEfacturaData(): InvoiceData
{
    return new InvoiceData(
        invoiceNumber: $this->number,
        invoiceTypeCode: InvoiceTypeCode::CreditNote,  // value '381'
        precedingInvoiceNumber: $this->original_invoice_number,
        // ... same structure, quantities are negative
        lines: $this->items->map(fn ($item) => new InvoiceLineData(
            name: $item->name,
            quantity: -abs($item->quantity),     // Negative for credit notes
            unitPrice: $item->unit_price,        // Always positive — never negated
            taxPercent: $item->vat_rate,
            taxAmount: -abs($item->tax_amount),  // Sign MUST follow quantity
        ))->toArray(),
    );
}
\`\`\`

### Credit Note Notes
- \`invoiceTypeCode: InvoiceTypeCode::CreditNote\` (backing value \`'381'\`) identifies this as a credit note and switches the SDK to the UBL \`<CreditNote>\` document schema
- \`precedingInvoiceNumber\` references the original invoice being reversed
- Pass **negative** quantities for items being credited — but not because ANAF wants negatives. The SDK **negates** credit note line quantities (and the matching \`taxAmount\`) before building the XML, so ANAF receives the **positive** values a \`<CreditNote>\` document expects. Pass **positive** quantities for debit-back lines (e.g. discount reversals), which the same negation flips to negative.
- This is a standard B2B or B2C upload — use \`EFactura::queueUpload()\` as normal
`;
