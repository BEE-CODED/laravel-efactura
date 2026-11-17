<?php

/*
 * This file is part of the Laravel e-Factura package.
 *
 * (c) BEE-CODED <dev.ttl@beecoded.ro>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use BeeCoded\EFactura\Jobs\SyncMessages;
use BeeCoded\EFactura\Models\EfacturaMessage;
use BeeCoded\EFactura\Models\EfacturaToken;
use BeeCoded\EFactura\Services\DownloadService;
use BeeCoded\EFactura\Services\MessageSyncService;
use BeeCoded\EFacturaSdk\Exceptions\ApiException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

/**
 * syncMessages() caught \Throwable, logged one line, and carried on. That is WHY the
 * message-sync bug (reading a property the SDK never declared) stayed invisible for
 * months: every sync threw, every throw was swallowed, and every run reported success
 * while syncing zero messages.
 *
 * Genuinely unexpected errors must be loud. Per-company isolation is still worth having
 * — one company's broken token should not stop the others — but efactura:sync must not
 * report success when part of it failed.
 */
const MESSAGES_URL = 'https://api.anaf.ro/test/FCTEL/rest/listaMesajePaginatieFactura*';

beforeEach(function () {
    config([
        'efactura-sdk.sandbox' => true,
        'efactura-sdk.oauth.client_id' => 'test-client-id',
        'efactura-sdk.oauth.client_secret' => 'test-client-secret',
        'efactura-sdk.oauth.redirect_uri' => 'https://app.test/efactura/callback',
        'efactura-sdk.http.retry_times' => 1,
        'efactura-sdk.http.retry_delay' => 0,
    ]);

    $this->messageSyncService = app(MessageSyncService::class);
});

function makeSyncToken(string $cui): EfacturaToken
{
    return EfacturaToken::create([
        'cui' => $cui,
        'access_token' => 'ACCESS_'.$cui,
        'refresh_token' => 'REFRESH_'.$cui,
        'expires_at' => now()->addHour(),
        'is_active' => true,
    ]);
}

/**
 * The query of every listing request sent so far.
 */
function syncQueries(): Collection
{
    return Http::recorded()->map(function (array $pair) {
        parse_str((string) parse_url($pair[0]->url(), PHP_URL_QUERY), $query);

        return $query;
    })->values();
}

function anafMessagesBody(string $cui, array $mesaje = []): array
{
    return [
        'mesaje' => $mesaje,
        'serial' => '1234AA456',
        'cui' => $cui,
        'titlu' => 'Lista Mesaje disponibile din ultimele 60 zile',
    ];
}

describe('syncMessages', function () {
    it('surfaces an ANAF failure instead of silently syncing nothing', function () {
        Http::fake([MESSAGES_URL => Http::response('Eroare', 500)]);

        $token = makeSyncToken('12345678');

        expect(fn () => $this->messageSyncService->syncMessages($token))
            ->toThrow(ApiException::class);
    });

    it('reports how many messages it actually synced', function () {
        Http::fake([
            MESSAGES_URL => Http::response(anafMessagesBody('12345678', [[
                'data_creare' => '202407171230',
                'cif' => '12345678',
                'id_solicitare' => '5001130255',
                'detalii' => 'Factura cu id_incarcare=5001130255 emisa de cif_emitent=12345678 pentru cif_beneficiar=87654321',
                'tip' => 'FACTURA TRIMISA',
                'id' => '3001130059',
            ]])),
        ]);

        $synced = $this->messageSyncService->syncMessages(makeSyncToken('12345678'));

        expect($synced)->toBe(1);
        expect(EfacturaMessage::count())->toBe(1);
    });

    it('does not rewrite a listed message whose stored details differ only in key order', function () {
        Http::fake([MESSAGES_URL => Http::response(anafMessagesBody('12345678', [[
            'data_creare' => '202407171230',
            'cif' => '12345678',
            'id_solicitare' => '5001130255',
            'detalii' => 'Factura cu id_incarcare=5001130255 emisa de cif_emitent=12345678 pentru cif_beneficiar=87654321',
            'tip' => 'FACTURA TRIMISA',
            'id' => '3001130059',
        ]]))]);
        $token = makeSyncToken('12345678');

        $this->messageSyncService->syncMessages($token);
        // MySQL's JSON type stores the keys in its own order.
        DB::table('efactura_messages')->update(['details' => json_encode(array_reverse(EfacturaMessage::sole()->details, true))]);
        $updatedAt = EfacturaMessage::sole()->updated_at;
        $this->travel(1)->minutes();

        $this->messageSyncService->syncMessages($token);

        expect(EfacturaMessage::sole()->updated_at->equalTo($updatedAt))->toBeTrue();
    });

    it('skips listed messages without a usable id instead of merging them into one row', function () {
        // The SDK reads a missing, empty or non-scalar id as null. Upserting on it would
        // key every such message on the same (null) message_id.
        $message = fn (array $id) => [
            'data_creare' => '202407171230',
            'cif' => '12345678',
            'id_solicitare' => '5001130255',
            'detalii' => 'Factura cu id_incarcare=5001130255 emisa de cif_emitent=12345678 pentru cif_beneficiar=87654321',
            'tip' => 'FACTURA PRIMITA',
            ...$id,
        ];

        Http::fake([
            MESSAGES_URL => Http::response(anafMessagesBody('12345678', [
                $message([]),
                $message(['id' => '  ']),
                $message(['id' => ['3001130059']]),
                // Not an ANAF id: the SDK's download refuses it, so it could never be fetched.
                $message(['id' => 'ABC-3001130061']),
                $message(['id' => '3001130060']),
            ])),
        ]);

        Log::spy();
        Log::shouldReceive('channel')->andReturnSelf(); // the SDK client logs through a channel

        $synced = $this->messageSyncService->syncMessages(makeSyncToken('12345678'));

        expect($synced)->toBe(1);
        expect(EfacturaMessage::pluck('message_id')->all())->toBe(['3001130060']);
        Log::shouldHaveReceived('warning')
            ->with('EFactura: ANAF listed a message without a usable id, skipping it', Mockery::type('array'))
            ->times(4);
    });
});

/**
 * The simple list answers "more than 500 messages, use the paginated endpoint" once a CUI
 * has over 500 messages in 60 days, so the sync always pages through one fixed window.
 */
describe('paginated listing', function () {
    it('pages through one fixed window until the last page', function () {
        $this->travelTo(Carbon::parse('2026-11-10 12:00:00', 'UTC'));
        $message = fn (string $id) => [
            'data_creare' => '202407171230',
            'cif' => '12345678',
            'id_solicitare' => '5001130255',
            'detalii' => 'Factura cu id_incarcare=5001130255 emisa de cif_emitent=12345678 pentru cif_beneficiar=87654321',
            'tip' => 'FACTURA TRIMISA',
            'id' => $id,
        ];

        $start = (string) now()->subDays(60)->addMinutes(70)->getTimestampMs();
        $end = (string) now()->subMinute()->getTimestampMs();

        Http::fake([MESSAGES_URL => function (Request $request) use ($message) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $page = (int) $query['pagina'];
            // Each page takes time, so a window computed per page would move.
            $this->travel(61)->seconds();

            return Http::response([
                ...anafMessagesBody('12345678', [$message('300113005'.$page)]),
                'numar_total_pagini' => 2,
                'index_pagina_curenta' => $page,
            ]);
        }]);

        expect($this->messageSyncService->syncMessages(makeSyncToken('12345678')))->toBe(2);

        $queries = syncQueries();

        expect($queries->pluck('pagina')->all())->toBe(['1', '2'])
            ->and($queries->pluck('startTime')->unique()->values()->all())->toBe([$start])
            ->and($queries->pluck('endTime')->unique()->values()->all())->toBe([$end]);
        expect(EfacturaMessage::pluck('message_id')->sort()->values()->all())->toBe(['3001130051', '3001130052']);
    });

    it('stops at its own page count when ANAF keeps answering with the same page index', function () {
        Http::fake([MESSAGES_URL => function () {
            // A runaway loop fails here instead of hanging the suite.
            if (Http::recorded()->count() > 3) {
                return Http::response('runaway', 500);
            }

            return Http::response([...anafMessagesBody('12345678'), 'numar_total_pagini' => 2, 'index_pagina_curenta' => 1]);
        }]);

        $this->messageSyncService->syncMessages(makeSyncToken('12345678'));

        expect(syncQueries()->pluck('pagina')->all())->toBe(['1', '2']);
    });

    it('keeps paging by its own count when ANAF does not say which page it answered', function () {
        Http::fake([MESSAGES_URL => Http::response([...anafMessagesBody('12345678'), 'numar_total_pagini' => 2])]);

        $this->messageSyncService->syncMessages(makeSyncToken('12345678'));

        expect(syncQueries()->pluck('pagina')->all())->toBe(['1', '2']);
    });

    /**
     * Sixty wall-clock days back from November cross the end of summer time, an hour more than
     * the SDK's 60-day limit, which refused every sync until late December.
     */
    it('keeps the window within 60 days in an app time zone with summer time', function () {
        $zone = date_default_timezone_get();
        config(['app.timezone' => 'Europe/Bucharest']);
        date_default_timezone_set('Europe/Bucharest');

        try {
            $this->travelTo(Carbon::parse('2026-11-10 12:00:00', 'Europe/Bucharest'));
            Http::fake([MESSAGES_URL => Http::response(anafMessagesBody('12345678'))]);

            expect($this->messageSyncService->syncMessages(makeSyncToken('12345678')))->toBe(0);

            $query = syncQueries()->sole();
            expect((int) $query['endTime'] - (int) $query['startTime'])->toBe((60 * 86400 - 71 * 60) * 1000)
                ->and((int) $query['endTime'])->toBe(now()->subMinute()->getTimestampMs());
        } finally {
            date_default_timezone_set($zone);
        }
    });

    it('is not stopped by the simple list\'s 500-message limit', function () {
        Http::fake([
            'https://api.anaf.ro/test/FCTEL/rest/listaMesajeFactura*' => Http::response([
                'eroare' => 'Lista de mesaje este mai mare decat numarul de 500 elemente permise in pagina. Folositi endpoint-ul cu paginatie.',
                'titlu' => 'Lista Mesaje',
            ]),
            MESSAGES_URL => Http::response(anafMessagesBody('12345678', [[
                'data_creare' => '202407171230',
                'cif' => '12345678',
                'id_solicitare' => '5001130255',
                'detalii' => 'Factura cu id_incarcare=5001130255 emisa de cif_emitent=12345678 pentru cif_beneficiar=87654321',
                'tip' => 'FACTURA TRIMISA',
                'id' => '3001130059',
            ]])),
        ]);

        expect($this->messageSyncService->syncMessages(makeSyncToken('12345678')))->toBe(1);
    });
});

describe('ANAF error channel', function () {
    it('throws when ANAF reports a real error at HTTP 200', function () {
        // ANAF reports listaMesaje failures as HTTP 200 + {"eroare": ...}. The SDK's
        // BaseApiClient::call() only throws when ! $response->successful(), so nothing
        // throws here: `mesaje` is simply null. Without an explicit hasError() check
        // this is indistinguishable from a quiet day, forever.
        Http::fake([MESSAGES_URL => Http::response([
            'eroare' => 'Nu exista niciun CIF pentru care sa aveti drept de interogare',
            'titlu' => 'Lista Mesaje',
        ])]);

        expect(fn () => $this->messageSyncService->syncMessages(makeSyncToken('12345678')))
            ->toThrow(ApiException::class, 'Nu exista niciun CIF pentru care sa aveti drept de interogare');

        expect(EfacturaMessage::count())->toBe(0);
    });

    it('syncs zero quietly when ANAF says there are simply no messages', function () {
        // ANAF uses the SAME `eroare` channel for the BENIGN empty case. Throwing on
        // every hasError() would dead-letter the sync on every quiet day.
        Http::fake([MESSAGES_URL => Http::response([
            'eroare' => 'Nu exista mesaje in ultimele 60 zile',
            'titlu' => 'Lista Mesaje',
        ])]);

        $synced = $this->messageSyncService->syncMessages(makeSyncToken('12345678'));

        expect($synced)->toBe(0);
        expect(EfacturaMessage::count())->toBe(0);
    });

    it('treats the paginated no-messages wording as benign as well', function () {
        Http::fake([MESSAGES_URL => Http::response([
            'eroare' => 'Nu exista mesaje in intervalul selectat',
            'titlu' => 'Lista Mesaje',
        ])]);

        expect($this->messageSyncService->syncMessages(makeSyncToken('12345678')))->toBe(0);
    });
});

/**
 * The operator-facing end of the same bug: `efactura:sync` printed "Synced 0
 * message(s)." and exited 0 while ANAF was refusing every request. Both directions are
 * asserted, because an exit-code test that only ever checks the failure case cannot
 * tell a real gate from a command that always fails.
 */
describe('efactura:sync command', function () {
    it('exits non-zero rather than reporting a successful zero-message sync', function () {
        makeSyncToken('12345678');

        Http::fake([MESSAGES_URL => Http::response([
            'eroare' => 'Nu exista niciun CIF pentru care sa aveti drept de interogare',
            'titlu' => 'Lista Mesaje',
        ])]);

        $this->artisan('efactura:sync')
            ->assertExitCode(1);
    });

    it('syncs and downloads for the other companies, prints only the class of a failure, and exits 1', function () {
        $failing = makeSyncToken('12345678');
        $healthy = makeSyncToken('87654321');
        $message = fn (string $cui, string $id) => anafMessagesBody($cui, [[
            'data_creare' => '202407171230',
            'cif' => $cui,
            'id_solicitare' => '5001130255',
            'detalii' => "Factura cu id_incarcare=5001130255 emisa de cif_emitent=11111111 pentru cif_beneficiar={$cui}",
            'tip' => 'FACTURA PRIMITA',
            'id' => $id,
        ]]);
        Http::fake([
            'https://api.anaf.ro/test/FCTEL/rest/listaMesajePaginatieFactura?cif=12345678*' => Http::response($message('12345678', '3001130059')),
            'https://api.anaf.ro/test/FCTEL/rest/listaMesajePaginatieFactura?cif=87654321*' => Http::response($message('87654321', '3001130060')),
        ]);
        EfacturaMessage::creating(function (EfacturaMessage $row) use ($failing) {
            if ($row->efactura_token_id === $failing->id) {
                throw new QueryException('testing', 'insert into efactura_messages values (?)', ['SECRET-BINDING'], new PDOException('boom'));
            }
        });
        $downloads = $this->mock(DownloadService::class);
        $downloads->shouldReceive('downloadAllReceivedInvoices')->twice()->andReturn(true);

        $this->artisan('efactura:sync', ['--download' => true])
            ->expectsOutputToContain('Message sync failed for 12345678 (QueryException); see the log.')
            ->doesntExpectOutputToContain('SECRET-BINDING')
            ->assertExitCode(1);

        expect(EfacturaMessage::forToken($healthy)->count())->toBe(1);
    });

    it('carries on past a received-invoice download that throws, and exits 1', function () {
        makeSyncToken('12345678');
        makeSyncToken('87654321');
        Http::fake([MESSAGES_URL => Http::response([
            'eroare' => 'Nu exista mesaje in intervalul selectat',
            'titlu' => 'Lista Mesaje',
        ])]);
        $downloads = $this->mock(DownloadService::class);
        $downloads->shouldReceive('downloadAllReceivedInvoices')->twice()->andReturnUsing(
            fn (EfacturaToken $token) => $token->cui === '12345678' ? throw new RuntimeException('SECRET-PATH') : true,
        );

        $this->artisan('efactura:sync', ['--download' => true])
            ->expectsOutputToContain('Received-invoice download failed for 12345678 (RuntimeException); see the log.')
            ->doesntExpectOutputToContain('SECRET-PATH')
            ->assertExitCode(1);
    });

    it('still reports a genuinely quiet day as success', function () {
        makeSyncToken('12345678');

        Http::fake([MESSAGES_URL => Http::response([
            'eroare' => 'Nu exista mesaje in ultimele 60 zile',
            'titlu' => 'Lista Mesaje',
        ])]);

        $this->artisan('efactura:sync')
            ->expectsOutputToContain('Synced 0 message(s).')
            ->assertExitCode(0);
    });
});

/**
 * A null-CUI SyncMessages used to sync every token inside one 120 s job, so one slow CUI
 * used up the budget of all the others. It now only fans out one job per active CUI; each
 * fails and retries on its own, without re-listing the healthy ones.
 */
describe('SyncMessages job', function () {
    it('fans out one job per active CUI, and a broken CUI fails alone', function () {
        Queue::fake();

        makeSyncToken('12345678');
        makeSyncToken('87654321');
        makeSyncToken('11111111')->update(['is_active' => false]);

        Http::fake([
            'https://api.anaf.ro/test/FCTEL/rest/listaMesajePaginatieFactura?cif=12345678*' => Http::response(
                anafMessagesBody('12345678', [[
                    'data_creare' => '202407171230',
                    'cif' => '12345678',
                    'id_solicitare' => '5001130255',
                    'detalii' => 'Factura cu id_incarcare=5001130255 emisa de cif_emitent=12345678 pentru cif_beneficiar=87654321',
                    'tip' => 'FACTURA TRIMISA',
                    'id' => '3001130059',
                ]])
            ),
            'https://api.anaf.ro/test/FCTEL/rest/listaMesajePaginatieFactura?cif=87654321*' => Http::response('Eroare', 500),
        ]);

        app()->call([new SyncMessages, 'handle']);

        Http::assertNothingSent();
        $jobs = Queue::pushed(SyncMessages::class);
        expect($jobs->pluck('cui')->sort()->values()->all())->toBe(['12345678', '87654321']);

        app()->call([$jobs->firstWhere('cui', '12345678'), 'handle']);
        expect(fn () => app()->call([$jobs->firstWhere('cui', '87654321'), 'handle']))->toThrow(ApiException::class);

        expect(EfacturaMessage::pluck('message_id')->all())->toBe(['3001130059']);
    });

    it('still lets a single-CUI sync retry, since that failure may be transient', function () {
        makeSyncToken('12345678');

        Http::fake([MESSAGES_URL => Http::response('Eroare', 500)]);

        // The single-CUI path has no healthy neighbours whose quota it could burn, and
        // its failure may be a transient ANAF blip, so it must keep retrying — i.e. the
        // exception must still BUBBLE OUT of handle(). That bubble is precisely what
        // tells the worker to release the job for another attempt, and it is the whole
        // behavioural difference from the batch path above.
        expect(fn () => SyncMessages::dispatch('12345678'))->toThrow(ApiException::class);
    });
});

describe('message filters', function () {
    it('sends the filter of each public sync to ANAF', function (Closure $sync, string $filtru) {
        Http::fake([MESSAGES_URL => Http::response(anafMessagesBody('12345678'))]);

        $sync($this->messageSyncService, makeSyncToken('12345678'));

        expect(syncQueries()->sole()['filtru'] ?? null)->toBe($filtru);
    })->with([
        'syncSentMessages' => [fn (MessageSyncService $service, EfacturaToken $token) => $service->syncSentMessages($token), 'T'],
        'syncReceivedMessages' => [fn (MessageSyncService $service, EfacturaToken $token) => $service->syncReceivedMessages($token), 'P'],
    ]);
});
