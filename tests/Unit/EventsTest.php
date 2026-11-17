<?php

use BeeCoded\EFactura\Enums\UploadStatus;
use BeeCoded\EFactura\Events\InvoiceFailed;
use BeeCoded\EFactura\Events\InvoiceProcessed;
use BeeCoded\EFactura\Events\InvoiceRateLimited;
use BeeCoded\EFactura\Events\InvoiceReceived;
use BeeCoded\EFactura\Events\InvoiceUploaded;
use BeeCoded\EFactura\Events\TokenRefreshed;
use BeeCoded\EFactura\Events\TokenStored;
use BeeCoded\EFactura\Models\EfacturaMessage;
use BeeCoded\EFactura\Models\EfacturaToken;
use BeeCoded\EFactura\Models\EfacturaUpload;
use Illuminate\Support\Facades\Event;

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

// A queued listener reads the model as it is when it runs, not as it was when dispatched.
it('dispatches each event statically and restores its model for a queued listener', function (string $event, string $property) {
    Event::fake([$event]);
    $model = $this->{$property};

    $event::dispatch($model);

    Event::assertDispatched($event, fn ($e) => $e->{$property}->is($model));

    $serialized = serialize(new $event($model));
    $model->newQuery()->whereKey($model->getKey())->update(['updated_at' => $later = now()->addDay()->startOfSecond()]);

    expect(unserialize($serialized)->{$property}->updated_at->equalTo($later))->toBeTrue();
})->with([
    [TokenStored::class, 'token'],
    [TokenRefreshed::class, 'token'],
    [InvoiceUploaded::class, 'upload'],
    [InvoiceProcessed::class, 'upload'],
    [InvoiceFailed::class, 'upload'],
    [InvoiceRateLimited::class, 'upload'],
    [InvoiceReceived::class, 'message'],
]);
