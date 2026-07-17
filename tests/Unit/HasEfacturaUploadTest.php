<?php

use BeeCoded\EFactura\Enums\UploadStatus;
use BeeCoded\EFactura\Models\EfacturaToken;
use BeeCoded\EFactura\Models\EfacturaUpload;
use BeeCoded\EFactura\Tests\Fixtures\TestInvoice;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    // Create test_invoices table for the TestInvoice model
    if (!Schema::hasTable('test_invoices')) {
        Schema::create('test_invoices', function ($table) {
            $table->id();
            $table->string('number');
            $table->string('cui');
            $table->decimal('total', 10, 2)->nullable();
        });
    }

    $this->token = EfacturaToken::create([
        'cui' => '12345678',
        'access_token' => 'test',
        'refresh_token' => 'test',
        'expires_at' => now()->addHour(),
        'is_active' => true,
    ]);

    $this->invoice = TestInvoice::create([
        'number' => 'INV-001',
        'cui' => '12345678',
        'total' => 100,
    ]);
});

describe('HasEfacturaUpload Trait', function () {
    describe('efacturaUpload relationship', function () {
        it('returns a MorphOne relationship', function () {
            expect($this->invoice->efacturaUpload())
                ->toBeInstanceOf(MorphOne::class);
        });

        it('returns the associated upload', function () {
            $upload = EfacturaUpload::create([
                'efactura_token_id' => $this->token->id,
                'uploadable_type' => TestInvoice::class,
                'uploadable_id' => $this->invoice->id,
                'status' => UploadStatus::Pending,
                'standard' => 'UBL',
            ]);

            expect($this->invoice->fresh()->efacturaUpload)
                ->toBeInstanceOf(EfacturaUpload::class)
                ->id->toBe($upload->id);
        });
    });

    describe('isUploadedToEfactura', function () {
        it('returns false when no upload exists', function () {
            expect($this->invoice->isUploadedToEfactura())->toBeFalse();
        });

        it('returns true when upload exists', function () {
            EfacturaUpload::create([
                'efactura_token_id' => $this->token->id,
                'uploadable_type' => TestInvoice::class,
                'uploadable_id' => $this->invoice->id,
                'status' => UploadStatus::Pending,
                'standard' => 'UBL',
            ]);

            expect($this->invoice->isUploadedToEfactura())->toBeTrue();
        });

        it('uses loaded relationship when available', function () {
            // Load the relationship
            $invoice = TestInvoice::with('efacturaUpload')->find($this->invoice->id);
            expect($invoice->isUploadedToEfactura())->toBeFalse();

            // Create upload and reload with relationship
            EfacturaUpload::create([
                'efactura_token_id' => $this->token->id,
                'uploadable_type' => TestInvoice::class,
                'uploadable_id' => $this->invoice->id,
                'status' => UploadStatus::Pending,
                'standard' => 'UBL',
            ]);

            $invoiceWithUpload = TestInvoice::with('efacturaUpload')->find($this->invoice->id);
            expect($invoiceWithUpload->isUploadedToEfactura())->toBeTrue();
        });
    });

    describe('getEfacturaStatus', function () {
        it('returns null when no upload exists', function () {
            expect($this->invoice->getEfacturaStatus())->toBeNull();
        });

        it('returns the upload status', function () {
            EfacturaUpload::create([
                'efactura_token_id' => $this->token->id,
                'uploadable_type' => TestInvoice::class,
                'uploadable_id' => $this->invoice->id,
                'status' => UploadStatus::Processing,
                'standard' => 'UBL',
            ]);

            expect($this->invoice->getEfacturaStatus())->toBe(UploadStatus::Processing);
        });

        it('uses loaded relationship when available', function () {
            EfacturaUpload::create([
                'efactura_token_id' => $this->token->id,
                'uploadable_type' => TestInvoice::class,
                'uploadable_id' => $this->invoice->id,
                'status' => UploadStatus::Completed,
                'standard' => 'UBL',
            ]);

            $invoiceWithUpload = TestInvoice::with('efacturaUpload')->find($this->invoice->id);
            expect($invoiceWithUpload->getEfacturaStatus())->toBe(UploadStatus::Completed);
        });
    });

    describe('isEfacturaProcessed', function () {
        it('returns false when no upload exists', function () {
            expect($this->invoice->isEfacturaProcessed())->toBeFalse();
        });

        it('returns false for pending status', function () {
            EfacturaUpload::create([
                'efactura_token_id' => $this->token->id,
                'uploadable_type' => TestInvoice::class,
                'uploadable_id' => $this->invoice->id,
                'status' => UploadStatus::Pending,
                'standard' => 'UBL',
            ]);

            expect($this->invoice->isEfacturaProcessed())->toBeFalse();
        });

        it('returns true for completed status', function () {
            EfacturaUpload::create([
                'efactura_token_id' => $this->token->id,
                'uploadable_type' => TestInvoice::class,
                'uploadable_id' => $this->invoice->id,
                'status' => UploadStatus::Completed,
                'standard' => 'UBL',
            ]);

            expect($this->invoice->isEfacturaProcessed())->toBeTrue();
        });

        it('returns true for failed status', function () {
            EfacturaUpload::create([
                'efactura_token_id' => $this->token->id,
                'uploadable_type' => TestInvoice::class,
                'uploadable_id' => $this->invoice->id,
                'status' => UploadStatus::Failed,
                'standard' => 'UBL',
            ]);

            expect($this->invoice->isEfacturaProcessed())->toBeTrue();
        });
    });

    describe('getEfacturaResponsePath', function () {
        it('returns null when no upload exists', function () {
            expect($this->invoice->getEfacturaResponsePath())->toBeNull();
        });

        it('returns the response path', function () {
            EfacturaUpload::create([
                'efactura_token_id' => $this->token->id,
                'uploadable_type' => TestInvoice::class,
                'uploadable_id' => $this->invoice->id,
                'status' => UploadStatus::Completed,
                'standard' => 'UBL',
                'response_path' => 'efactura/responses/test.zip',
            ]);

            expect($this->invoice->getEfacturaResponsePath())->toBe('efactura/responses/test.zip');
        });
    });

    describe('getEfacturaXmlPath', function () {
        it('returns null when no upload exists', function () {
            expect($this->invoice->getEfacturaXmlPath())->toBeNull();
        });

        it('returns the xml path', function () {
            EfacturaUpload::create([
                'efactura_token_id' => $this->token->id,
                'uploadable_type' => TestInvoice::class,
                'uploadable_id' => $this->invoice->id,
                'status' => UploadStatus::Pending,
                'standard' => 'UBL',
                'xml_path' => 'efactura/uploads/test.xml',
            ]);

            expect($this->invoice->getEfacturaXmlPath())->toBe('efactura/uploads/test.xml');
        });
    });

    describe('getEfacturaErrors', function () {
        it('returns null when no upload exists', function () {
            expect($this->invoice->getEfacturaErrors())->toBeNull();
        });

        it('returns null when no errors', function () {
            EfacturaUpload::create([
                'efactura_token_id' => $this->token->id,
                'uploadable_type' => TestInvoice::class,
                'uploadable_id' => $this->invoice->id,
                'status' => UploadStatus::Completed,
                'standard' => 'UBL',
            ]);

            expect($this->invoice->getEfacturaErrors())->toBeNull();
        });

        it('returns the errors array', function () {
            EfacturaUpload::create([
                'efactura_token_id' => $this->token->id,
                'uploadable_type' => TestInvoice::class,
                'uploadable_id' => $this->invoice->id,
                'status' => UploadStatus::Failed,
                'standard' => 'UBL',
                'errors' => ['Error 1', 'Error 2'],
            ]);

            expect($this->invoice->getEfacturaErrors())->toBe(['Error 1', 'Error 2']);
        });
    });

    describe('scopes', function () {
        beforeEach(function () {
            // Create invoices with different statuses
            $this->pendingInvoice = TestInvoice::create(['number' => 'INV-PENDING', 'cui' => '12345678']);
            EfacturaUpload::create([
                'efactura_token_id' => $this->token->id,
                'uploadable_type' => TestInvoice::class,
                'uploadable_id' => $this->pendingInvoice->id,
                'status' => UploadStatus::Pending,
                'standard' => 'UBL',
            ]);

            $this->uploadingInvoice = TestInvoice::create(['number' => 'INV-UPLOADING', 'cui' => '12345678']);
            EfacturaUpload::create([
                'efactura_token_id' => $this->token->id,
                'uploadable_type' => TestInvoice::class,
                'uploadable_id' => $this->uploadingInvoice->id,
                'status' => UploadStatus::Uploading,
                'standard' => 'UBL',
            ]);

            $this->processingInvoice = TestInvoice::create(['number' => 'INV-PROCESSING', 'cui' => '12345678']);
            EfacturaUpload::create([
                'efactura_token_id' => $this->token->id,
                'uploadable_type' => TestInvoice::class,
                'uploadable_id' => $this->processingInvoice->id,
                'status' => UploadStatus::Processing,
                'standard' => 'UBL',
            ]);

            $this->completedInvoice = TestInvoice::create(['number' => 'INV-COMPLETED', 'cui' => '12345678']);
            EfacturaUpload::create([
                'efactura_token_id' => $this->token->id,
                'uploadable_type' => TestInvoice::class,
                'uploadable_id' => $this->completedInvoice->id,
                'status' => UploadStatus::Completed,
                'standard' => 'UBL',
                'download_id' => 'DL123',
                'response_path' => 'efactura/responses/completed.zip', // Has response
            ]);

            $this->failedInvoice = TestInvoice::create(['number' => 'INV-FAILED', 'cui' => '12345678']);
            EfacturaUpload::create([
                'efactura_token_id' => $this->token->id,
                'uploadable_type' => TestInvoice::class,
                'uploadable_id' => $this->failedInvoice->id,
                'status' => UploadStatus::Failed,
                'standard' => 'UBL',
            ]);

            $this->awaitingInvoice = TestInvoice::create(['number' => 'INV-AWAITING', 'cui' => '12345678']);
            EfacturaUpload::create([
                'efactura_token_id' => $this->token->id,
                'uploadable_type' => TestInvoice::class,
                'uploadable_id' => $this->awaitingInvoice->id,
                'status' => UploadStatus::Completed,
                'standard' => 'UBL',
                'download_id' => 'DL456',
                'response_path' => null,
            ]);
        });

        it('notUploadedToEfactura returns invoices without uploads', function () {
            $invoices = TestInvoice::notUploadedToEfactura()->get();

            // Only the original $this->invoice has no upload
            expect($invoices)->toHaveCount(1);
            expect($invoices->first()->id)->toBe($this->invoice->id);
        });

        it('withEfacturaStatus filters by specific status', function () {
            $invoices = TestInvoice::withEfacturaStatus(UploadStatus::Pending)->get();
            expect($invoices)->toHaveCount(1);
            expect($invoices->first()->id)->toBe($this->pendingInvoice->id);
        });

        it('efacturaPending returns pending invoices', function () {
            $invoices = TestInvoice::efacturaPending()->get();
            expect($invoices)->toHaveCount(1);
            expect($invoices->first()->id)->toBe($this->pendingInvoice->id);
        });

        it('efacturaInProgress returns uploading and processing invoices', function () {
            $invoices = TestInvoice::efacturaInProgress()->get();
            expect($invoices)->toHaveCount(2);
            expect($invoices->pluck('id')->toArray())
                ->toContain($this->uploadingInvoice->id)
                ->toContain($this->processingInvoice->id);
        });

        it('efacturaCompleted returns completed invoices', function () {
            $invoices = TestInvoice::efacturaCompleted()->get();
            // Both $completedInvoice and $awaitingInvoice are Completed
            expect($invoices)->toHaveCount(2);
        });

        it('efacturaFailed returns failed invoices', function () {
            $invoices = TestInvoice::efacturaFailed()->get();
            expect($invoices)->toHaveCount(1);
            expect($invoices->first()->id)->toBe($this->failedInvoice->id);
        });

        it('efacturaProcessed returns completed and failed invoices', function () {
            $invoices = TestInvoice::efacturaProcessed()->get();
            // completedInvoice + awaitingInvoice (both Completed) + failedInvoice = 3
            expect($invoices)->toHaveCount(3);
        });

        it('efacturaAwaitingResponse returns completed invoices without response', function () {
            $invoices = TestInvoice::efacturaAwaitingResponse()->get();
            expect($invoices)->toHaveCount(1);
            expect($invoices->first()->id)->toBe($this->awaitingInvoice->id);
        });
    });
});
