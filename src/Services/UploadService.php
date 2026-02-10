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

use BeeCoded\EFactura\Contracts\EFacturaUploadableInterface;
use BeeCoded\EFactura\Enums\UploadStatus;
use BeeCoded\EFactura\Events\InvoiceFailed;
use BeeCoded\EFactura\Events\InvoiceUploaded;
use BeeCoded\EFactura\Models\EfacturaUpload;
use BeeCoded\EFacturaSdk\Data\Invoice\UploadOptionsData;
use BeeCoded\EFacturaSdk\Enums\StandardType;
use BeeCoded\EFacturaSdk\Facades\UblBuilder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class UploadService
{
    public function __construct(
        protected TokenService $tokenService,
    ) {}

    /**
     * Queue a model for e-Factura upload.
     */
    public function queueUpload(EFacturaUploadableInterface $model, ?array $options = null): EfacturaUpload
    {
        $cui = $model->getEfacturaCui();
        $token = $this->tokenService->getToken($cui);

        if (!$token) {
            throw new \RuntimeException("No active token found for CUI: {$cui}");
        }

        return EfacturaUpload::create([
            'efactura_token_id' => $token->id,
            'uploadable_type' => get_class($model),
            'uploadable_id' => $model->getKey(),
            'status' => UploadStatus::Pending,
            'standard' => $options['standard'] ?? 'UBL',
            'is_extern' => $options['extern'] ?? false,
            'is_self_billed' => $options['self_billed'] ?? false,
            'is_b2c' => false,
        ]);
    }

    /**
     * Queue a model for B2C e-Factura upload.
     */
    public function queueB2CUpload(EFacturaUploadableInterface $model): EfacturaUpload
    {
        $cui = $model->getEfacturaCui();
        $token = $this->tokenService->getToken($cui);

        if (!$token) {
            throw new \RuntimeException("No active token found for CUI: {$cui}");
        }

        return EfacturaUpload::create([
            'efactura_token_id' => $token->id,
            'uploadable_type' => get_class($model),
            'uploadable_id' => $model->getKey(),
            'status' => UploadStatus::Pending,
            'standard' => 'UBL',
            'is_b2c' => true,
        ]);
    }

    /**
     * Process a single upload.
     */
    public function processUpload(EfacturaUpload $upload): void
    {
        if (!$upload->isPending()) {
            return;
        }

        $upload->loadMissing(['token', 'uploadable']);

        if (!$upload->token) {
            Log::error('EFactura: Upload has no associated token', ['upload_id' => $upload->id]);
            $this->markUploadAsFailed($upload, ['No associated token found for upload']);
            event(new InvoiceFailed($upload, ['No associated token found for upload']));

            return;
        }

        $this->markUploadAsUploading($upload);

        try {
            $model = $upload->uploadable;

            if (!$model instanceof EFacturaUploadableInterface) {
                throw new \RuntimeException('Uploadable model must implement EFacturaUploadableInterface');
            }

            // Generate XML
            $xml = $this->generateXml($model);

            // Store XML
            $xmlPath = $this->storeXml($upload, $xml);
            $upload->update(['xml_path' => $xmlPath]);

            // Upload with concurrent-safe token handling
            $uploadOptions = new UploadOptionsData(
                standard: StandardType::from($upload->standard),
                extern: $upload->is_extern,
                selfBilled: $upload->is_self_billed,
            );

            $response = $this->tokenService->executeWithClient(
                $upload->token,
                function ($client) use ($xml, $uploadOptions, $upload) {
                    if ($upload->is_b2c) {
                        return $client->uploadB2CDocument($xml, $uploadOptions);
                    }

                    return $client->uploadDocument($xml, $uploadOptions);
                }
            );

            if ($response->isSuccessful()) {
                $this->markUploadAsProcessing($upload, $response->indexIncarcare);
                event(new InvoiceUploaded($upload));
            } else {
                $this->markUploadAsFailed($upload, $response->errors ?? ['Unknown error']);
                event(new InvoiceFailed($upload, $response->errors ?? []));
            }

        } catch (\Throwable $e) {
            Log::error('EFactura upload failed', [
                'upload_id' => $upload->id,
                'error' => $e->getMessage(),
            ]);

            $this->markUploadAsFailed($upload, [$e->getMessage()]);
            event(new InvoiceFailed($upload, [$e->getMessage()]));
        }
    }

    /**
     * Mark upload as uploading.
     */
    public function markUploadAsUploading(EfacturaUpload $upload): void
    {
        $upload->update([
            'status' => UploadStatus::Uploading,
        ]);
    }

    /**
     * Mark upload as processing with upload index.
     */
    public function markUploadAsProcessing(EfacturaUpload $upload, string $uploadIndex): void
    {
        $upload->update([
            'status' => UploadStatus::Processing,
            'upload_index' => $uploadIndex,
            'uploaded_at' => now(),
        ]);
    }

    /**
     * Mark upload as completed.
     */
    public function markUploadAsCompleted(EfacturaUpload $upload, string $downloadId): void
    {
        $upload->update([
            'status' => UploadStatus::Completed,
            'download_id' => $downloadId,
            'processed_at' => now(),
        ]);
    }

    /**
     * Mark upload as failed with errors.
     */
    public function markUploadAsFailed(EfacturaUpload $upload, array $errors, ?string $downloadId = null): void
    {
        $data = [
            'status' => UploadStatus::Failed,
            'errors' => $errors,
            'processed_at' => now(),
        ];

        if ($downloadId !== null) {
            $data['download_id'] = $downloadId;
        }

        $upload->update($data);
    }

    /**
     * Process all pending uploads for a CUI (or all CUIs if null).
     */
    public function processPendingUploads(?string $cui = null): void
    {
        $query = EfacturaUpload::pending()->with(['token', 'uploadable']);

        if ($cui) {
            $token = $this->tokenService->getToken($cui);
            if (!$token) {
                Log::warning('EFactura: No token found for CUI in processPendingUploads', ['cui' => $cui]);

                return;
            }
            $query->forToken($token);
        }

        $query->each(fn (EfacturaUpload $upload) => $this->processUpload($upload));
    }

    /**
     * Generate XML from model.
     */
    public function generateXml(EFacturaUploadableInterface $model): string
    {
        $invoiceData = $model->toEfacturaData();

        return UblBuilder::generateInvoiceXml($invoiceData);
    }

    /**
     * Store XML file.
     */
    protected function storeXml(EfacturaUpload $upload, string $xml): string
    {
        $disk = config('efactura.storage.disk', 'local');
        $basePath = config('efactura.storage.path', 'efactura');

        $filename = sprintf(
            '%s/uploads/%s/%s_%s.xml',
            $basePath,
            $upload->token->cui,
            $upload->id,
            now()->format('Ymd_His')
        );

        Storage::disk($disk)->put($filename, $xml);

        return $filename;
    }
}
