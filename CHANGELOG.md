# Changelog

All notable changes to `bee-coded/laravel-efactura` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html). Entries start at 4.0.0;
earlier releases are described in the README's upgrade sections.

## [4.0.0] - Unreleased

Every behaviour change since 3.1.1. The README's "Upgrading to v4.0" section explains each one.

### Breaking

- Laravel 11 is no longer supported: `illuminate/*` is now `^12.0|^13.0` (and
  `orchestra/testbench` `^10.0|^11.0` for development), matching the SDK 4.0 requirement.
- `routes.middleware` defaults to `['web', 'auth']` (it was `['web']`): a guest can no longer start
  an authorization. `auth` does not check that the user may act for the CUI; gate the
  `efactura/auth/{cui}` route yourself (for example a `can:` middleware) or disable the routes. The
  callback also needs the logged-in browser, including after `efactura:auth`. A published config
  keeps its value.
- An authorization started through the `efactura/auth/{cui}` route is bound to that browser session
  and user: the callback refuses it ("Invalid or expired state parameter") in another session or for
  another user. `TokenService::getAuthorizationUrl()` / `EFactura::getAuthorizationUrl()` take an
  optional session nonce and user id, and `validateOAuthState()` the same pair; your own controller
  passes them to bind its flows. The `efactura:auth` command passes neither, so its URL still
  completes in any logged-in browser. `OAuthCallbackController::redirect()` now takes the `Request`
  first (`redirect(Request $request, string $cui)`).
- Removed `UploadService::markUploadAsCompleted()` (unguarded status write; nothing called it) and
  `TokenService::updateTokenFromOAuth()` (wrote a grant without checking that the token is active or
  that the grant is newer). Store a new grant with `TokenService::storeToken()`; status answers go
  through `DownloadService::checkStatus()`.
- `MessageSyncService::reportsNoMessages()` (protected) takes a `PaginatedMessagesResponseData`
  instead of a `ListMessagesResponseData`, and `SyncMessages::failBatch()` (protected) is removed.
- `UploadService::classifyApiException()` (protected) is removed: the upload call's answer and
  exceptions are classified by the SDK's `UploadOutcome` (`fromResponse()` / `fromThrowable()`).

### Added

- Migration `2024_01_01_000008_add_failure_counters_to_efactura_tables`: `efactura_uploads.status_failures`
  and `efactura_messages.download_attempts` (both default 0). Run `php artisan migrate` before queue workers start the 4.0 code (keep workers stopped during the deploy): 4.0 writes the new columns right after ANAF accepts an upload. It also
  moves `failed` rows with `validation` or no `failure_reason` (a pre-3.0 failure migration
  `000005` could not classify) that have an `upload_index` but no `download_id` to
  `indeterminate`: 3.x recorded `nok` status answers without a download id that way, and
  re-queueing one filed it again. 3.x never stored the index on a failed upload answer, so those
  rows have no index; a 3.x `validation` row without an index cannot be told apart from a real
  rejection and is left as it is. The data move is idempotent before 4.0 writes rows; re-running it
  after a rollback also moves 4.0's own "nepreluat" rows to `indeterminate` (the safe direction).
- Config key `polling.max_status_failures` (`EFACTURA_POLLING_MAX_STATUS_FAILURES`, default 5):
  permanent status-check failures before an upload is parked as indeterminate.
- `EfacturaMessage::MAX_DOWNLOAD_ATTEMPTS` (3): permanent download failures before a received
  invoice is no longer downloaded.
- Translation keys `completed_without_download_id`, `status_nok_without_download_id`,
  `status_poll_failures_exhausted`, `not_processed_at_anaf` and `upload_outcome_unknown` (en, ro).
- `BeeCoded\EFactura\Enums\StatusCheckOutcome` (`Checked`, `NotPollable`, `RateLimited`, `Failed`),
  returned by `DownloadService::checkStatus()`.
- `efactura:reconcile --not-filed=<id> --despite-index` and
  `UploadService::resolveIndeterminateAsNotFiled($upload, despiteIndex: true)`: an operator who
  checked SPV under an upload's index and did not find the invoice re-queues it. The command asks
  for confirmation and refuses with `--no-interaction`; the index is cleared, kept in `errors` and
  logged as a warning. Without the flag an indexed upload is still refused.
- `BeeCoded\EFactura\Exceptions\RefreshCooldownException` (an SDK `AuthenticationException`,
  code 401), thrown during the refresh cooldown, `TokenService::refreshCooldownSeconds($token)`
  (seconds left of a grant's cooldown, 0 when none), and the `EfacturaUpload::awaitingDispatch()`
  scope used by the pending-upload dispatchers.
- Protected extension points: `DownloadService::parkAsIndeterminate()`,
  `TokenService::persistClientRefresh()` and `TokenService::saveRefreshedTokens()`.
- `CHANGELOG.md`, and one recommended cadence per scheduled job in the README and the MCP job
  reference. The package still schedules nothing itself.
- `BeeCoded\EFactura\Services\CachedAnafLookup`: a cached, fail-closed company check. Construct it
  with the lookup client (normally `new AnafDetailsClient(strict: true, ...)`); `resolve($cui)`
  returns an `AnafLookupResult` (`status`, `cui`, `queryDate`, `active`) with `AnafLookupStatus`
  `Found`, `NotFound`, `Unavailable` or `Busy`, and `cachedRead($cui)` returns today's cached
  verdict without calling ANAF. Found is cached for the rest of the day and NotFound 15 minutes in
  the default cache store, as plain arrays, keyed by policy version, lookup URL, ANAF date and CUI; Unavailable
  and Busy are not cached. Found needs exactly one company with that CUI and NotFound exactly that
  CUI in `notFound`; an answer that arrives after midnight (Bucharest) is Unavailable. A cache that
  cannot store the verdict does not change the answer.
- Config key `pipeline_tables` (`EFACTURA_PIPELINE_TABLES`, default `true`). Set it to `false` when
  you use only tokens, OAuth and the raw client: the package then loads and publishes only the token
  migrations (`000001`, `000004`, `000009`), and the upload/message pipeline (`queueUpload()`,
  `queueB2CUpload()`, `processUpload()`, `DownloadService`, `MessageSyncService`, the manager's
  `uploadService()` / `downloadService()` / `messageSyncService()`, the upload, status, download and
  message jobs and the `efactura:upload`, `efactura:status`, `efactura:sync` and `efactura:reconcile`
  commands) throws `BeeCoded\EFactura\Exceptions\PipelineTablesDisabledException`, also when a
  service is resolved straight from the container. `getToken()`, `getAuthorizationUrl()`,
  `client()` and `tokenService()` keep working. Migrations you already published stay published.
- Migration `2024_01_01_000009_make_efactura_token_expiry_an_instant` (PostgreSQL only, not gated):
  `efactura_tokens.expires_at` becomes `timestamp with time zone`. The naive values were written as
  app-timezone wall time, so they are converted `AT TIME ZONE` `app.timezone`; run it with the same
  `APP_TIMEZONE` that wrote them. It is a no-op when the column is already `timestamptz` and on
  every other database.
- Migration `2024_01_01_000010_add_response_verification_and_next_poll_to_efactura_uploads_table`
  (not loaded when `pipeline_tables` is false): `efactura_uploads.response_verification`
  (`BeeCoded\EFactura\Enums\ResponseVerification`: `verified`, `limits_only`, `mismatch`; null for
  a response stored before 4.0) and `efactura_uploads.next_poll_at` with an index on
  `(status, next_poll_at)`.
- Migration `2024_01_01_000011_key_efactura_messages_per_token` (not loaded when `pipeline_tables`
  is false): `efactura_messages` is unique on `(efactura_token_id, message_id)` instead of
  `message_id`, so a message listed to two connected companies is stored once per company. It is
  re-entrant and cannot abort on data.

### Changed

- **Requires `bee-coded/laravel-efactura-sdk` ^4.0.**
- `queueB2CUpload()` honours `$options['standard']` like `queueUpload()` (it always stored `UBL`).
  The package sends the standard you queue whatever the document type: queue a credit note with
  `['standard' => 'CN']`.
- The OAuth callback no longer shows an unrecognised `error` / `error_description` from the request:
  it flashes a fixed message and reports the shortened values. A non-string `error`,
  `error_description`, `code` or `state` counts as absent (it was a 500).
  `getOAuthErrorMessage()`'s default ignores the description.
- Upload answers: a digit-only `index_incarcare` makes the upload `Processing` with that index and
  fires `InvoiceUploaded`, whatever `ExecutionStatus` says (it was `Failed` / `validation`). An
  answer that does not say whether ANAF took the document in (an unreadable 2xx body, a non-digit
  index, success without an index, no recognised status) is `Failed` / `indeterminate` (it was
  `validation`). Only `ExecutionStatus=1` without an index stays `validation`.
- Upload exceptions are classified by the SDK's `UploadOutcome::fromThrowable()`; the refresh
  cooldown hand-back and "thrown before the document reached the client = `transient`" are decided
  first, as before. Two outcomes change: a `RequestNotSentException` (the SDK proved the request
  never left: DNS, connect, TLS or a connect-phase timeout) is `transient` and retried (it was an
  `indeterminate` 500), and an HTTP 408 is `indeterminate` (it was `validation`): a timeout says
  nothing about whether ANAF filed the document. An ApiException with a status outside 2xx/4xx/5xx
  is `indeterminate` too (it was `validation`); an `XmlParsingException` cannot escape the upload call
  in SDK 4.0 and is no longer caught separately. On status checks and downloads an HTTP 408 is
  transient like a 5xx and no longer counts toward parking or giving up.
- A queue retry of a stale-guarded job (`jobs.max_staleness_seconds`) is never discarded as stale,
  so a failing per-CUI job reaches `failed_jobs`. Nor is a per-CUI job that a run without a CUI
  dispatched: it waits behind the other CUIs' jobs, so a stale guard would drop the later CUIs on
  every run. A per-CUI job your scheduler dispatches directly keeps the guard.
- Status answers are read through the SDK's `StatusResponseData::verdict()`. A download id that is
  not ANAF's digits (1 to 64) cannot be downloaded and counts as none: `ok` with one is `Completed`
  with `download_id = null`, `nok` with one is `Failed` / `indeterminate` (both stored the id before).
- Status answers: `ok` without a download id is `Completed` with `download_id = null` and a note in
  `errors`; no response is downloaded, so `InvoiceProcessed` does not fire for it.
- Status answers: `nok` without a download id is `Failed` / `indeterminate`, keeping the upload
  index (it was `validation`).
- Status answers: "XML cu erori nepreluat de sistem" is `Failed` / `validation` with no download.
- Permanent status-check failures (a 4xx, an unreadable 2xx answer, an index the SDK refuses, any
  other error) are counted and park the upload as `indeterminate` at
  `polling.max_status_failures`; transient ones (5xx, network, token problems) are retried without
  counting. An unreadable 2xx status answer is a permanent failure: the parked upload keeps its
  index, so recover it with `efactura:reconcile --filed=<id> --index=<its upload index>`.
- Code that re-arms a parked upload itself (for example a raw update back to `Processing`) must also
  reset `status_failures` to 0, or the next permanent failure parks it again.
  `resolveIndeterminateAsFiled()` and `markUploadAsProcessing()` reset it.
- A per-message SDK rate limit (`RateLimitScope::Status` for status checks, `RateLimitScope::Download`
  for downloads) skips that row and the batch carries on. A global limit, any other limit and
  ANAF's 429 still stop the batch.
- Response and received-invoice downloads count every permanent failure (now also a 4xx or a
  refused id, not only a 2xx body that is not a ZIP) against `EfacturaUpload::MAX_RESPONSE_ATTEMPTS`
  / `EfacturaMessage::MAX_DOWNLOAD_ATTEMPTS`; transient failures are not counted.
- More outcomes are `indeterminate`, and `queueUpload()` refuses every `indeterminate` row with
  `DuplicateUploadException`: resolve them with `php artisan efactura:reconcile`.
- `UploadService::resolveIndeterminateAsFiled()` trims the index and throws
  `InvalidArgumentException` when it is not 1 to 64 digits; `efactura:reconcile --filed` prints the
  error and exits with a failure code.
- `UploadService::resolveIndeterminateAsNotFiled()` throws `InvalidArgumentException` for an upload
  that has an upload index and changes nothing, unless called with `despiteIndex: true`: ANAF
  decides that upload's outcome under its index, so re-queueing it could double-file the invoice.
  `efactura:reconcile --not-filed` prints why, suggests `--filed=<id> --index=<its upload index>`
  (or `--despite-index` when SPV has no invoice under that index) and exits with a failure code.
- The `efactura:reconcile` list shows `Upload index`, `Uploaded at` and `Parked at` (the
  `Claimed at` column, which showed the parked time).
- `efactura:status --upload=<id>` prints "Done." only when ANAF answered. A check skipped at the
  per-message status limit, or a failed check, exits with a failure code and says so; an upload
  with nothing to poll says why, and a response download that was skipped or failed is reported.
- Listener exceptions: an exception from a listener of `TokenStored`, `TokenRefreshed`,
  `InvoiceUploaded`, `InvoiceProcessed`, `InvoiceFailed`, `InvoiceRateLimited` or `InvoiceReceived`
  is reported, never propagated, and never changes the state recorded before the event. A throwing
  `TokenStored` listener no longer sends the OAuth callback to the error URL with its message.
- Token bookkeeping after an ANAF call never replaces the call's result or exception. A grant the
  SDK refreshed during a call is saved even when the call threw, by one conditional write instead
  of a lock; a failed save is logged as critical. `LockTimeoutException` now comes only from the
  pre-call refresh lock.
- `createClient()` passes the SDK's `tokenPersister`: a grant the SDK refreshes is saved (same
  conditional write) while the SDK still holds its refresh lock, so the next worker reloads the new
  refresh token instead of spending the old one. The save after the call stays as a retry and
  changes nothing once the grant is stored. `handleClientTokenRefresh()` uses the conditional write
  too (it wrote unconditionally) and fires `TokenRefreshed` only when it stored the grant, so a
  hand-driven client announces a refresh once.
- Message sync skips a listed message without a usable id, with a warning, instead of upserting it.
- A deactivated token is refused before every ANAF call (`RuntimeException` "Token for CUI … has
  been deactivated"), not only when it was about to be refreshed: uploads fail `transient`, status
  checks are not counted.
- A grant ANAF refuses to refresh (HTTP 400/401) is not offered again for 15 minutes, keyed by the
  token id and its refresh token and checked before the refresh lock; later calls in that window
  throw `RefreshCooldownException` (an `AuthenticationException`, 401) without calling ANAF. The
  cooldown is checked again once the refresh lock is taken, so workers that waited on the lock do
  not each send a refused request. An upload refused this way stays `pending`; its job sleeps the
  rest of the cooldown without building or storing its XML and without spending a transient
  attempt, and the dispatchers add no job for it meanwhile. A grant that stays refused leaves its
  uploads `pending` for at most `rate_limit.retry_window_hours`, then `failed()` abandons them with
  `InvoiceFailed`; usually sooner, because after each cooldown the next caller asks ANAF again and
  an upload job's refusal spends one transient attempt, so a lone upload is abandoned after about
  (`jobs.max_transient_attempts` − 1) × the 15-minute cooldown (about an hour at the defaults). The cooldown also
  refuses calls while the access token still has its last 120 seconds or less to run. A status run skips the grant's rows and logs the cooldown once per grant, as a
  warning, instead of an error per row. A new grant is tried at once. Tokens are not deactivated
  automatically.
- The wrapper's refresh lock is held for the SDK HTTP timeout (rounded up) plus 30 seconds, like
  the SDK's own lock, instead of a fixed 120 seconds.
- `ProcessPendingUploads` and `dispatchPendingUploads()`
  skip a `pending` row with a `failure_reason` (a rate-limited row whose own job still owns it), and
  any row changed within `jobs.transient_retry_delay_seconds` + 60 seconds (90 s by default): a job
  waiting out a transient retry, a job released by the quota pre-check (which now touches the row),
  or a freshly queued row whose own job may still be queued. **Hosts that rely on the scheduler
  alone see a new upload sent up to about 90 seconds later than before**; dispatch
  `ProcessSingleUpload` after `queueUpload()` for an immediate send. The two queue dispatchers also
  skip a row whose grant is in its refresh cooldown. `efactura:upload` prints how many `pending`
  rows it left out (waiting for their own job, or on a grant in its cooldown); the new
  `UploadService::pendingLeftOut()` returns those counts.
- The synchronous `processPendingUploads()` (`efactura:upload --sync`) still takes every `pending`
  row, a fresh or rate-limited one included, since no job owns a row on that path: a row it left
  rate-limited is retried by its next run. It skips a row whose grant is in its refresh cooldown and
  no longer counts it as processed; `--sync` prints the skipped rows separately.
  `RetryRateLimitedUploads` re-dispatches a `pending` / `rate_limited` row only once it has not
  changed for `rate_limit.retry_window_hours`, whatever its age (`retry_max_age_days` applies to
  `failed` rows only). A `ProcessSingleUpload` that finds uploads disabled clears `rate_limited` on
  its `pending` row, so `ProcessPendingUploads` picks it up once uploads are enabled again.
- `queueUpload()` recycles a failed row with a write guarded on the status and reason it read; if
  another worker changed the row meanwhile, it decides again on its current state (once; then it
  throws `DuplicateUploadException`). The recycle also resets `response_attempts`,
  `response_failed_at` and `status_failures`.
- `resolveIndeterminateAsFiled()` and `resolveIndeterminateAsNotFiled()` also clear `download_id`,
  `response_path`, `response_attempts`, `response_failed_at` (and `status_failures`).
- `jobs.max_transient_attempts` counts transient failures of one queued job in the cache
  (`efactura:upload:{id}:transient:{job uuid}`, kept for `rate_limit.retry_window_hours`) instead of
  `attempts()`, so rate-limit releases no longer use it up. A run without a queue job (`handle()`
  called directly) counts under an id of its own.
- An XML file, response or received invoice that cannot be written to the storage disk (`put()`
  returns `false` or throws) is an error. For an upload it is `transient` (it was `validation`) and
  fires no `InvoiceFailed`; a download is logged and retried by the next run without counting
  against the download attempts. `failBeforeTransmission()` fires `InvoiceFailed` only for a reason
  that is not retried.
- Status checks record an answer only on a row that is still `Processing`, and response downloads
  store a path only when none is stored and the row still has the download id they fetched, so
  overlapping runs fire `InvoiceFailed` / `InvoiceProcessed` once and a row re-queued meanwhile
  gets no old file. The `InvoiceFailed` of a `nok` with a download id carries ANAF's errors.
  `recordPoisonedResponse()` counts with a query-builder `increment()` guarded on that download id.
  `markUploadAsFailed()` is unchanged and no longer called by `checkStatus()`.
- An error-only status answer (SDK 4.0 `hasAnafError()`: an XML `<Errors/>` header, a JSON
  `eroare`) is parked `indeterminate` with ANAF's errors on the row, after the translated note.
- `efacturaAwaitingResponse()` leaves out uploads at `EfacturaUpload::MAX_RESPONSE_ATTEMPTS`, and
  `efactura:status --upload` says when a response is no longer downloaded automatically.
- `DuplicateUploadException::indeterminate()` suggests `--filed=<id> --index=<its index>` (found in
  SPV) or `--not-filed=<id> --despite-index` (checked SPV, not there) for an upload that has an
  upload index.
- Message sync also skips (with the same warning) a listed id that is not digits, and records every
  `MESAJ CUMPARATOR…` tip as `received`, so they are downloaded and fire `InvoiceReceived` like a
  received invoice; listeners check `$message->details['tip']`.
- A received invoice is marked downloaded only while `is_downloaded` is still false, and
  `InvoiceReceived` fires only for the run that marked it. A run that loses that write, or the
  `response_path` write of a response download, deletes the file it stored (unless the winner
  stored the same file name).
- Signatures for subclasses: `DownloadService::markMessageAsDownloaded()` returns `bool` (whether it
  marked the message) instead of `void`; `DownloadService::recordPoisonedResponse()` (protected) takes
  any `\Throwable`, and `DownloadService::checkStatus()` returns `StatusCheckOutcome` instead of `void`.
- Message sync always uses ANAF's paginated list (`/listaMesajePaginatieFactura`): one window per run,
  from 60 days minus 70 minutes ago to a minute ago, counted in UTC (in an `app.timezone` with summer
  time, wall-clock days made the window longer than the SDK's 60-day limit for about two months a
  year, and every sync failed), paged until the last page. Each page is one call
  against the SDK's `paginated_list_per_day_cui` quota (default 50,000) instead of one
  `simple_list_per_day_cui` call per run.
- A `SyncMessages`, `CheckUploadStatuses`, `DownloadResponses` or `DownloadReceivedInvoices` job
  without a CUI only dispatches one job of its kind per active CUI (for the three upload/download
  jobs, per active CUI with work due) and does no work itself, so each CUI gets its own timeout and
  retries and one slow CUI no longer holds up the rest. `SyncMessages` is now unique per CUI
  (`ShouldBeUnique`, `jobs.unique_for_seconds`) and discards itself when stale
  (`jobs.max_staleness_seconds`); its batch no longer dead-letters a `MessageSyncFailedException`
  (each CUI's job fails on its own; `efactura:sync` still reports the batch).
  `DownloadReceivedInvoices` gained `uniqueId()` (`$cui ?? 'all'`).
- `CheckUploadStatuses`, `DownloadResponses` and `DownloadReceivedInvoices` run behind
  `WithoutOverlapping($cui ?? 'all')` (not released, lock expires after the timeout + 30 s): a run
  that outlasts a short schedule interval is no longer overlapped by the next one on another worker,
  which spent the same rows' daily quota twice.
- `RetryRateLimitedUploads` measures `rate_limit.retry_max_age_days` for a `failed` row from when it
  failed (`processed_at`), not from `created_at`; a failed row without `processed_at` (older than any
  3.x release) is no longer retried.
- While an upload waits after a rate limit, a refresh cooldown or a transient failure after its claim,
  `xml_path` is null and that attempt's XML file is deleted (nothing was sent; the next attempt stores
  a new file). A run that loses the claim deletes the file it stored, unless the row names that file.
- New uploads store `uploadable_type` as the model's `getMorphClass()` (the morph map alias, if your
  app has one; otherwise the class name, as before). `queueUpload()` finds an existing row under
  either form.
- On PostgreSQL `EfacturaToken` writes dates with their offset (`getDateFormat()` is
  `Y-m-d H:i:sP`), so `expires_at` is the same instant whatever the session time zone; naive columns
  ignore the offset. A query-builder binding of a Carbon value against `expires_at` (for example
  `EfacturaToken::where(...)->update(['expires_at' => now()])`) has no offset and is read in the
  session time zone: write through a model instead.
- `efactura:auth` and `efactura:reconcile` refuse to run while `enabled` is false, like the other
  commands.
- Response archives are checked with the SDK's `ArchiveInspector` before they are stored: against the
  uploaded XML when ANAF accepted the upload and its XML is still stored (`verified`), otherwise only
  its limits (`limits_only`; a removed XML file counts as not stored also on a disk with
  `throw => true`). An archive that does not hold the uploaded invoice is stored as
  received, marked `mismatch` and logged as an error; `InvoiceProcessed` still fires. An archive
  outside the limits (not a ZIP, empty, encrypted, too large, too many members) is counted against
  `EfacturaUpload::MAX_RESPONSE_ATTEMPTS` like any permanent download failure; a local failure
  (temporary file, reading the stored XML) is retried by the next run without counting.
  Received-invoice archives get the same limits-only check before they are stored: one outside the
  limits counts against `EfacturaMessage::MAX_DOWNLOAD_ATTEMPTS` and fires no `InvoiceReceived`; a
  local failure is retried without counting.
  `DownloadService` takes an optional `ArchiveInspector` as its third constructor argument. The SDK
  needs `ext-zip` and `ext-dom`.
- Status polling follows the SDK's `PollSchedule`: after each "still in progress" answer the upload's
  `next_poll_at` is set 1, 5, 15 or 60 minutes ahead, less a one-minute margin (never under 60
  seconds, so the 1-minute step stays 1 minute) so the read is due on the scheduled run it aims at (by the time since `uploaded_at`: under 10 minutes, an hour, three
  hours, older; an upload without `uploaded_at` counts as older).
  `EfacturaUpload::needsStatusCheck()` takes only due rows (`next_poll_at` null or past), so
  `CheckUploadStatuses` (and `efactura:status` without `--upload`) reads only those and fans out
  only to CUIs that have one; `efactura:status --upload=<id>` still checks at once. Every transition
  that resets `status_failures` (`queueUpload()` recycling a row, `markUploadAsProcessing()`,
  `resolveIndeterminateAsFiled()`, `resolveIndeterminateAsNotFiled()`) clears `next_poll_at`, and
  those that clear `response_path` also clear `response_verification`.

### Changed (4.0): guarded writes fire no Eloquent model events

- Status answers (`DownloadService`), response paths, recycled failed rows (`EfacturaUpload`),
  received-invoice downloads (`EfacturaMessage`) and refreshed grants (`EfacturaToken`) are written
  with query-builder updates guarded on the state they expect (`status = processing`,
  `response_path IS NULL` and the fetched `download_id`, `is_downloaded = false`, an older
  `expires_at`), so overlapping runs
  cannot both apply one answer. Eloquent's `updating` / `updated` / `saving` / `saved` events and
  model observers do not fire for them.
- Upgrade: listen to the package events instead: `InvoiceProcessed`, `InvoiceFailed`,
  `InvoiceReceived`, `TokenRefreshed`. They fire once, for the run whose write landed.

### Known limitation: `queueUpload()` inside a transaction on MySQL/MariaDB

- Under REPEATABLE READ, a caller transaction cannot see a row another worker inserted for the
  same model while it was open, and a locking re-read fails with "Record has changed since last
  read". A lost insert race inside the caller's transaction therefore surfaces as
  `UniqueConstraintViolationException` instead of returning the existing upload. The winner's row
  stands; nothing is filed twice. Outside a transaction, and on PostgreSQL and SQLite, the
  existing upload is returned. Inside a caller transaction the recycle's re-read also sees the
  snapshot, so a lost recycle race (another worker re-drove the failed row) surfaces as
  `DuplicateUploadException` ("kept changing") instead of returning the row. Call `queueUpload()`
  after your transaction commits. CI now runs the suite on MariaDB as well as PostgreSQL.

### Known limitation: message sync capacity

- A `SyncMessages` run lists one CUI's 60-day window from page 1 within the job's 120-second
  timeout: at roughly 2–3 seconds a page about 40 pages, some 20,000 messages. A CUI with more times
  out every run and its later pages are not synced. The timeout stays 120 seconds because raising it
  also needs the queue connection's `retry_after` raised above it. Before 4.0 such a CUI failed on
  the simple list's 500-message limit, so this is a ceiling, not a regression.

### Known limitation: a re-queued failed upload keeps its old XML file

- Queueing a failed upload again keeps `xml_path` naming the failed attempt's file until the next
  claim stores a new one; the old file then stays on the disk, named by no row. The row does not
  record whether ANAF received that file (an ANAF refusal) or the SDK refused it before sending, so
  the package does not delete it. Unchanged since 3.x.

### Known limitation: `QUEUE_CONNECTION=sync`

- A sync job's `release()` does not put the job back, so an upload that hits a rate limit (or the
  refresh cooldown) is not retried by its own job. A rate-limited row then waits for
  `RetryRateLimitedUploads`, which takes it once it has not changed for
  `rate_limit.retry_window_hours`; a cooling-down row waits for the next `ProcessPendingUploads`.
  Use a real queue connection for uploads, or, without a worker, schedule
  `efactura:upload --sync`, whose next run retries a row it left rate-limited.
- A batch job without a CUI (`SyncMessages`, `CheckUploadStatuses`, `DownloadResponses`,
  `DownloadReceivedInvoices`) dispatches its per-CUI jobs, and the sync connection runs them inline
  inside the parent: a child's exception propagates through the parent job and stops the remaining
  CUIs of that run.

### Removed

- `TokenService::persistTokenRefreshWithLock()` and `TokenService::isFresherThanStored()`
  (protected), replaced by the conditional save.
- `DownloadService::isPoisonedBody()` (protected): every permanent download failure is now counted.
- `UploadService::markUploadAsUploading()`: unused; only the atomic claim sets `uploading`.
- `UploadService::markUploadAsCompleted()` and `TokenService::updateTokenFromOAuth()`: unused,
  unguarded writes (see Breaking).
- `SyncMessages::failBatch()` (protected): the job no longer syncs a batch.

### Fixed

- Messages listed without an id no longer merge into one `efactura_messages` row.
- Message sync no longer moves one ANAF message between two tokens that list the same id.
- `InvoiceRateLimited` fires only when the upload was actually handed back to `pending`; a row a
  sweep or `failed()` parked during the call no longer announces a rate limit.
- A model read before its row was reset for a retry (for example by a second job on the same row)
  no longer leaves a never-sent upload in `uploading` after a failed run: the claim updates the
  model's original attributes, so the failure write always records `status` and `failure_reason`.
- A status check, response download or received-invoice download that fails permanently is no
  longer retried on every run forever.
- `RetryRateLimitedUploads` threw a `TypeError` on every run when `EFACTURA_RATE_LIMIT_RETRY_MAX_DAYS`
  was set (`env()` returns a string); both retry limits are now cast to int.
- Message sync failed on every run for a CUI with more than 500 messages in 60 days (ANAF's simple
  list refuses those).
- The status, response, received-invoice, stale-upload and pending-upload loops no longer skip rows:
  they paged by offset, so rows that left the scope during a page pushed others past the next one
  (with over 1,000 matching rows, a run visited about 1,000).
- A failed row that was recycled long after it was first queued and then failed transiently was
  never retried by `RetryRateLimitedUploads` (its age counted from its first attempt).
- Every re-drive of a rate-limited upload left another XML file behind (about one a minute).
- In an app with a morph map the trait helpers and scopes (`isUploadedToEfactura()`,
  `notUploadedToEfactura()`, …) never saw the upload, because the row stored the class name.
- An authorization started on the web could be completed by another browser: whoever got a user to
  open the ANAF link stored that user's grant under a CUI of their choosing.
- `RetryingAnafDetailsClient` retries only the SDK's own limiter refusal. ANAF's own 429 (SDK 4.0
  `RateLimitExceededException` with `fromProvider`) is re-thrown at once instead of being asked
  again up to `anaf_lookup.retry_attempts` times. The refresh cooldown still starts only on ANAF's
  400/401 refusals, never on a code-0 failure such as missing OAuth configuration.
