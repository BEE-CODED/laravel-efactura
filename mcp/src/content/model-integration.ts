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
- \`efacturaAwaitingResponse()\` — Completed but no response_path yet

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
- Quantities **must be negative** — the SDK and ANAF both require this for proper credit note processing
- This is a standard B2B or B2C upload — use \`EFactura::queueUpload()\` as normal
`;
