<?php

use BeeCoded\EFactura\Enums\StatusCheckOutcome;
use BeeCoded\EFactura\Enums\UploadStatus;
use BeeCoded\EFactura\Models\EfacturaToken;
use BeeCoded\EFactura\Models\EfacturaUpload;
use BeeCoded\EFactura\Services\DownloadService;
use BeeCoded\EFactura\Services\MessageSyncService;
use BeeCoded\EFactura\Services\TokenService;
use BeeCoded\EFactura\Services\UploadService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->token = EfacturaToken::create([
        'cui' => '12345678',
        'access_token' => 'test',
        'refresh_token' => 'test',
        'expires_at' => now()->addHour(),
        'is_active' => true,
    ]);
});

/**
 * Matches the test's token as a service argument.
 */
function isTestToken(): Mockery\Matcher\Closure
{
    return Mockery::on(fn ($token) => $token instanceof EfacturaToken && $token->is(test()->token));
}

describe('EfacturaUploadCommand', function () {
    it('fails when upload feature is disabled', function () {
        config(['efactura.enabled' => true, 'efactura.features.upload_invoices' => false]);

        $this->artisan('efactura:upload')
            ->expectsOutput('e-Factura upload feature is disabled.')
            ->assertExitCode(1);
    });

    it('fails when efactura is disabled', function () {
        config(['efactura.enabled' => false, 'efactura.features.upload_invoices' => true]);

        $this->artisan('efactura:upload')
            ->expectsOutput('e-Factura upload feature is disabled.')
            ->assertExitCode(1);
    });

    /**
     * The command QUEUES uploads instead of uploading them inline: each upload then gets
     * the rate-limit pre-flight and its row's retry schedule.
     */
    it('queues the uploads of every active company when no CUI is given', function () {
        $uploadService = Mockery::mock(UploadService::class);
        $uploadService->shouldReceive('dispatchPendingUploads')->once()->with(isTestToken())->andReturn(2);
        $this->app->instance(UploadService::class, $uploadService);

        $this->artisan('efactura:upload')
            ->expectsOutputToContain('Queued 2 pending upload(s)')
            ->assertExitCode(0);
    });

    it('sends due uploads inline behind --sync', function () {
        $due = EfacturaUpload::create([
            'efactura_token_id' => $this->token->id,
            'uploadable_type' => 'App\\Models\\Invoice',
            'uploadable_id' => 1,
            'status' => UploadStatus::Pending,
        ]);

        $uploadService = Mockery::mock(UploadService::class);
        $uploadService->shouldReceive('processUpload')->once()->with(Mockery::on(fn ($upload) => $upload->is($due)));
        $uploadService->shouldNotReceive('dispatchPendingUploads');
        $this->app->instance(UploadService::class, $uploadService);

        $this->artisan('efactura:upload', ['--sync' => true])
            ->expectsOutputToContain('Processed 1 upload(s).')
            ->assertExitCode(0);
    });

    // No per-CUI path reaches a legacy row stored under a non-canonical CUI (no client can be
    // built for it, no status read finds it), so its uploads wait in Pending untouched.
    it('skips a company stored under a non-canonical CUI', function (array $options) {
        Queue::fake();
        $this->token->update(['is_active' => false]);
        $legacy = EfacturaToken::create([
            'cui' => '012345678',
            'access_token' => 'test',
            'refresh_token' => 'test',
            'expires_at' => now()->addHour(),
            'is_active' => true,
        ]);
        $due = EfacturaUpload::create([
            'efactura_token_id' => $legacy->id,
            'uploadable_type' => 'App\\Models\\Invoice',
            'uploadable_id' => 1,
            'status' => UploadStatus::Pending,
        ]);

        $this->artisan('efactura:upload', $options)
            ->expectsOutput('012345678: skipped (non-canonical CUI).')
            ->assertExitCode(0);

        Queue::assertNothingPushed();
        expect($due->fresh())->status->toBe(UploadStatus::Pending)
            ->failure_reason->toBeNull()
            ->next_poll_at->toBeNull();
    })->with(['queued' => [[]], 'sync' => [['--sync' => true]]]);

    it('queues the uploads of the given company, whatever its CUI spelling', function (string $cui) {
        $uploadService = Mockery::mock(UploadService::class);
        $uploadService->shouldReceive('dispatchPendingUploads')->once()->with(isTestToken())->andReturn(1);
        $this->app->instance(UploadService::class, $uploadService);

        $this->artisan('efactura:upload', ['--cui' => $cui])
            ->assertExitCode(0);
    })->with(['12345678', 'RO12345678', 'ro 1234 5678']);

    it('names the queue the default connection uses when efactura.queue is not set', function () {
        config([
            'efactura.queue' => null,
            'queue.default' => 'database',
            'queue.connections.database.queue' => 'invoices',
        ]);

        $uploadService = Mockery::mock(UploadService::class);
        $uploadService->shouldReceive('dispatchPendingUploads')->once()->andReturn(1);
        $this->app->instance(UploadService::class, $uploadService);

        $this->artisan('efactura:upload')
            ->expectsOutputToContain('running on the "invoices" queue.')
            ->assertExitCode(0);
    });
});

describe('EfacturaStatusCommand', function () {
    it('fails when upload feature is disabled', function () {
        config(['efactura.enabled' => true, 'efactura.features.upload_invoices' => false]);

        $this->artisan('efactura:status')
            ->expectsOutput('e-Factura upload feature is disabled.')
            ->assertExitCode(1);
    });

    it('fails when upload not found', function () {
        $this->artisan('efactura:status', ['--upload' => '999'])
            ->expectsOutput('Upload #999 not found.')
            ->assertExitCode(1);
    });

    it('checks status for specific upload', function () {
        $upload = EfacturaUpload::create([
            'efactura_token_id' => $this->token->id,
            'uploadable_type' => 'App\\Models\\Invoice',
            'uploadable_id' => 1,
            'status' => UploadStatus::Processing,
            'upload_index' => 'IDX123',
            'standard' => 'UBL',
        ]);

        $downloadService = Mockery::mock(DownloadService::class);
        $downloadService->shouldReceive('checkStatus')
            ->once()
            ->with(Mockery::type(EfacturaUpload::class))
            ->andReturn(StatusCheckOutcome::Checked);

        $this->app->instance(DownloadService::class, $downloadService);

        $this->artisan('efactura:status', ['--upload' => $upload->id])
            ->expectsOutputToContain("Checking status for upload #{$upload->id}")
            ->assertExitCode(0);
    });

    it('downloads response if download_id exists', function () {
        $upload = EfacturaUpload::create([
            'efactura_token_id' => $this->token->id,
            'uploadable_type' => 'App\\Models\\Invoice',
            'uploadable_id' => 1,
            'status' => UploadStatus::Completed,
            'download_id' => 'DL123',
            'standard' => 'UBL',
        ]);

        $downloadService = Mockery::mock(DownloadService::class);
        $downloadService->shouldReceive('checkStatus')->once()->andReturn(StatusCheckOutcome::NotPollable);
        $downloadService->shouldReceive('downloadResponse')
            ->once()
            ->with(Mockery::type(EfacturaUpload::class))
            ->andReturn(true);

        $this->app->instance(DownloadService::class, $downloadService);

        $this->artisan('efactura:status', ['--upload' => $upload->id])
            ->expectsOutput("Upload #{$upload->id} was not polled: it has no ANAF upload index yet.")
            ->expectsOutput('Downloading response...')
            ->doesntExpectOutput('Done.')
            ->assertExitCode(0);
    });

    it('checks all statuses when no upload specified', function () {
        $downloadService = Mockery::mock(DownloadService::class);
        $downloadService->shouldReceive('checkAllStatuses')->once()->with(isTestToken())->andReturn(true);
        $downloadService->shouldReceive('downloadAllResponses')->once()->with(isTestToken())->andReturn(true);

        $this->app->instance(DownloadService::class, $downloadService);

        $this->artisan('efactura:status')
            ->expectsOutput('Checking all upload statuses...')
            ->expectsOutput('Done.')
            ->assertExitCode(0);
    });

    it('filters by CUI when specified', function () {
        EfacturaToken::create([
            'cui' => '87654321',
            'access_token' => 'other',
            'refresh_token' => 'other',
            'expires_at' => now()->addHour(),
            'is_active' => true,
        ]);

        $downloadService = Mockery::mock(DownloadService::class);
        $downloadService->shouldReceive('checkAllStatuses')->once()->with(isTestToken())->andReturn(true);
        $downloadService->shouldReceive('downloadAllResponses')->once()->with(isTestToken())->andReturn(true);

        $this->app->instance(DownloadService::class, $downloadService);

        $this->artisan('efactura:status', ['--cui' => '12345678'])
            ->assertExitCode(0);
    });

    /**
     * PostgreSQL refuses a non-numeric bigint and MySQL cuts it to its leading digits, and a
     * bare or '0' --upload used to fall through to the run over every company.
     */
    it('says an --upload that names no upload of the company is not found', function (Closure $options) {
        $upload = EfacturaUpload::create([
            'efactura_token_id' => $this->token->id,
            'uploadable_type' => 'App\\Models\\Invoice',
            'uploadable_id' => 1,
            'status' => UploadStatus::Processing,
            'upload_index' => '5001130255',
            'standard' => 'UBL',
        ]);
        EfacturaToken::create([
            'cui' => '87654321',
            'access_token' => 'other',
            'refresh_token' => 'other',
            'expires_at' => now()->addHour(),
            'is_active' => true,
        ]);
        Http::fake();

        $downloadService = Mockery::mock(DownloadService::class);
        $downloadService->shouldNotReceive('checkStatus', 'checkAllStatuses', 'downloadAllResponses');
        $this->app->instance(DownloadService::class, $downloadService);

        $this->artisan('efactura:status', $options($upload))
            ->expectsOutputToContain('not found')
            ->assertExitCode(1);

        Http::assertNothingSent();
    })->with([
        'bare' => fn () => ['--upload' => null],
        'blank' => fn () => ['--upload' => ''],
        'zero' => fn () => ['--upload' => '0'],
        'not a number' => fn () => ['--upload' => 'abc'],
        'a decimal' => fn (EfacturaUpload $upload) => ['--upload' => "{$upload->id}.0"],
        'over a bigint' => fn () => ['--upload' => '1234567890123456789'],
        "another company's" => fn (EfacturaUpload $upload) => ['--upload' => (string) $upload->id, '--cui' => '87654321'],
    ]);
});

describe('EfacturaSyncCommand', function () {
    it('fails when sync feature is disabled', function () {
        config(['efactura.enabled' => true, 'efactura.features.sync_messages' => false]);

        $this->artisan('efactura:sync')
            ->expectsOutput('e-Factura sync feature is disabled.')
            ->assertExitCode(1);
    });

    it('syncs every active company when no CUI specified', function () {
        $messageSyncService = Mockery::mock(MessageSyncService::class);
        $messageSyncService->shouldReceive('syncMessages')->once()->with(isTestToken())->andReturn(2);

        $this->app->instance(MessageSyncService::class, $messageSyncService);
        $this->app->instance(DownloadService::class, Mockery::mock(DownloadService::class));

        $this->artisan('efactura:sync')
            ->expectsOutput('Syncing messages from ANAF...')
            ->expectsOutput('Synced 2 message(s).')
            ->expectsOutput('Done.')
            ->assertExitCode(0);
    });

    it('syncs messages for specific CUI', function () {
        $messageSyncService = Mockery::mock(MessageSyncService::class);
        $messageSyncService->shouldReceive('syncMessages')->once()->with(isTestToken())->andReturn(0);

        $this->app->instance(MessageSyncService::class, $messageSyncService);
        $this->app->instance(DownloadService::class, Mockery::mock(DownloadService::class));

        $this->artisan('efactura:sync', ['--cui' => 'RO12345678'])
            ->assertExitCode(0);
    });

    it('fails when CUI token not found', function () {
        $messageSyncService = Mockery::mock(MessageSyncService::class);
        $messageSyncService->shouldNotReceive('syncMessages');

        $this->app->instance(MessageSyncService::class, $messageSyncService);
        $this->app->instance(DownloadService::class, Mockery::mock(DownloadService::class));

        $this->artisan('efactura:sync', ['--cui' => '99999999'])
            ->expectsOutput('No active token found for CUI: 99999999')
            ->assertExitCode(1);
    });

    it('downloads invoices when --download flag is set', function () {
        $messageSyncService = Mockery::mock(MessageSyncService::class);
        $messageSyncService->shouldReceive('syncMessages')->once()->andReturn(0);

        $downloadService = Mockery::mock(DownloadService::class);
        $downloadService->shouldReceive('downloadAllReceivedInvoices')->once()->with(isTestToken())->andReturn(true);

        $this->app->instance(MessageSyncService::class, $messageSyncService);
        $this->app->instance(DownloadService::class, $downloadService);

        $this->artisan('efactura:sync', ['--download' => true])
            ->expectsOutput('Downloading received invoices...')
            ->assertExitCode(0);
    });

    it('says --download was skipped while features.download_received is off', function () {
        config(['efactura.features.download_received' => false]);

        $messageSyncService = Mockery::mock(MessageSyncService::class);
        $messageSyncService->shouldReceive('syncMessages')->once()->andReturn(0);

        $downloadService = Mockery::mock(DownloadService::class);
        $downloadService->shouldNotReceive('downloadAllReceivedInvoices');

        $this->app->instance(MessageSyncService::class, $messageSyncService);
        $this->app->instance(DownloadService::class, $downloadService);

        $this->artisan('efactura:sync', ['--download' => true])
            ->expectsOutput('Skipped --download: features.download_received is disabled.')
            ->assertExitCode(0);
    });
});

/**
 * A blank, bare or malformed --cui used to mean "every company", and an unknown one ran for
 * no company at all, both with exit 0.
 */
it('refuses a --cui that names no active company', function (string $command, array $options, string $error) {
    EfacturaToken::create([
        'cui' => '87654321',
        'access_token' => 'inactive',
        'refresh_token' => 'inactive',
        'expires_at' => now()->addHour(),
        'is_active' => false,
    ]);
    EfacturaUpload::create([
        'efactura_token_id' => $this->token->id,
        'uploadable_type' => 'App\\Models\\Invoice',
        'uploadable_id' => 1,
        'status' => UploadStatus::Pending,
    ]);
    Http::fake();
    Queue::fake();

    $this->artisan($command, $options)
        ->expectsOutput($error)
        ->assertExitCode(1);

    Http::assertNothingSent();
    Queue::assertNothingPushed();
})->with(['efactura:status', 'efactura:sync', 'efactura:upload'])->with([
    'bare' => [['--cui' => null], "Invalid CUI: ''."],
    'blank' => [['--cui' => ''], "Invalid CUI: ''."],
    'prefix only' => [['--cui' => 'RO'], "Invalid CUI: 'RO'."],
    'zero' => [['--cui' => '0'], "Invalid CUI: '0'."],
    'leading zero' => [['--cui' => '0123'], "Invalid CUI: '0123'."],
    'unknown' => [['--cui' => '14399840'], 'No active token found for CUI: 14399840'],
    'deactivated' => [['--cui' => 'RO87654321'], 'No active token found for CUI: 87654321'],
]);

describe('EfacturaAuthCommand', function () {
    beforeEach(function () {
        $this->token->update(['cui' => '12345674']);
    });

    it('shows existing token info and asks for confirmation', function () {
        $tokenService = Mockery::mock(TokenService::class);
        $this->app->instance(TokenService::class, $tokenService);

        $this->artisan('efactura:auth', ['cui' => '12345674'])
            ->expectsOutput('A token already exists for CUI: 12345674')
            ->expectsOutput('Status: Active')
            ->expectsConfirmation('Do you want to generate a new authorization URL anyway?', 'no')
            ->assertExitCode(0);
    });

    it('generates no URL over an existing token without an interactive confirmation', function () {
        $tokenService = Mockery::mock(TokenService::class);
        $tokenService->shouldNotReceive('getAuthorizationUrl');
        $this->app->instance(TokenService::class, $tokenService);

        $this->artisan('efactura:auth', ['cui' => '12345674', '--no-interaction' => true])
            ->expectsOutput('No URL generated: a token already exists for this CUI; rerun interactively to confirm.')
            ->assertExitCode(1);
    });

    it('generates URL for new CUI', function () {
        $tokenService = Mockery::mock(TokenService::class);
        $tokenService->shouldReceive('getAuthorizationUrl')
            ->with('14399840')
            ->once()
            ->andReturn('https://auth.example.com/oauth');

        $this->app->instance(TokenService::class, $tokenService);

        $this->artisan('efactura:auth', ['cui' => '14399840'])
            ->expectsOutput('Authorization URL for CUI: 14399840')
            ->expectsOutput('https://auth.example.com/oauth')
            ->assertExitCode(0);
    });

    it('reads the CUI without its RO prefix and spaces', function () {
        $tokenService = Mockery::mock(TokenService::class);
        $tokenService->shouldReceive('getAuthorizationUrl')
            ->with('10000008')
            ->once()
            ->andReturn('https://auth.example.com');

        $this->app->instance(TokenService::class, $tokenService);

        $this->artisan('efactura:auth', ['cui' => 'RO 1000 0008'])
            ->assertExitCode(0);
    });

    it('refuses a CUI whose control digit is wrong', function () {
        $tokenService = Mockery::mock(TokenService::class);
        $tokenService->shouldNotReceive('getAuthorizationUrl');
        $this->app->instance(TokenService::class, $tokenService);

        $this->artisan('efactura:auth', ['cui' => '12345678'])
            ->expectsOutput('Invalid CUI format provided.')
            ->assertExitCode(1);
    });
});

it('refuses efactura:auth and efactura:reconcile while e-Factura is disabled', function (string $command) {
    config(['efactura.enabled' => false]);

    $this->artisan($command)
        ->expectsOutput('e-Factura is disabled.')
        ->assertExitCode(1);
})->with(['efactura:auth 12345678', 'efactura:reconcile']);
