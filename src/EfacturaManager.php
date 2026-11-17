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
use BeeCoded\EFactura\Exceptions\DuplicateUploadException;
use BeeCoded\EFactura\Exceptions\PipelineTablesDisabledException;
use BeeCoded\EFactura\Exceptions\RefreshCooldownException;
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

    /**
     * From a web request, pass its session nonce and user id: see TokenService::getAuthorizationUrl().
     */
    public function getAuthorizationUrl(string $cui, #[\SensitiveParameter] ?string $sessionNonce = null, int|string|null $userId = null): string
    {
        return $this->tokenService->getAuthorizationUrl($cui, $sessionNonce, $userId);
    }

    /**
     * A raw SDK client for this CUI — an escape hatch, not the happy path.
     *
     * The client stores a refresh it performs through TokenService's token
     * persister. ANAF rotates refresh tokens, so if that save fails the new grant
     * lives only in the returned object and the stored one is spent.
     *
     * Prefer TokenService::executeWithClient(), which also retries that save
     * after your operation:
     *
     *     app(TokenService::class)->executeWithClient($token, fn ($client) => ...);
     *
     * If you use this, retry the save afterwards yourself:
     *
     *     $client = EFactura::client($cui);
     *     $result = $client->getMessages(...);
     *     app(TokenService::class)->handleClientTokenRefresh($client, EFactura::getToken($cui));
     *
     * @throws \RuntimeException When the CUI has no active token
     * @throws RefreshCooldownException When the grant is paused (nothing is sent)
     */
    public function client(string $cui): EFacturaClient
    {
        return $this->tokenService->createClientForCui($cui);
    }

    // Upload Operations

    /**
     * @throws PipelineTablesDisabledException
     * @throws \RuntimeException When the CUI has no active token
     * @throws \InvalidArgumentException When $options has a key other than standard, extern or
     *                                   self_billed, or an unknown standard
     * @throws DuplicateUploadException See UploadService::queueUpload()
     */
    public function queueUpload(EFacturaUploadableInterface $model, ?array $options = null): EfacturaUpload
    {
        return $this->uploadService->queueUpload($model, $options);
    }

    /**
     * @throws PipelineTablesDisabledException
     * @throws \RuntimeException When the CUI has no active token
     * @throws \InvalidArgumentException When $options has a key other than standard, extern or
     *                                   self_billed, or an unknown standard
     * @throws DuplicateUploadException See UploadService::queueUpload()
     */
    public function queueB2CUpload(EFacturaUploadableInterface $model, ?array $options = null): EfacturaUpload
    {
        return $this->uploadService->queueB2CUpload($model, $options);
    }

    /**
     * Send the upload now if it is due (see UploadService::processUpload()).
     *
     * @throws PipelineTablesDisabledException
     */
    public function processUpload(EfacturaUpload $upload): void
    {
        $this->uploadService->processUpload($upload);
    }

    // Services access for advanced usage

    public function tokenService(): TokenService
    {
        return $this->tokenService;
    }

    /**
     * @throws PipelineTablesDisabledException
     */
    public function uploadService(): UploadService
    {
        PipelineTablesDisabledException::throwIfDisabled();

        return $this->uploadService;
    }

    /**
     * @throws PipelineTablesDisabledException
     */
    public function downloadService(): DownloadService
    {
        PipelineTablesDisabledException::throwIfDisabled();

        return $this->downloadService;
    }

    /**
     * @throws PipelineTablesDisabledException
     */
    public function messageSyncService(): MessageSyncService
    {
        PipelineTablesDisabledException::throwIfDisabled();

        return $this->messageSyncService;
    }
}
