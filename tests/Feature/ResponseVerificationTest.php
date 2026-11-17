<?php

declare(strict_types=1);

use BeeCoded\EFactura\Enums\ResponseVerification;
use BeeCoded\EFactura\Enums\UploadStatus;
use BeeCoded\EFactura\Events\InvoiceReceived;
use BeeCoded\EFactura\Models\EfacturaMessage;
use BeeCoded\EFactura\Models\EfacturaToken;
use BeeCoded\EFactura\Models\EfacturaUpload;
use BeeCoded\EFactura\Services\DownloadService;
use BeeCoded\EFacturaSdk\Support\ArchiveInspector;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

const VERIFY_DOWNLOAD_URL = 'https://api.anaf.ro/test/FCTEL/rest/descarcare*';
const VERIFY_INVOICE = '<Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2" xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2"><cbc:ID>INV-1</cbc:ID></Invoice>';
const VERIFY_SIGNATURE = '<Signature xmlns="http://www.w3.org/2000/09/xmldsig#"/>';

beforeEach(function () {
    config(['efactura-sdk.http.retry_times' => 1, 'efactura-sdk.http.retry_delay' => 0]);
    Storage::fake('local');
    Storage::disk('local')->put('efactura-test/INV-1.xml', VERIFY_INVOICE);

    $this->token = $token = EfacturaToken::create(['cui' => '12345678', 'access_token' => 'ACCESS', 'refresh_token' => 'REFRESH', 'expires_at' => now()->addHour()]);
    $this->upload = fn (UploadStatus $status, ?string $xmlPath = 'efactura-test/INV-1.xml') => EfacturaUpload::create([
        'efactura_token_id' => $token->id,
        'uploadable_type' => 'App\\Models\\Invoice',
        'uploadable_id' => EfacturaUpload::count() + 1,
        'status' => $status,
        'download_id' => (string) (3001130059 + EfacturaUpload::count()),
        'xml_path' => $xmlPath,
        'standard' => 'UBL',
    ]);
});

it('stores a response archive with how it was checked, and keeps a mismatching one', function (UploadStatus $status, ?string $xmlPath, array $members, ResponseVerification $expected) {
    Http::fake([VERIFY_DOWNLOAD_URL => Http::response(responseZip($members), 200, ['Content-Type' => 'application/zip'])]);
    $upload = ($this->upload)($status, $xmlPath);

    app(DownloadService::class)->downloadResponse($upload);

    $upload->refresh();
    // response_attempts counts tries made: this one.
    expect($upload->response_verification)->toBe($expected)
        ->and($upload->response_path)->not->toBeNull()
        ->and($upload->response_attempts)->toBe(1);
})->with([
    'accepted, same invoice' => [UploadStatus::Completed, 'efactura-test/INV-1.xml', ['1.xml' => VERIFY_INVOICE, 'semnatura_1.xml' => VERIFY_SIGNATURE], ResponseVerification::Verified],
    'accepted, another invoice' => [UploadStatus::Completed, 'efactura-test/INV-1.xml', ['1.xml' => str_replace('INV-1', 'INV-2', VERIFY_INVOICE)], ResponseVerification::Mismatch],
    'accepted, uploaded XML not stored' => [UploadStatus::Completed, null, ['1.xml' => 'anything'], ResponseVerification::LimitsOnly],
    'rejected: an error report' => [UploadStatus::Failed, 'efactura-test/INV-1.xml', ['raport.xml' => '<Raport/>'], ResponseVerification::LimitsOnly],
]);

/**
 * A disk with `throw => true` (common for S3) throws on a missing file instead of returning
 * null, which used to leave the response unstored and downloaded again on every run.
 */
it('checks only the limits when the uploaded XML is gone from a disk that throws', function () {
    Storage::fake('local', ['throw' => true]);
    Http::fake([VERIFY_DOWNLOAD_URL => Http::response(responseZip(['1.xml' => VERIFY_INVOICE]), 200, ['Content-Type' => 'application/zip'])]);
    $upload = ($this->upload)(UploadStatus::Completed, 'efactura-test/removed.xml');

    app(DownloadService::class)->downloadResponse($upload);

    $upload->refresh();
    expect([$upload->response_verification, $upload->response_attempts])->toBe([ResponseVerification::LimitsOnly, 1])
        ->and($upload->response_path)->not->toBeNull();
});

/** A non-throwing disk (S3's default) answers null for a stored file it cannot read right now. */
it('retries without recording a failure, instead of checking only the limits, when the stored XML cannot be read', function () {
    $disk = Mockery::mock(Storage::disk('local'))->makePartial();
    $disk->shouldReceive('get')->with('efactura-test/INV-1.xml')->andReturn(null);
    Storage::set('local', $disk);
    Http::fake([VERIFY_DOWNLOAD_URL => Http::response(responseZip(['1.xml' => VERIFY_INVOICE]), 200, ['Content-Type' => 'application/zip'])]);
    $upload = ($this->upload)(UploadStatus::Completed);

    app(DownloadService::class)->downloadResponse($upload);

    $upload->refresh();
    expect([$upload->response_verification, $upload->response_path, $upload->response_failed_at])->toBe([null, null, null]);
});

it('records an archive outside the limits as failed, but not a local temp-file failure', function () {
    // Starts with "PK", so it passes the SDK's download guard, but is no ZIP.
    Http::fake([VERIFY_DOWNLOAD_URL => Http::response("PK\x03\x04not a zip", 200, ['Content-Type' => 'application/zip'])]);
    $unopenable = ($this->upload)(UploadStatus::Completed);
    app(DownloadService::class)->downloadResponse($unopenable);

    $this->app->instance(ArchiveInspector::class, new ArchiveInspector(sys_get_temp_dir().'/efactura-missing-'.uniqid()));
    $this->app->forgetInstance(DownloadService::class);
    $local = ($this->upload)(UploadStatus::Completed);
    app(DownloadService::class)->downloadResponse($local);

    // Both tries were made; only ANAF's archive is recorded as failed.
    expect([$unopenable->fresh()->response_attempts, $unopenable->fresh()->response_path])->toBe([1, null])
        ->and($unopenable->fresh()->response_failed_at)->not->toBeNull()
        ->and([$local->fresh()->response_attempts, $local->fresh()->response_path, $local->fresh()->response_failed_at])->toBe([1, null, null]);
});

it('stores neither a received archive outside the limits nor one it could not check', function () {
    Event::fake([InvoiceReceived::class]);
    Http::fake([VERIFY_DOWNLOAD_URL => Http::response("PK\x03\x04not a zip", 200, ['Content-Type' => 'application/zip'])]);
    $received = fn (string $id) => EfacturaMessage::create([
        'efactura_token_id' => $this->token->id,
        'message_id' => $id,
        'download_id' => $id,
        'type' => 'received',
    ]);

    $unopenable = $received('4001130001');
    app(DownloadService::class)->downloadMessage($unopenable);

    $this->app->instance(ArchiveInspector::class, new ArchiveInspector(sys_get_temp_dir().'/efactura-missing-'.uniqid()));
    $this->app->forgetInstance(DownloadService::class);
    $local = $received('4001130002');
    app(DownloadService::class)->downloadMessage($local);

    expect([$unopenable->fresh()->download_attempts, $unopenable->fresh()->is_downloaded, $unopenable->fresh()->xml_path])->toBe([1, false, null])
        ->and([$local->fresh()->download_attempts, $local->fresh()->is_downloaded, $local->fresh()->xml_path])->toBe([1, false, null]);
    Event::assertNotDispatched(InvoiceReceived::class);
});
