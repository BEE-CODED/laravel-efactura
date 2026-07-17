<?php

/*
 * This file is part of the Laravel e-Factura package.
 *
 * (c) BEE-CODED <dev.ttl@beecoded.ro>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace BeeCoded\EFactura;

use BeeCoded\EFactura\Contracts\EFacturaUploadableInterface;
use BeeCoded\EFactura\Models\EfacturaToken;
use BeeCoded\EFactura\Models\EfacturaUpload;
use BeeCoded\EFactura\Services\DownloadService;
use BeeCoded\EFactura\Services\MessageSyncService;
use BeeCoded\EFactura\Services\TokenService;
use BeeCoded\EFactura\Services\UploadService;
use BeeCoded\EFacturaSdk\Services\ApiClients\EFacturaClient;

class EfacturaManager
{
    public function __construct(
        protected TokenService $tokenService,
        protected UploadService $uploadService,
        protected DownloadService $downloadService,
        protected MessageSyncService $messageSyncService,
    ) {}

    // Token Management

    public function getToken(string $cui): ?EfacturaToken
    {
        return $this->tokenService->getToken($cui);
    }

    public function getAuthorizationUrl(string $cui): string
    {
        return $this->tokenService->getAuthorizationUrl($cui);
    }

    /**
     * A raw SDK client for this CUI — an escape hatch, not the happy path.
     *
     * WARNING: nothing persists a refresh that happens on this client. ANAF
     * rotates refresh tokens, so if the SDK refreshes mid-call the new grant
     * lives only in the returned object: the stored token is now spent, and once
     * you discard the client the company must re-authorise from scratch. No
     * concurrency is involved — a token within the SDK's 120s expiry buffer is
     * enough.
     *
     * Prefer TokenService::executeWithClient(), which locks, refreshes and
     * persists around your operation:
     *
     *     app(TokenService::class)->executeWithClient($token, fn ($client) => ...);
     *
     * If you must use this, persist afterwards yourself:
     *
     *     $client = EFactura::client($cui);
     *     $result = $client->getMessages(...);
     *     app(TokenService::class)->handleClientTokenRefresh($client, EFactura::getToken($cui));
     */
    public function client(string $cui): EFacturaClient
    {
        return $this->tokenService->createClientForCui($cui);
    }

    // Upload Operations

    public function queueUpload(EFacturaUploadableInterface $model, ?array $options = null): EfacturaUpload
    {
        return $this->uploadService->queueUpload($model, $options);
    }

    public function queueB2CUpload(EFacturaUploadableInterface $model, ?array $options = null): EfacturaUpload
    {
        return $this->uploadService->queueB2CUpload($model, $options);
    }

    public function processUpload(EfacturaUpload $upload): void
    {
        $this->uploadService->processUpload($upload);
    }

    // Services access for advanced usage

    public function tokenService(): TokenService
    {
        return $this->tokenService;
    }

    public function uploadService(): UploadService
    {
        return $this->uploadService;
    }

    public function downloadService(): DownloadService
    {
        return $this->downloadService;
    }

    public function messageSyncService(): MessageSyncService
    {
        return $this->messageSyncService;
    }
}
