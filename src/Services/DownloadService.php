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
use BeeCoded\EFactura\Enums\ResponseVerification;
use BeeCoded\EFactura\Enums\StatusCheckOutcome;
use BeeCoded\EFactura\Enums\UploadStatus;
use BeeCoded\EFactura\Events\InvoiceFailed;
use BeeCoded\EFactura\Events\InvoiceProcessed;
use BeeCoded\EFactura\Events\InvoiceReceived;
use BeeCoded\EFactura\Exceptions\PipelineTablesDisabledException;
use BeeCoded\EFactura\Models\EfacturaMessage;
use BeeCoded\EFactura\Models\EfacturaToken;
use BeeCoded\EFactura\Models\EfacturaUpload;
use BeeCoded\EFacturaSdk\Data\Response\StatusResponseData;
use BeeCoded\EFacturaSdk\Enums\ArchiveLimitReason;
use BeeCoded\EFacturaSdk\Enums\RateLimitScope;
use BeeCoded\EFacturaSdk\Enums\StatusVerdict;
use BeeCoded\EFacturaSdk\Exceptions\ApiException;
use BeeCoded\EFacturaSdk\Exceptions\ArchiveLimitException;
use BeeCoded\EFacturaSdk\Exceptions\ArchiveMismatchException;
use BeeCoded\EFacturaSdk\Exceptions\AuthenticationException;
use BeeCoded\EFacturaSdk\Exceptions\RateLimitExceededException;
use BeeCoded\EFacturaSdk\Support\ArchiveInspector;
use BeeCoded\EFacturaSdk\Support\PollSchedule;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class DownloadService
{
    /** Taken off each PollSchedule interval so a read stays due on the scheduler tick it aims at. */
    private const POLL_MARGIN_SECONDS = 60;

    public function __construct(
        protected TokenService $tokenService,
        protected UploadService $uploadService,
        protected ArchiveInspector $archiveInspector = new ArchiveInspector,
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
        PipelineTablesDisabledException::throwIfDisabled();

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
            // Guarded like recordVerdict(): a row sent again meanwhile keeps its own count.
            if ($upload->status_failures > 0) {
                EfacturaUpload::whereKey($upload->id)
                    ->where('upload_index', $upload->upload_index)
                    ->update(['status_failures' => 0]);
            }

            // The SDK's reading of the answer: Ready and Rejected need a download id of ANAF's
            // digits; any other id cannot be downloaded, so it counts as none.
            $verdict = $response->verdict();

            if ($verdict === StatusVerdict::Ready || $verdict === StatusVerdict::ReadyWithoutDownloadId) {
                $downloadId = $verdict === StatusVerdict::Ready ? $response->idDescarcare : null;

                if ($downloadId === null) {
                    Log::warning('EFactura: ANAF reported an upload as ok without a download id', [
                        'upload_id' => $upload->id,
                        'upload_index' => $upload->upload_index,
                        'id_descarcare' => $response->idDescarcare,
                    ]);
                }

                // ANAF can answer `ok` without a download id. The invoice is still filed, so it
                // is completed (demoting it would be the worse lie), with a note that no
                // response can be downloaded. Guarded; see recordVerdict().
                $this->recordVerdict($upload, [
                    'status' => UploadStatus::Completed,
                    'download_id' => $downloadId,
                    'processed_at' => now(),
                    ...($downloadId === null ? ['errors' => [__('efactura::messages.completed_without_download_id')]] : []),
                ]);

                return StatusCheckOutcome::Checked;
            }

            // A nok without a download id is no verdict on the document either.
            if ($verdict === StatusVerdict::RejectedWithoutDownloadId) {
                $this->parkAsIndeterminate($upload, [
                    __('efactura::messages.status_nok_without_download_id', ['index' => $upload->upload_index]),
                    ...array_values($response->errors ?? []),
                ]);

                return StatusCheckOutcome::Checked;
            }

            if ($verdict === StatusVerdict::Rejected) {
                $errors = $response->errors ?? [__('efactura::messages.processing_failed_at_anaf')];
                // ANAF processed the document and rejected it on its merits: terminal.
                if ($this->recordFailure($upload, $errors, $response->idDescarcare, FailureReason::Validation)) {
                    rescue(fn () => event(new InvoiceFailed($upload, $errors)));
                }

                return StatusCheckOutcome::Checked;
            }

            // NotProcessed: ANAF did not take the file in — final, no download id.
            if ($verdict === StatusVerdict::NotProcessed) {
                $errors = $response->errors ?: [__('efactura::messages.not_processed_at_anaf')];
                // Validation is not retryable: the document is never re-submitted automatically.
                if ($this->recordFailure($upload, $errors, null, FailureReason::Validation)) {
                    rescue(fn () => event(new InvoiceFailed($upload, $errors)));
                }

                return StatusCheckOutcome::Checked;
            }

            if ($verdict === StatusVerdict::InProgress) {
                // ANAF said, in so many words, "not yet": read it again on the SDK's schedule.
                // An upload without uploaded_at is treated as old (the slowest cadence). A minute's
                // margin keeps a read due on the scheduled run it aims at, not the one after.
                $age = $upload->uploaded_at ? (int) $upload->uploaded_at->diffInSeconds(now(), true) : 10800;
                EfacturaUpload::whereKey($upload->id)
                    ->where('status', UploadStatus::Processing)
                    ->where('upload_index', $upload->upload_index)
                    ->update(['next_poll_at' => now()->addSeconds(max(PollSchedule::status($age) - self::POLL_MARGIN_SECONDS, self::POLL_MARGIN_SECONDS))]);

                return StatusCheckOutcome::Checked;
            }

            // An error-only answer (an XML <Errors/> header or a JSON "eroare": an unknown
            // index, "Nu aveti dreptul") is no verdict on the document. Park it, keeping
            // ANAF's words on the row for whoever reconciles it.
            if ($verdict === StatusVerdict::AnafError) {
                $this->parkAsIndeterminate($upload, [
                    __('efactura::messages.status_nok_without_download_id', ['index' => $upload->upload_index]),
                    ...array_values($response->errors ?? []),
                ], $response->idDescarcare);

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
            || ($e instanceof ApiException && ($e->statusCode >= 500 || $e->statusCode === 408));
    }

    /**
     * Count a permanent status-check failure and park the upload once the bound is reached.
     *
     * Indeterminate, not rejected: ANAF issued the index, so the document reached it, but
     * its outcome cannot be read.
     */
    private function countPermanentStatusFailure(EfacturaUpload $upload, \Throwable $e): void
    {
        // Guarded like recordVerdict(): a failure for an old index is not the re-sent row's.
        $counted = EfacturaUpload::whereKey($upload->id)
            ->where('upload_index', $upload->upload_index)
            ->increment('status_failures');

        if ($counted === 0) {
            return;
        }

        $failures = (int) EfacturaUpload::whereKey($upload->id)->value('status_failures');

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
        // Only ANAF's digits can be downloaded; any other id counts as none, as for ok and nok.
        if ($downloadId !== null && preg_match('/\A[0-9]{1,64}\z/', $downloadId) !== 1) {
            $downloadId = null;
        }

        if (!$this->recordFailure($upload, $errors, $downloadId, FailureReason::Indeterminate)) {
            return;
        }

        Log::error('EFactura: parking upload for reconciliation', [
            'upload_id' => $upload->id,
            'upload_index' => $upload->upload_index,
            'errors' => $errors,
        ]);

        rescue(fn () => event(new InvoiceFailed($upload, $errors)));
    }

    /**
     * Record a status verdict only on a row that is still Processing under the index that
     * was asked about.
     *
     * Overlapping status runs can both hold the row; only the one whose write lands
     * records the verdict, and only it announces it.
     *
     * @param  array<string, mixed>  $attributes
     * @return bool Whether this call recorded it.
     */
    private function recordVerdict(EfacturaUpload $upload, array $attributes): bool
    {
        $recorded = EfacturaUpload::where('id', $upload->id)
            ->where('status', UploadStatus::Processing)
            // The answer is for the index this run asked about; a row sent again meanwhile has another.
            ->where('upload_index', $upload->upload_index)
            ->update($attributes) > 0;

        if ($recorded) {
            $upload->refresh();
        }

        return $recorded;
    }

    /**
     * As UploadService::markUploadAsFailed(), guarded; see recordVerdict().
     *
     * @param  array<int, mixed>  $errors
     */
    private function recordFailure(EfacturaUpload $upload, array $errors, ?string $downloadId, FailureReason $reason): bool
    {
        return $this->recordVerdict($upload, [
            'status' => UploadStatus::Failed,
            'failure_reason' => $reason,
            'errors' => $errors,
            'processed_at' => now(),
            ...($downloadId !== null ? ['download_id' => $downloadId] : []),
        ]);
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
        PipelineTablesDisabledException::throwIfDisabled();

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

            return;
        }

        $verification = $this->verifyResponse($upload, $response->content);

        if ($verification === null) {
            return;
        }

        // ANAF delivered: a failure to keep the file is ours, so it is retried by the next
        // run without counting against the download attempts.
        try {
            $path = $this->storeResponse($upload, $response->content);

            // Guarded: an overlapping run may have stored it already, and announced it; and a
            // row re-queued meanwhile (a new or no download id) must not get this attempt's file.
            $stored = EfacturaUpload::where('id', $upload->id)
                ->where('download_id', $upload->download_id)
                ->whereNull('response_path')
                ->update(['response_path' => $path, 'response_verification' => $verification]) > 0;
        } catch (\Throwable $e) {
            Log::error('EFactura: downloaded response could not be stored', [
                'upload_id' => $upload->id,
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        if (!$stored) {
            $this->deleteLostFile($path, EfacturaUpload::whereKey($upload->id)->value('response_path'));

            return;
        }

        $upload->refresh();

        // Only fire InvoiceProcessed for successful uploads, not error report downloads
        if ($upload->status === UploadStatus::Completed) {
            rescue(fn () => event(new InvoiceProcessed($upload)));
        }
    }

    /**
     * Check a downloaded response archive with the SDK's ArchiveInspector: against the
     * uploaded XML when ANAF accepted the upload and that XML is stored, otherwise its
     * limits only.
     *
     * A mismatch keeps the archive as ANAF sent it, marked and logged. An archive outside
     * the limits (not a ZIP, empty, encrypted, too large, too many members) counts as a
     * poisoned response. A local failure (temporary file, reading the uploaded XML) is
     * ours: the next run retries it without counting.
     *
     * @return ResponseVerification|null Null when the archive is not to be stored.
     */
    protected function verifyResponse(EfacturaUpload $upload, string $archive): ?ResponseVerification
    {
        try {
            $disk = Storage::disk(config('efactura.storage.disk', 'local'));
            // exists() first: a disk with `throw => true` throws on a missing file instead of
            // returning null, and a file no longer stored means limits only.
            $uploadedXml = $upload->status === UploadStatus::Completed && $upload->xml_path && $disk->exists($upload->xml_path)
                ? $disk->get($upload->xml_path)
                : null;
            // A stored file a non-throwing disk could not read is a local failure, not "limits only".
            if ($uploadedXml === null && $upload->status === UploadStatus::Completed && $upload->xml_path && $disk->exists($upload->xml_path)) {
                throw new \RuntimeException('The stored upload XML could not be read.');
            }

            $this->archiveInspector->verify($archive, $uploadedXml);

            return $uploadedXml === null ? ResponseVerification::LimitsOnly : ResponseVerification::Verified;
        } catch (ArchiveMismatchException $e) {
            Log::error('EFactura: response archive does not hold the uploaded invoice; kept as received', [
                'upload_id' => $upload->id,
                'download_id' => $upload->download_id,
                'error' => $e->getMessage(),
            ]);

            return ResponseVerification::Mismatch;
        } catch (\Throwable $e) {
            if ($e instanceof ArchiveLimitException && $e->reason !== ArchiveLimitReason::Io) {
                $this->recordPoisonedResponse($upload, $e);

                return null;
            }

            Log::error('EFactura: response archive could not be checked', [
                'upload_id' => $upload->id,
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);

            return null;
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

        // Count this attempt so needsResponseDownload() eventually stops selecting the
        // row instead of re-hitting ANAF forever; increment() so overlapping runs both
        // count. `now()` records the most recent failure for the reconcile view. Only for
        // the download id this run asked for: a row re-queued meanwhile starts at zero.
        EfacturaUpload::whereKey($upload->id)->where('download_id', $upload->download_id)->increment('response_attempts', 1, [
            'errors' => array_values(array_unique(array_merge(
                $upload->errors ?? [],
                [$e->getMessage()],
            ))),
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
        PipelineTablesDisabledException::throwIfDisabled();

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

            return;
        } catch (\Throwable $e) {
            $this->recordMessageDownloadFailure($message, $e, $requested);

            return;
        }

        // Limits only, like a rejection report: a received archive holds no upload of ours.
        try {
            $this->archiveInspector->verify($response->content);
        } catch (\Throwable $e) {
            if ($e instanceof ArchiveLimitException && $e->reason !== ArchiveLimitReason::Io) {
                $this->recordMessageDownloadFailure($message, $e, requested: true);

                return;
            }

            Log::error('EFactura: received archive could not be checked', [
                'message_id' => $message->id,
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        // As for responses: a storage failure is retried without counting an attempt.
        try {
            $path = $this->storeMessageDownload($message, $response->content);
            $marked = $this->markMessageAsDownloaded($message, $path);
        } catch (\Throwable $e) {
            Log::error('EFactura: downloaded message could not be stored', [
                'message_id' => $message->id,
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        // An overlapping run stored it first, and announced it.
        if (!$marked) {
            $this->deleteLostFile($path, EfacturaMessage::whereKey($message->id)->value('xml_path'));

            return;
        }

        rescue(fn () => event(new InvoiceReceived($message)));
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
     * Mark message as downloaded, only if no other run has.
     *
     * @return bool Whether this call marked it.
     */
    public function markMessageAsDownloaded(EfacturaMessage $message, ?string $xmlPath = null): bool
    {
        PipelineTablesDisabledException::throwIfDisabled();

        $marked = EfacturaMessage::whereKey($message->id)
            ->where('is_downloaded', false)
            ->update(['is_downloaded' => true, 'xml_path' => $xmlPath]) > 0;

        if ($marked) {
            $message->refresh();
        }

        return $marked;
    }

    /**
     * Remove the file a run stored but lost the guarded write for.
     *
     * Kept when the winner stored the same name (both runs wrote it in the same second).
     */
    private function deleteLostFile(string $path, ?string $storedPath): void
    {
        if ($path === $storedPath) {
            return;
        }

        rescue(fn () => Storage::disk(config('efactura.storage.disk', 'local'))->delete($path));
    }

    /**
     * Check statuses for all uploads needing status check.
     */
    public function checkAllStatuses(?string $cui = null): void
    {
        PipelineTablesDisabledException::throwIfDisabled();

        $query = EfacturaUpload::needsStatusCheck()->with('token');

        if ($cui) {
            $token = $this->tokenService->getToken($cui);
            if (!$token) {
                Log::warning('EFactura: No token found for CUI in checkAllStatuses', ['cui' => $cui]);

                return;
            }
            $query->forToken($token);
        }

        $coolingDown = [];

        // lazyById, not each(): each() pages by OFFSET, so rows that leave the scope while
        // a page is processed shift the rest below the next offset and are skipped.
        foreach ($query->lazyById() as $upload) {
            if ($this->waitsOutRefreshCooldown($upload->token, $coolingDown, 'EFactura: Token refresh is cooling down, status checks wait')) {
                continue;
            }

            $this->checkStatus($upload);
        }
    }

    /**
     * Download all pending responses.
     */
    public function downloadAllResponses(?string $cui = null): void
    {
        PipelineTablesDisabledException::throwIfDisabled();

        $query = EfacturaUpload::needsResponseDownload()->with('token');

        if ($cui) {
            $token = $this->tokenService->getToken($cui);
            if (!$token) {
                Log::warning('EFactura: No token found for CUI in downloadAllResponses', ['cui' => $cui]);

                return;
            }
            $query->forToken($token);
        }

        $coolingDown = [];

        foreach ($query->lazyById() as $upload) {
            if ($this->waitsOutRefreshCooldown($upload->token, $coolingDown, 'EFactura: Token refresh is cooling down, response downloads wait')) {
                continue;
            }

            $this->downloadResponse($upload);
        }
    }

    /**
     * Download all pending received invoices.
     */
    public function downloadAllReceivedInvoices(?string $cui = null): void
    {
        PipelineTablesDisabledException::throwIfDisabled();

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

        $coolingDown = [];

        foreach ($query->lazyById() as $message) {
            if ($this->waitsOutRefreshCooldown($message->token, $coolingDown, 'EFactura: Token refresh is cooling down, received invoice downloads wait')) {
                continue;
            }

            $this->downloadMessage($message);
        }
    }

    /**
     * Whether the grant is in its refresh cooldown, which would refuse every row; said once per grant.
     *
     * @param  array<int, true>  $warned
     */
    private function waitsOutRefreshCooldown(?EfacturaToken $token, array &$warned, string $message): bool
    {
        if (!$token || ($seconds = $this->tokenService->refreshCooldownSeconds($token)) <= 0) {
            return false;
        }

        if (!isset($warned[$token->id])) {
            $warned[$token->id] = true;
            Log::warning($message, ['cui' => $token->cui, 'retry_in_seconds' => $seconds]);
        }

        return true;
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

        if (!Storage::disk($disk)->put($filename, $content)) {
            throw new \RuntimeException("Could not store the response at {$filename} on disk {$disk}");
        }

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

        if (!Storage::disk($disk)->put($filename, $content)) {
            throw new \RuntimeException("Could not store the received invoice at {$filename} on disk {$disk}");
        }

        return $filename;
    }
}
