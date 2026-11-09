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
use BeeCoded\EFactura\Enums\StatusCheckOutcome;
use BeeCoded\EFactura\Enums\UploadStatus;
use BeeCoded\EFactura\Events\InvoiceFailed;
use BeeCoded\EFactura\Events\InvoiceProcessed;
use BeeCoded\EFactura\Events\InvoiceReceived;
use BeeCoded\EFactura\Models\EfacturaMessage;
use BeeCoded\EFactura\Models\EfacturaUpload;
use BeeCoded\EFacturaSdk\Data\Response\StatusResponseData;
use BeeCoded\EFacturaSdk\Enums\RateLimitScope;
use BeeCoded\EFacturaSdk\Exceptions\ApiException;
use BeeCoded\EFacturaSdk\Exceptions\AuthenticationException;
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
     * A per-message status limit only skips this upload; the next run polls it again.
     *
     * @return StatusCheckOutcome Whether ANAF was asked and answered, so a caller can tell a
     *                            skipped or failed check from a recorded answer.
     *
     * @throws ApiException|RateLimitExceededException When ANAF's quota, or the SDK's
     *                                                 global one, is exhausted, so the
     *                                                 caller stops the batch instead of
     *                                                 walking the backlog.
     */
    public function checkStatus(EfacturaUpload $upload): StatusCheckOutcome
    {
        if (!$upload->upload_index || $upload->isTerminal()) {
            return StatusCheckOutcome::NotPollable;
        }

        $upload->loadMissing('token');

        if (!$upload->token) {
            Log::error('EFactura: Upload has no associated token', ['upload_id' => $upload->id]);

            return StatusCheckOutcome::NotPollable;
        }

        // Flipped when the SDK client is handed the request; see isTransient().
        $requested = false;

        try {
            $response = $this->tokenService->executeWithClient(
                $upload->token,
                function ($client) use ($upload, &$requested) {
                    $requested = true;

                    return $client->getStatusMessage($upload->upload_index);
                }
            );

            // ANAF answered, so whatever it said, the status is readable again.
            if ($upload->status_failures > 0) {
                $upload->update(['status_failures' => 0]);
            }

            if ($response->isReady()) {
                if ($response->idDescarcare === null) {
                    Log::warning('EFactura: ANAF reported an upload as ok without a download id', [
                        'upload_id' => $upload->id,
                        'upload_index' => $upload->upload_index,
                    ]);
                }

                $this->uploadService->markUploadAsCompleted($upload, $response->idDescarcare);

                return StatusCheckOutcome::Checked;
            }

            if ($response->isFailed()) {
                // The SDK reads a bare <header><Errors/> answer (an unknown index, "Nu aveti
                // dreptul") as nok without a download id: no verdict on the document.
                if ($response->idDescarcare === null) {
                    $this->parkAsIndeterminate($upload, [
                        __('efactura::messages.status_nok_without_download_id', ['index' => $upload->upload_index]),
                        ...array_values($response->errors ?? []),
                    ]);

                    return StatusCheckOutcome::Checked;
                }

                $errors = $response->errors ?? [__('efactura::messages.processing_failed_at_anaf')];
                // ANAF processed the document and rejected it on its merits: terminal.
                $this->uploadService->markUploadAsFailed(
                    $upload,
                    $errors,
                    $response->idDescarcare,
                    FailureReason::Validation,
                );
                rescue(fn () => event(new InvoiceFailed($upload, $response->errors ?? [])));

                return StatusCheckOutcome::Checked;
            }

            // NotProcessed: ANAF did not take the file in — final, no download id.
            if ($response->isNotProcessed()) {
                $errors = $response->errors ?: [__('efactura::messages.not_processed_at_anaf')];
                // Validation is not retryable: the document is never re-submitted automatically.
                $this->uploadService->markUploadAsFailed($upload, $errors, null, FailureReason::Validation);
                rescue(fn () => event(new InvoiceFailed($upload, $errors)));

                return StatusCheckOutcome::Checked;
            }

            if ($response->isInProgress()) {
                // The ONLY legitimate no-op: ANAF said, in so many words, "not yet".
                return StatusCheckOutcome::Checked;
            }

            $this->parkUnrecognisableStatus($upload, $response);

            return StatusCheckOutcome::Checked;
        } catch (RateLimitExceededException $e) {
            // The SDK's client-side pre-flight limiter refused to make the call. Its
            // per-message limit blocks only this upload; any other stops the batch.
            if ($this->skipsOnlyThisMessage($e, ['upload_id' => $upload->id])) {
                return StatusCheckOutcome::RateLimited;
            }

            $this->stopBatchForRateLimit($upload, $e);
        } catch (\Throwable $e) {
            // A genuine ANAF 429 arrives as a plain ApiException(429): the SDK's
            // isRetryable() deliberately excludes 429. Swallowing it made a rate-limited
            // run walk the ENTIRE backlog, confirming the limit once per upload.
            if ($e instanceof ApiException && $e->statusCode === 429) {
                $this->stopBatchForRateLimit($upload, $e);
            }

            // Anything else stays isolated to this row: one broken upload must not stop
            // the others. Transient failures are retried by the next run; permanent ones
            // are counted, so the row cannot be polled forever.
            Log::error('EFactura status check failed', [
                'upload_id' => $upload->id,
                'exception' => $e::class,
                'status_code' => $e instanceof ApiException ? $e->statusCode : null,
                'error' => $e->getMessage(),
            ]);

            if (!$this->isTransient($e, $requested)) {
                $this->countPermanentStatusFailure($upload, $e);
            }

            return StatusCheckOutcome::Failed;
        }
    }

    /**
     * Whether a failure says nothing about this row, so it is retried without counting.
     *
     * That is anything thrown before the SDK was handed the request (reading, decrypting
     * or refreshing the token), a token ANAF refused (it applies to every row of the
     * company and clears with re-authorisation), and ANAF or the network being down: the
     * SDK reports a 5xx as 502 and a transport failure as 500. Everything else (a 4xx, an
     * unreadable 2xx, an id the SDK refuses, any other error) will not fix itself.
     */
    private function isTransient(\Throwable $e, bool $requested): bool
    {
        return !$requested
            || $e instanceof AuthenticationException
            || ($e instanceof ApiException && $e->statusCode >= 500);
    }

    /**
     * Count a permanent status-check failure and park the upload once the bound is reached.
     *
     * Indeterminate, not rejected: ANAF issued the index, so the document reached it, but
     * its outcome cannot be read.
     */
    private function countPermanentStatusFailure(EfacturaUpload $upload, \Throwable $e): void
    {
        $upload->increment('status_failures');

        $failures = $upload->status_failures;

        if ($failures < (int) config('efactura.polling.max_status_failures', 5)) {
            return;
        }

        $this->parkAsIndeterminate($upload, [
            __('efactura::messages.status_poll_failures_exhausted', ['index' => $upload->upload_index, 'count' => $failures]),
            $e->getMessage(),
        ]);
    }

    /**
     * Whether the SDK limit hit covers only this message, so the batch carries on.
     *
     * The status (per upload index) and download (per download id) limits are per
     * message. Global, per-CUI and unscoped limits stop the batch. A skipped row is not
     * a failure: nothing was counted against it, and the next run tries it again.
     *
     * @param  array<string, mixed>  $context
     */
    private function skipsOnlyThisMessage(RateLimitExceededException $e, array $context): bool
    {
        if ($e->scope !== RateLimitScope::Status && $e->scope !== RateLimitScope::Download) {
            return false;
        }

        Log::warning('EFactura: per-message rate limit reached, skipping it in this batch', [
            ...$context,
            'scope' => $e->scope->value,
            'retry_after_seconds' => $e->retryAfterSeconds,
        ]);

        return true;
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

        Log::error('EFactura: unrecognisable ANAF status', [
            'upload_id' => $upload->id,
            'anaf_errors' => $response->errors,
        ]);

        $this->parkAsIndeterminate($upload, [$error], $response->idDescarcare);
    }

    /**
     * Park a Processing upload whose outcome cannot be read, for human reconciliation.
     *
     * Never auto-resubmitted: the document may be filed, and re-sending could double-file it.
     *
     * @param  array<int, mixed>  $errors
     */
    protected function parkAsIndeterminate(EfacturaUpload $upload, array $errors, ?string $downloadId = null): void
    {
        Log::error('EFactura: parking upload for reconciliation', [
            'upload_id' => $upload->id,
            'upload_index' => $upload->upload_index,
            'errors' => $errors,
        ]);

        $this->uploadService->markUploadAsFailed($upload, $errors, $downloadId, FailureReason::Indeterminate);

        rescue(fn () => event(new InvoiceFailed($upload, $errors)));
    }

    /**
     * Download response for a completed upload.
     *
     * A per-message download limit only skips this upload; the next run tries it again.
     *
     * @throws ApiException|RateLimitExceededException When ANAF's quota, or the SDK's
     *                                                 global one, is exhausted.
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

        $requested = false;

        try {
            $response = $this->tokenService->executeWithClient(
                $upload->token,
                function ($client) use ($upload, &$requested) {
                    $requested = true;

                    return $client->downloadDocument($upload->download_id);
                }
            );

            $path = $this->storeResponse($upload, $response->content);
            $upload->update([
                'response_path' => $path,
            ]);

            // Only fire InvoiceProcessed for successful uploads, not error report downloads
            if ($upload->status === UploadStatus::Completed) {
                rescue(fn () => event(new InvoiceProcessed($upload)));
            }

        } catch (RateLimitExceededException $e) {
            if ($this->skipsOnlyThisMessage($e, ['upload_id' => $upload->id])) {
                return;
            }

            $this->stopBatchForRateLimit($upload, $e);
        } catch (\Throwable $e) {
            if ($e instanceof ApiException && $e->statusCode === 429) {
                $this->stopBatchForRateLimit($upload, $e);
            }

            if (!$this->isTransient($e, $requested)) {
                $this->recordPoisonedResponse($upload, $e);

                return;
            }

            Log::error('EFactura response download failed', [
                'upload_id' => $upload->id,
                'exception' => $e::class,
                'status_code' => $e instanceof ApiException ? $e->statusCode : null,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Record a response ANAF will never hand over.
     *
     * Typically the SDK's guardDownloadBody() rejecting a 2xx body that is not a ZIP (a
     * JSON {"eroare": ...} or an HTML page), which it rethrows carrying the 2xx status;
     * also a 4xx, a download id the SDK refuses, or any other permanent failure.
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
    protected function recordPoisonedResponse(EfacturaUpload $upload, \Throwable $e): void
    {
        Log::error('EFactura: response download failed permanently', [
            'upload_id' => $upload->id,
            'download_id' => $upload->download_id,
            'exception' => $e::class,
            'status_code' => $e instanceof ApiException ? $e->statusCode : null,
            'error' => $e->getMessage(),
            'body_sample' => $e instanceof ApiException ? $e->details : null,
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
     *
     * A per-message download limit only skips this message; the next run tries it again.
     *
     * @throws ApiException|RateLimitExceededException When ANAF's quota, or the SDK's
     *                                                 global one, is exhausted.
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

        $requested = false;

        try {
            $response = $this->tokenService->executeWithClient(
                $message->token,
                function ($client) use ($message, &$requested) {
                    $requested = true;

                    return $client->downloadDocument($message->download_id);
                }
            );

            $path = $this->storeMessageDownload($message, $response->content);
            $this->markMessageAsDownloaded($message, $path);

            rescue(fn () => event(new InvoiceReceived($message)));

        } catch (RateLimitExceededException $e) {
            // Same reasoning as checkStatus(): once ANAF has said "no more", walking the
            // rest of the backlog only re-confirms it, unless only this message is blocked.
            if ($this->skipsOnlyThisMessage($e, ['message_id' => $message->id])) {
                return;
            }

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

            $this->recordMessageDownloadFailure($message, $e, $requested);
        } catch (\Throwable $e) {
            $this->recordMessageDownloadFailure($message, $e, $requested);
        }
    }

    /**
     * Log a failed message download and count it when it is permanent.
     *
     * Past EfacturaMessage::MAX_DOWNLOAD_ATTEMPTS the message leaves
     * downloadAllReceivedInvoices(), instead of being re-downloaded on every run.
     */
    private function recordMessageDownloadFailure(EfacturaMessage $message, \Throwable $e, bool $requested): void
    {
        Log::error('EFactura message download failed', [
            'message_id' => $message->id,
            'exception' => $e::class,
            'status_code' => $e instanceof ApiException ? $e->statusCode : null,
            'error' => $e->getMessage(),
        ]);

        if (!$this->isTransient($e, $requested)) {
            $message->increment('download_attempts');
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
        $query = EfacturaMessage::notDownloaded()->received()
            ->where('download_attempts', '<', EfacturaMessage::MAX_DOWNLOAD_ATTEMPTS)
            ->with('token');

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
