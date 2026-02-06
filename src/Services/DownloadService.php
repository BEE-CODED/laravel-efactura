<?php

/*
 * This file is part of the Laravel e-Factura package.
 *
 * (c) BEE-CODED <dev.ttl@beecoded.ro>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace BeeCoded\EFactura\Services;

use BeeCoded\EFactura\Events\InvoiceFailed;
use BeeCoded\EFactura\Events\InvoiceProcessed;
use BeeCoded\EFactura\Events\InvoiceReceived;
use BeeCoded\EFactura\Models\EfacturaMessage;
use BeeCoded\EFactura\Models\EfacturaUpload;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class DownloadService
{
    public function __construct(
        protected TokenService $tokenService,
        protected UploadService $uploadService,
    ) {}

    /**
     * Check status of an upload and update accordingly.
     */
    public function checkStatus(EfacturaUpload $upload): void
    {
        if (!$upload->upload_index || $upload->isTerminal()) {
            return;
        }

        $upload->loadMissing('token');

        if (!$upload->token) {
            Log::error('EFactura: Upload has no associated token', ['upload_id' => $upload->id]);

            return;
        }

        try {
            $response = $this->tokenService->executeWithClient(
                $upload->token,
                fn ($client) => $client->getStatusMessage($upload->upload_index)
            );

            if ($response->isReady()) {
                $this->uploadService->markUploadAsCompleted($upload, $response->idDescarcare);
            } elseif ($response->isFailed()) {
                $this->uploadService->markUploadAsFailed($upload, $response->errors ?? ['Processing failed at ANAF']);
                event(new InvoiceFailed($upload, $response->errors ?? []));
            }
            // If still in progress, do nothing

        } catch (\Throwable $e) {
            Log::error('EFactura status check failed', [
                'upload_id' => $upload->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Download response for a completed upload.
     */
    public function downloadResponse(EfacturaUpload $upload): void
    {
        if (!$upload->download_id || $upload->response_path) {
            return;
        }

        $upload->loadMissing('token');

        if (!$upload->token) {
            Log::error('EFactura: Upload has no associated token', ['upload_id' => $upload->id]);

            return;
        }

        try {
            $response = $this->tokenService->executeWithClient(
                $upload->token,
                fn ($client) => $client->downloadDocument($upload->download_id)
            );

            $path = $this->storeResponse($upload, $response->content);
            $upload->update([
                'response_path' => $path,
            ]);

            event(new InvoiceProcessed($upload));

        } catch (\Throwable $e) {
            Log::error('EFactura response download failed', [
                'upload_id' => $upload->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Download a received message/invoice.
     */
    public function downloadMessage(EfacturaMessage $message): void
    {
        if ($message->is_downloaded || !$message->download_id) {
            return;
        }

        $message->loadMissing('token');

        if (!$message->token) {
            Log::error('EFactura: Message has no associated token', ['message_id' => $message->id]);

            return;
        }

        try {
            $response = $this->tokenService->executeWithClient(
                $message->token,
                fn ($client) => $client->downloadDocument($message->download_id)
            );

            $path = $this->storeMessageDownload($message, $response->content);
            $this->markMessageAsDownloaded($message, $path);

            event(new InvoiceReceived($message));

        } catch (\Throwable $e) {
            Log::error('EFactura message download failed', [
                'message_id' => $message->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Mark message as downloaded.
     */
    public function markMessageAsDownloaded(EfacturaMessage $message, ?string $xmlPath = null): void
    {
        $message->update([
            'is_downloaded' => true,
            'xml_path' => $xmlPath,
        ]);
    }

    /**
     * Check statuses for all uploads needing status check.
     */
    public function checkAllStatuses(?string $cui = null): void
    {
        $query = EfacturaUpload::needsStatusCheck()->with('token');

        if ($cui) {
            $token = $this->tokenService->getToken($cui);
            if (!$token) {
                Log::warning('EFactura: No token found for CUI in checkAllStatuses', ['cui' => $cui]);

                return;
            }
            $query->forToken($token);
        }

        $query->each(fn (EfacturaUpload $upload) => $this->checkStatus($upload));
    }

    /**
     * Download all pending responses.
     */
    public function downloadAllResponses(?string $cui = null): void
    {
        $query = EfacturaUpload::needsResponseDownload()->with('token');

        if ($cui) {
            $token = $this->tokenService->getToken($cui);
            if (!$token) {
                Log::warning('EFactura: No token found for CUI in downloadAllResponses', ['cui' => $cui]);

                return;
            }
            $query->forToken($token);
        }

        $query->each(fn (EfacturaUpload $upload) => $this->downloadResponse($upload));
    }

    /**
     * Download all pending received invoices.
     */
    public function downloadAllReceivedInvoices(?string $cui = null): void
    {
        $query = EfacturaMessage::notDownloaded()->received()->with('token');

        if ($cui) {
            $token = $this->tokenService->getToken($cui);
            if (!$token) {
                Log::warning('EFactura: No token found for CUI in downloadAllReceivedInvoices', ['cui' => $cui]);

                return;
            }
            $query->forToken($token);
        }

        $query->each(fn (EfacturaMessage $message) => $this->downloadMessage($message));
    }

    /**
     * Store response ZIP file.
     */
    protected function storeResponse(EfacturaUpload $upload, string $content): string
    {
        $disk = config('efactura.storage.disk', 'local');
        $basePath = config('efactura.storage.path', 'efactura');

        $filename = sprintf(
            '%s/responses/%s/%s_%s.zip',
            $basePath,
            $upload->token->cui,
            $upload->id,
            now()->format('Ymd_His')
        );

        Storage::disk($disk)->put($filename, $content);

        return $filename;
    }

    /**
     * Store downloaded message file.
     */
    protected function storeMessageDownload(EfacturaMessage $message, string $content): string
    {
        $disk = config('efactura.storage.disk', 'local');
        $basePath = config('efactura.storage.path', 'efactura');

        $filename = sprintf(
            '%s/received/%s/%s_%s.zip',
            $basePath,
            $message->token->cui,
            $message->message_id,
            now()->format('Ymd_His')
        );

        Storage::disk($disk)->put($filename, $content);

        return $filename;
    }
}
