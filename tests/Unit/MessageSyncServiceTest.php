<?php

use BeeCoded\EFactura\Models\EfacturaMessage;
use BeeCoded\EFactura\Models\EfacturaToken;
use BeeCoded\EFactura\Services\MessageSyncService;
use BeeCoded\EFactura\Services\TokenService;
use BeeCoded\EFacturaSdk\Data\Response\ListMessagesResponseData;
use BeeCoded\EFacturaSdk\Enums\MessageFilter;
use Illuminate\Support\Facades\Log;

/**
 * These tests build REAL SDK DTOs via ListMessagesResponseData::fromAnafResponse()
 * with realistic ANAF payloads.
 *
 * The previous version of this file hand-built anonymous classes exposing a
 * ->messages property and ->cifEmitent / ->cifBeneficiar / ->idDescarcare fields.
 * None of those exist on the SDK's DTOs (the real names are ->mesaje and
 * ->id / ->cif / ->dataCreare / ->tip / ->detalii / ->idSolicitare), so the suite
 * was green against a fiction while sync was syncing zero messages in production.
 */
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

afterEach(function () {
    // date_default_timezone_set() is process-global; a timezone test must not leak
    // into its neighbours.
    date_default_timezone_set('UTC');
});

/**
 * A realistic ANAF /listaMesajeFactura response envelope.
 *
 * @param  array<int, array<string, string>>  $mesaje
 * @return array<string, mixed>
 */
function anafListResponse(array $mesaje): ListMessagesResponseData
{
    return ListMessagesResponseData::fromAnafResponse([
        'mesaje' => $mesaje,
        'serial' => '1234AA456',
        'cui' => '12345678',
        'titlu' => 'Lista Mesaje disponibile din ultimele 60 zile',
    ]);
}

/**
 * A single ANAF message item, in ANAF's real snake_case wire shape.
 *
 * @return array<string, string>
 */
function anafMessage(array $overrides = []): array
{
    return array_merge([
        'data_creare' => '202407171230',
        'cif' => '12345678',
        'id_solicitare' => '5001130255',
        'detalii' => 'Factura cu id_incarcare=5001130255 emisa de cif_emitent=12345678 pentru cif_beneficiar=87654321',
        'tip' => 'FACTURA TRIMISA',
        'id' => '3001130059',
    ], $overrides);
}

describe('MessageSyncService', function () {
    describe('syncMessages', function () {
        it('syncs messages from API', function () {
            $response = anafListResponse([
                anafMessage([
                    'id' => '3001130059',
                    'id_solicitare' => '5001130255',
                    'tip' => 'FACTURA TRIMISA',
                    'detalii' => 'Factura cu id_incarcare=5001130255 emisa de cif_emitent=12345678 pentru cif_beneficiar=87654321',
                ]),
                anafMessage([
                    'id' => '3001130060',
                    'id_solicitare' => '5001130256',
                    'tip' => 'FACTURA PRIMITA',
                    'detalii' => 'Factura cu id_incarcare=5001130256 emisa de cif_emitent=99999999 pentru cif_beneficiar=12345678',
                ]),
            ]);

            $this->tokenService->shouldReceive('executeWithClient')
                ->once()
                ->andReturn($response);

            $this->messageSyncService->syncMessages($this->token);

            expect(EfacturaMessage::count())->toBe(2);
            expect(EfacturaMessage::where('message_id', '3001130059')->exists())->toBeTrue();
            expect(EfacturaMessage::where('message_id', '3001130060')->exists())->toBeTrue();
        });

        it('uses the ANAF message id as the download id', function () {
            $this->tokenService->shouldReceive('executeWithClient')
                ->once()
                ->andReturn(anafListResponse([anafMessage(['id' => '3001130059'])]));

            $this->messageSyncService->syncMessages($this->token);

            $message = EfacturaMessage::where('message_id', '3001130059')->first();
            expect($message->download_id)->toBe('3001130059');
        });

        it('parses ANAF YmdHi dates into a real datetime', function () {
            $this->tokenService->shouldReceive('executeWithClient')
                ->once()
                ->andReturn(anafListResponse([anafMessage(['data_creare' => '202407171230'])]));

            $this->messageSyncService->syncMessages($this->token);

            $message = EfacturaMessage::first();

            // The datetime cast would read 202407171230 as a unix timestamp (year ~8383).
            expect($message->message_date)->not->toBeNull();
            expect($message->message_date->year)->toBe(2024);
        });

        /**
         * The previous version of this test asserted
         *   $message->message_date->format('Y-m-d H:i') === '2024-07-17 12:30'
         * which is a same-timezone round-trip: parse in tz X, read back in tz X. It
         * holds for ANY app timezone and therefore could never fail — it asserted the
         * bug as hard as it asserted the fix.
         *
         * ANAF emits data_creare in ROMANIAN local time. The only assertion that can
         * tell right from wrong is the absolute instant, checked from an app timezone
         * that is neither UTC nor Europe/Bucharest.
         */
        it('anchors ANAF dates to Romanian time rather than the app timezone', function () {
            // 2024-07-17 is EEST (UTC+3) in Bucharest, so 12:30 there is 09:30 UTC.
            // Under the app's own timezone (New York, UTC-4 in July) a naive parse
            // would land on 16:30 UTC — nearly seven hours out.
            config(['app.timezone' => 'America/New_York']);
            date_default_timezone_set('America/New_York');

            $this->tokenService->shouldReceive('executeWithClient')
                ->once()
                ->andReturn(anafListResponse([anafMessage(['data_creare' => '202407171230'])]));

            $this->messageSyncService->syncMessages($this->token);

            $message = EfacturaMessage::first();

            expect($message->message_date)->not->toBeNull();
            expect($message->message_date->copy()->utc()->format('Y-m-d H:i'))
                ->toBe('2024-07-17 09:30');
        });

        /**
         * Winter is EET (UTC+2), so the offset ANAF's wall clock carries is not a
         * constant — a hardcoded "+3" would pass the summer test and skew every
         * winter message by an hour.
         */
        it('honours the Romanian DST offset in force on the message date', function () {
            config(['app.timezone' => 'America/New_York']);
            date_default_timezone_set('America/New_York');

            $this->tokenService->shouldReceive('executeWithClient')
                ->once()
                ->andReturn(anafListResponse([anafMessage(['data_creare' => '202401151230'])]));

            $this->messageSyncService->syncMessages($this->token);

            expect(EfacturaMessage::first()->message_date->copy()->utc()->format('Y-m-d H:i'))
                ->toBe('2024-01-15 10:30');
        });

        it('leaves message_date null when ANAF sends an unparseable date', function () {
            $this->tokenService->shouldReceive('executeWithClient')
                ->once()
                ->andReturn(anafListResponse([anafMessage(['data_creare' => ''])]));

            $this->messageSyncService->syncMessages($this->token);

            expect(EfacturaMessage::first()->message_date)->toBeNull();
        });

        it('derives emitent and beneficiar CUIs from detalii', function () {
            $this->tokenService->shouldReceive('executeWithClient')
                ->once()
                ->andReturn(anafListResponse([anafMessage([
                    'detalii' => 'Factura cu id_incarcare=5001130255 emisa de cif_emitent=12345678 pentru cif_beneficiar=87654321',
                ])]));

            $this->messageSyncService->syncMessages($this->token);

            $message = EfacturaMessage::first();
            expect($message->cif_emitent)->toBe('12345678');
            expect($message->cif_beneficiar)->toBe('87654321');
        });

        it('stores the real DTO fields in details', function () {
            $this->tokenService->shouldReceive('executeWithClient')
                ->once()
                ->andReturn(anafListResponse([anafMessage()]));

            $this->messageSyncService->syncMessages($this->token);

            $details = EfacturaMessage::first()->details;
            expect($details)->toHaveKeys(['id', 'cif', 'dataCreare', 'tip', 'detalii', 'idSolicitare']);
            expect($details['tip'])->toBe('FACTURA TRIMISA');
            expect($details['idSolicitare'])->toBe('5001130255');
        });

        it('updates existing messages', function () {
            EfacturaMessage::create([
                'efactura_token_id' => $this->token->id,
                'message_id' => '3001130059',
                'type' => 'sent',
                'cif_emitent' => '11111111',
            ]);

            $this->tokenService->shouldReceive('executeWithClient')
                ->once()
                ->andReturn(anafListResponse([anafMessage([
                    'id' => '3001130059',
                    'detalii' => 'Factura cu id_incarcare=5001130255 emisa de cif_emitent=22222222 pentru cif_beneficiar=33333333',
                ])]));

            $this->messageSyncService->syncMessages($this->token);

            expect(EfacturaMessage::where('message_id', '3001130059')->count())->toBe(1);
            $message = EfacturaMessage::where('message_id', '3001130059')->first();
            expect($message->cif_emitent)->toBe('22222222');
            expect($message->cif_beneficiar)->toBe('33333333');
        });

        it('handles a response with no messages', function () {
            $this->tokenService->shouldReceive('executeWithClient')
                ->once()
                ->andReturn(anafListResponse([]));

            $this->messageSyncService->syncMessages($this->token);

            expect(EfacturaMessage::count())->toBe(0);
        });

        /**
         * This test used to assert the OPPOSITE — that syncMessages() swallowed the
         * exception and returned normally. That swallowing is exactly why the
         * message-sync wire-contract break (reading a property the SDK never declared)
         * stayed invisible for months: every sync threw, every throw was logged and
         * dropped, and every run reported success while syncing zero messages.
         *
         * A single-token sync must be loud. Per-token isolation belongs to the BATCH
         * caller (syncAllMessages), which still isolates but then refuses to report
         * success — see tests/Feature/MessageSyncFailureTest.php.
         */
        it('propagates an unexpected error instead of silently syncing nothing', function () {
            $this->tokenService->shouldReceive('executeWithClient')
                ->once()
                ->andThrow(new \Exception('API Error'));

            expect(fn () => $this->messageSyncService->syncMessages($this->token))
                ->toThrow(\Exception::class, 'API Error');

            expect(EfacturaMessage::count())->toBe(0);
        });
    });

    describe('syncSentMessages', function () {
        it('calls syncMessages with InvoiceSent filter', function () {
            $this->tokenService->shouldReceive('executeWithClient')
                ->once()
                ->andReturn(anafListResponse([anafMessage(['id' => '3001130059'])]));

            $this->messageSyncService->syncSentMessages($this->token);

            expect(EfacturaMessage::where('message_id', '3001130059')->exists())->toBeTrue();
        });
    });

    describe('syncReceivedMessages', function () {
        it('calls syncMessages with InvoiceReceived filter', function () {
            $this->tokenService->shouldReceive('executeWithClient')
                ->once()
                ->andReturn(anafListResponse([anafMessage([
                    'id' => '3001130060',
                    'tip' => 'FACTURA PRIMITA',
                ])]));

            $this->messageSyncService->syncReceivedMessages($this->token);

            expect(EfacturaMessage::where('message_id', '3001130060')->exists())->toBeTrue();
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

            $this->tokenService->shouldReceive('getActiveTokens')
                ->once()
                ->andReturn(new \Illuminate\Database\Eloquent\Collection([$this->token, $token2]));

            $this->tokenService->shouldReceive('executeWithClient')
                ->twice()
                ->andReturn(
                    anafListResponse([anafMessage(['id' => '3001130059'])]),
                    anafListResponse([anafMessage(['id' => '3001130060', 'cif' => '87654321'])]),
                );

            $this->messageSyncService->syncAllMessages();

            expect(EfacturaMessage::count())->toBe(2);
            expect(EfacturaMessage::where('efactura_token_id', $this->token->id)->count())->toBe(1);
            expect(EfacturaMessage::where('efactura_token_id', $token2->id)->count())->toBe(1);
        });

        it('passes filter to syncMessages', function () {
            $this->tokenService->shouldReceive('getActiveTokens')
                ->once()
                ->andReturn(new \Illuminate\Database\Eloquent\Collection([$this->token]));

            $this->tokenService->shouldReceive('executeWithClient')
                ->once()
                ->andReturn(anafListResponse([anafMessage(['id' => '3001130059'])]));

            $this->messageSyncService->syncAllMessages(MessageFilter::InvoiceSent);

            expect(EfacturaMessage::where('message_id', '3001130059')->exists())->toBeTrue();
        });
    });

    describe('determineMessageType', function () {
        it('classifies FACTURA TRIMISA as sent from the authoritative tip field', function () {
            $this->tokenService->shouldReceive('executeWithClient')
                ->once()
                ->andReturn(anafListResponse([anafMessage(['tip' => 'FACTURA TRIMISA'])]));

            $this->messageSyncService->syncMessages($this->token);

            expect(EfacturaMessage::first()->type)->toBe('sent');
        });

        it('classifies FACTURA PRIMITA as received from the authoritative tip field', function () {
            $this->tokenService->shouldReceive('executeWithClient')
                ->once()
                ->andReturn(anafListResponse([anafMessage([
                    'tip' => 'FACTURA PRIMITA',
                    'detalii' => 'Factura cu id_incarcare=5001130256 emisa de cif_emitent=99999999 pentru cif_beneficiar=12345678',
                ])]));

            $this->messageSyncService->syncMessages($this->token);

            expect(EfacturaMessage::first()->type)->toBe('received');
        });

        it('classifies ERORI FACTURA as error from the authoritative tip field', function () {
            $this->tokenService->shouldReceive('executeWithClient')
                ->once()
                ->andReturn(anafListResponse([anafMessage([
                    'tip' => 'ERORI FACTURA',
                    'detalii' => 'Erori de validare identificate la factura transmisa cu id_incarcare=5001130255',
                ])]));

            $this->messageSyncService->syncMessages($this->token);

            expect(EfacturaMessage::first()->type)->toBe('error');
        });

        it('classifies MESAJ CUMPARATOR as received from the authoritative tip field', function () {
            $this->tokenService->shouldReceive('executeWithClient')
                ->once()
                ->andReturn(anafListResponse([anafMessage(['tip' => 'MESAJ CUMPARATOR'])]));

            $this->messageSyncService->syncMessages($this->token);

            expect(EfacturaMessage::first()->type)->toBe('received');
        });

        it('trusts tip over a contradicting filter', function () {
            // ANAF's tip is authoritative. A PRIMITA message must not be recorded as
            // sent just because the caller passed the InvoiceSent filter.
            $this->tokenService->shouldReceive('executeWithClient')
                ->once()
                ->andReturn(anafListResponse([anafMessage(['tip' => 'FACTURA PRIMITA'])]));

            $this->messageSyncService->syncMessages($this->token, MessageFilter::InvoiceSent);

            expect(EfacturaMessage::first()->type)->toBe('received');
        });

        it('falls back to the filter when tip is unrecognised', function () {
            $this->tokenService->shouldReceive('executeWithClient')
                ->once()
                ->andReturn(anafListResponse([anafMessage(['tip' => 'SOMETHING NEW FROM ANAF'])]));

            $this->messageSyncService->syncMessages($this->token, MessageFilter::InvoiceReceived);

            expect(EfacturaMessage::first()->type)->toBe('received');
        });

        /**
         * This test used to assert ONLY the silent 'sent' fallback — it blessed the
         * bug. The production sync path (SyncMessages job) passes NO filter, so an
         * unrecognised `tip` lands here. If ANAF ever qualifies a tip (say
         * "MESAJ CUMPARATOR PRIMIT"), a RECEIVED invoice is recorded as 'sent', never
         * matches ->received(), and is never downloaded — with nothing in the log to
         * say so. The fallback itself is a reasonable default; the SILENCE is the bug.
         */
        it('warns when tip is unrecognised instead of silently guessing sent', function () {
            Log::spy();

            $this->tokenService->shouldReceive('executeWithClient')
                ->once()
                ->andReturn(anafListResponse([anafMessage(['tip' => 'MESAJ CUMPARATOR PRIMIT'])]));

            $this->messageSyncService->syncMessages($this->token, null);

            expect(EfacturaMessage::first()->type)->toBe('sent');

            Log::shouldHaveReceived('warning')
                ->once()
                ->withArgs(fn (string $message, array $context) => str_contains($message, 'unrecognised message type')
                    && $context['tip'] === 'MESAJ CUMPARATOR PRIMIT'
                    && $context['fell_back_to'] === 'sent');
        });

        it('warns about an unrecognised tip even when a filter rescues the type', function () {
            Log::spy();

            $this->tokenService->shouldReceive('executeWithClient')
                ->once()
                ->andReturn(anafListResponse([anafMessage(['tip' => 'MESAJ CUMPARATOR PRIMIT'])]));

            $this->messageSyncService->syncMessages($this->token, MessageFilter::InvoiceReceived);

            Log::shouldHaveReceived('warning')
                ->once()
                ->withArgs(fn (string $message, array $context) => str_contains($message, 'unrecognised message type')
                    && $context['fell_back_to'] === 'received');
        });

        it('stays quiet for a tip it recognises', function () {
            Log::spy();

            $this->tokenService->shouldReceive('executeWithClient')
                ->once()
                ->andReturn(anafListResponse([anafMessage(['tip' => 'FACTURA PRIMITA'])]));

            $this->messageSyncService->syncMessages($this->token);

            Log::shouldNotHaveReceived('warning');
        });

        it('matches tip case-insensitively and ignores surrounding whitespace', function () {
            $this->tokenService->shouldReceive('executeWithClient')
                ->once()
                ->andReturn(anafListResponse([anafMessage(['tip' => '  factura primita  '])]));

            $this->messageSyncService->syncMessages($this->token);

            expect(EfacturaMessage::first()->type)->toBe('received');
        });
    });
});
