<?php

use BeeCoded\EFactura\Models\EfacturaMessage;
use BeeCoded\EFactura\Models\EfacturaToken;
use BeeCoded\EFactura\Services\MessageSyncService;
use BeeCoded\EFactura\Services\TokenService;
use BeeCoded\EFacturaSdk\Enums\MessageFilter;

beforeEach(function () {
    $this->token = EfacturaToken::create([
        'cui' => '12345678',
        'access_token' => 'test_access',
        'refresh_token' => 'test_refresh',
        'expires_at' => now()->addHour(),
        'is_active' => true,
    ]);

    $this->tokenService = Mockery::mock(TokenService::class);
    $this->messageSyncService = new MessageSyncService($this->tokenService);
});

describe('MessageSyncService', function () {
    describe('syncMessages', function () {
        it('syncs messages from API', function () {
            $message1 = (object) [
                'id' => 'MSG001',
                'idDescarcare' => 'DL001',
                'cifEmitent' => '12345678',
                'cifBeneficiar' => '87654321',
                'dataCreare' => '2024-01-01',
            ];

            $message2 = (object) [
                'id' => 'MSG002',
                'idDescarcare' => 'DL002',
                'cifEmitent' => '99999999',
                'cifBeneficiar' => '12345678',
                'dataCreare' => '2024-01-02',
            ];

            $mockResponse = new class($message1, $message2)
            {
                public array $messages;

                public function __construct($m1, $m2)
                {
                    $this->messages = [$m1, $m2];
                }
            };

            $this->tokenService->shouldReceive('executeWithClient')
                ->once()
                ->andReturn($mockResponse);

            $this->messageSyncService->syncMessages($this->token);

            // Verify messages were created
            expect(EfacturaMessage::count())->toBe(2);
            expect(EfacturaMessage::where('message_id', 'MSG001')->exists())->toBeTrue();
            expect(EfacturaMessage::where('message_id', 'MSG002')->exists())->toBeTrue();
        });

        it('syncs with specific filter', function () {
            $message = (object) [
                'id' => 'MSG003',
                'cifEmitent' => '12345678',
            ];

            $mockResponse = new class($message)
            {
                public array $messages;

                public function __construct($m)
                {
                    $this->messages = [$m];
                }
            };

            $this->tokenService->shouldReceive('executeWithClient')
                ->once()
                ->andReturn($mockResponse);

            $this->messageSyncService->syncMessages($this->token, MessageFilter::InvoiceSent);

            $dbMessage = EfacturaMessage::where('message_id', 'MSG003')->first();
            expect($dbMessage->type)->toBe('sent');
        });

        it('updates existing messages', function () {
            // Create existing message
            EfacturaMessage::create([
                'efactura_token_id' => $this->token->id,
                'message_id' => 'MSG004',
                'type' => 'sent',
                'cif_emitent' => '11111111',
            ]);

            $message = (object) [
                'id' => 'MSG004',
                'cifEmitent' => '22222222', // Updated
                'cifBeneficiar' => '33333333',
            ];

            $mockResponse = new class($message)
            {
                public array $messages;

                public function __construct($m)
                {
                    $this->messages = [$m];
                }
            };

            $this->tokenService->shouldReceive('executeWithClient')
                ->once()
                ->andReturn($mockResponse);

            $this->messageSyncService->syncMessages($this->token);

            // Should update, not create duplicate
            expect(EfacturaMessage::where('message_id', 'MSG004')->count())->toBe(1);
            $dbMessage = EfacturaMessage::where('message_id', 'MSG004')->first();
            expect($dbMessage->cif_emitent)->toBe('22222222');
        });

        it('handles exceptions gracefully', function () {
            $this->tokenService->shouldReceive('executeWithClient')
                ->once()
                ->andThrow(new \Exception('API Error'));

            // Should not throw
            $this->messageSyncService->syncMessages($this->token);

            expect(true)->toBeTrue();
        });
    });

    describe('syncSentMessages', function () {
        it('calls syncMessages with InvoiceSent filter', function () {
            $mockResponse = new class
            {
                public array $messages = [];
            };

            $this->tokenService->shouldReceive('executeWithClient')
                ->once()
                ->andReturn($mockResponse);

            $this->messageSyncService->syncSentMessages($this->token);

            expect(true)->toBeTrue();
        });
    });

    describe('syncReceivedMessages', function () {
        it('calls syncMessages with InvoiceReceived filter', function () {
            $mockResponse = new class
            {
                public array $messages = [];
            };

            $this->tokenService->shouldReceive('executeWithClient')
                ->once()
                ->andReturn($mockResponse);

            $this->messageSyncService->syncReceivedMessages($this->token);

            expect(true)->toBeTrue();
        });
    });

    describe('syncAllMessages', function () {
        it('syncs messages for all active tokens', function () {
            $token2 = EfacturaToken::create([
                'cui' => '87654321',
                'access_token' => 'test2',
                'refresh_token' => 'test2',
                'expires_at' => now()->addHour(),
                'is_active' => true,
            ]);

            $mockResponse = new class
            {
                public array $messages = [];
            };

            $this->tokenService->shouldReceive('getActiveTokens')
                ->once()
                ->andReturn(new \Illuminate\Database\Eloquent\Collection([$this->token, $token2]));

            $this->tokenService->shouldReceive('executeWithClient')
                ->twice() // Once for each token
                ->andReturn($mockResponse);

            $this->messageSyncService->syncAllMessages();

            expect(true)->toBeTrue();
        });

        it('passes filter to syncMessages', function () {
            $mockResponse = new class
            {
                public array $messages = [];
            };

            $this->tokenService->shouldReceive('getActiveTokens')
                ->once()
                ->andReturn(new \Illuminate\Database\Eloquent\Collection([$this->token]));

            $this->tokenService->shouldReceive('executeWithClient')
                ->once()
                ->andReturn($mockResponse);

            $this->messageSyncService->syncAllMessages(MessageFilter::InvoiceSent);

            expect(true)->toBeTrue();
        });
    });

    describe('determineMessageType', function () {
        it('returns sent when filter is InvoiceSent', function () {
            $message = (object) [
                'id' => 'MSG010',
                'cifEmitent' => '99999999',
            ];

            $mockResponse = new class($message)
            {
                public array $messages;

                public function __construct($m)
                {
                    $this->messages = [$m];
                }
            };

            $this->tokenService->shouldReceive('executeWithClient')
                ->once()
                ->andReturn($mockResponse);

            $this->messageSyncService->syncMessages($this->token, MessageFilter::InvoiceSent);

            $dbMessage = EfacturaMessage::where('message_id', 'MSG010')->first();
            expect($dbMessage->type)->toBe('sent');
        });

        it('returns received when filter is InvoiceReceived', function () {
            $message = (object) [
                'id' => 'MSG011',
            ];

            $mockResponse = new class($message)
            {
                public array $messages;

                public function __construct($m)
                {
                    $this->messages = [$m];
                }
            };

            $this->tokenService->shouldReceive('executeWithClient')
                ->once()
                ->andReturn($mockResponse);

            $this->messageSyncService->syncMessages($this->token, MessageFilter::InvoiceReceived);

            $dbMessage = EfacturaMessage::where('message_id', 'MSG011')->first();
            expect($dbMessage->type)->toBe('received');
        });

        it('returns received when filter is BuyerMessage', function () {
            $message = (object) [
                'id' => 'MSG012',
            ];

            $mockResponse = new class($message)
            {
                public array $messages;

                public function __construct($m)
                {
                    $this->messages = [$m];
                }
            };

            $this->tokenService->shouldReceive('executeWithClient')
                ->once()
                ->andReturn($mockResponse);

            $this->messageSyncService->syncMessages($this->token, MessageFilter::BuyerMessage);

            $dbMessage = EfacturaMessage::where('message_id', 'MSG012')->first();
            expect($dbMessage->type)->toBe('received');
        });

        it('returns error when filter is InvoiceErrors', function () {
            $message = (object) [
                'id' => 'MSG013',
            ];

            $mockResponse = new class($message)
            {
                public array $messages;

                public function __construct($m)
                {
                    $this->messages = [$m];
                }
            };

            $this->tokenService->shouldReceive('executeWithClient')
                ->once()
                ->andReturn($mockResponse);

            $this->messageSyncService->syncMessages($this->token, MessageFilter::InvoiceErrors);

            $dbMessage = EfacturaMessage::where('message_id', 'MSG013')->first();
            expect($dbMessage->type)->toBe('error');
        });

        it('infers sent when cifEmitent matches token CUI', function () {
            $message = (object) [
                'id' => 'MSG014',
                'cifEmitent' => 'RO12345678', // With prefix
                'cifBeneficiar' => '99999999',
            ];

            $mockResponse = new class($message)
            {
                public array $messages;

                public function __construct($m)
                {
                    $this->messages = [$m];
                }
            };

            $this->tokenService->shouldReceive('executeWithClient')
                ->once()
                ->andReturn($mockResponse);

            $this->messageSyncService->syncMessages($this->token, null);

            $dbMessage = EfacturaMessage::where('message_id', 'MSG014')->first();
            expect($dbMessage->type)->toBe('sent');
        });

        it('infers received when cifBeneficiar matches token CUI', function () {
            $message = (object) [
                'id' => 'MSG015',
                'cifEmitent' => '99999999',
                'cifBeneficiar' => 'RO12345678', // With prefix
            ];

            $mockResponse = new class($message)
            {
                public array $messages;

                public function __construct($m)
                {
                    $this->messages = [$m];
                }
            };

            $this->tokenService->shouldReceive('executeWithClient')
                ->once()
                ->andReturn($mockResponse);

            $this->messageSyncService->syncMessages($this->token, null);

            $dbMessage = EfacturaMessage::where('message_id', 'MSG015')->first();
            expect($dbMessage->type)->toBe('received');
        });

        it('infers error when message has error indicators', function () {
            $message = (object) [
                'id' => 'MSG016',
                'cifEmitent' => '99999999',
                'cifBeneficiar' => '88888888',
                'erpirsError' => true,
            ];

            $mockResponse = new class($message)
            {
                public array $messages;

                public function __construct($m)
                {
                    $this->messages = [$m];
                }
            };

            $this->tokenService->shouldReceive('executeWithClient')
                ->once()
                ->andReturn($mockResponse);

            $this->messageSyncService->syncMessages($this->token, null);

            $dbMessage = EfacturaMessage::where('message_id', 'MSG016')->first();
            expect($dbMessage->type)->toBe('error');
        });

        it('defaults to sent when no clear indicator', function () {
            $message = (object) [
                'id' => 'MSG017',
                'cifEmitent' => '99999999',
                'cifBeneficiar' => '88888888',
            ];

            $mockResponse = new class($message)
            {
                public array $messages;

                public function __construct($m)
                {
                    $this->messages = [$m];
                }
            };

            $this->tokenService->shouldReceive('executeWithClient')
                ->once()
                ->andReturn($mockResponse);

            $this->messageSyncService->syncMessages($this->token, null);

            $dbMessage = EfacturaMessage::where('message_id', 'MSG017')->first();
            expect($dbMessage->type)->toBe('sent');
        });
    });
});
