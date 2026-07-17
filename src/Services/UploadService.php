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
use BeeCoded\EFactura\Enums\FailureReason;
use BeeCoded\EFactura\Enums\UploadStatus;
use BeeCoded\EFactura\Events\InvoiceFailed;
use BeeCoded\EFactura\Events\InvoiceRateLimited;
use BeeCoded\EFactura\Events\InvoiceUploaded;
use BeeCoded\EFactura\Exceptions\DuplicateUploadException;
use BeeCoded\EFactura\Jobs\ProcessSingleUpload;
use BeeCoded\EFactura\Models\EfacturaUpload;
use BeeCoded\EFacturaSdk\Data\Invoice\UploadOptionsData;
use BeeCoded\EFacturaSdk\Enums\StandardType;
use BeeCoded\EFacturaSdk\Exceptions\ApiException;
use BeeCoded\EFacturaSdk\Exceptions\AuthenticationException;
use BeeCoded\EFacturaSdk\Exceptions\RateLimitExceededException;
use BeeCoded\EFacturaSdk\Exceptions\ValidationException;
use BeeCoded\EFacturaSdk\Exceptions\XmlParsingException;
use BeeCoded\EFacturaSdk\Facades\UblBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class UploadService
{
    public function __construct(
        protected TokenService $tokenService,
    ) {}

    /**
     * Queue a model for e-Factura upload.
     *
     * Idempotent: a model has at most ONE upload record, matching the 1:1 morphOne
     * the trait exposes. See queueFor() for why.
     *
     * @throws DuplicateUploadException If the existing upload's delivery to ANAF is
     *                                  indeterminate and must be reconciled first.
     */
    public function queueUpload(EFacturaUploadableInterface $model, ?array $options = null): EfacturaUpload
    {
        return $this->queueFor($model, [
            'standard' => $options['standard'] ?? 'UBL',
            'is_extern' => $options['extern'] ?? false,
            'is_self_billed' => $options['self_billed'] ?? false,
            'is_b2c' => false,
        ]);
    }

    /**
     * Queue a model for B2C e-Factura upload.
     *
     * @throws DuplicateUploadException If the existing upload's delivery to ANAF is
     *                                  indeterminate and must be reconciled first.
     */
    public function queueB2CUpload(EFacturaUploadableInterface $model, ?array $options = null): EfacturaUpload
    {
        return $this->queueFor($model, [
            'standard' => 'UBL',
            'is_extern' => $options['extern'] ?? false,
            'is_self_billed' => $options['self_billed'] ?? false,
            'is_b2c' => true,
        ]);
    }

    /**
     * Create — or safely reuse — the single upload record for a model.
     *
     * Previously this create()d unconditionally, so two calls for the same model (a
     * double-clicked button, an application-level retry) produced two Pending rows.
     * Each passed the per-row atomic claim independently, so BOTH were sent to ANAF
     * and the invoice was filed twice. The trait's morphOne could only ever see one
     * of them, which is what kept it invisible.
     *
     * The resulting rule, one row per (uploadable_type, uploadable_id):
     *
     *   - Pending / Uploading / Processing → return it. The filing is already in the
     *     pipeline; queueing again would duplicate it.
     *   - Completed                        → return it. ANAF accepted this document;
     *     re-filing it is precisely the thing we must never do.
     *   - Failed, retryable or terminal    → reuse the row, reset to Pending. This is
     *     how re-upload after a failure stays possible without accumulating rows and
     *     without morphOne silently returning the stale one.
     *   - Failed, indeterminate            → refuse. It may ALREADY be filed; only a
     *     human who has checked ANAF may resolve it (efactura:reconcile).
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws DuplicateUploadException
     */
    protected function queueFor(EFacturaUploadableInterface $model, array $attributes): EfacturaUpload
    {
        $cui = $model->getEfacturaCui();
        $token = $this->tokenService->getToken($cui);

        if (!$token) {
            throw new \RuntimeException("No active token found for CUI: {$cui}");
        }

        $existing = $this->findExistingUpload($model);

        if ($existing) {
            return $this->reuseExistingUpload($existing, $token->id, $attributes);
        }

        try {
            // withSavepointIfNeeded is what lets the recovery below survive a
            // caller's own transaction on Postgres. There, a failed statement
            // poisons the WHOLE transaction — every later query, including our
            // findExisting re-read, throws "current transaction is aborted". When
            // already inside a transaction this issues a SAVEPOINT and rolls back
            // only to it, leaving the caller's transaction usable; when not, it
            // runs the create() bare (no needless BEGIN, and the row an autocommit
            // race inserts is not swept into a transaction we then roll back).
            // This is exactly how Laravel's own createOrFirst() handles the race;
            // we cannot call that directly because our recovery is reuseExisting,
            // not a plain first(). MySQL and SQLite never poisoned, so this only
            // changes behaviour for the driver that did.
            return EfacturaUpload::query()->withSavepointIfNeeded(fn () => EfacturaUpload::create([
                'efactura_token_id' => $token->id,
                'uploadable_type' => get_class($model),
                'uploadable_id' => $model->getKey(),
                'status' => UploadStatus::Pending,
                ...$attributes,
            ]));
        } catch (UniqueConstraintViolationException $e) {
            // Exactly the race the unique index exists for: a concurrent request inserted
            // the row between our existence check above and this create(). The index did
            // its job and prevented the duplicate ROW — but letting the violation escape
            // handed the LOSING request an unhandled QueryException (a 500), which the
            // documented contract never mentions ("idempotent, returns the existing
            // record"; @throws DuplicateUploadException only), so callers written to that
            // contract do not catch it.
            $winner = $this->findExistingUpload($model);

            if (!$winner) {
                throw $e; // Not our uniqueness constraint — do not swallow it.
            }

            return $this->reuseExistingUpload($winner, $token->id, $attributes);
        }
    }

    private function findExistingUpload(EFacturaUploadableInterface $model): ?EfacturaUpload
    {
        return EfacturaUpload::where('uploadable_type', get_class($model))
            ->where('uploadable_id', $model->getKey())
            ->first();
    }

    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws DuplicateUploadException
     */
    protected function reuseExistingUpload(
        EfacturaUpload $existing,
        int $tokenId,
        array $attributes,
    ): EfacturaUpload {
        if ($existing->failure_reason?->needsReconciliation()) {
            throw DuplicateUploadException::indeterminate($existing);
        }

        // Anything not failed is in flight, already filed, or waiting out a rate limit.
        // The row cannot be recycled — but the requested options must not be silently
        // DISCARDED either. Returning early here meant queueB2CUpload() on a model with a
        // Pending B2B row handed back the B2B row unchanged, with no signal whatsoever,
        // and the invoice was then filed to ANAF in the wrong mode. Same for standard
        // (UBL vs CII), extern and self_billed.
        if (!$existing->isFailed()) {
            return $this->reuseInFlightUpload($existing, $attributes);
        }

        // A failed upload is provably not filed (indeterminate is excluded above), so
        // the row can be recycled for a fresh attempt.
        $existing->update([
            'efactura_token_id' => $tokenId,
            'status' => UploadStatus::Pending,
            'failure_reason' => null,
            'errors' => null,
            'upload_index' => null,
            'download_id' => null,
            'response_path' => null,
            'uploaded_at' => null,
            'processed_at' => null,
            ...$attributes,
        ]);

        return $existing->refresh();
    }

    /**
     * Re-queue a model whose upload is already in the pipeline or already filed.
     *
     * A PENDING row has not been transmitted, so new options are simply applied — that is
     * the ordinary "queue it again, this time as B2C" case, and the only outcome that
     * neither lies nor files in the wrong mode.
     *
     * Once the row is Uploading/Processing/Completed, its options are a FACT about what
     * was put on the wire. Rewriting them would make the row misdescribe the filing;
     * ignoring them would file in the wrong mode. Both are wrong, so this refuses loudly
     * and leaves the decision to the caller.
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws DuplicateUploadException
     */
    private function reuseInFlightUpload(EfacturaUpload $existing, array $attributes): EfacturaUpload
    {
        if ($existing->isPending()) {
            // Guarded: a worker may claim the row between the check above and this write,
            // and we must never rewrite the mode of a document already going to ANAF.
            $applied = EfacturaUpload::where('id', $existing->id)
                ->where('status', UploadStatus::Pending)
                ->update($attributes) > 0;

            if ($applied) {
                return $existing->refresh();
            }

            // Lost the race — it is in flight now. Fall through to the conflict check.
            $existing->refresh();
        }

        $conflicts = $this->conflictingOptions($existing, $attributes);

        if ($conflicts !== []) {
            throw new DuplicateUploadException(
                "Cannot re-queue upload {$existing->id} with different options ("
                    .implode(', ', $conflicts).'): it is already '.$existing->status->value
                    .', so those options describe what has already been sent to ANAF. '
                    .'Resolve the existing upload before queueing it differently.',
                $existing,
            );
        }

        return $existing;
    }

    /**
     * Which requested options differ from the ones the existing row carries.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<int, string>
     */
    private function conflictingOptions(EfacturaUpload $existing, array $attributes): array
    {
        $conflicts = [];

        foreach ($attributes as $key => $value) {
            if ($existing->getAttribute($key) != $value) {
                $conflicts[] = $key;
            }
        }

        return $conflicts;
    }

    /**
     * Process a single upload.
     *
     * PHASE 1 (preparation) deliberately runs while the row is still PENDING, and only
     * PHASE 2 claims it into Uploading. The claim used to come first, so ~45 lines of
     * preparation — toEfacturaData(), generateInvoiceXml(), Storage::put() — ran with the
     * row already marked as in-flight. An OOM while building the UBL therefore stranded it
     * in Uploading, and SweepStaleUploads parked it Indeterminate 30 minutes later, telling
     * an operator to reconcile a document that provably never left this process.
     *
     * Uploading now means what parkStrandedUploadAsIndeterminate()'s docblock has always
     * claimed it means: set immediately before the document goes to ANAF, and never for
     * code that cannot transmit.
     */
    public function processUpload(EfacturaUpload $upload): void
    {
        $upload->loadMissing(['token', 'uploadable']);

        // Cheap pre-check so we neither prepare nor overwrite a row somebody else owns.
        // The AUTHORITATIVE gate is still the atomic claim, taken below.
        if (EfacturaUpload::where('id', $upload->id)->value('status') !== UploadStatus::Pending) {
            return;
        }

        if (!$upload->token) {
            Log::error('EFactura: Upload has no associated token', ['upload_id' => $upload->id]);
            $this->failBeforeTransmission(
                $upload,
                ['No associated token found for upload'],
                FailureReason::Configuration
            );

            return;
        }

        // PHASE 1 — preparation. Everything here happens strictly BEFORE anything is
        // transmitted, so a failure proves nothing was filed. A broken invoice or a
        // broken model is the author's data problem: terminal, no point retrying.
        try {
            $model = $upload->uploadable;

            if (!$model instanceof EFacturaUploadableInterface) {
                $this->failBeforeTransmission(
                    $upload,
                    ['Uploadable model must implement EFacturaUploadableInterface'],
                    FailureReason::Configuration
                );

                return;
            }

            $xml = $this->generateXml($model);
            $xmlPath = $this->storeXml($upload, $xml);

            $uploadOptions = new UploadOptionsData(
                standard: StandardType::from($upload->standard),
                extern: $upload->is_extern,
                selfBilled: $upload->is_self_billed,
            );
        } catch (\Throwable $e) {
            Log::error('EFactura: Upload preparation failed', [
                'upload_id' => $upload->id,
                'error' => $e->getMessage(),
            ]);

            $this->failBeforeTransmission($upload, [$e->getMessage()], FailureReason::Validation);

            return;
        }

        // PHASE 2 — transmission. From here on, a failure's CLASSIFICATION depends on
        // whether it proves the document never entered ANAF's pipeline.
        if (!$this->claimForTransmission($upload, $xmlPath)) {
            return;
        }

        $this->transmit($upload, $xml, $uploadOptions);
    }

    /**
     * Atomically claim a prepared upload for transmission.
     *
     * Only one process can win the Pending -> Uploading transition, and the winner is the
     * only one that transmits. Recording xml_path in the SAME statement keeps it a fact
     * about the document that was actually put on the wire, and clearing the previous
     * attempt's markers here is what keeps a re-driven row from carrying a stale
     * failure_reason into Processing/Completed.
     */
    private function claimForTransmission(EfacturaUpload $upload, string $xmlPath): bool
    {
        $claimed = EfacturaUpload::where('id', $upload->id)
            ->where('status', UploadStatus::Pending)
            ->update([
                'status' => UploadStatus::Uploading,
                'xml_path' => $xmlPath,
                'failure_reason' => null,
                'errors' => null,
            ]) > 0;

        if (!$claimed) {
            return false;
        }

        $upload->status = UploadStatus::Uploading;
        $upload->xml_path = $xmlPath;
        $upload->failure_reason = null;
        $upload->errors = null;

        return true;
    }

    /**
     * Fail an upload that never reached the transmission phase.
     *
     * Guarded on `status = pending`: preparation now runs BEFORE the atomic claim, so a
     * concurrent worker may legitimately own the row by the time we get here. This path
     * must never rewrite an in-flight or already-filed outcome.
     *
     * @param  array<int, string>  $errors
     */
    protected function failBeforeTransmission(EfacturaUpload $upload, array $errors, FailureReason $reason): void
    {
        $failed = EfacturaUpload::where('id', $upload->id)
            ->where('status', UploadStatus::Pending)
            ->update([
                'status' => UploadStatus::Failed,
                'failure_reason' => $reason,
                'errors' => $errors,
                'processed_at' => now(),
            ]) > 0;

        if ($failed) {
            event(new InvoiceFailed($upload->refresh(), $errors));
        }
    }

    /**
     * Send a prepared document to ANAF and classify the outcome.
     *
     * The catch order is load-bearing. See FailureReason for why "retryable" is
     * narrower than "transient-looking".
     */
    protected function transmit(EfacturaUpload $upload, string $xml, UploadOptionsData $uploadOptions): void
    {
        // Flipped the moment the SDK client exists and the document is being handed to it.
        // Everything before that point — reading and decrypting the token, refreshing it,
        // building the client — provably precedes the HTTP request, so its failures CANNOT
        // be indeterminate. See the catch(\Throwable) below.
        $transmissionStarted = false;

        try {
            $response = $this->tokenService->executeWithClient(
                $upload->token,
                function ($client) use ($xml, $uploadOptions, $upload, &$transmissionStarted) {
                    $transmissionStarted = true;

                    if ($upload->is_b2c) {
                        return $client->uploadB2CDocument($xml, $uploadOptions);
                    }

                    return $client->uploadDocument($xml, $uploadOptions);
                }
            );

            if ($response->isSuccessful()) {
                $this->markUploadAsProcessing($upload, $response->indexIncarcare);
                event(new InvoiceUploaded($upload));

                return;
            }

            // ANAF answered, and answered "no". That is a verdict on the document.
            $this->failTerminally(
                $upload,
                $response->errors ?? ['Unknown error'],
                FailureReason::Validation
            );
        } catch (RateLimitExceededException $e) {
            // The SDK's CLIENT-SIDE pre-flight limiter refused to make the call.
            // Nothing was sent.
            $this->markAsRateLimited($upload, $e->getMessage(), $e->retryAfterSeconds);
        } catch (AuthenticationException $e) {
            // Either the token refresh failed (before the call) or ANAF answered 401
            // (rejected, not filed). Both prove the document did not enter the pipeline.
            Log::warning('EFactura: Authentication failure during upload, will retry', [
                'upload_id' => $upload->id,
                'error' => $e->getMessage(),
            ]);

            $this->markUploadAsFailed($upload, [$e->getMessage()], reason: FailureReason::Transient);
        } catch (ApiException $e) {
            $this->classifyApiException($upload, $e);
        } catch (ValidationException|XmlParsingException $e) {
            $this->failTerminally($upload, [$e->getMessage()], FailureReason::Validation);
        } catch (\Throwable $e) {
            if (!$transmissionStarted) {
                // Thrown before the client was even built, so the document provably never
                // entered ANAF's pipeline. Two real cases land here, both RuntimeExceptions
                // that match none of the specific catches above:
                //
                //   - DecryptException, from a token row that predates the v3 encryption
                //     migration. v3 makes that throw ON PURPOSE (fail loud, no plaintext
                //     fallback) — but it is thrown while READING the token, before any HTTP
                //     request exists.
                //   - TokenService's RuntimeException("...has been deactivated").
                //
                // Classifying these as Indeterminate meant that during a normal deploy
                // window — code live before `migrate` finishes — every upload for every
                // company was written off as "never auto-resubmitted, requires human
                // reconciliation", and running the migration afterwards did NOT unpark them.
                // They are transient: they clear the moment the migration lands, and the
                // job's attempt cap bounds them if they do not.
                Log::warning('EFactura: Upload failed before transmission, will retry', [
                    'upload_id' => $upload->id,
                    'exception' => $e::class,
                    'error' => $e->getMessage(),
                ]);

                $this->markUploadAsFailed($upload, [$e->getMessage()], reason: FailureReason::Transient);

                return;
            }

            // We have no idea where this landed. It may have been thrown after ANAF
            // accepted the document. Re-driving it could DOUBLE-FILE a legal invoice,
            // so it is parked for human reconciliation rather than retried or
            // written off.
            Log::error('EFactura: Upload failed with an indeterminate error', [
                'upload_id' => $upload->id,
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);

            $this->failTerminally(
                $upload,
                [$e->getMessage()],
                FailureReason::Indeterminate
            );
        }
    }

    /**
     * Classify an SDK ApiException by the HTTP status it carries.
     *
     * A genuine ANAF 429 arrives HERE, not as RateLimitExceededException: the SDK's
     * isRetryable() deliberately excludes 429, so it throws a plain ApiException(429).
     * RateLimitExceededException is only ever thrown by the SDK's client-side
     * pre-flight limiter — which is bypassed entirely when rate_limits.enabled=false,
     * when counters are lost to a cache:clear, or when several app servers share no
     * cache. Treating both identically is the whole point of this method.
     */
    protected function classifyApiException(EfacturaUpload $upload, ApiException $e): void
    {
        if ($e->statusCode === 429) {
            $this->markAsRateLimited($upload, $e->getMessage(), null);

            return;
        }

        // Two shapes arrive here, and both are ambiguous — ANAF may have accepted the
        // document and then failed to tell us — so neither may be auto-resubmitted:
        //
        //   - a 5xx RESPONSE, which the SDK collapses to 502
        //     (`$response->status() >= 500 ? 502 : $response->status()`);
        //   - a transport failure with no response at all, which the SDK reports as 500
        //     (`new ApiException($exception->getMessage(), 500, ...)` in BaseApiClient).
        //
        // There is deliberately no `statusCode === 0` branch: nothing in the SDK ever
        // produces one. Every `new ApiException(` passes 500, 502, or $response->status(),
        // and the constructor's $statusCode has no default — so that branch was dead code,
        // and the comment claiming transport failures arrive as 0 was simply false.
        if ($e->statusCode >= 500) {
            Log::error('EFactura: Upload failed with an indeterminate transport error', [
                'upload_id' => $upload->id,
                'status_code' => $e->statusCode,
                'error' => $e->getMessage(),
            ]);

            $this->failTerminally($upload, [$e->getMessage()], FailureReason::Indeterminate);

            return;
        }

        // Any other 4xx is ANAF rejecting the request itself. Retrying is pointless.
        $this->failTerminally($upload, [$e->getMessage()], FailureReason::Validation);
    }

    /**
     * Record a transient rate-limit hit.
     *
     * Fires InvoiceRateLimited, NOT InvoiceFailed: the upload is still in the pipeline and
     * will be re-submitted.
     *
     * The row therefore RELEASES ITS CLAIM back to Pending rather than being marked Failed.
     * ANAF's quota rejected the document before filing, so nothing is at risk and nothing
     * is finished. Writing status=Failed/processed_at=now() here — as this used to —
     * contradicted InvoiceRateLimited's own documented contract ("Do not treat this as a
     * failure. The upload stays in the pipeline") for every consumer that reads the MODEL
     * rather than listening for the event: isFailed(), isTerminal() and, worst of all,
     * isEfacturaProcessed() all reported a terminal outcome, and the efacturaFailed() scope
     * matched — for a row that flips back to Pending 60 seconds later.
     *
     * failure_reason still carries the classification: it is what the retry paths read
     * (ProcessSingleUpload's post-check, processPendingUploads()'s batch stop), and the
     * atomic claim clears it on the next attempt.
     */
    protected function markAsRateLimited(EfacturaUpload $upload, string $message, ?int $retryAfterSeconds): void
    {
        Log::warning('EFactura: Rate limit hit during upload', [
            'upload_id' => $upload->id,
            'retry_after' => $retryAfterSeconds,
            'error' => $message,
        ]);

        EfacturaUpload::where('id', $upload->id)
            ->where('status', UploadStatus::Uploading)
            ->update([
                'status' => UploadStatus::Pending,
                'failure_reason' => FailureReason::RateLimited,
                'errors' => [$message],
                'processed_at' => null,
            ]);

        event(new InvoiceRateLimited($upload->refresh(), [$message], $retryAfterSeconds));
    }

    /**
     * Mark an upload as failed for good and announce it.
     *
     * InvoiceFailed is reserved for outcomes the pipeline will not itself recover
     * from, so that consumers alerting on it are not woken by transient noise.
     *
     * @param  array<int, string>  $errors
     */
    protected function failTerminally(EfacturaUpload $upload, array $errors, FailureReason $reason): void
    {
        $this->markUploadAsFailed($upload, $errors, reason: $reason);

        event(new InvoiceFailed($upload, $errors));
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
     * Mark upload as failed with errors and an authoritative failure classification.
     *
     * @param  array<int, string>  $errors
     * @param  FailureReason  $reason  Why it failed. Drives whether the upload may
     *                                 ever be re-submitted — see FailureReason.
     */
    public function markUploadAsFailed(
        EfacturaUpload $upload,
        array $errors,
        ?string $downloadId = null,
        FailureReason $reason = FailureReason::Validation,
    ): void {
        $data = [
            'status' => UploadStatus::Failed,
            'failure_reason' => $reason,
            'errors' => $errors,
            'processed_at' => now(),
        ];

        if ($downloadId !== null) {
            $data['download_id'] = $downloadId;
        }

        $upload->update($data);
    }

    /**
     * Atomically reset a retryable failed upload back to pending.
     *
     * Refuses any upload whose failure reason is not provably safe to re-submit —
     * an Indeterminate row must never be re-driven, because the original attempt may
     * have reached ANAF and re-sending would double-file a legal invoice.
     *
     * Returns true if the reset was applied (upload was still Failed AND retryable).
     */
    public function resetForRetry(EfacturaUpload $upload): bool
    {
        return EfacturaUpload::where('id', $upload->id)
            ->where('status', UploadStatus::Failed)
            ->whereIn('failure_reason', FailureReason::retryable())
            ->update([
                'status' => UploadStatus::Pending,
                'failure_reason' => null,
                'errors' => null,
                'processed_at' => null,
            ]) > 0;
    }

    /**
     * The pipeline has run out of attempts for a retryable failure. Retire the row.
     *
     * Leaves it NON-RETRYABLE, which is the entire point. Announcing a terminal failure
     * while leaving the row Failed/transient left it matching exactly what
     * RetryRateLimitedUploads selects (Failed + rate_limited|transient), so the scheduled
     * job reset it to Pending with a fresh attempts counter and a fresh retry window: the
     * cap reset itself every ten minutes, InvoiceFailed fired every ten minutes for an
     * upload that was immediately retried, and with retry_batch_size 250 that put up to
     * 1250 calls per ten minutes against ANAF's 1000/60min quota.
     *
     * $expected must be a retryable reason. Guarding on it makes this a no-op against any
     * concurrent outcome — in particular it can never touch an Indeterminate row.
     *
     * @param  array<int, string>  $errors
     * @return bool Whether this call is the one that retired the upload.
     */
    public function abandonFailure(EfacturaUpload $upload, FailureReason $expected, array $errors): bool
    {
        $abandoned = EfacturaUpload::where('id', $upload->id)
            ->where('status', UploadStatus::Failed)
            ->where('failure_reason', $expected)
            ->update([
                'failure_reason' => FailureReason::Abandoned,
                'errors' => $errors,
                'processed_at' => now(),
            ]) > 0;

        if (!$abandoned) {
            return false;
        }

        event(new InvoiceFailed($upload->refresh(), $errors));

        return true;
    }

    /**
     * Retire an upload the queue gave up on BEFORE it was ever transmitted.
     *
     * The counterpart to parkStrandedUploadAsIndeterminate(): that one handles a row
     * stranded mid-flight (Uploading), where delivery is genuinely unknown. This one
     * handles the deadline-expiry routes, which leave the row PENDING — handle() calls
     * resetForRetry() (-> Pending) and then release()s, and the rate-limit pre-check
     * release()s a still-Pending row outright.
     *
     * Without this, retryUntil expiry hit parkStranded's `WHERE status = uploading` guard,
     * matched ZERO rows and returned false silently: no terminal state, no InvoiceFailed —
     * and the scheduled ProcessPendingUploads then picked the still-Pending row straight
     * back up with a brand-new 24h window, so retry_window_hours capped nothing at all.
     *
     * Nothing was sent from a Pending row, so it is abandoned rather than parked for
     * reconciliation. The guard leaves Uploading, Processing and Completed rows alone, and
     * refuses to overwrite a non-retryable verdict that is already recorded.
     *
     * @return bool Whether this call is the one that retired the upload.
     */
    public function abandonUnsentUpload(EfacturaUpload $upload, string $reason): bool
    {
        $abandoned = EfacturaUpload::where('id', $upload->id)
            ->where(function (Builder $query) {
                $query->where('status', UploadStatus::Pending)
                    ->orWhere(fn (Builder $failed) => $failed
                        ->where('status', UploadStatus::Failed)
                        ->whereIn('failure_reason', FailureReason::retryable()));
            })
            ->update([
                'status' => UploadStatus::Failed,
                'failure_reason' => FailureReason::Abandoned,
                'errors' => [$reason],
                'processed_at' => now(),
            ]) > 0;

        if (!$abandoned) {
            return false;
        }

        Log::warning('EFactura: Upload abandoned before it was ever sent to ANAF', [
            'upload_id' => $upload->id,
            'reason' => $reason,
        ]);

        event(new InvoiceFailed($upload->refresh(), [$reason]));

        return true;
    }

    /**
     * Park an upload stranded in Uploading as Failed/Indeterminate.
     *
     * "Uploading" is only ever set by processUpload()'s atomic claim, immediately before
     * the document goes to ANAF — the claim is taken AFTER the document has been prepared,
     * precisely so that this remains true and no preparation failure can be mistaken for a
     * document in flight. A row still sitting there means the worker died somewhere around
     * the POST — and we cannot tell from this side whether ANAF received the document. So
     * this deliberately does NOT return it to Pending: that would double-file a legal
     * invoice whenever the crash happened after delivery.
     *
     * The guard (WHERE status = uploading) makes this safe to call on any upload and
     * from concurrent sweepers: a row that has since reached a real terminal state is
     * left untouched.
     *
     * @return bool Whether this call is the one that parked the upload.
     */
    public function parkStrandedUploadAsIndeterminate(EfacturaUpload $upload, string $reason): bool
    {
        $parked = EfacturaUpload::where('id', $upload->id)
            ->where('status', UploadStatus::Uploading)
            ->update([
                'status' => UploadStatus::Failed,
                'failure_reason' => FailureReason::Indeterminate,
                'errors' => [
                    $reason,
                    'It is UNKNOWN whether ANAF received this document. It will NOT be '
                        .'re-submitted automatically. Reconcile against ANAF (efactura:reconcile) '
                        .'before resolving it either way.',
                ],
                'processed_at' => now(),
            ]) > 0;

        if (!$parked) {
            return false;
        }

        Log::critical('EFactura: Upload stranded mid-flight, needs reconciliation', [
            'upload_id' => $upload->id,
            'reason' => $reason,
        ]);

        event(new InvoiceFailed($upload->refresh(), [$reason]));

        return true;
    }

    /**
     * Every upload parked awaiting human reconciliation against ANAF.
     *
     * @return Collection<int, EfacturaUpload>
     */
    public function awaitingReconciliation(): Collection
    {
        return EfacturaUpload::where('status', UploadStatus::Failed)
            ->where('failure_reason', FailureReason::Indeterminate)
            ->with('token')
            ->oldest()
            ->get();
    }

    /**
     * An operator checked ANAF and confirmed the document IS filed.
     *
     * Hands the upload back to the normal pipeline with the real index_incarcare, so
     * CheckUploadStatuses can carry it to Completed/Failed as usual. This is the only
     * way an indeterminate upload can rejoin the pipeline as already-sent — it must
     * never be inferred automatically.
     *
     * @return bool Whether the upload was actually awaiting reconciliation.
     */
    public function resolveIndeterminateAsFiled(EfacturaUpload $upload, string $uploadIndex): bool
    {
        $resolved = $this->indeterminateQuery($upload)->update([
            'status' => UploadStatus::Processing,
            'failure_reason' => null,
            'errors' => null,
            'upload_index' => $uploadIndex,
            'uploaded_at' => now(),
            'processed_at' => null,
        ]) > 0;

        if ($resolved) {
            Log::info('EFactura: Indeterminate upload reconciled as filed at ANAF', [
                'upload_id' => $upload->id,
                'upload_index' => $uploadIndex,
            ]);
        }

        return $resolved;
    }

    /**
     * An operator checked ANAF and confirmed the document is NOT filed.
     *
     * Returns it to Pending so the ordinary upload path re-drives it. Safe only
     * because a human verified the absence — which is exactly why nothing automatic
     * is allowed to make this call.
     *
     * @return bool Whether the upload was actually awaiting reconciliation.
     */
    public function resolveIndeterminateAsNotFiled(EfacturaUpload $upload): bool
    {
        $resolved = $this->indeterminateQuery($upload)->update([
            'status' => UploadStatus::Pending,
            'failure_reason' => null,
            'errors' => null,
            'processed_at' => null,
        ]) > 0;

        if ($resolved) {
            Log::info('EFactura: Indeterminate upload reconciled as not filed, re-queued', [
                'upload_id' => $upload->id,
            ]);
        }

        return $resolved;
    }

    /**
     * Guard shared by both resolutions: only ever act on a row that is still parked
     * as indeterminate, so a concurrent sweep or a stale operator decision cannot
     * overwrite a real outcome.
     */
    private function indeterminateQuery(EfacturaUpload $upload): Builder
    {
        return EfacturaUpload::where('id', $upload->id)
            ->where('status', UploadStatus::Failed)
            ->where('failure_reason', FailureReason::Indeterminate);
    }

    /**
     * Park every upload that has sat in Uploading past the staleness window.
     *
     * The safety net for crashes the queue never reports: a SIGKILL'd worker never
     * runs failed(), so nothing else would ever notice these rows.
     *
     * @return int How many uploads were parked.
     */
    public function sweepStaleUploads(int $staleAfterMinutes): int
    {
        $cutoff = now()->subMinutes($staleAfterMinutes);

        $swept = 0;

        EfacturaUpload::where('status', UploadStatus::Uploading)
            ->where('updated_at', '<', $cutoff)
            ->each(function (EfacturaUpload $upload) use ($staleAfterMinutes, &$swept) {
                $parked = $this->parkStrandedUploadAsIndeterminate(
                    $upload,
                    "Upload was stuck in the uploading state for over {$staleAfterMinutes} minutes; "
                        .'its worker died without reporting a failure.',
                );

                if ($parked) {
                    $swept++;
                }
            });

        return $swept;
    }

    /**
     * Dispatch every pending upload through the rate-limit-aware queue pipeline.
     *
     * This is what callers should almost always want: ProcessSingleUpload does the
     * quota pre-flight, releases instead of failing when the quota is gone, and
     * re-drives retryable failures. processPendingUploads() has none of that.
     *
     * Rows waiting out a rate limit are skipped: markAsRateLimited releases the
     * claim back to Pending (the upload never left the pipeline), but its own
     * ProcessSingleUpload is still queued on a 60s release. ProcessSingleUpload
     * has no uniqueness guard, so dispatching again would stack a second job on
     * the same row every scheduled tick — each retrying every 60s until the 24h
     * retryUntil. With `rate_limits.enabled = false`, which is exactly when real
     * ANAF 429s reach us and the quota pre-flight is skipped, that is unbounded
     * hammering: it saturates the 1000/60min quota hard enough to stop it
     * resetting. A genuinely queued row has failure_reason NULL, because
     * resetForRetry() clears it — so the reason is what tells "nobody is on this"
     * apart from "a job is waiting one out".
     *
     * @return int|null How many uploads were dispatched, or null if the CUI is unknown.
     */
    public function dispatchPendingUploads(?string $cui = null): ?int
    {
        $query = EfacturaUpload::pending()->whereNull('failure_reason');

        if ($cui) {
            $token = $this->tokenService->getToken($cui);
            if (!$token) {
                Log::warning('EFactura: No token found for CUI in dispatchPendingUploads', ['cui' => $cui]);

                return null;
            }
            $query->forToken($token);
        }

        $dispatched = 0;

        $query->each(function (EfacturaUpload $upload) use (&$dispatched) {
            ProcessSingleUpload::dispatch($upload);
            $dispatched++;
        });

        return $dispatched;
    }

    /**
     * Process all pending uploads for a CUI (or all CUIs if null), SYNCHRONOUSLY.
     *
     * Prefer dispatchPendingUploads(). This path has no queue to release into, so it
     * cannot wait out a rate limit — it can only stop. It therefore aborts as soon as
     * an upload comes back rate-limited, leaving the rest Pending for a later run.
     * Previously it ploughed through the whole backlog, marking every invoice past
     * ANAF's quota permanently Failed and firing an InvoiceFailed storm.
     *
     * @return int How many uploads were processed before stopping.
     */
    public function processPendingUploads(?string $cui = null): int
    {
        $query = EfacturaUpload::pending()->with(['token', 'uploadable']);

        if ($cui) {
            $token = $this->tokenService->getToken($cui);
            if (!$token) {
                Log::warning('EFactura: No token found for CUI in processPendingUploads', ['cui' => $cui]);

                return 0;
            }
            $query->forToken($token);
        }

        $processed = 0;

        foreach ($query->lazyById() as $upload) {
            $this->processUpload($upload);
            $processed++;

            if ($upload->refresh()->failure_reason === FailureReason::RateLimited) {
                Log::warning('EFactura: Rate limit reached, stopping synchronous batch', [
                    'processed' => $processed,
                    'stopped_at_upload_id' => $upload->id,
                ]);

                break;
            }
        }

        return $processed;
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
