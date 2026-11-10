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

### Added

- Migration `2024_01_01_000008_add_failure_counters_to_efactura_tables`: `efactura_uploads.status_failures`
  and `efactura_messages.download_attempts` (both default 0). Run `php artisan migrate` before queue workers start the 4.0 code (keep workers stopped during the deploy): 4.0 writes the new columns right after ANAF accepts an upload. It also
  moves `failed` / `validation` rows that have an `upload_index` but no `download_id` to
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

### Changed

- **Requires `bee-coded/laravel-efactura-sdk` ^4.0.**
- Upload answers: a digit-only `index_incarcare` makes the upload `Processing` with that index and
  fires `InvoiceUploaded`, whatever `ExecutionStatus` says (it was `Failed` / `validation`). An
  answer that does not say whether ANAF took the document in (an unreadable 2xx body, a non-digit
  index, success without an index, no recognised status) is `Failed` / `indeterminate` (it was
  `validation`). Only `ExecutionStatus=1` without an index stays `validation`.
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
  `recordPoisonedResponse()` counts with a query-builder `increment()` guarded on that download id. `markUploadAsCompleted()` /
  `markUploadAsFailed()` are unchanged and no longer called by `checkStatus()`.
- An error-only status answer (SDK 4.0 `hasAnafError()`: an XML `<Errors/>` header, a JSON
  `eroare`) is parked `indeterminate` with ANAF's errors on the row, after the translated note.
- `efacturaAwaitingResponse()` leaves out uploads at `EfacturaUpload::MAX_RESPONSE_ATTEMPTS`, and
  `efactura:status --upload` says when a response is no longer downloaded automatically.
- `DuplicateUploadException::indeterminate()` suggests `--filed=<id> --index=<its index>` (found in
  SPV) or `--not-filed=<id> --despite-index` (checked SPV, not there) for an upload that has an
  upload index.
- Message sync also skips (with the same warning) a listed id that is not digits, and records every
  `MESAJ CUMPARATOR…` tip as `received`.
- A received invoice is marked downloaded only while `is_downloaded` is still false, and
  `InvoiceReceived` fires only for the run that marked it. A run that loses that write, or the
  `response_path` write of a response download, deletes the file it stored (unless the winner
  stored the same file name).
- Signatures for subclasses: `DownloadService::markMessageAsDownloaded()` returns `bool` (whether it
  marked the message) instead of `void`; `UploadService::markUploadAsCompleted()` accepts a `?string` download
  id, `DownloadService::recordPoisonedResponse()` (protected) takes any `\Throwable`, and
  `DownloadService::checkStatus()` returns `StatusCheckOutcome` instead of `void`.

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

### Known limitation: `QUEUE_CONNECTION=sync`

- A sync job's `release()` does not put the job back, so an upload that hits a rate limit (or the
  refresh cooldown) is not retried by its own job. A rate-limited row then waits for
  `RetryRateLimitedUploads`, which takes it once it has not changed for
  `rate_limit.retry_window_hours`; a cooling-down row waits for the next `ProcessPendingUploads`.
  Use a real queue connection for uploads, or, without a worker, schedule
  `efactura:upload --sync`, whose next run retries a row it left rate-limited.

### Removed

- `TokenService::persistTokenRefreshWithLock()` and `TokenService::isFresherThanStored()`
  (protected), replaced by the conditional save.
- `DownloadService::isPoisonedBody()` (protected): every permanent download failure is now counted.
- `UploadService::markUploadAsUploading()`: unused; only the atomic claim sets `uploading`.

### Fixed

- Messages listed without an id no longer merge into one `efactura_messages` row.
- `InvoiceRateLimited` fires only when the upload was actually handed back to `pending`; a row a
  sweep or `failed()` parked during the call no longer announces a rate limit.
- A model read before its row was reset for a retry (for example by a second job on the same row)
  no longer leaves a never-sent upload in `uploading` after a failed run: the claim updates the
  model's original attributes, so the failure write always records `status` and `failure_reason`.
- A status check, response download or received-invoice download that fails permanently is no
  longer retried on every run forever.
