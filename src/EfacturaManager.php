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

    public function client(string $cui): EFacturaClient
    {
        return $this->tokenService->createClientForCui($cui);
    }

    // Upload Operations

    public function queueUpload(EFacturaUploadableInterface $model, ?array $options = null): EfacturaUpload
    {
        return $this->uploadService->queueUpload($model, $options);
    }

    public function queueB2CUpload(EFacturaUploadableInterface $model): EfacturaUpload
    {
        return $this->uploadService->queueB2CUpload($model);
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
