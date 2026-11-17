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
use BeeCoded\EFactura\Exceptions\PipelineTablesDisabledException;
use BeeCoded\EFactura\Jobs\ProcessSingleUpload;
use BeeCoded\EFactura\Models\EfacturaToken;
use BeeCoded\EFactura\Models\EfacturaUpload;
use BeeCoded\EFacturaSdk\Data\Invoice\UploadOptionsData;
use BeeCoded\EFacturaSdk\Enums\StandardType;
use BeeCoded\EFacturaSdk\Enums\UploadOutcomeKind;
use BeeCoded\EFacturaSdk\Enums\UploadOutcomeReason;
use BeeCoded\EFacturaSdk\Exceptions\ApiException;
use BeeCoded\EFacturaSdk\Exceptions\EFacturaException;
use BeeCoded\EFacturaSdk\Facades\UblBuilder;
use BeeCoded\EFacturaSdk\Services\RateLimiter;
use BeeCoded\EFacturaSdk\Support\UploadOutcome;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class UploadService
{
    /**
     * An ANAF upload index: digits only, as ANAF assigns them and the SDK polls them.
     */
    private const UPLOAD_INDEX_PATTERN = '/^[0-9]{1,64}$/D';

    /** Seconds before an upload that failed without being sent is tried again. */
    public const TRANSIENT_RETRY_DELAY = 30;

    /** Failures without a send that end an upload as Failed/abandoned. */
    public const MAX_TRANSIENT_ATTEMPTS = 5;

    /** How long a dispatch reserves a row for its job (next_poll_at holds the lease). */
    private const LEASE_SECONDS = 300;

    public function __construct(
        protected TokenService $tokenService,
        private readonly RateLimiter $rateLimiter,
    ) {}

    /**
     * Queue a model for e-Factura upload.
     *
     * Idempotent: a model has at most ONE upload record, matching the 1:1 morphOne
     * the trait exposes. See queueFor() for why.
     *
     * @throws PipelineTablesDisabledException
     * @throws \RuntimeException When the CUI has no active token
     * @throws \InvalidArgumentException When $options['standard'] is not a StandardType value
     * @throws DuplicateUploadException If the existing upload is indeterminate and must be
     *                                  reconciled first, is in flight or filed and is re-queued
     *                                  with different options, or kept changing while it was
     *                                  re-queued.
     */
    public function queueUpload(EFacturaUploadableInterface $model, ?array $options = null): EfacturaUpload
    {
        PipelineTablesDisabledException::throwIfDisabled();

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
     * @throws PipelineTablesDisabledException
     * @throws \RuntimeException When the CUI has no active token
     * @throws \InvalidArgumentException When $options['standard'] is not a StandardType value
     * @throws DuplicateUploadException If the existing upload is indeterminate and must be
     *                                  reconciled first, is in flight or filed and is re-queued
     *                                  with different options, or kept changing while it was
     *                                  re-queued.
     */
    public function queueB2CUpload(EFacturaUploadableInterface $model, ?array $options = null): EfacturaUpload
    {
        PipelineTablesDisabledException::throwIfDisabled();

        return $this->queueFor($model, [
            'standard' => $options['standard'] ?? 'UBL',
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
     *   - Pending                          → takes the requested options (atomic; a worker
     *     preparing the old ones loses its claim); its schedule is kept.
     *   - Uploading / Processing / Completed → returned; different options throw. The
     *     filing is already in the pipeline or filed; queueing again would duplicate it.
     *   - Failed, other than indeterminate → reuse the row, reset to Pending. This is
     *     how re-upload after a failure stays possible without accumulating rows and
     *     without morphOne silently returning the stale one.
     *   - Failed, indeterminate            → refuse. It may ALREADY be filed; only a
     *     human who has checked ANAF may resolve it (efactura:reconcile).
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws \RuntimeException When the CUI has no active token
     * @throws \InvalidArgumentException When $attributes['standard'] is not a StandardType value
     * @throws DuplicateUploadException
     */
    protected function queueFor(EFacturaUploadableInterface $model, array $attributes): EfacturaUpload
    {
        // Checked here, not when the job builds the document, where a typo would fail the invoice as invalid.
        $standard = $attributes['standard'] instanceof StandardType
            ? $attributes['standard']
            : StandardType::tryFrom((string) $attributes['standard']);

        if ($standard === null) {
            throw new \InvalidArgumentException("Unknown e-Factura standard '{$attributes['standard']}': use UBL, CN, CII or RASP.");
        }

        $attributes['standard'] = $standard->value;

        $cui = $model->getEfacturaCui();
        $token = $this->tokenService->getToken($cui);

        if (!$token) {
            throw new \RuntimeException("No active token found for CUI: {$cui}");
        }

        $existing = $this->findExistingUpload($model);

        if ($existing) {
            $type = $this->morphTypes($model)[0];

            // A row stored under the class name before the app added a morph map takes the alias, so morphOne finds it.
            if ($existing->uploadable_type !== $type) {
                EfacturaUpload::whereKey($existing->id)->where('uploadable_type', $existing->uploadable_type)->update(['uploadable_type' => $type]);
                $existing->refresh();
            }

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
                'uploadable_type' => $this->morphTypes($model)[0],
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

    /**
     * Rows stored before the app added a morph map carry the class name, so both forms
     * are looked up: missing one would insert a second row (the unique index sees two
     * different types) and file the invoice twice.
     */
    private function findExistingUpload(EFacturaUploadableInterface $model): ?EfacturaUpload
    {
        return EfacturaUpload::whereIn('uploadable_type', $this->morphTypes($model))
            ->where('uploadable_id', $model->getKey())
            ->first();
    }

    /**
     * The type morphOne() queries first (the morph map alias, or the class name), then the
     * class name.
     *
     * @return non-empty-list<string>
     */
    private function morphTypes(EFacturaUploadableInterface $model): array
    {
        $type = $model->getMorphClass();

        return array_values(array_unique([$type, $model::class]));
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
        return $this->reuseUpload($existing, $tokenId, $attributes, reread: false);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  bool  $reread  Whether the row was already re-read after losing a race.
     *
     * @throws DuplicateUploadException
     */
    private function reuseUpload(EfacturaUpload $existing, int $tokenId, array $attributes, bool $reread): EfacturaUpload
    {
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
        // the row can be recycled for a fresh attempt. That holds because every other
        // reason is recorded only on proof: the document never left (pre-transmission,
        // rate limit, configuration) or ANAF refused it (a 4xx, an explicit
        // ExecutionStatus=1 without an upload index, a rejected status poll). Any answer
        // that proves neither — 5xx, transport loss, an unreadable 2xx body, an index or
        // status that cannot be used — is recorded Indeterminate by transmit().
        //
        // Guarded on the state just read: a worker may re-drive or re-classify the row in
        // between, and resetting an in-flight row to Pending would file it twice.
        $recycled = EfacturaUpload::where('id', $existing->id)
            ->where('status', UploadStatus::Failed)
            ->where('failure_reason', $existing->failure_reason)
            ->update([
                'efactura_token_id' => $tokenId,
                'status' => UploadStatus::Pending,
                'failure_reason' => null,
                'errors' => null,
                'upload_index' => null,
                'download_id' => null,
                'response_path' => null,
                'response_verification' => null,
                'uploaded_at' => null,
                'processed_at' => null,
                'response_attempts' => 0,
                'response_failed_at' => null,
                'status_failures' => 0,
                'next_poll_at' => null,
                ...$attributes,
            ]) > 0;

        if (!$recycled) {
            if ($reread) {
                throw new DuplicateUploadException(
                    "Upload {$existing->id} kept changing while it was being re-queued; queue it again.",
                    $existing->refresh(),
                );
            }

            // Decide again on what the row is now: in flight, indeterminate, or failed anew.
            return $this->reuseUpload($existing->refresh(), $tokenId, $attributes, reread: true);
        }

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
            // and we must never rewrite the mode of a document already going to ANAF. A worker
            // preparing the old options then loses its claim.
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
     *
     * The row owns its retry schedule: a run without a lease works the row only when its
     * next_poll_at is due, and a run with one (a dispatched ProcessSingleUpload) only while
     * the row still holds that lease. Every outcome that is not final writes when the row is
     * due again.
     *
     * @param  string|null  $lease  The next_poll_at dispatchPendingUploads() reserved the row with.
     */
    public function processUpload(EfacturaUpload $upload, ?string $lease = null): void
    {
        PipelineTablesDisabledException::throwIfDisabled();

        // Cheap pre-check so we neither prepare nor overwrite a row somebody else owns.
        // The AUTHORITATIVE gate is still the atomic claim, taken below.
        if (!$this->pendingRow($upload, $lease)->exists()) {
            return;
        }

        $upload->loadMissing(['token', 'uploadable']);

        if (!$upload->token) {
            Log::error('EFactura: Upload has no associated token', ['upload_id' => $upload->id]);
            $this->markUploadAsFailed($this->pendingRow($upload, $lease), $upload, ['No associated token found for upload'], FailureReason::Configuration);

            return;
        }

        // A paused grant would be refused before anything is sent: skip building the XML and
        // hand the lease back, so the first dispatch after the pause takes the row at once.
        if ($this->tokenService->refreshCooldownSeconds($upload->token) > 0) {
            Log::info('EFactura: The grant is paused, upload waits', ['upload_id' => $upload->id]);
            $this->retryLater($upload, $this->pendingRow($upload, $lease), false, null);

            return;
        }

        if ($this->rateLimiter->isEnabled()) {
            $quota = $this->rateLimiter->getRemainingQuota('global');

            if ($quota['remaining'] <= 0) {
                Log::info('EFactura: Rate limit quota exhausted, upload waits', [
                    'upload_id' => $upload->id,
                    'resets_in' => $quota['resetsIn'],
                ]);
                $this->retryLater($upload, $this->pendingRow($upload, $lease), false, FailureReason::RateLimited, waitSeconds: $quota['resetsIn']);

                return;
            }
        }

        // PHASE 1 — preparation. Everything here happens strictly BEFORE anything is
        // transmitted, so a failure proves nothing was filed. A broken invoice or a
        // broken model is the author's data problem: terminal, no point retrying. A
        // database error is transient.
        try {
            $model = $upload->uploadable;

            if (!$model instanceof EFacturaUploadableInterface) {
                $this->markUploadAsFailed(
                    $this->pendingRow($upload, $lease),
                    $upload,
                    ['Uploadable model must implement EFacturaUploadableInterface'],
                    FailureReason::Configuration,
                );

                return;
            }

            $xml = $this->generateXml($model);

            $uploadOptions = new UploadOptionsData(
                standard: StandardType::from($upload->standard),
                extern: $upload->is_extern,
                selfBilled: $upload->is_self_billed,
            );
        } catch (\PDOException $e) {
            // A deadlock, timeout or lost connection while the model loads its data (a raw
            // PDOException as well as a QueryException): nothing was sent and it may clear.
            Log::warning('EFactura: Upload preparation hit a database error, will retry', [
                'upload_id' => $upload->id,
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);

            $this->retryLater($upload, $this->pendingRow($upload, $lease), false, FailureReason::Transient, $this->errorText($e));

            return;
        } catch (\Throwable $e) {
            Log::error('EFactura: Upload preparation failed', [
                'upload_id' => $upload->id,
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);

            // The author's own data error: its message is what they need to fix it.
            $this->markUploadAsFailed($this->pendingRow($upload, $lease), $upload, [$e->getMessage()], FailureReason::Validation);

            return;
        }

        // Storage is not the invoice's fault: retried, not recorded as a rejection.
        try {
            $xmlPath = $this->storeXml($upload, $xml);
        } catch (\Throwable $e) {
            Log::error('EFactura: Upload XML could not be stored, will retry', [
                'upload_id' => $upload->id,
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);

            $this->retryLater($upload, $this->pendingRow($upload, $lease), false, FailureReason::Transient, $this->errorText($e));

            return;
        }

        // PHASE 2 — transmission. From here on, a failure's CLASSIFICATION depends on
        // whether it proves the document never entered ANAF's pipeline.
        try {
            $claimed = $this->claimForTransmission($upload, $xmlPath, $lease);
        } catch (\Throwable $e) {
            // Kept while the row names it: a claim that committed before its error was reported.
            rescue(fn () => $this->deleteUnsentXml($upload, $xmlPath), report: false);

            throw $e;
        }

        if (!$claimed) {
            $this->deleteUnsentXml($upload, $xmlPath);

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
     * failure_reason into Processing/Completed. The claim is taken only with the options
     * this run read and is about to send.
     */
    private function claimForTransmission(EfacturaUpload $upload, string $xmlPath, ?string $lease): bool
    {
        $data = [
            'status' => UploadStatus::Uploading,
            'xml_path' => $xmlPath,
            'failure_reason' => null,
            'errors' => null,
            'next_poll_at' => null,
        ];

        $claimed = $this->pendingRow($upload, $lease)
            // A re-queue that changed the options while the document was prepared makes the
            // claim fail, and the next attempt reads them afresh.
            ->where('standard', $upload->standard)
            ->where('is_extern', $upload->is_extern)
            ->where('is_self_billed', $upload->is_self_billed)
            ->where('is_b2c', $upload->is_b2c)
            ->update($data) > 0;

        if (!$claimed) {
            return false;
        }

        // These are the row's values now: a model read earlier (e.g. while Failed) must not
        // let a later update() skip writing a status equal to the stale one.
        $upload->forceFill($data)->syncOriginalAttributes(array_keys($data));

        return true;
    }

    /**
     * The row while it is Pending and this run may work it: holding $lease, or due when
     * there is none. Never guarded on xml_path, which a Pending row does not own.
     */
    private function pendingRow(EfacturaUpload $upload, ?string $lease): Builder
    {
        $row = EfacturaUpload::whereKey($upload->getKey());

        return $lease === null
            ? $row->awaitingDispatch()
            : $row->where('status', UploadStatus::Pending)->where('next_poll_at', $lease);
    }

    /** The row while this run's attempt holds it in Uploading. */
    private function uploadingRow(EfacturaUpload $upload): Builder
    {
        return EfacturaUpload::whereKey($upload->getKey())
            ->where('status', UploadStatus::Uploading)
            ->where('xml_path', $upload->xml_path);
    }

    /**
     * The row of this run's attempt, Uploading or parked as Indeterminate meanwhile: ANAF's
     * answer is the truth about the document a sweep or failed() could only guess at.
     */
    private function attemptRow(EfacturaUpload $upload): Builder
    {
        return EfacturaUpload::whereKey($upload->getKey())
            ->where('xml_path', $upload->xml_path)
            ->where(fn (Builder $state) => $state->where('status', UploadStatus::Uploading)
                ->orWhere(fn (Builder $parked) => $parked
                    ->where('status', UploadStatus::Failed)
                    ->where('failure_reason', FailureReason::Indeterminate)));
    }

    /**
     * Send a prepared document to ANAF and classify the outcome.
     *
     * The SDK's UploadOutcome classifies the answer and whatever the upload call threw;
     * this method only maps its verdict onto the row. One thing is decided here first,
     * because UploadOutcome cannot know it: a failure that makes the grant unusable
     * (TokenService::isGrantFailure()) hands the claim back uncounted. See FailureReason
     * for why "retryable" is narrower than "transient-looking".
     *
     * - Refused (an ANAF 4xx other than 408/429, the SDK's own validation, ExecutionStatus=1
     *   without an index): final, recorded as validation.
     * - Not sent, rate limited (the SDK's pre-flight limiter or ANAF's 429): back to Pending
     *   until the wait is over, at least a minute.
     * - Not sent otherwise (a transport failure before anything was sent): counted, retried.
     * - Uncertain (a 5xx, a 408 timeout, an unreadable 2xx answer, an index that cannot be
     *   polled, a transport failure that may have delivered, anything unknown): ANAF may have
     *   filed it, so it is parked for reconciliation. Re-sending could DOUBLE-FILE a legal
     *   invoice.
     */
    protected function transmit(EfacturaUpload $upload, string $xml, UploadOptionsData $uploadOptions): void
    {
        // Flipped the moment the SDK client exists and the document is being handed to it.
        // Everything before that point — reading and decrypting the token, refreshing it,
        // building the client — provably precedes the HTTP request.
        $transmissionStarted = false;
        $response = null;
        $thrown = null;

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

            $outcome = UploadOutcome::fromResponse($response);
        } catch (\Throwable $e) {
            if ($this->tokenService->isGrantFailure($e, $transmissionStarted)) {
                Log::warning('EFactura: The grant could not be used, upload waits', [
                    'upload_id' => $upload->id,
                    'exception' => $e::class,
                    'error' => $e->getMessage(),
                ]);

                $this->retryLater($upload, $this->uploadingRow($upload), true, null, $this->errorText($e));

                return;
            }

            $thrown = $e;
            $outcome = UploadOutcome::fromThrowable($e);
        }

        // ANAF assigns an upload index only to a document it received, so a usable index
        // means its outcome is decided under that index whatever ExecutionStatus says.
        if ($outcome->kind === UploadOutcomeKind::Accepted && $outcome->index !== null) {
            $this->markUploadAsProcessing($upload, $outcome->index);

            return;
        }

        if ($outcome->kind === UploadOutcomeKind::Refused) {
            $errors = $thrown !== null ? [$this->errorText($thrown)] : ($response->errors ?? ['Unknown error']);
            $this->markUploadAsFailed($this->attemptRow($upload), $upload, $errors, FailureReason::Validation);

            return;
        }

        if ($thrown !== null && $outcome->kind === UploadOutcomeKind::NotSent) {
            $rateLimited = $outcome->reason === UploadOutcomeReason::RateLimited;

            Log::warning($rateLimited ? 'EFactura: Rate limit hit during upload' : 'EFactura: Upload was not sent, will retry', [
                'upload_id' => $upload->id,
                'reason' => $outcome->reason?->value,
                'retry_after' => $outcome->retryAfterSeconds,
                'exception' => $thrown::class,
                'error' => $thrown->getMessage(),
            ]);

            $this->retryLater(
                $upload,
                $this->uploadingRow($upload),
                true,
                $rateLimited ? FailureReason::RateLimited : FailureReason::Transient,
                $this->errorText($thrown),
                $outcome->retryAfterSeconds ?? 0,
            );

            return;
        }

        // Uncertain. The index ANAF returned is kept even when it cannot be polled: SPV is
        // searched by it.
        $index = $response?->indexIncarcare;

        Log::error('EFactura: Upload outcome is unknown, parked for reconciliation', [
            'upload_id' => $upload->id,
            'upload_index' => $index,
            'exception' => $thrown !== null ? $thrown::class : null,
            'status_code' => $thrown instanceof ApiException ? $thrown->statusCode : null,
        ]);

        // The "outcome unknown" warning leads, so ANAF's text is never read as a rejection.
        $detail = $thrown !== null
            ? [$this->errorText($thrown)]
            : array_values(array_filter($response->errors ?? [], fn (string $error): bool => trim($error) !== ''));

        $this->markUploadAsFailed(
            $this->uploadingRow($upload),
            $upload,
            [__($thrown !== null ? 'efactura::messages.upload_outcome_no_answer' : 'efactura::messages.upload_outcome_unknown'), ...$detail],
            FailureReason::Indeterminate,
            $index !== null && $index !== '' && mb_strlen($index) <= 255 ? ['upload_index' => $index] : [],
        );
    }

    /**
     * Hand an upload that was not sent back to Pending, with when it is due again.
     *
     * $row is the guard this run holds the row by: pendingRow() before the claim, or
     * uploadingRow() after it ($attempt), when this attempt's XML is also released. A
     * write that does not land (another run moved the row) changes nothing and fires
     * nothing.
     *
     * - null reason: nothing counts against the row (a paused or unusable grant). Pending
     *   only gives the lease back; an attempt also records $error.
     * - RateLimited: due after the wait, at least a minute. Never abandoned for waiting.
     * - Transient: counted in status_failures, due after TRANSIENT_RETRY_DELAY; the
     *   MAX_TRANSIENT_ATTEMPTS-th ends the upload as Failed/abandoned.
     *
     * @return bool Whether the write landed.
     */
    private function retryLater(
        EfacturaUpload $upload,
        Builder $row,
        bool $attempt,
        ?FailureReason $reason,
        ?string $error = null,
        int $waitSeconds = 0,
    ): bool {
        $xmlPath = $upload->xml_path;
        $released = $attempt ? ['status' => UploadStatus::Pending, 'xml_path' => null] : [];

        $data = [...$released, ...match ($reason) {
            null => ['next_poll_at' => null],
            FailureReason::RateLimited => ['failure_reason' => $reason, 'next_poll_at' => now()->addSeconds(max($waitSeconds, 60))],
            default => ['failure_reason' => $reason, 'next_poll_at' => now()->addSeconds(self::TRANSIENT_RETRY_DELAY)],
        }];

        if ($error !== null) {
            $data['errors'] = [$error];
        }

        if ($reason === FailureReason::Transient) {
            // The two guards exclude each other, so at most one of them lands. The count on a
            // Pending row never passes MAX - 1, so the final one writes MAX.
            if ($this->markUploadAsFailed(
                (clone $row)->where('status_failures', '>=', self::MAX_TRANSIENT_ATTEMPTS - 1),
                $upload,
                [$error ?? 'The upload failed '.self::MAX_TRANSIENT_ATTEMPTS.' times before it was sent.'],
                FailureReason::Abandoned,
                [...($attempt ? ['xml_path' => null] : []), 'status_failures' => self::MAX_TRANSIENT_ATTEMPTS],
            )) {
                $this->deleteUnsentXml($upload, $attempt ? $xmlPath : null);

                return true;
            }

            $landed = $row->where('status_failures', '<', self::MAX_TRANSIENT_ATTEMPTS - 1)
                ->increment('status_failures', 1, $data) > 0;
        } else {
            $landed = $row->update($data) > 0;
        }

        if (!$landed) {
            return false;
        }

        $upload->forceFill($data)->syncOriginalAttributes(array_keys($data));

        if ($attempt) {
            $this->deleteUnsentXml($upload, $xmlPath);
        }

        // Only a refusal of this attempt is announced: the quota pre-check sent nothing.
        if ($attempt && $reason === FailureReason::RateLimited) {
            rescue(fn () => event(new InvoiceRateLimited($upload, [$error], $waitSeconds > 0 ? $waitSeconds : null)));
        }

        return true;
    }

    /**
     * The text a row or an event may show for a failure: an SDK exception's own message,
     * otherwise only the class, since a database or storage message can carry SQL or data.
     */
    private function errorText(\Throwable $e): string
    {
        return $e instanceof EFacturaException
            ? $e->getMessage()
            : __('efactura::messages.local_failure', ['class' => class_basename($e)]);
    }

    /**
     * Delete the XML file of an attempt that was not sent: every re-drive stores a new one,
     * so a rate-limited row would otherwise leave one file per minute behind.
     *
     * Kept while the row names it: a claim that committed before its connection failed sent
     * this file.
     */
    private function deleteUnsentXml(EfacturaUpload $upload, ?string $path): void
    {
        if ($path === null || EfacturaUpload::whereKey($upload->getKey())->value('xml_path') === $path) {
            return;
        }

        rescue(fn () => Storage::disk(config('efactura.storage.disk', 'local'))->delete($path));
    }

    /**
     * Record the index ANAF assigned: the document is in ANAF's pipeline, and polling reads
     * its outcome. Clears any park written meanwhile.
     *
     * A failed write is tried once more and the index logged at critical level, since only
     * this write keeps it. A write that finds the row moved checks whether an earlier run of
     * it already landed (a lost connection after the commit).
     */
    private function markUploadAsProcessing(EfacturaUpload $upload, string $index): void
    {
        $data = [
            'status' => UploadStatus::Processing,
            'upload_index' => $index,
            'uploaded_at' => now(),
            'status_failures' => 0,
            'next_poll_at' => null,
            'failure_reason' => null,
            'errors' => null,
            'processed_at' => null,
        ];

        try {
            $written = $this->attemptRow($upload)->update($data) > 0;
        } catch (\Throwable $e) {
            Log::critical('EFactura: Could not record the upload index ANAF assigned, writing it again', [
                'upload_id' => $upload->id,
                'upload_index' => $index,
                'exception' => $e::class,
            ]);

            $written = $this->attemptRow($upload)->update($data) > 0;
        }

        $written = $written || EfacturaUpload::whereKey($upload->getKey())
            ->where('status', UploadStatus::Processing)
            ->where('upload_index', $index)
            ->exists();

        if (!$written) {
            Log::critical('EFactura: The upload index ANAF assigned was not recorded: the row was moved meanwhile', [
                'upload_id' => $upload->id,
                'upload_index' => $index,
            ]);

            return;
        }

        $upload->forceFill($data)->syncOriginalAttributes(array_keys($data));
        rescue(fn () => event(new InvoiceUploaded($upload)));
    }

    /**
     * Fail an upload for good, guarded by $row, and announce it.
     *
     * InvoiceFailed is reserved for outcomes the pipeline will not itself recover from, so
     * that consumers alerting on it are not woken by transient noise. A write that does not
     * land (another run moved the row) fires nothing.
     *
     * @param  array<int, string>  $errors
     * @param  array<string, mixed>  $also  Further columns written in the same statement.
     * @return bool Whether the write landed.
     */
    private function markUploadAsFailed(Builder $row, EfacturaUpload $upload, array $errors, FailureReason $reason, array $also = []): bool
    {
        if ($reason->isRetryable()) {
            throw new \LogicException("A {$reason->value} upload is retried, not failed: use retryLater().");
        }

        $data = [
            'status' => UploadStatus::Failed,
            'failure_reason' => $reason,
            'errors' => $errors,
            'processed_at' => now(),
            ...$also,
        ];

        if ($row->update($data) === 0) {
            return false;
        }

        $upload->forceFill($data)->syncOriginalAttributes(array_keys($data));
        rescue(fn () => event(new InvoiceFailed($upload, $errors)));

        return true;
    }

    /**
     * The upload job failed: it threw, or the queue gave up on it.
     *
     * An Uploading row may have reached ANAF, so it is parked for reconciliation, never sent
     * again. A Pending row sent nothing, so it counts as one transient attempt. Guarded by
     * $lease, so a job whose processUpload() already recorded an outcome counts nothing more.
     *
     * A redelivered copy (MaxAttemptsExceededException, not a timeout) means the first run's
     * worker died: its Uploading row is left to SweepStaleUploads, and its Pending row counts
     * the try while it still holds the job's lease, so a worker that dies preparing the same
     * document every time ends the upload instead of being dispatched again forever.
     */
    public function jobFailed(EfacturaUpload $upload, ?string $lease, \Throwable $e): void
    {
        Log::error('EFactura: Upload job failed', [
            'upload_id' => $upload->id,
            'exception' => $e::class,
            'error' => $e->getMessage(),
        ]);

        if ($e instanceof MaxAttemptsExceededException && !$e instanceof TimeoutExceededException) {
            if ($lease !== null) {
                $this->retryLater($upload, $this->pendingRow($upload, $lease), false, FailureReason::Transient, 'The upload job stopped before it recorded an outcome.');
            }

            return;
        }

        if ($this->parkStrandedUploadAsIndeterminate($upload, 'Upload job died mid-flight: '.$this->errorText($e))) {
            return;
        }

        $this->retryLater($upload, $this->pendingRow($upload, $lease), false, FailureReason::Transient, $this->errorText($e));
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

        rescue(fn () => event(new InvoiceFailed($upload->refresh(), [$reason])));

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
     *
     * @throws \InvalidArgumentException When the index is not an ANAF upload index (digits only).
     */
    public function resolveIndeterminateAsFiled(EfacturaUpload $upload, string $uploadIndex): bool
    {
        $uploadIndex = trim($uploadIndex);

        if (preg_match(self::UPLOAD_INDEX_PATTERN, $uploadIndex) !== 1) {
            throw new \InvalidArgumentException('The ANAF upload index must be 1 to 64 digits.');
        }

        $resolved = $this->indeterminateQuery($upload)->update([
            'status' => UploadStatus::Processing,
            'failure_reason' => null,
            'errors' => null,
            'upload_index' => $uploadIndex,
            'uploaded_at' => now(),
            'processed_at' => null,
            'status_failures' => 0,
            'next_poll_at' => null,
            // A parked row's response belonged to the outcome it was parked with; the
            // next status check records the download id for this index.
            'download_id' => null,
            'response_path' => null,
            'response_verification' => null,
            'response_attempts' => 0,
            'response_failed_at' => null,
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
     * Refused by default for an upload that has an upload index: ANAF received that
     * document and decides its outcome under the index (filed, rejected, or not taken in),
     * so re-sending it could double-file the invoice. When ANAF keeps answering that index
     * with an error, an operator who checked SPV under it and did not find the invoice
     * opts in with $despiteIndex: the index is cleared, kept in `errors` and logged.
     *
     * @param  bool  $despiteIndex  The operator checked SPV under the upload index and the
     *                              invoice is not there.
     * @return bool Whether the upload was actually awaiting reconciliation.
     *
     * @throws \InvalidArgumentException When the parked upload has an ANAF upload index and
     *                                   $despiteIndex is false.
     */
    public function resolveIndeterminateAsNotFiled(EfacturaUpload $upload, bool $despiteIndex = false): bool
    {
        $indexed = $this->indeterminateQuery($upload)->whereNotNull('upload_index')->value('upload_index');

        if ($indexed !== null && !$despiteIndex) {
            throw new \InvalidArgumentException(
                "Upload {$upload->id} has ANAF upload index {$indexed}: ANAF decides its outcome under that index, "
                    .'so it cannot be resolved as not filed.'
            );
        }

        // Guarded on the index just read, so the note names the index that was cleared.
        $resolved = $this->indeterminateQuery($upload)->where('upload_index', $indexed)->update([
            'status' => UploadStatus::Pending,
            'failure_reason' => null,
            'errors' => $indexed === null ? null : [
                "Resolved as not filed by an operator who checked SPV under ANAF upload index {$indexed}.",
            ],
            'processed_at' => null,
            'upload_index' => null,
            'uploaded_at' => null,
            'download_id' => null,
            'response_path' => null,
            'response_verification' => null,
            'response_attempts' => 0,
            'response_failed_at' => null,
            'status_failures' => 0,
            'next_poll_at' => null,
        ]) > 0;

        if ($resolved && $indexed !== null) {
            Log::warning('EFactura: Indexed indeterminate upload reconciled as not filed on the operator\'s word, re-queued', [
                'upload_id' => $upload->id,
                'upload_index' => $indexed,
            ]);
        } elseif ($resolved) {
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

        // lazyById, not each(): each() pages by OFFSET, and every parked row leaves the
        // scope, so the next page would skip as many rows as this one parked.
        $stale = EfacturaUpload::where('status', UploadStatus::Uploading)
            ->where('updated_at', '<', $cutoff);

        foreach ($stale->lazyById() as $upload) {
            $parked = $this->parkStrandedUploadAsIndeterminate(
                $upload,
                "Upload was stuck in the uploading state for over {$staleAfterMinutes} minutes; "
                    .'its worker died without reporting a failure.',
            );

            if ($parked) {
                $swept++;
            }
        }

        return $swept;
    }

    /**
     * Queue a ProcessSingleUpload for each of this grant's uploads that is due.
     *
     * Each row is first leased: its next_poll_at becomes now + LEASE_SECONDS in a guarded
     * write, and only the dispatch whose write landed queues a job, carrying the lease. A
     * second dispatcher (the next tick, another server) finds the row not due, and a job
     * whose lease the row no longer holds does nothing. A job that never runs gives the row
     * back when its lease ends.
     *
     * The caller passes a usable grant (TokenService::usableTokens() or usableToken()): a
     * paused one's rows would only be handed back.
     *
     * @return int How many uploads were dispatched.
     */
    public function dispatchPendingUploads(EfacturaToken $token): int
    {
        $dispatched = 0;

        // lazyById, not each(): a row a worker takes (at once on the sync queue) leaves the
        // scope, and each()'s OFFSET pages would skip as many rows.
        foreach (EfacturaUpload::awaitingDispatch()->forToken($token)->lazyById() as $upload) {
            $lease = now()->addSeconds(self::LEASE_SECONDS)->toDateTimeString();

            if (EfacturaUpload::whereKey($upload->id)->awaitingDispatch()->update(['next_poll_at' => $lease]) > 0) {
                ProcessSingleUpload::dispatch($upload, $lease);
                $dispatched++;
            }
        }

        return $dispatched;
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
     * Store XML file, under a name no other run of the row shares (two runs can store in
     * the same second).
     */
    protected function storeXml(EfacturaUpload $upload, string $xml): string
    {
        $disk = config('efactura.storage.disk', 'local');
        $basePath = config('efactura.storage.path', 'efactura');

        $filename = sprintf(
            '%s/uploads/%s/%s_%s_%s.xml',
            $basePath,
            $upload->token->cui,
            $upload->id,
            now()->format('Ymd_His'),
            Str::ulid(),
        );

        if (!Storage::disk($disk)->put($filename, $xml)) {
            throw new \RuntimeException("Could not store the upload XML at {$filename} on disk {$disk}");
        }

        return $filename;
    }
}
