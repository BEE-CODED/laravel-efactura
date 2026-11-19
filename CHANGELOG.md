# Changelog

All notable changes to `bee-coded/laravel-efactura` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html). Entries start at 4.0.0. To
upgrade from 2.x, follow the v3.1.1 README first.

## [4.1.0] - 2026-11-19

### Added

- `efactura.connection` (`EFACTURA_QUEUE_CONNECTION`): the queue connection every job the package
  dispatches goes on — the scheduled batch jobs, the per-company jobs they dispatch,
  `ProcessSingleUpload` and `CheckSingleUploadStatus`. Before, these jobs always ran on the app's
  default connection, so an app whose default connection has a large `retry_after` (for example
  1020 for its own long jobs) could not give them the `retry_after` they need: above their
  120-second timeout, and with the time a job waits in the queue, under about 240 seconds (README,
  [Requirements](README.md#requirements)). Set it to a connection whose `retry_after` is about 180,
  and give the package jobs a queue (`EFACTURA_QUEUE`) that no worker of another connection reads on
  the same Redis database or jobs table: the worker that takes a job applies its own connection's
  `retry_after`. Null or blank keeps the app's default connection, as before. A `connection:` passed to
  `$schedule->job()` and a `dispatchSync()` still win. `efactura:upload` names the connection in its
  queue-worker hint when one is set.

### Changed

- Requires `bee-coded/laravel-efactura-sdk` ^4.0.1, which accepts brick/math 0.20 and 1.x, so
  `composer require bee-coded/laravel-efactura` installs in a fresh Laravel 13 application (which
  locks brick/math 1.0) without `-W`.
- `composer.json` declares `laravel/framework` `^12.27.1|^13.0`. 4.0.0 needed 12.27.1 too, but
  Composer enforced it only through the SDK's brick/math range.
- Release archives ship no maintainer files: tests, CI, static-analysis and style configs, the MCP
  server's sources and tests and local notes are left out (`.gitattributes`). `mcp/dist` and
  `mcp/package.json` still ship.
- The MCP server reports its version as 4.1.0 instead of 1.0.0.

## [4.0.0] - 2026-11-18

Every behaviour change since 3.1.1.

### Upgrading from 3.1.1

Follow the steps in order. Each step names the section that has the details.

1. Check every host that runs the app: PHP 8.4 or later with `ext-dom` and `ext-zip`, and Laravel
   12.27.1 or later, or Laravel 13. See the README's [Requirements](README.md#requirements).
2. On every production host, set `EFACTURA_SANDBOX=false` (or `0`). In 4.0 a blank or `null` value
   selects the ANAF sandbox; in 3.x it selected production (SDK CHANGELOG, "Fixed: blank env values
   keep the safe default").
3. If `EFACTURA_STORAGE_DISK` is present but blank, set it to the disk your e-Factura files are on
   (your `FILESYSTEM_DISK`). In 4.0 a blank value means `local` (Breaking, "Before upgrading, check
   `EFACTURA_STORAGE_DISK`").
4. Run the token query in Breaking, "Before upgrading, check `efactura_tokens.cui`", and note the rows
   it returns. Fix them in step 15, not earlier: 3.x finds a token only by its stored spelling, so a
   row fixed while 3.x runs stops matching the CUI your code passes.
5. Check every CUI your own code passes to the package (for example to `getToken()`, a scheduled
   job's `$cui` or a command's `--cui`). In 4.0 a CUI with a leading zero, `''`, `'0'` or `'RO'` is
   refused or fails the job; spaces and an `RO` prefix are still accepted. Correct those CUIs in
   step 15.
6. List the uploads that migration `000008` sends again:

   ```sql
   SELECT id, uploadable_type, uploadable_id, failure_reason, processed_at
   FROM efactura_uploads
   WHERE status = 'failed' AND failure_reason IN ('rate_limited', 'transient');
   ```

   A row with `processed_at` within 7 days of the migration goes back to `pending` and is sent; an
   older row becomes `abandoned` (Added, migration `000008`). Check the recent rows against SPV and
   your own records, and note each one that was filed by hand or whose document was cancelled.
7. Change your requirement to `bee-coded/laravel-efactura` `^4.0`, and to
   `bee-coded/laravel-efactura-sdk` `^4.0` if you require the SDK directly. On a development machine,
   run `composer update bee-coded/laravel-efactura bee-coded/laravel-efactura-sdk` (or
   `composer update bee-coded/laravel-efactura -W`) and commit `composer.lock`: updating the wrapper
   alone cannot resolve while the lock holds SDK 3.x.
8. In the same branch, remove `RetryRateLimitedUploads` from your schedule (the class is gone, see
   Removed) and run `ProcessPendingUploads` every minute. The README's
   [Recommended schedule](README.md#recommended-schedule) lists the cadence of every job.
9. If you published `config/efactura.php`, set `routes.middleware` to `['web', 'auth']`: a published
   config keeps `['web']`. Add your own check that the user may act for the CUI (Breaking, the
   `routes.middleware` entry). A check placed in `routes.middleware` also runs on
   `efactura/callback` with a null CUI and must allow a null CUI; otherwise put the check on the
   `efactura/auth/{cui}` route only. Delete the published config's `rate_limit` and `jobs` blocks, which
   4.0 ignores (Removed).
10. In the same branch, update your calls to every API listed in Breaking, including the builder
    input SDK 4.0 refuses, and run your tests against 4.0.
11. Start the maintenance window: pause the scheduler on every host that runs `schedule:run`. Keep
    the 3.x workers running.
12. Let the 3.x workers drain the queue the package jobs use: run
    `php artisan queue:monitor <connection>:<queue>` (for example `redis:efactura`) until it reports
    0. Never flush the queue instead: a 3.x `ProcessSingleUpload` that survives into 4.0 fails, and a
    flushed 3.x batch job leaves its unique lock behind, which blocks the 4.0 job with the same key
    for up to an hour.
13. Run `php artisan down` on every web host, because 4.0 code reads the columns step 17 adds. A
    worker takes no jobs in maintenance mode unless it runs with `--force`: if `queue:monitor` now
    shows a job your web code queued after step 12, drain it with a 3.x `queue:work --force`, then
    stop the 3.x workers.
14. Run the step 6 query again, because 3.x workers changed these rows until the drain. Mark each
    upload that must not be sent again as `abandoned`; migration `000008` leaves an `abandoned` row as
    it is:

    ```sql
    UPDATE efactura_uploads SET failure_reason = 'abandoned'
    WHERE id IN (...) AND status = 'failed' AND failure_reason IN ('rate_limited', 'transient');
    ```

15. Fix the token rows from step 4 and your own CUIs from step 5, as Breaking, "Before upgrading,
    check `efactura_tokens.cui`", describes.
16. Before you deploy, check the `retry_after` of the queue connection the package jobs use on every
    host: it must be above the jobs' 120-second timeout (Laravel's default of 90 is not), and
    `retry_after` plus the time a job waits in the queue must stay under about 240 seconds, because
    of the 300-second dispatch lease (README, [Requirements](README.md#requirements)). If your
    default connection's `retry_after` must stay larger for your own jobs, set
    `EFACTURA_QUEUE_CONNECTION` (available from 4.1.0) to a connection whose `retry_after` is about
    180, and run the package's workers on that connection. Keep `APP_TIMEZONE` unchanged in this
    deploy: migration `000009` converts the stored token expiry times with it (Added). Change
    `APP_TIMEZONE`, if at all, in a later release. Then deploy the branch to every host: code,
    `composer.lock` (installed with `composer install`), schedule, config (including any
    `retry_after` change) and caller changes together. Keep every host in maintenance mode, and do
    not start workers or the scheduler.
17. Run `php artisan migrate --force` once. The package loads migrations `000008` to `000011` itself,
    also when you published the older ones (Added). On PostgreSQL, migrations `000010`
    (`efactura_uploads`) and `000011` (`efactura_messages`) each run in one transaction and build
    their indexes inside it: their table is locked against writes, and also against reads, until
    the migration finishes. The app is down in this step, so nothing waits.
18. Run `php artisan up`, then start the 4.0 workers, then re-enable the scheduler.
19. Expect a burst of ANAF reads on the first runs: every `processing` upload is due for a status
    read at once (`next_poll_at` is null), and a 3.x response that stopped after 3 download attempts
    and was processed within the last 60 days is downloaded again and fires `InvoiceProcessed`
    (Breaking, the download-retry entry). Rate-limit warnings during this burst are not an upgrade
    failure.
20. Check `failed_jobs` and the log. A 3.x `ProcessSingleUpload` in `failed_jobs` survived the drain;
    an error naming `RetryRateLimitedUploads` means the old schedule line is still deployed;
    `EFactura: no usable token for CUI` names a company whose token is unknown, deactivated, paused or
    not stored under a canonical CUI.
21. Run `php artisan efactura:reconcile`: migration `000008` moved 3.x `failed` rows with `validation`
    or no `failure_reason` that have an upload index but no download id to `indeterminate`. Resolve
    each one after checking SPV, as the README's
    [Reconciliation (indeterminate uploads)](README.md#reconciliation-indeterminate-uploads)
    describes.

### Breaking

- Laravel 11 is no longer supported: the package requires `laravel/framework` `^12.0|^13.0`
  instead of `illuminate/contracts`, `illuminate/database` and `illuminate/support`
  `^11.0|^12.0|^13.0` (and `orchestra/testbench` `^10.0|^11.0` for development;
  `pestphp/pest-plugin-laravel` is no longer a development dependency), matching the SDK 4.0
  requirement.
- **Before upgrading, check `efactura_tokens.cui`.** 4.0 reads a CUI only in its canonical form
  (SDK `VatNumberValidator::canonical()`: 2 to 10 digits without a leading zero, or a 13-digit CNP;
  spaces and an `RO` prefix are ignored). 3.x could store a leading-zero CUI through the OAuth
  route, and spaces, letters or wrong lengths through `efactura:auth`. In 4.0 such a row is skipped
  by `TokenService::usableTokens()`, `getToken()` cannot reach it, and its uploads wait in `pending`
  with no `InvoiceFailed`; a client built straight from it throws the SDK's `ValidationException`.
  List those rows:

  ```sql
  -- PostgreSQL
  SELECT id, cui FROM efactura_tokens WHERE cui !~ '^([1-9][0-9]{1,9}|[1-9][0-9]{12}|0{13})$';
  -- MySQL / MariaDB
  SELECT id, cui FROM efactura_tokens WHERE cui NOT REGEXP '^([1-9][0-9]{1,9}|[1-9][0-9]{12}|0{13})$';
  ```

  Where no row already holds the canonical CUI, set `cui` to the canonical form in place; resolve a
  row that has a canonical twin by hand. Never delete such a row: its uploads and messages are
  deleted with it. Do not re-authorise such a company (`efactura:auth` or the `efactura/auth` route)
  before its row is fixed: that stores a second, canonical row, which syncs ANAF's messages again
  and fires `InvoiceReceived` again for invoices the old row already received, while the old row's
  uploads stay pending. Fix the rows during the maintenance window, at step 15 of "Upgrading from
  3.1.1".
- **Before upgrading, check `EFACTURA_STORAGE_DISK`.** A blank value now means `local` (see Fixed).
  If yours is blank, set it to the disk your files are on (your `FILESYSTEM_DISK`) before upgrading,
  or copy them to `local` under the same paths: otherwise response verification for those uploads
  falls back to limits-only and their cleanup deletes nothing.
- **Stop 3.x queue workers before running the migrations, and drain the jobs they queued.** Start
  the 4.0 workers after `php artisan migrate`. Migration `000008` changes 3.x rows a 3.x worker can
  still write (see Added), and the 4.0 jobs take different constructor arguments. Drain with the 3.x
  workers still running, then stop them: steps 11 to 18 of "Upgrading from 3.1.1".
- **Schedule `ProcessPendingUploads` every minute and remove `RetryRateLimitedUploads` from your
  schedule.** The upload row owns its retry schedule: an upload that hit a rate limit, or failed
  before it was sent, waits in `pending` with `failure_reason` `rate_limited` or `transient` until its
  `next_poll_at`, and the first `ProcessPendingUploads` run after that sends it again. Nothing
  re-drives a `failed` upload any more. A `transient` upload is tried
  again after `UploadService::TRANSIENT_RETRY_DELAY` (30 s), at most
  `UploadService::MAX_TRANSIENT_ATTEMPTS` (5) times, and is then `failed` / `abandoned` with one
  `InvoiceFailed`; a rate-limited one waits for the limiter's `retryAfterSeconds` (at least 60 s,
  also after ANAF's own 429) and is never given up for waiting.
- `routes.middleware` defaults to `['web', 'auth']` (it was `['web']`): a guest can no longer start
  an authorization. `auth` does not check that the user may act for the CUI; gate the
  `efactura/auth/{cui}` route yourself (for example a `can:` middleware; a `can:` gate in
  `routes.middleware` also runs on `efactura/callback` with a null CUI and must allow it, see the
  README's OAuth Flow) or disable the routes. The callback also needs the logged-in browser,
  including after `efactura:auth`. A published config
  keeps its value.
- An authorization started through the `efactura/auth/{cui}` route is bound to that browser session
  (its CSRF token) and user: the callback refuses it ("Invalid or expired state parameter") in
  another session, for another user, or when the session was regenerated in between (for example by
  a login). `TokenService::getAuthorizationUrl()` / `EFactura::getAuthorizationUrl()` take an
  optional session nonce and user id, and `validateOAuthState()` the same pair; your own controller
  passes them to bind its flows. The `efactura:auth` command passes neither, so its URL still
  completes in any logged-in browser. `OAuthCallbackController::redirect()` now takes the `Request`
  first (`redirect(Request $request, string $cui)`).
- The batch service methods take the company's token instead of an optional CUI, where null meant
  every company. `DownloadService::checkAllStatuses()`, `downloadAllResponses()` and
  `downloadAllReceivedInvoices()` are `(EfacturaToken $token): bool` (they returned nothing); they
  return false when the grant is paused or a rate limit or a grant failure stopped the run, and
  never throw a rate limit. `UploadService::dispatchPendingUploads(EfacturaToken $token): int`
  replaces `dispatchPendingUploads(?string $cui): ?int`. Use `TokenService::usableTokens()` for the
  companies to run.
- `DownloadService::downloadResponse()` and `downloadMessage()` return `bool` (true only when that
  call stored the file) and no longer throw a rate limit. `checkStatus()` returns a
  `StatusCheckOutcome` and throws no rate limit or ANAF failure (a local database error, or
  `PipelineTablesDisabledException`, still propagates): `RateLimited` covers the upload's own per-message limit, a
  global limit and ANAF's 429, and a grant failure is `Failed`.
- Constructors: `DownloadService` takes `(TokenService, ArchiveInspector = new ArchiveInspector)` (it
  took an `UploadService` second), `UploadService` takes `(TokenService, RateLimiter)`, and
  `ProcessSingleUpload` takes `(EfacturaUpload $upload, ?string $lease = null)`.
- `UploadService::processUpload()` gains `?string $lease`. Without one it sends the upload only when
  its row is due (`next_poll_at` null or past) and `pending`; `EFactura::processUpload()` and a
  `ProcessSingleUpload` dispatched by hand without a lease behave the same.
- The batch jobs `CheckUploadStatuses`, `DownloadResponses`, `DownloadReceivedInvoices`,
  `ProcessPendingUploads` and `SyncMessages` extend `CompanyBatchJob`: `handle()` is final and takes
  `(TokenService, Container)`, and each job's work is in a public `run(EfacturaToken $token, …)`. Code
  that called `->handle($service)` on them calls `app()->call([$job, 'handle'])`.
  `ProcessSingleUpload::handle()` takes only the `UploadService`.
- `UploadService::markUploadAsProcessing()` and `markUploadAsFailed()` are private.
- A refused, paused or unreadable grant, or a deactivated company, leaves its uploads `pending`,
  uncounted, with no `InvoiceFailed`, until the company is re-authorised (3.x failed them
  `transient`). A paused, unreadable or deactivated grant claims no row; a run that finds the grant
  refused by ANAF stops after claiming the row it was reading, and a download counts that as one
  try.
- Downloads are retried for as long as ANAF keeps the archive, with no attempt cap.
  `response_attempts` and `download_attempts` count the tries made; the tries back off from an hour,
  doubling to a day, each less a one-minute margin so a try is due on the scheduled run it aims at,
  for 60 days after the upload was processed (a received invoice: after its
  message date, or when it was listed). 3.x uploads that stopped at 3 attempts and were processed within the last 60 days
  are downloaded again. `efacturaAwaitingResponse()` and `EfacturaUpload::needsResponseDownload()`
  take only uploads processed within the last 60 days, and `needsResponseDownload()` only those
  whose next try is due. `DownloadService::recordPoisonedResponse()` (protected) no longer counts:
  it records ANAF's errors and `response_failed_at` while the response is still missing.
- The commands refuse bad input with exit code 1: an invalid, blank or bare `--cui`, a CUI with no
  active token (unknown or deactivated), and a malformed, bare, zero or other-company `--upload`, `--filed` or
  `--not-filed`. `efactura:auth` refuses a CUI whose control digit is wrong, and a non-interactive
  run over an existing token exits 1 without a URL. `efactura:status` exits 1 when a company's run
  stopped or an `--upload` check hit a rate limit, and `efactura:sync` exits 1 when a company failed.
- A job built with an invalid CUI (`''`, `'0'`, `'RO'`, a leading zero) fails when it runs; it never
  means "every company". A per-company job whose company is unknown, deactivated or paused logs
  `EFactura: no usable token for CUI` and does nothing.
- `queueUpload()` and `queueB2CUpload()` throw `InvalidArgumentException` for a `standard` other than
  `UBL`, `CN`, `CII` or `RASP` (3.x failed the invoice as `validation` when it was sent), and for an
  option key other than `standard`, `extern` and `self_billed` (3.x ignored it). A `StandardType` is
  accepted and stored as its value.
- `TokenService::storeToken()` and `getAuthorizationUrl()` throw `InvalidArgumentException` for a
  malformed CUI (`getAuthorizationUrl()` also for a wrong control digit or an invalid CNP), and
  `getToken()` returns null for a malformed one. All three ignore spaces and an `RO` prefix.
  `EfacturaToken::forCui()` does too, so it no longer finds a legacy row stored in that raw form.
  `EfacturaToken::getVatNumber()` returns the canonical CUI.
- `EFacturaUploadableInterface` must be implemented by an Eloquent model.
- SDK 4.0's builder refuses input it used to build. The wrapper records that as `Failed` /
  `validation` plus `InvoiceFailed`. Check your `toEfacturaData()` for:
  - a positive invoice without a `dueDate`;
  - amounts with more decimals than filed (6 for `quantity`, `unitPrice` and `taxAmount`; 2 for
    `taxPercent`, `taxAmountRon` and `payableRoundingAmount`): refused, not rounded;
  - a Bucharest address without a sector the SDK can resolve;
  - currency or country codes that are not uppercase ISO codes;
  - text XML 1.0 cannot hold;
  - blank required fields (a whitespace-only county of a Romanian address included);
  - a date that does not exist (`2024-02-30`, `24:00`).

  See the SDK CHANGELOG sections "Breaking (4.0): amounts are exact or refused" and
  "Breaking (4.0): the builder refuses documents ANAF rejects".
- Removed `UploadService::markUploadAsCompleted()` (unguarded status write; nothing called it) and
  `TokenService::updateTokenFromOAuth()` (wrote a grant without checking that the token is active or
  that the grant is newer). Store a new grant with `TokenService::storeToken()`; status answers go
  through `DownloadService::checkStatus()`.
- `MessageSyncService::reportsNoMessages()` (protected) takes a `PaginatedMessagesResponseData`
  instead of a `ListMessagesResponseData`, and `SyncMessages::failBatch()` (protected) is removed.
- `UploadService::classifyApiException()` (protected) is removed: the upload call's answer and
  exceptions are classified by the SDK's `UploadOutcome` (`fromResponse()` / `fromThrowable()`).
- The bundled MCP server (`mcp/`) no longer carries its own copy of the docs. It reads README.md,
  CHANGELOG.md and `config/efactura.php` from the installed package when it is asked. The tool
  names stay. `get-wrapper-docs` topics are now the README's section slugs (an unknown topic lists
  them), plus `changelog`; the `overview`, `setup`, `upload-pipeline`, `token-management`, `commands`
  and `migration` topics are gone. If your `.mcp.json` points at `mcp/dist/index.js`, it keeps
  working.

### Added

- Migration `2024_01_01_000008_add_failure_counters_to_efactura_tables`: `efactura_uploads.status_failures`
  (default 0; one count per phase: send attempts that failed before ANAF answered while the upload
  is `pending`, status reads ANAF refused while it is `processing`),
  `efactura_messages.download_attempts` (default 0, `unsignedSmallInteger`) and
  `efactura_messages.next_download_at` (when a received invoice's next download try is due; null =
  now). Run `php artisan migrate` before queue workers start the 4.0 code (keep workers stopped
  during the deploy). The package
  loads its migrations itself; if you also published them, publish again
  (`--tag=efactura-migrations`) to get the new files. It also changes 3.x rows:
  - `failed` rows with `validation` or no `failure_reason` (a pre-3.0 failure migration
    `000005` could not classify) that have an `upload_index` but no `download_id` move to
    `indeterminate`: 3.x recorded `nok` status answers without a download id that way, and
    re-queueing one filed it again. 3.x never stored the index on a failed upload answer, so those
    rows have no index; a 3.x `validation` row without an index cannot be told apart from a real
    rejection and is left as it is. Re-running it after a rollback also moves 4.0's own
    "nepreluat" rows to `indeterminate` (the safe direction).
  - `failed` rows with `rate_limited` or `transient` (provably not sent) processed within the last
    7 days go back to `pending` (reason kept, `status_failures` 0, `processed_at` null) and are sent
    again; older ones become `abandoned`, so an invoice is not filed weeks late.
- `DownloadService::MAX_STATUS_FAILURES` (5): status reads ANAF refuses before an upload is parked
  as `indeterminate`.
- `UploadService::TRANSIENT_RETRY_DELAY` (30) and `UploadService::MAX_TRANSIENT_ATTEMPTS` (5), and
  `UploadService::jobFailed()`, which `ProcessSingleUpload::failed()` calls.
- `BeeCoded\EFactura\Jobs\CompanyBatchJob`, the base of the five batch jobs (see Breaking), and
  `SweepStaleUploads::STALE_MINUTES` (30).
- `TokenService::usableTokens()` (active, not paused, canonical CUI; optionally only the given token
  ids), `usableToken($cui)` and
  `isGrantFailure($e, $sent)`, the one rule for failures that apply to the whole grant.
- `EfacturaMessage::needsDownload()`: received invoices whose next download try is due, within 60
  days.
- Translation keys `completed_without_download_id`, `status_nok_without_download_id`,
  `status_poll_failures_exhausted`, `not_processed_at_anaf`, `upload_outcome_unknown` (ANAF
  answered without a usable index or status), `upload_outcome_no_answer` (the upload may have
  reached ANAF but no usable answer came back, for example a lost connection, a timeout or a 5xx) and
  `local_failure` (en, ro).
- `BeeCoded\EFactura\Enums\StatusCheckOutcome` (`Checked`, `NotPollable`, `RateLimited`, `Failed`),
  returned by `DownloadService::checkStatus()`.
- `efactura:reconcile --not-filed=<id> --despite-index` and
  `UploadService::resolveIndeterminateAsNotFiled($upload, despiteIndex: true)`: an operator who
  checked SPV under an upload's index and did not find the invoice re-queues it. The command asks
  for confirmation and refuses with `--no-interaction`; the index is cleared, kept in `errors` and
  logged as a warning. Without the flag an indexed upload is still refused.
- `BeeCoded\EFactura\Exceptions\RefreshCooldownException` (an SDK `AuthenticationException`,
  code 401), thrown while a company's grant is paused (see Changed),
  `TokenService::refreshCooldownSeconds($token)` (seconds left of the pause, 0 when none), and the
  `EfacturaUpload::awaitingDispatch()` scope: `pending` uploads whose `next_poll_at` is null or
  past.
- Protected extension points: `DownloadService::parkAsIndeterminate()` and
  `TokenService::persistClientRefresh()`.
- `CHANGELOG.md`, and one recommended cadence per scheduled job in the README. The package still
  schedules nothing itself.
- `BeeCoded\EFactura\Services\CachedAnafLookup`: a cached, fail-closed company check. Resolved from
  the container it uses `new AnafDetailsClient(strict: true, tries: 1, timeoutSeconds: 2)`; a client
  you pass yourself must be strict too. `resolve($cui)` returns an `AnafLookupResult` (`status`,
  `cui`, `queryDate`, `active`) with `AnafLookupStatus` `Found`, `NotFound`, `Unavailable` or `Busy`,
  and `cachedRead($cui)` returns today's cached verdict without calling ANAF. Both accept spaces
  and an `RO` prefix and throw `InvalidArgumentException`, without calling ANAF, for a CUI that is
  not 2 to 10 digits with a valid control digit (a CNP included). Found is cached for the rest of
  the day and NotFound 15 minutes in the default cache store, as plain arrays, keyed by policy
  version, lookup URL, ANAF date and CUI; Unavailable
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
  `(status, next_poll_at)`, and one on `(status, efactura_token_id, processed_at)`
  (`efactura_uploads_response_due_index`) for the response-download reads. It drops the plain
  `(uploadable_type, uploadable_id)` and `status` indexes, which the unique
  `(uploadable_type, uploadable_id)` index and the `(status, next_poll_at)` one cover; a
  rollback recreates them. Run before 000006 (with `--path`, while duplicates block 000006), it
  keeps the plain `(uploadable_type, uploadable_id)` index.
- Migration `2024_01_01_000011_key_efactura_messages_per_token` (not loaded when `pipeline_tables`
  is false): `efactura_messages` is unique on `(efactura_token_id, message_id)` instead of
  `message_id`, so a message listed to two connected companies is stored once per company. It is
  re-entrant and cannot abort on data.

### Changed

- **Requires `bee-coded/laravel-efactura-sdk` ^4.0.**
- The README no longer holds upgrade guides. Upgrading from 3.x is this `## [4.0.0]` section; from
  2.x, follow the v3.1.1 README first. The `APP_KEY` obligations moved to the README's
  "Credentials and APP_KEY" section.
- `queueB2CUpload()` honours `$options['standard']` like `queueUpload()` (it always stored `UBL`).
  The package sends the standard you queue whatever the document type: queue a credit note with
  `['standard' => 'CN']`.
- The OAuth callback no longer shows an unrecognised `error` / `error_description` from the request:
  it flashes a fixed message and reports the shortened values with control characters replaced by
  spaces. A non-string `error`,
  `error_description`, `code` or `state` counts as absent (it was a 500).
  `getOAuthErrorMessage()`'s default ignores the description. The OAuth redirect refuses a
  leading-zero CUI.
- Upload answers: a digit-only `index_incarcare` makes the upload `Processing` with that index and
  fires `InvoiceUploaded`, whatever `ExecutionStatus` says (it was `Failed` / `validation`). An
  answer that does not say whether ANAF took the document in (an unreadable 2xx body, a non-digit
  index, success without an index, no recognised status) is `Failed` / `indeterminate` (it was
  `validation`); it keeps the index ANAF returned (up to 255 characters) in `upload_index`, so
  `efactura:reconcile --not-filed` then needs `--despite-index`. Only `ExecutionStatus=1` without an
  index stays `validation`.
- Upload exceptions are classified by the SDK's `UploadOutcome::fromThrowable()`, after the grant
  check: a failure `TokenService::isGrantFailure()` attributes to the grant (a refused or paused
  grant, a deactivated token, unreadable credentials) leaves the upload `pending`, uncounted. Two
  outcomes change: a `RequestNotSentException` (the SDK proved the request
  never left: DNS, connect, TLS or a connect-phase timeout) is `transient` and retried (it was an
  `indeterminate` 500), and an HTTP 408 is `indeterminate` (it was `validation`): a timeout says
  nothing about whether ANAF filed the document. An ApiException with a status outside 2xx/4xx/5xx
  is `indeterminate` too (it was `validation`); an `XmlParsingException` cannot escape the upload call
  in SDK 4.0 and is no longer caught separately.
- Status answers are read through the SDK's `StatusResponseData::verdict()`. A download id that is
  not ANAF's digits (1 to 64) cannot be downloaded and counts as none: `ok` with one is `Completed`
  with `download_id = null`, `nok` with one is `Failed` / `indeterminate` (both stored the id before).
  An error-only or unrecognised answer that names such an id is parked `indeterminate` without it.
- Status answers: `ok` without a download id is `Completed` with `download_id = null` and a note in
  `errors`; no response is downloaded, so `InvoiceProcessed` does not fire for it.
- Status answers: `nok` without a download id is `Failed` / `indeterminate`, keeping the upload
  index (it was `validation`).
- Status answers: "XML cu erori nepreluat de sistem" is `Failed` / `validation` with no download.
- Reads (status checks, response and received-invoice downloads) record only ANAF's refusal of the
  row: a 4xx (not 408) or unreadable 2xx answer, an id the SDK refuses, or an archive outside the
  limits. A refused status read counts in `status_failures` and parks the upload as `indeterminate`
  at `DownloadService::MAX_STATUS_FAILURES`; a refused download is recorded (`response_failed_at`
  and `errors`) and tried again at its next slot; a later stored response clears
  `response_failed_at`. A 5xx, a 408, a network error, or a local
  database, cache, storage or configuration error is never counted: the row is read again at its
  next slot. An unreadable 2xx status answer is a refusal: the parked upload keeps its index, so
  have it checked again with `efactura:reconcile --filed=<id> --index=<its upload index>`.
- Every read claims its row first, by writing when the row is due again, so no run reads a row
  again before that slot, whatever the outcome; a refusal by the SDK's own limiter still spends the
  claimed try. The upload's own per-message limit (`RateLimitScope::Status`,
  `RateLimitScope::Download`) skips that row until its slot and the batch carries on. A grant
  failure, a global or per-CUI limit, or ANAF's 429 stops that company's batch (the job no longer
  fails or retries on these); a paused, unreadable or deactivated grant makes no claim, in a batch or
  a per-row call. A batch reads never-read rows first,
  then the most overdue, at most 500, and starts no new read after 90 seconds.
- Code that re-arms a parked upload itself (for example a raw update back to `Processing`) must also
  reset `status_failures` to 0, or the next refused read parks it again.
  `resolveIndeterminateAsFiled()` and ANAF accepting an upload reset it.
- More outcomes are `indeterminate`, and `queueUpload()` refuses every `indeterminate` row with
  `DuplicateUploadException`: resolve them with `php artisan efactura:reconcile`.
  `DuplicateUploadException` is also thrown for an upload that is `uploading`, `processing` or
  `completed` and queued again with other options or for another company, and for a failed upload
  that kept changing while it was queued again. A `pending` upload queued again takes the company's
  current token.
- `UploadService::resolveIndeterminateAsFiled()` trims the index and throws
  `InvalidArgumentException` when it is not 1 to 64 digits; `efactura:reconcile --filed` prints the
  error and exits with a failure code. Resolving an upload as filed fires `InvoiceUploaded`.
- `UploadService::resolveIndeterminateAsNotFiled()` throws `InvalidArgumentException` for an upload
  that has an upload index and changes nothing, unless called with `despiteIndex: true`: ANAF
  decides that upload's outcome under its index, so re-queueing it could double-file the invoice.
  `efactura:reconcile --not-filed` prints why, suggests `--filed=<id> --index=<its upload index>`
  (the upload's own index only when it is 1 to 64 digits, otherwise `<index_incarcare as shown in
  SPV>`; or `--despite-index` when SPV has no invoice under that index) and exits with a failure
  code.
- `efactura:reconcile --filed` with an index other than the one the upload already has asks for
  confirmation; refused, or with `--no-interaction`, it prints "Upload {id} was left as it is." and
  exits 1.
- The `efactura:reconcile` list shows `Upload index`, `Uploaded at` and `Parked at` (when it was
  parked), in Romanian time with its zone, as SPV shows it (the `Claimed at` column, which showed the
  parked time).
- `efactura:status --upload=<id>` prints "Done." only when ANAF answered. A rate limit prints
  "ANAF rate limit reached, try again later." and a failed check says so, both with exit code 1; an
  upload with nothing to poll says why, and a response that was not downloaded is reported
  ("The response of upload #{id} was not downloaded; see the log.") without changing the exit
  code. A run
  without `--upload` checks each company in turn, prints "Stopped for {cui} (rate limit or token
  unavailable); see the log." for a company whose run stopped, carries on with the others and exits 1.
- `efactura:sync` syncs, and with `--download` downloads, every company even when one fails; it
  prints only the exception's class ("Message sync failed for {cui} (QueryException); see the log.")
  and exits 1, and its "Synced N message(s)" line says a failed company may have stored more. `--download` with `features.download_received` off prints a warning instead of
  doing nothing.
- `efactura:upload` prints "{cui}: skipped (cooling)." for a company whose grant is paused (exit 0,
  also with `--cui`), and names the queue the default connection really uses when `efactura.queue` is
  null. `efactura:upload --sync` sends each usable company's due uploads inline, one try each without
  a lease, and stops a company at its first upload that comes back rate-limited or once its grant is
  paused; the next run retries it once it is due.
- `efactura:upload`, `efactura:status` and `efactura:sync` without `--cui` print "{cui}: skipped
  (non-canonical CUI)." for a company stored under a non-canonical CUI and do not work it.
- Listener exceptions: an exception from a listener of `TokenStored`, `TokenRefreshed`,
  `InvoiceUploaded`, `InvoiceProcessed`, `InvoiceFailed`, `InvoiceRateLimited` or `InvoiceReceived`
  is reported, never propagated, and never changes the state recorded before the event. A throwing
  `TokenStored` listener no longer sends the OAuth callback to the error URL with its message.
- Token bookkeeping after an ANAF call never replaces the call's result or exception. A grant the
  SDK refreshed during a call is saved even when the call threw; a failed save is logged as
  critical. `LockTimeoutException` now comes only from the pre-call refresh lock.
- Refreshed grants are saved with a compare-and-set on the refresh token they replace: a refresh
  whose stored refresh token changed meanwhile (a re-authorisation, another worker's refresh) is
  dropped silently, instead of being ordered by expiry. `createClient()` passes the SDK's
  `tokenPersister`: a grant the SDK refreshes is saved while the SDK still holds its refresh lock,
  so the next worker reloads the new refresh token instead of spending the old one, and the save
  after the call runs only when that one did not store the grant. `handleClientTokenRefresh()`
  compares against the token the client was built from and fires `TokenRefreshed` only when it
  stored the grant, so a hand-driven client announces a refresh once.
- A grant ANAF sends without `expires_in` is stored with a one-hour expiry.
- `EfacturaToken::isExpiringSoon()` defaults to 120 seconds, the buffer the package refreshes at (it
  was 300).
- `TokenService::storeToken()` is one upsert: it replaces credentials the current `APP_KEY` cannot
  read, and fires no Eloquent model events.
- A credential save that fails throws `RuntimeException("EFactura: the token for CUI … could not be
  saved")` with no previous exception, and logs the cause's class and code as a warning, never the
  SQL or the encrypted credentials.
- Message sync skips a listed message without a usable id, with a warning, instead of upserting it.
- A deactivated token is refused before every ANAF call (`RuntimeException` "Token for CUI … has
  been deactivated"), not only when it was about to be refreshed.
- While a company's grant is paused, `executeWithClient()` and `EFactura::client()` refuse it with
  `RefreshCooldownException` without asking ANAF (a client obtained before the pause is not
  stopped). These answers pause it:

  | ANAF's answer | Pause |
  |---------------|-------|
  | The token endpoint refuses the refresh (HTTP 400/401), or a request is refused with 401 | 15 minutes |
  | The token endpoint answers 429, 5xx or an unreadable 2xx, or a refresh before the call gets no answer (code 0: a transport failure, missing OAuth configuration) | 60 seconds |
  | A request (or a refresh the SDK makes during it) gets no answer (code 0) | none |

  The pause is written before the refresh lock is released, so workers that waited on the lock do
  not each send a refused request. Re-authorising lifts it at once. A refused access token whose
  refresh token still works is paused again every 15 minutes until the company re-authorises. The
  exception's message names the seconds left. A paused company's uploads wait in `pending` and its
  reads stop, without counting. Tokens are not deactivated automatically.
- The wrapper's refresh lock is the SDK's (`EFacturaClient::tokenRefreshLock()` on the canonical
  CUI), held for the SDK HTTP timeout (rounded up) plus 30 seconds instead of a fixed 120 seconds; a
  failing release is ignored.
- `ProcessPendingUploads` and `dispatchPendingUploads()` lease each due `pending` row for 300 seconds
  (its `next_poll_at`) before they queue its job, so a second dispatcher does not queue it again and
  a job whose lease the row no longer holds (another dispatch leased it again) does nothing. A job
  that never runs hands the row back when its lease ends.
  A row queued again while a job holds it keeps its lease, `next_poll_at` and `status_failures`; if
  its options change while the job prepares the document, the job loses its claim and gives the lease
  back, so the next dispatch takes the row. `dispatchPendingUploads()` queues at most as many of its
  company's rows as the SDK's global quota has left; the rest wait for the next run. Nothing is
  reserved, so N companies dispatched in one run can queue up to N times the quota, and a job past it
  hands its row back for a later run.
- `ProcessSingleUpload` makes one try (`$tries = 1`), is dropped when its upload was deleted, and
  does nothing (keeping the row's state) when uploads are disabled. When the job throws or times out
  (`TimeoutExceededException`), `failed()` parks an upload still `uploading` under the job's lease as
  `indeterminate` and counts one `transient` attempt on a `pending` one. The `InvoiceFailed` of a
  parked upload (by `failed()` or `SweepStaleUploads`) carries the row's errors, including the
  warning that it is unknown whether ANAF received it. When the queue gives up on a redelivered copy
  (`MaxAttemptsExceededException`), a dead worker's `uploading` upload is left to
  `SweepStaleUploads`, and a `pending` one still holding the job's lease counts one `transient`
  attempt, so a document whose preparation kills the worker every time ends `abandoned`.
  `CheckSingleUploadStatus` is dropped too when its upload was deleted.
- The SDK's global quota pre-check leaves the upload `pending` / `rate_limited` until the quota
  resets and fires no `InvoiceRateLimited`.
- `queueUpload()` recycles a failed row with a write guarded on the status and reason it read; if
  another worker changed the row meanwhile, it decides again on its current state (once; then it
  throws `DuplicateUploadException`). A `pending` row that failed while it was being queued again is
  also decided again (recycled). The recycle also resets `response_attempts`,
  `response_failed_at` and `status_failures`.
- `resolveIndeterminateAsFiled()` and `resolveIndeterminateAsNotFiled()` also clear `download_id`,
  `response_path`, `response_attempts`, `response_failed_at` (and `status_failures`).
- An XML file, response or received invoice that cannot be written to the storage disk (`put()`
  returns `false` or throws) is an error. For an upload it is `transient` (it was `validation`), and
  so is a database error (a `QueryException` or a raw `PDOException`) while the invoice is built
  (`toEfacturaData()`): the upload is tried again up to `UploadService::MAX_TRANSIENT_ATTEMPTS` times,
  then `abandoned` with one `InvoiceFailed`. A download is logged and retried at its next slot
  without being recorded against it. An upload whose model class no longer resolves ends `Failed` /
  `validation` (one `InvoiceFailed`) instead of stopping the run.
- Row `errors`, event payloads and console lines carry the message of an SDK exception only; any
  other exception shows `local_failure` (its class), and the log has the details. The exception is a
  failure while the invoice is built (`toEfacturaData()` or the SDK builder and DTOs, which also throw
  plain `InvalidArgumentException`): its message is kept, as `validation`, because it is the
  author's to fix.
- Outcome writes are guarded on the attempt that made them: a worker whose attempt another worker
  took fires no `InvoiceFailed`, `InvoiceUploaded` or `InvoiceRateLimited`. ANAF's index or refusal
  replaces a park (a sweep or `failed()`) written meanwhile and fires `InvoiceUploaded`, or a second
  `InvoiceFailed` carrying ANAF's errors. A failed outcome write is retried once (for an accepted
  upload the index is also logged at critical level). A write whose connection failed after its
  commit still counts: a claim still sends its document, an outcome still fires its event and
  removes its unsent XML, and a refreshed grant still fires `TokenRefreshed`.
- Status checks record an answer only on a row that is still `Processing` under the upload index
  they asked about (a row sent again meanwhile keeps its state, including `next_poll_at`
  and `status_failures`), and
  response downloads
  store a path only when none is stored and the row still has the download id they fetched, so
  overlapping runs fire `InvoiceFailed` / `InvoiceProcessed` once and a row re-queued meanwhile
  gets no old file. The `InvoiceFailed` of a `nok` with a download id carries ANAF's errors.
- An error-only status answer (SDK 4.0 `hasAnafError()`: an XML `<Errors/>` header, a JSON
  `eroare`) is parked `indeterminate` with ANAF's errors on the row, after the translated note.
- `DuplicateUploadException::indeterminate()` suggests `--filed=<id> --index=<its index>` (found in
  SPV; its index only when it is 1 to 64 digits, otherwise `<index_incarcare as shown in SPV>`) or `--not-filed=<id> --despite-index` (checked SPV, not there) for an upload that has an
  upload index.
- Message sync also skips (with the same warning) a listed id that is not digits, and records every
  `MESAJ CUMPARATOR…` tip as `received`, so they are downloaded and fire `InvoiceReceived` like a
  received invoice; listeners check `$message->details['tip']`.
- A received invoice is marked downloaded only while `is_downloaded` is still false, and
  `InvoiceReceived` fires only for the run that marked it, and only for a `received` message:
  `downloadMessage()` of a sent or error message stores it without the event. A run that loses that write, or the
  `response_path` write of a response download, or whose write fails, deletes the file it stored
  (unless the winner stored the same file name). Received-invoice log lines carry `id` for the row
  and `message_id` for ANAF's id.
- Signatures for subclasses: `DownloadService::markMessageAsDownloaded()` returns `bool` (whether it
  marked the message) instead of `void`; `DownloadService::recordPoisonedResponse()` (protected) takes
  any `\Throwable`.
- Message sync always uses ANAF's paginated list (`/listaMesajePaginatieFactura`): one window per run,
  from 60 days minus 70 minutes ago to a minute ago, counted in UTC (in an `app.timezone` with summer
  time, wall-clock days made the window longer than the SDK's 60-day limit for about two months a
  year, and every sync failed), paged until the last page (by its own count when ANAF does not say
  which page it answered). Each page is one call
  against the SDK's `paginated_list_per_day_cui` quota (default 50,000) instead of one
  `simple_list_per_day_cui` call per run.
- The batch jobs without a CUI (`SyncMessages`, `CheckUploadStatuses`, `DownloadResponses`,
  `DownloadReceivedInvoices` and `ProcessPendingUploads`) only dispatch one job of their kind per
  usable company (active, not paused, canonical CUI; for all but `SyncMessages`, one with work due)
  and do no work themselves, so each company gets its own timeout and retries and one slow company
  no longer holds up the rest. All five are `ShouldBeUniqueUntilProcessing` for 3600 seconds per company and 120 seconds without
  a CUI, and run
  behind `WithoutOverlapping` (not released, lock expires after the timeout + 30 s), keyed on the
  canonical CUI (`'RO123'` and `'123'` share them, `'all'` without a CUI): at most one copy per
  company waits in the queue, and a run that outlasts a short schedule interval is no longer
  overlapped by the next one on another worker. A late job is no longer dropped as stale; after an
  outage longer than an hour, a late `SyncMessages` copy lists that company's 60 days once more.
- `SweepStaleUploads` parks uploads `uploading` for more than 30 minutes; not scheduling it is the
  only way to turn it off. Its unique lock lasts 600 seconds.
- While an upload waits after a rate limit, a paused grant or a failure before it was sent,
  `xml_path` is null and that attempt's XML file is deleted (nothing was sent; the next attempt
  stores a new file). XML files are named `{id}_{Ymd_His}_{ULID}.xml`, so two runs in one second
  never share a file. A run that loses the claim deletes the file it stored, unless the row names
  that file.
- New uploads store `uploadable_type` as the model's `getMorphClass()` (the morph map alias, if your
  app has one; otherwise the class name, as before). `queueUpload()` finds an existing row under
  either form, and a row stored under the class name takes the alias when its model is queued
  again; until then the trait helpers and scopes do not see it. To fix every row at once, run this
  once per model (it uses bindings, so the backslashes in the class name survive on every database;
  a raw SQL literal loses them on MySQL and MariaDB):
  `DB::table('efactura_uploads')->where('uploadable_type', Invoice::class)->update(['uploadable_type' => (new Invoice)->getMorphClass()]);`
- On PostgreSQL `EfacturaToken` writes dates with their offset (`getDateFormat()` is
  `Y-m-d H:i:sP`), so `expires_at` is the same instant whatever the session time zone; naive columns
  ignore the offset. A query-builder binding of a Carbon value against `expires_at` (for example
  `EfacturaToken::where(...)->update(['expires_at' => now()])`) has no offset and is read in the
  session time zone: write through a model instead. Read back, `expires_at` is the right instant but
  carries the database session's offset: convert it (`->setTimezone(config('app.timezone'))`)
  before you display it.
- `efactura:auth` and `efactura:reconcile` refuse to run while `enabled` is false, like the other
  commands. `efactura:auth` shows an existing token's expiry in `app.timezone`, with the zone.
- Response archives are checked with the SDK's `ArchiveInspector` before they are stored: against the
  uploaded XML when ANAF accepted the upload and its XML is still stored (`verified`), otherwise only
  its limits (`limits_only`; a removed XML file counts as not stored also on a disk with
  `throw => true`). An archive that does not hold the uploaded invoice is stored as
  received, marked `mismatch` and logged as an error; `InvoiceProcessed` still fires. An archive
  outside the limits (not a ZIP, empty, encrypted, too large, too many members) is recorded as a
  refused download; a local failure (temporary file, reading the stored XML) is retried at the next
  slot without being recorded.
  Received-invoice archives get the same limits-only check before they are stored: one outside the
  limits is a refused download and fires no `InvoiceReceived`.
  `DownloadService` takes an optional `ArchiveInspector` as its second constructor argument. The SDK
  needs `ext-zip` and `ext-dom`.
- Status polling follows the SDK's `PollSchedule`: each read sets the upload's `next_poll_at` 1, 5,
  15 or 60 minutes ahead, less a one-minute margin (never under 60
  seconds, so the 1-minute step stays 1 minute) so the read is due on the scheduled run it aims at (by the time since `uploaded_at`: under 10 minutes, an hour, three
  hours, older; an upload without `uploaded_at` counts as older).
  `EfacturaUpload::needsStatusCheck()` takes only due rows (`next_poll_at` null or past), so
  `CheckUploadStatuses` (and `efactura:status` without `--upload`) reads only those and fans out
  only to companies that have one; `efactura:status --upload=<id>` still checks at once. Every
  transition that resets `status_failures` (`queueUpload()` recycling a row, ANAF accepting the
  upload, `resolveIndeterminateAsFiled()`, `resolveIndeterminateAsNotFiled()`) clears
  `next_poll_at`, and those that clear `response_path` also clear `response_verification`.
- `CachedAnafLookup` resolved from the container uses a strict lookup client (see Added).
- The OAuth `$state` and session nonce parameters of `TokenService::getAuthorizationUrl()`,
  `validateOAuthState()`, `forgetOAuthState()`, `EfacturaManager::getAuthorizationUrl()` and
  `OAuthCallbackController::cleanupOAuthSession()` are `#[\SensitiveParameter]`. A call through the
  `EFactura` facade is not redacted (Laravel's `Facade::__callStatic` frame keeps its arguments):
  pass a session nonce through `EFactura::tokenService()->getAuthorizationUrl()`.

### Changed (4.0): guarded writes fire no Eloquent model events

- Status answers (`DownloadService`), response paths, recycled failed rows (`EfacturaUpload`),
  received-invoice downloads (`EfacturaMessage`), refreshed grants and re-authorisations
  (`EfacturaToken`) are written with query-builder updates or upserts guarded on the state they
  expect (`status = processing` and the polled `upload_index`, `response_path IS NULL` and the
  fetched `download_id`, `is_downloaded = false`, the refresh token the refresh replaced), so
  overlapping runs
  cannot both apply one answer. Eloquent's `updating` / `updated` / `saving` / `saved` events and
  model observers do not fire for them.
- Upgrade: listen to the package events instead: `InvoiceProcessed`, `InvoiceFailed`,
  `InvoiceReceived`, `TokenRefreshed`, `TokenStored`. They fire at most once, for the run whose write
  landed: an event whose dispatch fails, whose re-read after the write fails, or whose worker dies
  first is not fired again (see the README's Events). No event announces a `Completed` status answer
  (`InvoiceProcessed` follows only once its archive is stored), the stored archive of a rejected or
  parked upload, a recycled failed row, an `efactura:reconcile --not-filed` resolution or a direct
  `TokenService::storeToken()` call: read the row for those.

### Known limitation: `queueUpload()` inside a transaction on MySQL/MariaDB

- Under REPEATABLE READ, a caller transaction cannot see a row another worker inserted for the
  same model while it was open, and a locking re-read fails with "Record has changed since last
  read". A lost insert race inside the caller's transaction therefore surfaces as
  `UniqueConstraintViolationException` instead of returning the existing upload. The winner's row
  stands; nothing is filed twice. Outside a transaction, and on PostgreSQL (at its default READ
  COMMITTED isolation) and SQLite, the existing upload is returned. Inside a caller transaction the recycle's re-read also sees the
  snapshot, so a lost recycle race (another worker re-drove the failed row) surfaces as
  `DuplicateUploadException` ("kept changing") instead of returning the row. Call `queueUpload()`
  after your transaction commits. CI now runs the suite on MariaDB as well as PostgreSQL.

### Known limitation: ANAF calls inside a transaction

- Make `executeWithClient()` and `EFactura::client()` calls outside a database transaction: a grant
  refreshed during the call is stored inside it, the refresh lock is released before you commit, and
  a rollback loses the grant ANAF has already rotated, so the company must re-authorise.

### Known limitation: message sync capacity

- A `SyncMessages` run lists one CUI's 60-day window from page 1 within the job's 120-second
  timeout: at roughly 2–3 seconds a page about 40 pages, some 20,000 messages. A CUI with more times
  out every run and its later pages are not synced. The timeout stays 120 seconds because raising it
  also needs the queue connection's `retry_after` raised above it. Before 4.0 such a CUI failed on
  the simple list's 500-message limit, so this is a ceiling, not a regression.

### Known limitation: read batch capacity

- A status or download batch reads at most 500 rows per company and starts no new read after 90
  seconds; the rest lead the next run. One slow read can still run past the job's 120-second
  timeout.
- After a global rate limit, each company's run in a command or a fan-out still claims one row
  before it stops: that row's next try moves back, and a response or received invoice counts one
  try. This way one company's pause never stops the others.

### Known limitation: a re-queued failed upload keeps its old XML file

- Queueing a failed upload again keeps `xml_path` naming the failed attempt's file until the next
  claim stores a new one; the old file then stays on the disk, named by no row. The row does not
  record whether ANAF received that file (an ANAF refusal) or the SDK refused it before sending, so
  the package does not delete it. Re-queueing it, and `efactura:reconcile --filed`/`--not-filed`,
  also clear `response_path`; ANAF's response archive stays on the disk, named by no row. Unchanged
  since 3.x.

### Known limitation: wall-clock times in `efactura_uploads`

- `next_poll_at`, the dispatch lease and the `updated_at` the sweep reads are `app.timezone` wall
  time. In a zone with summer time, the repeated autumn hour can hold a lease, a retry or a status
  read back by up to an hour, and can make `SweepStaleUploads` park an upload that is still being
  sent (a false `InvoiceFailed`); ANAF's answer is still recorded through the `indeterminate` arm.
  `app.timezone = UTC` avoids both.

### Known limitation: `QUEUE_CONNECTION=sync`

- On the sync connection every job runs inside the process that dispatched it. A batch job without
  a CUI (`SyncMessages`, `CheckUploadStatuses`, `DownloadResponses`, `DownloadReceivedInvoices`,
  `ProcessPendingUploads`) runs its per-company jobs inline inside the parent: a child's exception
  propagates through the parent job and stops the remaining companies of that run. Use a real queue
  connection, or, without a worker, schedule `efactura:upload --sync`.

### Removed

- `BeeCoded\EFactura\Jobs\RetryRateLimitedUploads`: the upload row owns its retry schedule (see
  Breaking). Remove its schedule line.
- The config keys `rate_limit.retry_window_hours` (`EFACTURA_RATE_LIMIT_RETRY_HOURS`),
  `rate_limit.retry_batch_size` (`EFACTURA_RATE_LIMIT_RETRY_BATCH`),
  `rate_limit.retry_max_age_days` (`EFACTURA_RATE_LIMIT_RETRY_MAX_DAYS`),
  `jobs.max_staleness_seconds` (`EFACTURA_JOB_MAX_STALENESS`),
  `jobs.unique_for_seconds` (`EFACTURA_JOB_UNIQUE_FOR`),
  `jobs.transient_retry_delay_seconds` (`EFACTURA_JOB_TRANSIENT_RETRY_DELAY`),
  `jobs.max_transient_attempts` (`EFACTURA_JOB_MAX_TRANSIENT_ATTEMPTS`; 0 no longer disables the
  cap) and `jobs.stale_uploading_minutes` (`EFACTURA_JOB_STALE_UPLOADING_MINUTES`; 0 no longer turns
  the sweep off). They became `UploadService::TRANSIENT_RETRY_DELAY` (30),
  `UploadService::MAX_TRANSIENT_ATTEMPTS` (5), `SweepStaleUploads::STALE_MINUTES` (30) and a fixed
  job uniqueness (3600 s per company, 120 s for a company job without a CUI, 600 s for
  `SweepStaleUploads`); a published config that still sets them is ignored.
- `UploadService::resetForRetry()`, `UploadService::abandonFailure()`,
  `UploadService::abandonUnsentUpload()` and `UploadService::processPendingUploads()`, and the
  protected `UploadService::failBeforeTransmission()`, `UploadService::failTerminally()` and
  `UploadService::markAsRateLimited()`. `efactura:upload --sync` stays.
- `FailureReason::retryable()`: a reason that is retried marks a `pending` row
  (`FailureReason::isRetryable()` stays).
- `ProcessSingleUpload::retryUntil()` and its `$maxExceptions`: the job makes one try.
- `DownloadService::stopBatchForRateLimit()` (protected) and the protected `$uploadService`
  property of `DownloadService`.
- `EfacturaUpload::MAX_RESPONSE_ATTEMPTS`: downloads are retried for 60 days (see Breaking).
- `TokenService::getActiveTokens()`: use `TokenService::usableTokens()`.
- `MessageSyncService::syncAllMessages()` and
  `BeeCoded\EFactura\Exceptions\MessageSyncFailedException`: each company's `SyncMessages` job, and
  `efactura:sync`, sync one company at a time.
- `BeeCoded\EFactura\Jobs\Concerns\DiscardsWhenStale` (with its `$enqueuedAt`, `markEnqueued()`,
  `asFanOutChild()` and `isStale()`): per-company uniqueness bounds the queue instead.
- `TokenService::persistTokenRefreshWithLock()` and `TokenService::isFresherThanStored()`
  (protected), replaced by the compare-and-set save.
- `DownloadService::isPoisonedBody()` (protected): every refusal of the row is now recorded.
- `UploadService::markUploadAsUploading()`: unused; only the atomic claim sets `uploading`.
- `UploadService::markUploadAsCompleted()` and `TokenService::updateTokenFromOAuth()`: unused,
  unguarded writes (see Breaking).
- `SyncMessages::failBatch()` (protected): the job no longer syncs a batch.

### Fixed

- Messages listed without an id no longer merge into one `efactura_messages` row.
- Message sync no longer moves one ANAF message between two tokens that list the same id.
- Message sync no longer rewrites every listed message on each run on MySQL/MariaDB (its JSON
  details never compared equal).
- A message whose `data_creare` is not a real minute in Bucharest (30 February, the spring-forward
  gap) is stored without a `message_date` instead of a rolled-over date.
- `InvoiceRateLimited` fires only when the upload was actually handed back to `pending`; a row a
  sweep or `failed()` parked during the call no longer announces a rate limit. Its
  `retryAfterSeconds` is the SDK limiter's wait, and null after ANAF's own 429.
- A model read before its row was reset for a retry (for example by a second job on the same row)
  no longer leaves a never-sent upload in `uploading` after a failed run: the claim updates the
  model's original attributes, so the failure write always records `status` and `failure_reason`.
- An upload that was never sent is no longer abandoned after three quick exceptions: it is tried
  again after 30 seconds, up to five times.
- `isEfacturaProcessed()` and the `efacturaProcessed()` / `efacturaFailed()` scopes no longer
  report an upload waiting for a retry as finished: it is `pending`.
- A status check that fails permanently is no longer retried on every run forever.
- An ANAF index that came back while the upload was being parked no longer leaves it parked; a
  failed write of an accepted upload no longer loses its index.
- Two runs of one upload within one second no longer overwrite or delete each other's XML file, and
  a claim that throws no longer leaves its XML file behind.
- Message sync failed on every run for a CUI with more than 500 messages in 60 days (ANAF's simple
  list refuses those).
- The status, response, received-invoice, stale-upload and pending-upload loops no longer skip rows:
  they paged by offset, so rows that left the scope during a page pushed others past the next one
  (with over 1,000 matching rows, a run visited about 1,000).
- Every re-drive of a rate-limited upload left another XML file behind (about one a minute).
- A `pending` upload queued again with other options (`queueB2CUpload()`, `standard`, `extern`,
  `self_billed`) while a worker was preparing it was sent with the old options. The claim now also
  checks those options: the worker sends nothing, the row stays `pending`, and the next attempt
  sends it with the new options.
- In an app with a morph map the trait helpers and scopes (`isUploadedToEfactura()`,
  `notUploadedToEfactura()`, …) never saw the upload, because the row stored the class name. New
  rows and rows queued again are fixed; older rows need the one-off `UPDATE` (see Changed).
- The docs no longer tell you to delete `efactura_tokens` rows when the `APP_KEY` is lost, which
  deleted their uploads and messages too. Overwrite the credentials instead (README, "Credentials
  and APP_KEY"). Follow the docs, not the `000004` rollback message, which still says to delete
  them.
- An authorization started on the web could be completed by another browser: whoever got a user to
  open the ANAF link stored that user's grant under a CUI of their choosing.
- A non-ANAF failure in the OAuth callback (for example a database error) shows "Failed to complete
  the e-Factura authorization. Please try again." instead of the exception's message, which could
  hold SQL and the encrypted credentials.
- When ANAF could not be reached or the OAuth configuration is missing, the OAuth callback shows
  "Failed to complete the e-Factura authorization. Please try again." instead of the transport or
  configuration error.
- Re-authorising a company whose stored credentials the current `APP_KEY` cannot read no longer
  throws `DecryptException`.
- A failed credential save no longer puts the SQL and the encrypted credentials into the exception
  message, the upload errors or the logs.
- A grant ANAF sent without `expires_in` is no longer refreshed on every call.
- A refresh lock that cannot be released no longer fails the call; the wrapper and the SDK use the
  same lock key.
- The token reloader the SDK calls reads the primary database, never a stale copy. If it fails (a
  deleted row, a lost connection), the SDK fails the call as `AuthenticationException` code 0 instead
  of refreshing with the in-memory grant.
- `RetryingAnafDetailsClient` retries only the SDK's own limiter refusal. ANAF's own 429 (SDK 4.0
  `RateLimitExceededException` with `fromProvider`) is re-thrown at once instead of being asked
  again up to `anaf_lookup.retry_attempts` times.
- `efactura:sync --download` downloaded nothing when one company's sync failed, and printed raw
  exception messages (with SQL bindings).
- `efactura:status --upload` with a malformed id read the wrong upload or swept every company, and
  ignored `--cui`; `efactura:reconcile` matched a malformed id loosely.
- `efactura:auth` issued a URL for a CUI with a wrong control digit, showed an expiry hours off on
  PostgreSQL, and exited 0 without a URL when a token existed and the run was non-interactive.
- `efactura:upload` named the queue `default` when `efactura.queue` was null.
- `CheckSingleUploadStatus` no longer fails into `failed_jobs` when its upload was deleted.
- On MySQL, MariaDB and SQLite, `TokenService::storeToken()` given an expiry in a time zone other
  than the app's stored its wall time, so the token was read as expiring hours later (or earlier).
- Blank env values keep their defaults, as in the SDK: a blank or `null` `EFACTURA_STORAGE_DISK`,
  `EFACTURA_STORAGE_PATH`, `EFACTURA_ROUTES_PREFIX`, `EFACTURA_SUCCESS_REDIRECT` or
  `EFACTURA_ERROR_REDIRECT` means `local`, `efactura`, `efactura`, `/` and `/`, and a blank, boolean
  or non-integer `EFACTURA_ANAF_LOOKUP_RETRY_ATTEMPTS` means 5. A blank disk used to store files on
  the host's default disk, a blank prefix mounted the OAuth routes at the site root, a `null` redirect
  reported a stored authorization as failed, and a blank retry count turned lookup retries off.

[4.1.0]: https://github.com/BEE-CODED/laravel-efactura/compare/v4.0.0...v4.1.0
[4.0.0]: https://github.com/BEE-CODED/laravel-efactura/compare/v3.1.1...v4.0.0
