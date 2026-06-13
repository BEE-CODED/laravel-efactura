<?php

namespace BeeCoded\EFactura\Tests\Fixtures;

use BeeCoded\EFactura\Contracts\EFacturaUploadableInterface;
use BeeCoded\EFactura\Traits\HasEfacturaUpload;
use BeeCoded\EFacturaSdk\Data\Invoice\AddressData;
use BeeCoded\EFacturaSdk\Data\Invoice\InvoiceData;
use BeeCoded\EFacturaSdk\Data\Invoice\InvoiceLineData;
use BeeCoded\EFacturaSdk\Data\Invoice\PartyData;
use Illuminate\Database\Eloquent\Model;

class TestInvoice extends Model implements EFacturaUploadableInterface
{
    use HasEfacturaUpload;

    protected $table = 'test_invoices';

    protected $fillable = ['number', 'cui', 'total'];

    public $timestamps = false;

    public function toEfacturaData(): InvoiceData
    {
        return new InvoiceData(
            invoiceNumber: $this->number,
            issueDate: now(),
            dueDate: now()->addDays(30),
            currency: 'RON',
            supplier: new PartyData(
                registrationName: 'Test Supplier SRL',
                companyId: $this->cui,
                address: new AddressData(
                    street: 'Test Street 1',
                    city: 'Bucharest',
                    postalZone: '010101',
                    countryCode: 'RO',
                ),
                isVatPayer: true,
            ),
            customer: new PartyData(
                registrationName: 'Test Customer SRL',
                companyId: '87654321',
                address: new AddressData(
                    street: 'Customer Street 2',
                    city: 'Cluj',
                    postalZone: '400001',
                    countryCode: 'RO',
                ),
                isVatPayer: true,
            ),
            lines: [
                new InvoiceLineData(
                    name: 'Test Product',
                    quantity: 1,
                    unitPrice: $this->total ?? 100,
                    taxAmount: round(($this->total ?? 100) * 0.19, 2),
                    taxPercent: 19,
                ),
            ],
        );
    }

    public function getEfacturaCui(): string
    {
        return $this->cui;
    }
}
