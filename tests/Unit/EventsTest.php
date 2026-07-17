<?php

use BeeCoded\EFactura\Enums\UploadStatus;
use BeeCoded\EFactura\Events\InvoiceFailed;
use BeeCoded\EFactura\Events\InvoiceProcessed;
use BeeCoded\EFactura\Events\InvoiceReceived;
use BeeCoded\EFactura\Events\InvoiceUploaded;
use BeeCoded\EFactura\Events\TokenRefreshed;
use BeeCoded\EFactura\Events\TokenStored;
use BeeCoded\EFactura\Models\EfacturaMessage;
use BeeCoded\EFactura\Models\EfacturaToken;
use BeeCoded\EFactura\Models\EfacturaUpload;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

beforeEach(function () {
    $this->token = EfacturaToken::create([
        'cui' => '12345678',
        'access_token' => 'test',
        'refresh_token' => 'test',
        'expires_at' => now()->addHour(),
        'is_active' => true,
    ]);

    $this->upload = EfacturaUpload::create([
        'efactura_token_id' => $this->token->id,
        'uploadable_type' => 'App\\Models\\Invoice',
        'uploadable_id' => 1,
        'status' => UploadStatus::Pending,
        'standard' => 'UBL',
    ]);

    $this->message = EfacturaMessage::create([
        'efactura_token_id' => $this->token->id,
        'message_id' => 'MSG123',
        'type' => 'received',
    ]);
});

describe('TokenStored Event', function () {
    it('can be instantiated with token', function () {
        $event = new TokenStored($this->token);

        expect($event->token)->toBe($this->token);
    });

    it('uses Dispatchable trait', function () {
        expect(class_uses(TokenStored::class))
            ->toContain(Dispatchable::class);
    });

    it('uses SerializesModels trait', function () {
        expect(class_uses(TokenStored::class))
            ->toContain(SerializesModels::class);
    });
});

describe('TokenRefreshed Event', function () {
    it('can be instantiated with token', function () {
        $event = new TokenRefreshed($this->token);

        expect($event->token)->toBe($this->token);
    });

    it('uses Dispatchable trait', function () {
        expect(class_uses(TokenRefreshed::class))
            ->toContain(Dispatchable::class);
    });
});

describe('InvoiceUploaded Event', function () {
    it('can be instantiated with upload', function () {
        $event = new InvoiceUploaded($this->upload);

        expect($event->upload)->toBe($this->upload);
    });

    it('uses Dispatchable trait', function () {
        expect(class_uses(InvoiceUploaded::class))
            ->toContain(Dispatchable::class);
    });
});

describe('InvoiceProcessed Event', function () {
    it('can be instantiated with upload', function () {
        $event = new InvoiceProcessed($this->upload);

        expect($event->upload)->toBe($this->upload);
    });

    it('uses Dispatchable trait', function () {
        expect(class_uses(InvoiceProcessed::class))
            ->toContain(Dispatchable::class);
    });
});

describe('InvoiceFailed Event', function () {
    it('can be instantiated with upload and no errors', function () {
        $event = new InvoiceFailed($this->upload);

        expect($event->upload)->toBe($this->upload);
        expect($event->errors)->toBe([]);
    });

    it('can be instantiated with upload and errors', function () {
        $errors = ['Error 1', 'Error 2'];
        $event = new InvoiceFailed($this->upload, $errors);

        expect($event->upload)->toBe($this->upload);
        expect($event->errors)->toBe($errors);
    });

    it('uses Dispatchable trait', function () {
        expect(class_uses(InvoiceFailed::class))
            ->toContain(Dispatchable::class);
    });
});

describe('InvoiceReceived Event', function () {
    it('can be instantiated with message', function () {
        $event = new InvoiceReceived($this->message);

        expect($event->message)->toBe($this->message);
    });

    it('uses Dispatchable trait', function () {
        expect(class_uses(InvoiceReceived::class))
            ->toContain(Dispatchable::class);
    });
});
