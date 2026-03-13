export const eventReferenceContent = `# Laravel e-Factura Wrapper — Event Reference

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
