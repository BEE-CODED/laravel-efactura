<?php

/*
 * This file is part of the Laravel e-Factura package.
 *
 * (c) BEE-CODED <dev.ttl@beecoded.ro>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use BeeCoded\EFactura\Exceptions\MessageSyncFailedException;
use BeeCoded\EFactura\Jobs\SyncMessages;
use BeeCoded\EFactura\Models\EfacturaMessage;
use BeeCoded\EFactura\Models\EfacturaToken;
use BeeCoded\EFactura\Services\MessageSyncService;
use BeeCoded\EFacturaSdk\Exceptions\ApiException;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

/**
 * syncMessages() caught \Throwable, logged one line, and carried on. That is WHY the
 * message-sync bug (reading a property the SDK never declared) stayed invisible for
 * months: every sync threw, every throw was swallowed, and every run reported success
 * while syncing zero messages.
 *
 * Genuinely unexpected errors must be loud. Per-token isolation is still worth having
 * — one company's broken token should not stop the others — but the BATCH must not
 * report success when part of it failed.
 */
const MESSAGES_URL = 'https://api.anaf.ro/test/FCTEL/rest/listaMesajeFactura*';

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

    it('reports the eroare_descarcare channel too', function () {
        Http::fake([MESSAGES_URL => Http::response([
            'eroare_descarcare' => 'Serviciul de descarcare este indisponibil',
            'titlu' => 'Lista Mesaje',
        ])]);

        expect(fn () => $this->messageSyncService->syncMessages(makeSyncToken('12345678')))
            ->toThrow(ApiException::class, 'Serviciul de descarcare este indisponibil');
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

describe('syncAllMessages', function () {
    it('keeps per-token isolation but refuses to report success', function () {
        $good = makeSyncToken('12345678');
        $bad = makeSyncToken('87654321');

        Http::fake([
            'https://api.anaf.ro/test/FCTEL/rest/listaMesajeFactura?cif=12345678*' => Http::response(
                anafMessagesBody('12345678', [[
                    'data_creare' => '202407171230',
                    'cif' => '12345678',
                    'id_solicitare' => '5001130255',
                    'detalii' => 'Factura cu id_incarcare=5001130255 emisa de cif_emitent=12345678 pentru cif_beneficiar=87654321',
                    'tip' => 'FACTURA TRIMISA',
                    'id' => '3001130059',
                ]])
            ),
            'https://api.anaf.ro/test/FCTEL/rest/listaMesajeFactura?cif=87654321*' => Http::response('Eroare', 500),
        ]);

        expect(fn () => $this->messageSyncService->syncAllMessages())
            ->toThrow(MessageSyncFailedException::class);

        // Isolation held: the healthy token still synced.
        expect(EfacturaMessage::where('efactura_token_id', $good->id)->count())->toBe(1);
        expect(EfacturaMessage::where('efactura_token_id', $bad->id)->count())->toBe(0);
    });

    it('names the tokens that failed', function () {
        makeSyncToken('12345678');

        Http::fake([MESSAGES_URL => Http::response('Eroare', 500)]);

        try {
            $this->messageSyncService->syncAllMessages();
            $this->fail('Expected MessageSyncFailedException');
        } catch (MessageSyncFailedException $e) {
            expect($e->getMessage())->toContain('12345678');
            expect($e->failures)->toHaveCount(1);
        }
    });

    it('stays quiet when every token syncs', function () {
        makeSyncToken('12345678');
        makeSyncToken('87654321');

        Http::fake([MESSAGES_URL => Http::response(anafMessagesBody('12345678'))]);

        $this->messageSyncService->syncAllMessages();

        expect(true)->toBeTrue();
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
 * Isolating per-token failures and then throwing is right for the SERVICE, but the
 * JOB must not answer that throw by re-running the whole batch. syncAllMessages()
 * starts from scratch on every attempt, so tries=3 re-hits /listaMesajeFactura for
 * every HEALTHY CUI three times an hour — against ANAF's 750/day/CUI budget — purely
 * because one company's refresh token is permanently revoked. Retrying cannot fix a
 * revoked token; it only burns the healthy companies' quota.
 */
describe('SyncMessages job', function () {
    it('fails a broken batch loudly but does not re-run it for every healthy CUI', function () {
        // Listen on the REAL dispatcher: Event::fake() does not intercept JobFailed,
        // because the queue resolves its own dispatcher instance — a faked assertion
        // silently records nothing here.
        $failures = [];
        Event::listen(JobFailed::class, function (JobFailed $event) use (&$failures) {
            $failures[] = $event->exception;
        });

        makeSyncToken('12345678');
        makeSyncToken('87654321');

        Http::fake([
            'https://api.anaf.ro/test/FCTEL/rest/listaMesajeFactura?cif=12345678*' => Http::response(
                anafMessagesBody('12345678')
            ),
            'https://api.anaf.ro/test/FCTEL/rest/listaMesajeFactura?cif=87654321*' => Http::response('Eroare', 500),
        ]);

        // Must NOT bubble out of handle(): a bubbling exception is exactly what tells
        // the queue worker to release the job for another attempt.
        SyncMessages::dispatch();

        // Still loud — it dead-letters, it just does not retry first.
        expect($failures)->toHaveCount(1);
        expect($failures[0])->toBeInstanceOf(MessageSyncFailedException::class);
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
