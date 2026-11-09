# Changelog

All notable changes to `bee-coded/laravel-efactura` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html). Entries start at 4.0.0;
earlier releases are described in the README's upgrade sections.

## [4.0.0] - Unreleased

Every behaviour change since 3.1.1. The README's "Upgrading to v4.0" section explains each one.

### Added

- Migration `2024_01_01_000008_add_failure_counters_to_efactura_tables`: `efactura_uploads.status_failures`
  and `efactura_messages.download_attempts` (both default 0). Run `php artisan migrate`.
- Config key `polling.max_status_failures` (`EFACTURA_POLLING_MAX_STATUS_FAILURES`, default 5):
  permanent status-check failures before an upload is parked as indeterminate.
- `EfacturaMessage::MAX_DOWNLOAD_ATTEMPTS` (3): permanent download failures before a received
  invoice is no longer downloaded.
- Translation keys `completed_without_download_id`, `status_nok_without_download_id`,
  `status_poll_failures_exhausted`, `not_processed_at_anaf` and `upload_outcome_unknown` (en, ro).
- `BeeCoded\EFactura\Enums\StatusCheckOutcome` (`Checked`, `NotPollable`, `RateLimited`, `Failed`),
  returned by `DownloadService::checkStatus()`.
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
  that has an upload index and changes nothing: ANAF issues an index only for a document it took
  in, so re-queueing it would double-file the invoice. `efactura:reconcile --not-filed` prints why,
  suggests `--filed=<id> --index=<its upload index>` and exits with a failure code.
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
- Message sync skips a listed message without a usable id, with a warning, instead of upserting it.
- Signatures for subclasses: `UploadService::markUploadAsCompleted()` accepts a `?string` download
  id, `DownloadService::recordPoisonedResponse()` (protected) takes any `\Throwable`, and
  `DownloadService::checkStatus()` returns `StatusCheckOutcome` instead of `void`.

### Removed

- `TokenService::persistTokenRefreshWithLock()` and `TokenService::isFresherThanStored()`
  (protected), replaced by the conditional save.
- `DownloadService::isPoisonedBody()` (protected): every permanent download failure is now counted.

### Fixed

- Messages listed without an id no longer merge into one `efactura_messages` row.
- A status check, response download or received-invoice download that fails permanently is no
  longer retried on every run forever.
