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

use BeeCoded\EFactura\Enums\FailureReason;
use BeeCoded\EFactura\Enums\UploadStatus;
use BeeCoded\EFactura\Events\InvoiceFailed;
use BeeCoded\EFactura\Events\InvoiceProcessed;
use BeeCoded\EFactura\Events\InvoiceReceived;
use BeeCoded\EFactura\Models\EfacturaMessage;
use BeeCoded\EFactura\Models\EfacturaUpload;
use BeeCoded\EFacturaSdk\Data\Response\StatusResponseData;
use BeeCoded\EFacturaSdk\Exceptions\ApiException;
use BeeCoded\EFacturaSdk\Exceptions\RateLimitExceededException;
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
     *
     * @throws ApiException|RateLimitExceededException When ANAF's quota is exhausted,
     *                                                 so the caller stops the batch
     *                                                 instead of walking the backlog.
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

                return;
            }

            if ($response->isFailed()) {
                $errors = $response->errors ?? [__('efactura::messages.processing_failed_at_anaf')];
                // ANAF processed the document and rejected it on its merits: terminal.
                $this->uploadService->markUploadAsFailed(
                    $upload,
                    $errors,
                    $response->idDescarcare,
                    FailureReason::Validation,
                );
                event(new InvoiceFailed($upload, $response->errors ?? []));

                return;
            }

            if ($response->isInProgress()) {
                // The ONLY legitimate no-op: ANAF said, in so many words, "not yet".
                return;
            }

            $this->parkUnrecognisableStatus($upload, $response);
        } catch (RateLimitExceededException $e) {
            // The SDK's client-side pre-flight limiter refused to make the call, so
            // nothing was sent — and nothing will be for the rest of this batch either.
            $this->stopBatchForRateLimit($upload, $e);
        } catch (ApiException $e) {
            // A genuine ANAF 429 arrives HERE, not as RateLimitExceededException: the
            // SDK's isRetryable() deliberately excludes 429, so it throws a plain
            // ApiException(429). Swallowing it made a rate-limited run walk the ENTIRE
            // backlog, throwing and swallowing once per upload, burning nothing but
            // time and confirming the limit over and over.
            if ($e->statusCode === 429) {
                $this->stopBatchForRateLimit($upload, $e);
            }

            Log::error('EFactura status check failed', [
                'upload_id' => $upload->id,
                'status_code' => $e->statusCode,
                'error' => $e->getMessage(),
            ]);
        } catch (\Throwable $e) {
            // Anything else stays isolated to this row: one broken upload must not stop
            // the others, and the next scheduled run retries it.
            Log::error('EFactura status check failed', [
                'upload_id' => $upload->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Abandon the current batch because ANAF's quota is exhausted.
     *
     * Continuing is pointless — every remaining upload would hit the same wall — and
     * the next scheduled run picks the backlog up anyway.
     *
     * @throws ApiException|RateLimitExceededException Always.
     */
    protected function stopBatchForRateLimit(EfacturaUpload $upload, \Throwable $e): never
    {
        Log::warning('EFactura: rate limited by ANAF, stopping this batch', [
            'upload_id' => $upload->id,
            'error' => $e->getMessage(),
        ]);

        throw $e;
    }

    /**
     * Park an upload whose ANAF status we cannot read.
     *
     * ANAF answers an unknown index_incarcare with HTTP 200 + {"eroare": ...}, which
     * parses into a StatusResponseData with `stare = null`. isReady() and isFailed()
     * are then BOTH false, so the old code fell through to "still in progress, do
     * nothing" and polled this upload every 10 minutes FOREVER — logging nothing,
     * because nothing ever threw. SweepStaleUploads does not rescue it either: that
     * sweeps Uploading, and this row is Processing.
     *
     * Indeterminate is the honest verdict, not a guess: ANAF issued the index at
     * upload time, so the document DID reach ANAF, but ANAF now tells us nothing
     * recognisable about it. Whether it was filed is genuinely unknown — which is why
     * this reason is never auto-resubmitted (that could double-file a legal invoice)
     * and instead routes to `efactura:reconcile` for a human.
     */
    protected function parkUnrecognisableStatus(EfacturaUpload $upload, StatusResponseData $response): void
    {
        $error = sprintf(
            'ANAF returned no recognisable status (stare) for upload index %s; the upload state cannot be determined.',
            $upload->upload_index,
        );

        Log::error('EFactura: unrecognisable ANAF status, parking upload for reconciliation', [
            'upload_id' => $upload->id,
            'upload_index' => $upload->upload_index,
            'anaf_errors' => $response->errors,
        ]);

        $this->uploadService->markUploadAsFailed(
            $upload,
            [$error],
            $response->idDescarcare,
            FailureReason::Indeterminate,
        );

        event(new InvoiceFailed($upload, [$error]));
    }

    /**
     * Download response for a completed upload.
     *
     * @throws ApiException|RateLimitExceededException When ANAF's quota is exhausted.
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

            // Only fire InvoiceProcessed for successful uploads, not error report downloads
            if ($upload->status === UploadStatus::Completed) {
                event(new InvoiceProcessed($upload));
            }

        } catch (RateLimitExceededException $e) {
            $this->stopBatchForRateLimit($upload, $e);
        } catch (ApiException $e) {
            if ($e->statusCode === 429) {
                $this->stopBatchForRateLimit($upload, $e);
            }

            if ($this->isPoisonedBody($e)) {
                $this->recordPoisonedResponse($upload, $e);

                return;
            }

            Log::error('EFactura response download failed', [
                'upload_id' => $upload->id,
                'status_code' => $e->statusCode,
                'error' => $e->getMessage(),
            ]);
        } catch (\Throwable $e) {
            Log::error('EFactura response download failed', [
                'upload_id' => $upload->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Whether an ApiException came from the SDK's 2xx download-body guard.
     *
     * guardDownloadBody() rejects a "successful" /descarcare body that is not a ZIP —
     * a JSON {"eroare": ...} or an HTML maintenance page — and rethrows carrying the
     * response's own status. A 2xx on an ApiException is therefore the SDK saying
     * "ANAF answered OK and handed us something that is not a document", which is a
     * different animal from a transport or auth failure: it is usually PERMANENT for
     * this download id, and no amount of retrying will turn it into a ZIP.
     */
    protected function isPoisonedBody(ApiException $e): bool
    {
        return $e->statusCode >= 200 && $e->statusCode < 300;
    }

    /**
     * Record a response ANAF will never hand over.
     *
     * Left unrecorded, this row is re-selected by needsResponseDownload() (status
     * Completed/Failed + download_id + no response_path) on EVERY scheduled
     * DownloadResponses run, re-downloading the same poison forever — and, before
     * this, saying nothing about it at all.
     *
     * The upload itself stays Completed on purpose: ANAF accepted the INVOICE, and
     * only the response artefact is unavailable. Demoting a filed invoice to Failed
     * because its receipt is missing would be a far worse lie than the missing receipt.
     */
    protected function recordPoisonedResponse(EfacturaUpload $upload, ApiException $e): void
    {
        Log::error('EFactura: ANAF returned a non-ZIP body for a response download', [
            'upload_id' => $upload->id,
            'download_id' => $upload->download_id,
            'status_code' => $e->statusCode,
            'error' => $e->getMessage(),
            'body_sample' => $e->details,
        ]);

        $upload->update([
            'errors' => array_values(array_unique(array_merge(
                $upload->errors ?? [],
                [$e->getMessage()],
            ))),
            // Count this attempt so needsResponseDownload() eventually stops
            // selecting the row instead of re-hitting ANAF forever. `now()`
            // records the most recent failure for the reconcile view.
            'response_attempts' => $upload->response_attempts + 1,
            'response_failed_at' => now(),
        ]);
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

        } catch (RateLimitExceededException $e) {
            // Same reasoning as checkStatus(): once ANAF has said "no more", walking the
            // rest of the backlog only re-confirms it.
            Log::warning('EFactura: rate limited by ANAF, stopping this batch', [
                'message_id' => $message->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        } catch (ApiException $e) {
            if ($e->statusCode === 429) {
                Log::warning('EFactura: rate limited by ANAF, stopping this batch', [
                    'message_id' => $message->id,
                    'error' => $e->getMessage(),
                ]);

                throw $e;
            }

            Log::error('EFactura message download failed', [
                'message_id' => $message->id,
                'status_code' => $e->statusCode,
                'error' => $e->getMessage(),
            ]);
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
