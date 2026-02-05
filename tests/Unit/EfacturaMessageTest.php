<?php

use BeeCoded\EFactura\Models\EfacturaMessage;
use BeeCoded\EFactura\Models\EfacturaToken;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->token = EfacturaToken::create([
        'cui' => '12345678',
        'access_token' => 'test',
        'refresh_token' => 'test',
        'expires_at' => now()->addHour(),
        'is_active' => true,
    ]);

    $this->message = EfacturaMessage::create([
        'efactura_token_id' => $this->token->id,
        'message_id' => 'MSG123',
        'type' => 'sent',
        'download_id' => 'DL123',
        'cif_emitent' => '12345678',
        'cif_beneficiar' => '87654321',
        'is_downloaded' => false,
    ]);
});

describe('EfacturaMessage Model', function () {
    it('can be created with fillable attributes', function () {
        expect($this->message)->toBeInstanceOf(EfacturaMessage::class);
        $this->assertDatabaseHas('efactura_messages', [
            'message_id' => 'MSG123',
            'type' => 'sent',
        ]);
    });

    it('casts details to array', function () {
        $this->message->update(['details' => ['key' => 'value']]);
        expect($this->message->fresh()->details)->toBeArray()->toBe(['key' => 'value']);
    });

    it('casts is_downloaded to boolean', function () {
        expect($this->message->is_downloaded)->toBeBool()->toBeFalse();
    });

    it('casts message_date to datetime', function () {
        $this->message->update(['message_date' => now()]);
        expect($this->message->fresh()->message_date)->toBeInstanceOf(Carbon::class);
    });

    describe('type check methods', function () {
        it('isSent returns true for sent type', function () {
            expect($this->message->isSent())->toBeTrue();
            expect($this->message->isReceived())->toBeFalse();
            expect($this->message->isError())->toBeFalse();
        });

        it('isReceived returns true for received type', function () {
            $this->message->update(['type' => 'received']);
            expect($this->message->isReceived())->toBeTrue();
            expect($this->message->isSent())->toBeFalse();
        });

        it('isError returns true for error type', function () {
            $this->message->update(['type' => 'error']);
            expect($this->message->isError())->toBeTrue();
            expect($this->message->isSent())->toBeFalse();
        });
    });

    describe('scopes', function () {
        beforeEach(function () {
            EfacturaMessage::create([
                'efactura_token_id' => $this->token->id,
                'message_id' => 'MSG456',
                'type' => 'received',
                'is_downloaded' => true,
            ]);

            EfacturaMessage::create([
                'efactura_token_id' => $this->token->id,
                'message_id' => 'MSG789',
                'type' => 'received',
                'is_downloaded' => false,
            ]);
        });

        it('notDownloaded scope filters undownloaded messages', function () {
            $messages = EfacturaMessage::notDownloaded()->get();
            expect($messages)->toHaveCount(2);
        });

        it('received scope filters received messages', function () {
            $messages = EfacturaMessage::received()->get();
            expect($messages)->toHaveCount(2);
        });

        it('sent scope filters sent messages', function () {
            $messages = EfacturaMessage::sent()->get();
            expect($messages)->toHaveCount(1);
            expect($messages->first()->message_id)->toBe('MSG123');
        });

        it('forToken scope filters by token', function () {
            $otherToken = EfacturaToken::create([
                'cui' => '99999999',
                'access_token' => 'other',
                'refresh_token' => 'other',
                'expires_at' => now()->addHour(),
                'is_active' => true,
            ]);

            EfacturaMessage::create([
                'efactura_token_id' => $otherToken->id,
                'message_id' => 'OTHER',
                'type' => 'sent',
            ]);

            $messages = EfacturaMessage::forToken($this->token)->get();
            expect($messages)->toHaveCount(3);
        });
    });

    describe('relationships', function () {
        it('belongs to token', function () {
            expect($this->message->token)
                ->toBeInstanceOf(EfacturaToken::class)
                ->cui->toBe('12345678');
        });
    });
});
