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
use BeeCoded\EFactura\Exceptions\StopBatch;
use BeeCoded\EFactura\Models\EfacturaMessage;
use BeeCoded\EFactura\Models\EfacturaToken;
use BeeCoded\EFactura\Models\EfacturaUpload;
use BeeCoded\EFacturaSdk\Data\Response\DownloadResponseData;
use BeeCoded\EFacturaSdk\Data\Response\StatusResponseData;
use BeeCoded\EFacturaSdk\Enums\ArchiveLimitReason;
use BeeCoded\EFacturaSdk\Enums\RateLimitScope;
use BeeCoded\EFacturaSdk\Enums\StatusVerdict;
use BeeCoded\EFacturaSdk\Exceptions\ApiException;
use BeeCoded\EFacturaSdk\Exceptions\ArchiveLimitException;
use BeeCoded\EFacturaSdk\Exceptions\ArchiveMismatchException;
use BeeCoded\EFacturaSdk\Exceptions\RateLimitExceededException;
use BeeCoded\EFacturaSdk\Exceptions\ValidationException;
use BeeCoded\EFacturaSdk\Services\ApiClients\EFacturaClient;
use BeeCoded\EFacturaSdk\Support\ArchiveInspector;
use BeeCoded\EFacturaSdk\Support\PollSchedule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Every ANAF read claims its row first, by writing the row's next try (PollSchedule), so
 * no run reads a row again before that slot, whatever the outcome. Only ANAF refusing the
 * row is recorded against it; a rate limit or a grant failure stops the token's batch.
 */
class DownloadService
{
    /** Status reads ANAF refuses before the upload is parked as indeterminate for reconciliation. */
    public const MAX_STATUS_FAILURES = 5;

    /** Taken off each PollSchedule interval so a read stays due on the scheduler tick it aims at. */
    private const POLL_MARGIN_SECONDS = 60;

    /** The wait after the first download try; each further try doubles it, up to a day (less POLL_MARGIN_SECONDS). */
    private const FIRST_DOWNLOAD_RETRY_SECONDS = 3600;

    /** Rows one batch reads at most; every claimed row leaves the due set, so the rest lead the next run. */
    private const BATCH_LIMIT = 500;

    /** A batch starts no new read after this, under the batch jobs' 120 s timeout. */
    private const BATCH_SECONDS = 90;

    public function __construct(
        protected TokenService $tokenService,
        protected ArchiveInspector $archiveInspector = new ArchiveInspector,
    ) {}

    /**
     * Check the status of one upload now, due or not, and record ANAF's answer.
     *
     * @return StatusCheckOutcome Whether ANAF was asked and answered, so a caller can tell a
     *                            skipped or failed check from a recorded answer.
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

        try {
            return $this->pollStatus($upload, force: true);
        } catch (StopBatch $stop) {
            return $stop->grant ? StatusCheckOutcome::Failed : StatusCheckOutcome::RateLimited;
        }
    }

    /**
     * Claim the upload's status read, ask ANAF and record the answer.
     *
     * @param  bool  $force  Read it even when its next read is not due yet.
     *
     * @throws StopBatch When a rate limit or a grant failure applies to every row of the token.
     */
    private function pollStatus(EfacturaUpload $upload, bool $force): StatusCheckOutcome
    {
        // An upload without uploaded_at is treated as old (the slowest cadence). A minute's
        // margin keeps a read due on the scheduled run it aims at, not the one after.
        $age = $upload->uploaded_at ? (int) $upload->uploaded_at->diffInSeconds(now(), true) : 10800;
        $seconds = max(PollSchedule::status($age) - self::POLL_MARGIN_SECONDS, self::POLL_MARGIN_SECONDS);

        if (!$this->claim($this->pollRow($upload), 'next_poll_at', $seconds, force: $force)) {
            return StatusCheckOutcome::NotPollable;
        }

        $response = $this->read(
            $upload->token,
            ['upload_id' => $upload->id],
            fn (EFacturaClient $client) => $client->getStatusMessage($upload->upload_index),
        );

        try {
            if ($response === null) {
                return StatusCheckOutcome::RateLimited;
            }

            if ($response instanceof \Throwable) {
                if ($this->refusesRow($response)) {
                    $this->countPermanentStatusFailure($upload, $response);
                }

                return StatusCheckOutcome::Failed;
            }

            // ANAF answered, so whatever it said, the status is readable again. Not from the
            // in-memory count: another run may have counted meanwhile.
            $this->pollRow($upload)->where('status_failures', '>', 0)->update(['status_failures' => 0]);

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

            // ANAF said, in so many words, "not yet": the claim already set the next read.
            if ($verdict === StatusVerdict::InProgress) {
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
        } catch (\Throwable $e) {
            // Ours, not ANAF's: never counted against the upload.
            Log::error('EFactura: status answer could not be recorded', [
                'upload_id' => $upload->id,
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);

            return StatusCheckOutcome::Failed;
        }
    }

    /**
     * Count a status read ANAF refused and park the upload once the bound is reached.
     *
     * Indeterminate, not rejected: ANAF issued the index, so the document reached it, but
     * its outcome cannot be read.
     */
    private function countPermanentStatusFailure(EfacturaUpload $upload, \Throwable $e): void
    {
        // Guarded like recordVerdict(): a failure for an old index is not the re-sent row's.
        if ($this->pollRow($upload)->increment('status_failures') === 0) {
            return;
        }

        $failures = (int) EfacturaUpload::whereKey($upload->id)->value('status_failures');

        if ($failures < self::MAX_STATUS_FAILURES) {
            return;
        }

        $this->parkAsIndeterminate($upload, [
            __('efactura::messages.status_poll_failures_exhausted', ['index' => $upload->upload_index, 'count' => $failures]),
            $e->getMessage(),
        ]);
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
     * records the verdict, and only it announces it. A verdict makes the response download
     * due at once.
     *
     * @param  array<string, mixed>  $attributes
     * @return bool Whether this call recorded it.
     */
    private function recordVerdict(EfacturaUpload $upload, array $attributes): bool
    {
        $recorded = $this->pollRow($upload)->update([...$attributes, 'next_poll_at' => null]) > 0;

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
     * Download the response of one upload now, due or not.
     *
     * @return bool Whether this call stored the response.
     */
    public function downloadResponse(EfacturaUpload $upload): bool
    {
        PipelineTablesDisabledException::throwIfDisabled();

        if (!$upload->download_id || $upload->response_path) {
            return false;
        }

        $upload->loadMissing('token');

        if (!$upload->token) {
            Log::error('EFactura: Upload has no associated token', ['upload_id' => $upload->id]);

            return false;
        }

        try {
            return $this->fetchResponse($upload, force: true);
        } catch (StopBatch) {
            return false;
        }
    }

    /**
     * Claim the upload's response download, fetch the archive and store it.
     *
     * @param  bool  $force  Download it even when its next try is not due yet.
     *
     * @throws StopBatch When a rate limit or a grant failure applies to every row of the token.
     */
    private function fetchResponse(EfacturaUpload $upload, bool $force): bool
    {
        $seconds = PollSchedule::archive($upload->response_attempts + 1, self::FIRST_DOWNLOAD_RETRY_SECONDS) - self::POLL_MARGIN_SECONDS;

        if (!$this->claim($this->responseRow($upload), 'next_poll_at', $seconds, ['response_attempts' => DB::raw('response_attempts + 1')], $force)) {
            return false;
        }

        $response = $this->read(
            $upload->token,
            ['upload_id' => $upload->id],
            fn (EFacturaClient $client) => $client->downloadDocument($upload->download_id),
        );

        try {
            if (!$response instanceof DownloadResponseData) {
                if ($response instanceof \Throwable && $this->refusesRow($response)) {
                    $this->recordPoisonedResponse($upload, $response);
                }

                return false;
            }

            $verification = $this->verifyResponse($upload, $response->content);

            if ($verification === null) {
                return false;
            }

            // Guarded: an overlapping run may have stored it already, and announced it; and a
            // row re-queued meanwhile (a new or no download id) must not get this attempt's file.
            $stored = $this->storeThenRecord(
                fn () => $this->storeResponse($upload, $response->content),
                fn (string $path) => $this->responseRow($upload)->update(['response_path' => $path, 'response_verification' => $verification]) > 0,
                fn () => EfacturaUpload::whereKey($upload->id)->value('response_path'),
            );

            if (!$stored) {
                return false;
            }

            $upload->refresh();

            // Only fire InvoiceProcessed for successful uploads, not error report downloads
            if ($upload->status === UploadStatus::Completed) {
                rescue(fn () => event(new InvoiceProcessed($upload)));
            }

            return true;
        } catch (\Throwable $e) {
            // ANAF delivered: a failure to keep the file is ours, retried at the next slot.
            Log::error('EFactura: downloaded response could not be recorded', [
                'upload_id' => $upload->id,
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Check a downloaded response archive with the SDK's ArchiveInspector: against the
     * uploaded XML when ANAF accepted the upload and that XML is stored, otherwise its
     * limits only.
     *
     * A mismatch keeps the archive as ANAF sent it, marked and logged. An archive outside
     * the limits (not a ZIP, empty, encrypted, too large, too many members) is recorded as a
     * poisoned response. A local failure (temporary file, reading the uploaded XML) is
     * ours: it is retried at the next slot without being recorded.
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
            if ($this->refusesRow($e)) {
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
     * Record a response ANAF will never hand over: errors and `response_failed_at`, only while
     * this download id's response is still missing.
     *
     * Typically the SDK's guardDownloadBody() rejecting a 2xx body that is not a ZIP (a
     * JSON {"eroare": ...} or an HTML page), which it rethrows carrying the 2xx status;
     * also a 4xx, a download id the SDK refuses, or an archive outside the limits. The try
     * itself was counted when the run claimed the row.
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

        // A run that stored the response meanwhile, or a row re-queued under another
        // download id, keeps its own state.
        $this->responseRow($upload)->update([
            'errors' => array_values(array_unique(array_merge(
                $upload->errors ?? [],
                [$e->getMessage()],
            ))),
            'response_failed_at' => now(),
        ]);
    }

    /**
     * Download one received invoice now, due or not.
     *
     * @return bool Whether this call stored it.
     */
    public function downloadMessage(EfacturaMessage $message): bool
    {
        PipelineTablesDisabledException::throwIfDisabled();

        if ($message->is_downloaded || !$message->download_id) {
            return false;
        }

        $message->loadMissing('token');

        if (!$message->token) {
            Log::error('EFactura: Message has no associated token', ['id' => $message->id, 'message_id' => $message->message_id]);

            return false;
        }

        try {
            return $this->fetchMessage($message, force: true);
        } catch (StopBatch) {
            return false;
        }
    }

    /**
     * Claim the received invoice's download, fetch the archive and store it.
     *
     * @param  bool  $force  Download it even when its next try is not due yet.
     *
     * @throws StopBatch When a rate limit or a grant failure applies to every row of the token.
     */
    private function fetchMessage(EfacturaMessage $message, bool $force): bool
    {
        $seconds = PollSchedule::archive($message->download_attempts + 1, self::FIRST_DOWNLOAD_RETRY_SECONDS) - self::POLL_MARGIN_SECONDS;

        if (!$this->claim($this->messageRow($message), 'next_download_at', $seconds, ['download_attempts' => DB::raw('download_attempts + 1')], $force)) {
            return false;
        }

        $log = ['id' => $message->id, 'message_id' => $message->message_id];

        // A refusal is only logged (by read()): the claim counted the try.
        $response = $this->read(
            $message->token,
            $log,
            fn (EFacturaClient $client) => $client->downloadDocument($message->download_id),
        );

        try {
            if (!$response instanceof DownloadResponseData) {
                return false;
            }

            // Limits only, like a rejection report: a received archive holds no upload of ours.
            try {
                $this->archiveInspector->verify($response->content);
            } catch (\Throwable $e) {
                Log::error('EFactura: received archive could not be checked', [
                    ...$log,
                    'exception' => $e::class,
                    'error' => $e->getMessage(),
                ]);

                return false;
            }

            // An overlapping run may have stored it first, and announced it.
            $stored = $this->storeThenRecord(
                fn () => $this->storeMessageDownload($message, $response->content),
                fn (string $path) => $this->markMessageAsDownloaded($message, $path),
                fn () => EfacturaMessage::whereKey($message->id)->value('xml_path'),
            );

            if ($stored) {
                rescue(fn () => event(new InvoiceReceived($message)));
            }

            return $stored;
        } catch (\Throwable $e) {
            Log::error('EFactura: downloaded received invoice could not be recorded', [
                ...$log,
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);

            return false;
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
     * Store a downloaded file, then record it with a guarded write; a file the write did not
     * record is removed, so no run leaves an orphan behind.
     *
     * @param  \Closure(): string  $store
     * @param  \Closure(string): bool  $guardedWrite
     * @param  \Closure(): ?string  $storedPath  The path the row holds now.
     */
    private function storeThenRecord(\Closure $store, \Closure $guardedWrite, \Closure $storedPath): bool
    {
        $path = $store();

        try {
            $recorded = $guardedWrite($path);
        } catch (\Throwable $e) {
            // The write may have committed before its connection failed: an unreadable row keeps the file.
            $this->deleteLostFile($path, rescue($storedPath, $path, false));

            throw $e;
        }

        if (!$recorded) {
            $this->deleteLostFile($path, $storedPath());
        }

        return $recorded;
    }

    /**
     * Remove the file a run stored but did not record.
     *
     * Kept when the row names it (another run stored the same name in the same second).
     */
    private function deleteLostFile(string $path, ?string $storedPath): void
    {
        if ($path === $storedPath) {
            return;
        }

        rescue(fn () => Storage::disk(config('efactura.storage.disk', 'local'))->delete($path));
    }

    /**
     * Read the status of the token's uploads whose next read is due.
     *
     * @return bool False when the grant is paused, or a rate limit or a grant failure
     *              stopped the run; the rest wait for the next run.
     *
     * @throws PipelineTablesDisabledException
     */
    public function checkAllStatuses(EfacturaToken $token): bool
    {
        PipelineTablesDisabledException::throwIfDisabled();

        return $this->eachDue($token, EfacturaUpload::needsStatusCheck(), 'next_poll_at', fn (EfacturaUpload $upload) => $this->pollStatus($upload, force: false));
    }

    /**
     * Download the token's responses whose next try is due.
     *
     * @return bool False when the grant is paused, or a rate limit or a grant failure
     *              stopped the run; the rest wait for the next run.
     *
     * @throws PipelineTablesDisabledException
     */
    public function downloadAllResponses(EfacturaToken $token): bool
    {
        PipelineTablesDisabledException::throwIfDisabled();

        return $this->eachDue($token, EfacturaUpload::needsResponseDownload(), 'next_poll_at', fn (EfacturaUpload $upload) => $this->fetchResponse($upload, force: false));
    }

    /**
     * Download the token's received invoices whose next try is due.
     *
     * @return bool False when the grant is paused, or a rate limit or a grant failure
     *              stopped the run; the rest wait for the next run.
     *
     * @throws PipelineTablesDisabledException
     */
    public function downloadAllReceivedInvoices(EfacturaToken $token): bool
    {
        PipelineTablesDisabledException::throwIfDisabled();

        return $this->eachDue($token, EfacturaMessage::needsDownload(), 'next_download_at', fn (EfacturaMessage $message) => $this->fetchMessage($message, force: false));
    }

    /**
     * Try each due row of the token: never-read rows first, then the most overdue, at most
     * BATCH_LIMIT, and no new read after BATCH_SECONDS.
     *
     * @param  Builder<EfacturaUpload>|Builder<EfacturaMessage>  $due
     */
    private function eachDue(EfacturaToken $token, Builder $due, string $dueColumn, \Closure $try): bool
    {
        // A paused grant would refuse every row: claim none.
        if ($this->tokenService->refreshCooldownSeconds($token) > 0) {
            return false;
        }

        $deadline = now()->addSeconds(self::BATCH_SECONDS);
        $rows = $due->forToken($token)
            ->with('token')
            ->orderByRaw("{$dueColumn} IS NOT NULL")
            ->orderBy($dueColumn)
            ->orderBy('id')
            ->limit(self::BATCH_LIMIT)
            ->get();

        try {
            foreach ($rows as $row) {
                if (now()->greaterThan($deadline)) {
                    break;
                }

                $try($row);
            }
        } catch (StopBatch) {
            return false;
        }

        return true;
    }

    /**
     * Take one try at a row: write its next try, unless another run took it first. The row
     * leaves the due set whatever the outcome, so no run reads it again before that slot.
     *
     * @param  Builder<EfacturaUpload>|Builder<EfacturaMessage>  $phaseRow
     * @param  array<string, mixed>  $also
     * @param  bool  $force  Take it even when it is not due (a per-row call).
     */
    private function claim(Builder $phaseRow, string $dueColumn, int $seconds, array $also = [], bool $force = false): bool
    {
        if (!$force) {
            $phaseRow->where(fn (Builder $due) => $due->whereNull($dueColumn)->orWhere($dueColumn, '<=', now()));
        }

        // MySQL and MariaDB count changed rows, not matched ones: a forced claim that writes the
        // same slot again in the same second changes nothing, yet the row is still there to read.
        return $phaseRow->update([$dueColumn => now()->addSeconds($seconds), ...$also]) > 0
            || ($force && $phaseRow->exists());
    }

    /**
     * Ask ANAF on the token's grant.
     *
     * @param  array<string, mixed>  $log  The row's log keys.
     * @return mixed The answer; null when the row's own per-message limit is reached; the
     *               Throwable for any other failure of this row, already logged.
     *
     * @throws StopBatch When a rate limit or a grant failure applies to every row of the token.
     */
    private function read(EfacturaToken $token, array $log, \Closure $call): mixed
    {
        // Set when the SDK client is handed the request; a failure before it is the grant's.
        $sent = false;

        try {
            return $this->tokenService->executeWithClient($token, function (EFacturaClient $client) use ($call, &$sent) {
                $sent = true;

                return $call($client);
            });
        } catch (\Throwable $e) {
            if ($e instanceof RateLimitExceededException && ($e->scope === RateLimitScope::Status || $e->scope === RateLimitScope::Download)) {
                Log::warning('EFactura: per-message rate limit reached, skipping it in this batch', [
                    ...$log,
                    'scope' => $e->scope->value,
                    'retry_after_seconds' => $e->retryAfterSeconds,
                ]);

                return null;
            }

            $limit = $e instanceof RateLimitExceededException || ($e instanceof ApiException && $e->statusCode === 429);

            if ($limit || $this->tokenService->isGrantFailure($e, $sent)) {
                Log::warning('EFactura: stopping reads for this token', [
                    'stop' => $limit ? 'limit' : 'grant',
                    'cui' => $token->getVatNumber(),
                    'exception' => $e::class,
                    'status_code' => $e instanceof ApiException ? $e->statusCode : null,
                ]);

                throw new StopBatch(grant: !$limit, previous: $e);
            }

            Log::error('EFactura: ANAF read failed', [
                ...$log,
                'exception' => $e::class,
                'status_code' => $e instanceof ApiException ? $e->statusCode : null,
                'error' => $e->getMessage(),
            ]);

            return $e;
        }
    }

    /**
     * Whether ANAF, or the SDK on its behalf, refused this row: a 4xx or unreadable 2xx
     * answer, an id the SDK refuses, or an archive outside the limits. Anything else (5xx,
     * 408, transport, a local database, cache, filesystem or configuration error) says
     * nothing about the row and is retried at its next slot without being recorded.
     */
    private function refusesRow(\Throwable $e): bool
    {
        return ($e instanceof ApiException && $e->statusCode < 500 && $e->statusCode !== 408)
            || $e instanceof ValidationException
            || ($e instanceof ArchiveLimitException && $e->reason !== ArchiveLimitReason::Io);
    }

    /** @return Builder<EfacturaUpload> The upload while it is Processing under the index read. */
    private function pollRow(EfacturaUpload $upload): Builder
    {
        return EfacturaUpload::whereKey($upload->id)
            ->where('status', UploadStatus::Processing)
            ->where('upload_index', $upload->upload_index);
    }

    /** @return Builder<EfacturaUpload> The upload while this download id's response is missing. */
    private function responseRow(EfacturaUpload $upload): Builder
    {
        return EfacturaUpload::whereKey($upload->id)
            ->where('download_id', $upload->download_id)
            ->whereNull('response_path');
    }

    /** @return Builder<EfacturaMessage> The received invoice while it is not downloaded. */
    private function messageRow(EfacturaMessage $message): Builder
    {
        return EfacturaMessage::whereKey($message->id)->where('is_downloaded', false);
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
