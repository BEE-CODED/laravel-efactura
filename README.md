# Laravel e-Factura

A Laravel package that wraps [bee-coded/laravel-efactura-sdk](https://packagist.org/packages/bee-coded/laravel-efactura-sdk) to provide token storage, job scheduling, and easy model integration for Romanian e-Factura (ANAF SPV) compliance.

## Upgrading to v4.0

The full list is in [CHANGELOG.md](CHANGELOG.md). Every behaviour change since v3.1.1:

1. **Requires `bee-coded/laravel-efactura-sdk` ^4.0.** SDK 4.0 reports an upload `ExecutionStatus`
   that is missing or not exactly 0/1 as unknown instead of guessing, and returns `null` for an ANAF
   value it cannot use (for example a missing or non-scalar id) instead of throwing a `TypeError`. Its
   `RateLimitExceededException` carries a `$scope` (`RateLimitScope`).
2. **Run `php artisan migrate`.** `..._000008_add_failure_counters_to_efactura_tables` adds
   `efactura_uploads.status_failures` and `efactura_messages.download_attempts` (both default 0).
   Additive columns and one data update (item 16); it cannot abort. Run migrate before queue workers start the 4.0 code (keep workers stopped during the deploy): 4.0 writes the new columns right after ANAF accepts an upload. The package loads its
   migrations itself; if you also published them, publish again (`--tag=efactura-migrations`) to get
   the new file.
3. **Upload answers** (`ProcessSingleUpload` / `processUpload()`), which used to be recorded as a
   rejection (`failure_reason = validation`):
   - an answer with a digit-only `index_incarcare` (surrounding whitespace is trimmed by the SDK) is
     `Processing` with that index and fires `InvoiceUploaded`, whatever its `ExecutionStatus` says;
     the status check then decides;
   - an answer that does not say whether ANAF took the document in (an unreadable 2xx body, an index
     that is not digits, success without an index, no recognised status) is `Failed` /
     `indeterminate` and fires `InvoiceFailed`. Only an explicit `ExecutionStatus=1` without an
     index is still `validation`;
   - the SDK's `UploadOutcome` now classifies the upload call: a `RequestNotSentException` (the
     request provably never left: DNS, connect, TLS) is `transient` and retried, and an HTTP 408 is
     `indeterminate` (a timeout may have filed it), not `validation`.
     `UploadService::classifyApiException()` (protected) is gone.
4. **Status answers** (`CheckUploadStatuses` / `checkStatus()`), see
   [Status check outcomes](#status-check-outcomes):
   - `ok` without a download id is `Completed` with `download_id = null` and a note in `errors`. No
     response is downloaded for it, so `InvoiceProcessed` never fires for it;
   - **`nok` without a download id is now `Failed` / `indeterminate` (it was `validation`)**, keeping
     the upload index: ANAF answers an unknown index or "Nu aveti dreptul" this way, which is no
     verdict on the document;
   - "XML cu erori nepreluat de sistem" (ANAF did not take the file in) is `Failed` / `validation`,
     with no download;
   - a status check that fails permanently (a 4xx, an unreadable 2xx answer, an index the SDK
     refuses, any other error) is counted in `status_failures`; at
     `efactura.polling.max_status_failures` (default 5) the upload is parked `Failed` /
     `indeterminate`. Transient failures (5xx, network, token problems) are retried without
     counting;
   - **an unreadable 2xx status answer** (a body that is neither the status XML nor a JSON object)
     is one of those permanent failures: after `max_status_failures` of them the upload is parked
     `Failed` / `indeterminate`. It keeps its upload index; once you have checked it in SPV, recover
     it with `php artisan efactura:reconcile --filed=<id> --index=<its upload index>`;
   - an upload at its per-message status limit is skipped and the batch carries on; a global limit,
     or ANAF's own 429, still stops the batch;
   - `DownloadService::checkStatus()` returns a `StatusCheckOutcome` (`Checked`, `NotPollable`,
     `RateLimited` or `Failed`) instead of `void`. `efactura:status --upload=<id>` prints "Done."
     only when ANAF answered; a skipped upload ("Skipped: the per-message status limit ... try again
     later") or a failed check exits with a failure code, and an upload with nothing to poll says why.
     A subclass overriding `checkStatus()`, or a mock of it, must return a `StatusCheckOutcome`.
   - **If your own code re-arms a parked upload** (for example a raw update back to `Processing`),
     also reset `status_failures` to 0. Otherwise the count it was parked with still stands, and the
     next permanent failure parks it again. `resolveIndeterminateAsFiled()` and
     `markUploadAsProcessing()` reset it for you.
5. **Downloads:** a response or received-invoice download that fails permanently (now also a 4xx or
   an id the SDK refuses, not only a 2xx body that is not a ZIP) counts against
   `EfacturaUpload::MAX_RESPONSE_ATTEMPTS` / `EfacturaMessage::MAX_DOWNLOAD_ATTEMPTS` (3 each);
   after that the row is no longer downloaded. Transient failures are not counted. A download at its
   per-message download limit is skipped and the batch carries on.
6. **More uploads need [reconciliation](#reconciliation-indeterminate-uploads).** `queueUpload()`
   refuses every `indeterminate` row with `DuplicateUploadException` instead of recycling it, so the
   new `indeterminate` outcomes above are not re-sent by re-queueing; resolve them with
   `php artisan efactura:reconcile`.
7. **New exceptions:** `UploadService::resolveIndeterminateAsFiled()` throws
   `InvalidArgumentException` when the index is not 1 to 64 digits (after trimming).
   `efactura:reconcile --filed` prints that message and exits with a failure code.
   `UploadService::resolveIndeterminateAsNotFiled()` throws `InvalidArgumentException` for an
   upload that has an upload index, and changes nothing: ANAF decides that upload's outcome under
   its index, so re-queueing it could double-file the invoice. `efactura:reconcile --not-filed`
   prints why, suggests `--filed=<id> --index=<its upload index>`, and exits with a failure code.
   If you checked SPV under that index and the invoice is not there, `--not-filed=<id>
   --despite-index` re-queues it after you confirm (`despiteIndex: true` from code); see
   [Reconciliation](#reconciliation-indeterminate-uploads).
   The `efactura:reconcile` list now shows each upload's `Upload index`, `Uploaded at` and
   `Parked at` (the column was called `Claimed at`).
8. **Listeners:** an exception thrown by a listener of `TokenStored`, `TokenRefreshed`,
   `InvoiceUploaded`, `InvoiceProcessed`, `InvoiceFailed`, `InvoiceRateLimited` or
   `InvoiceReceived` is reported (`report()`) and never propagated: the state recorded before the
   event stays as it is, and a batch carries on. Before, it escaped into the calling service or
   job, and a throwing `TokenStored` listener sent the OAuth callback to the error URL with the
   exception's message although the token was stored; now the callback redirects to the success
   URL.
9. **Token bookkeeping** after an ANAF call never replaces the call's result or exception. A grant
   the SDK refreshed during the call is saved inside the SDK's refresh lock (its `tokenPersister`),
   and again after the call even when it threw, with a conditional write;
   `persistTokenRefreshWithLock()` is gone. `handleClientTokenRefresh()` uses the same conditional
   write (it wrote unconditionally) and fires `TokenRefreshed` only when it stored the grant. See
   [Token refresh](#token-refresh-and-bookkeeping).
10. **Message sync** skips a listed message without a usable id, with a warning, instead of upserting
    it (all such messages used to merge into one row).
11. **New translation keys** `completed_without_download_id`, `status_nok_without_download_id` and
    `status_poll_failures_exhausted` (plus `not_processed_at_anaf` and `upload_outcome_unknown`). If
    you published the translations, add them to translate them; otherwise the package's text is
    used.
12. **New config key** `polling.max_status_failures` (`EFACTURA_POLLING_MAX_STATUS_FAILURES`,
    default 5). A published `config/efactura.php` without it uses the default.
13. **Recommended schedule:** the docs now give one cadence per job, in
    [Recommended schedule](#recommended-schedule). The package still schedules nothing itself.
14. **Laravel 11 is no longer supported:** `illuminate/*` is now `^12.0|^13.0` (Laravel 12 needs
    laravel/framework 12.27.1 or later, through the SDK).
15. **The OAuth routes require a logged-in user by default:** `routes.middleware` is now
    `['web', 'auth']` (it was `['web']`). `auth` alone still lets any logged-in user authorise any
    CUI: add your own check, or disable the routes. A published `config/efactura.php` keeps the
    value it has. See [OAuth Flow](#oauth-flow).
16. **Migration `000008` reclassifies pre-4.0 rows.** It also moves pre-4.0 `failed` /
    `validation` rows, or failed rows with no `failure_reason` (a pre-3.0 failure migration `000005`
    could not classify), that have an upload index but no download id to `indeterminate`: 3.x
    recorded `nok` status answers without a download id that way, and re-queueing one would file
    it again. Review them with `php artisan efactura:reconcile`. **A 3.x `validation` row without
    an upload index cannot be told apart** from a real rejection; 3.x recorded every failed upload
    answer (including an index ANAF had assigned, an unreadable 2xx answer and an answer without an
    index) that way, without the index. If you re-queue old 3.x failures, check in SPV first. The
    data move is idempotent before 4.0 writes rows; re-running it after a rollback also moves 4.0's
    own "nepreluat" rows to `indeterminate`, the safe direction.
17. **Token refusals:** a deactivated token is refused before every ANAF call, not only when it was
    about to be refreshed (an upload then fails `transient`, a status check is not counted). When
    ANAF refuses to refresh a grant (HTTP 400/401), that grant is not offered again for 15 minutes,
    so the rest of a run skips it without calling ANAF (`RefreshCooldownException`, an
    `AuthenticationException`); a worker that waited on the refresh lock while the grant was refused
    does not ask again. An upload refused this way stays `pending` and its job sleeps the rest of
    the cooldown without building its XML or spending a transient attempt; the dispatchers add no
    job for it meanwhile, and a status run logs the cooldown once per grant instead of an error per
    row. **A grant that stays refused leaves its uploads `pending` for at most
    `rate_limit.retry_window_hours` (24 h), then abandons them with `InvoiceFailed`.** Usually
    sooner: after each cooldown the next caller asks ANAF again, and when that caller is an upload
    job the refusal spends one of its transient attempts, so an upload whose grant stays refused is
    abandoned after about (`jobs.max_transient_attempts` − 1) × the 15-minute cooldown (about an
    hour at the defaults). The cooldown also refuses calls while the access token still has its last 120
    seconds or less to run. Re-authorising issues a new grant, which is
    tried at once. The token is not deactivated. The refresh lock is held for the SDK HTTP timeout
    plus 30 seconds. See [Token refresh](#token-refresh-and-bookkeeping).
18. **Uploads:** `ProcessPendingUploads` and `dispatchPendingUploads()` skip a `pending` row
    waiting out a rate limit (its own job still owns it) and any row changed within
    `jobs.transient_retry_delay_seconds` + 60 seconds (90 s by default), including a freshly queued one (its own job may be queued, or waiting on the quota
    pre-check, which now touches the row). If you only rely on the scheduler, a new upload is
    therefore sent up to about 90 seconds plus one `ProcessPendingUploads` interval after it was
    queued; dispatch `ProcessSingleUpload` yourself for an immediate send. The two dispatchers also
    skip a row whose grant is in its refresh cooldown, and `efactura:upload` prints how many
    `pending` rows it left out for each reason. The synchronous `processPendingUploads()`
    (`efactura:upload --sync`) has no job that could own a row, so it takes every `pending` row at
    once, a rate-limited one included (retried on its next run), and skips only a grant in its
    refresh cooldown; `--sync` prints those skipped rows apart from the processed count.
    `RetryRateLimitedUploads` re-dispatches a rate-limited `pending` row only once it has not changed for `rate_limit.retry_window_hours`, whatever its age (`retry_max_age_days` counts, for a `failed` row, from when it failed). A job that finds
    uploads disabled hands its rate-limited row back to `ProcessPendingUploads`.
    `queueUpload()` recycles a failed row only if it is still in the state it read (another worker
    may have re-driven it), and resets `response_attempts`, `response_failed_at` and
    `status_failures`. The transient cap (`jobs.max_transient_attempts`) counts transient failures
    of one queued job; rate-limit releases no longer use it up. An XML file that cannot be stored is
    a `transient` failure (it was `validation`) and fires no `InvoiceFailed`.
19. **Status checks and downloads** record an answer only on a row that is still `Processing` (a
    response only when none is stored yet and the row still has the download id that was fetched),
    and fire `InvoiceFailed` / `InvoiceProcessed` only for
    the run that recorded it, so overlapping runs announce it once. The `InvoiceFailed` of a `nok`
    with a download id now carries ANAF's errors (it could be `[]`). An error-only status answer
    (SDK 4.0 `hasAnafError()`: an XML `<Errors/>` header or a JSON `eroare`) is parked
    `indeterminate` with ANAF's errors kept on the row. A downloaded file that cannot be stored is
    retried by the next run without counting against the download attempts. A received invoice is
    marked downloaded only if no other run has, so `InvoiceReceived` fires once
    (`DownloadService::markMessageAsDownloaded()` now returns whether it marked it), and the file a
    losing run stored is deleted.
20. **Smaller changes:** `efacturaAwaitingResponse()` leaves out uploads past
    `EfacturaUpload::MAX_RESPONSE_ATTEMPTS`; the `DuplicateUploadException` hint for an indexed
    `indeterminate` upload suggests `--filed=<id> --index=<its index>` or `--not-filed=<id>
    --despite-index`; `queueB2CUpload()` honours `$options['standard']` like `queueUpload()` (it
    always stored `UBL`; the package sends the standard you queue, so queue a credit note with
    `['standard' => 'CN']`); message sync also skips
    a listed id that is not digits; every `MESAJ CUMPARATOR…` tip is a received message; and
    `markUploadAsUploading()` is removed from `UploadService` (nothing called it: only the atomic claim
    sets `uploading`).
21. **Eloquent model events no longer fire for these writes:** status answers, response paths,
    recycled failed rows (`EfacturaUpload`), received-invoice downloads (`EfacturaMessage`) and
    refreshed grants (`EfacturaToken`) are now guarded query-builder updates, so concurrent runs
    cannot both apply them. Model observers or `updating` / `updated` / `saving` / `saved` listeners
    no longer see them; listen to the package events instead (`InvoiceProcessed`, `InvoiceFailed`,
    `InvoiceReceived`, `TokenRefreshed`).
22. **OAuth:** an authorization started through `efactura/auth/{cui}` must be completed in the same
    browser session by the same user; otherwise the callback answers "Invalid or expired state
    parameter". If you run the flow from your own controller, pass a session nonce and the user id
    to `EFactura::getAuthorizationUrl($cui, $nonce, $userId)` and the same pair to
    `validateOAuthState($state, $nonce, $userId)` (see [OAuth Flow](#oauth-flow)). `efactura:auth`
    is unchanged. `OAuthCallbackController::redirect()` now takes the `Request` first. The callback
    no longer shows an unrecognised `error` / `error_description` from the URL (it flashes a fixed
    message and reports the values), and a non-string `error`, `error_description`, `code` or
    `state` counts as absent instead of causing a 500.
23. **Removed** `UploadService::markUploadAsCompleted()` and `TokenService::updateTokenFromOAuth()`
    (unguarded writes nothing called). Store a grant with `TokenService::storeToken()`.
24. **Message sync** uses ANAF's paginated list, so a CUI with more than 500 messages in 60 days no
    longer fails every run. It spends the SDK's `paginated_list_per_day_cui` quota, one call per
    page of 500. `MessageSyncService::reportsNoMessages()` (protected) takes a
    `PaginatedMessagesResponseData`. The 60-day window is counted in UTC, so an `app.timezone` with
    summer time no longer makes it longer than ANAF's limit. Each run starts again at page 1 and has
    the job's 120-second timeout for every page: at roughly 2–3 seconds a page that is about 40
    pages, some 20,000 messages in 60 days for one CUI. A CUI with more fails its sync job every
    run (see [Known limitations](#known-limitations)).
25. **Batch jobs without a CUI** (`SyncMessages`, `CheckUploadStatuses`, `DownloadResponses`,
    `DownloadReceivedInvoices`) only dispatch one job per active CUI (with work due, for the last
    three) and each CUI runs, times out and retries on its own. `SyncMessages` is unique per CUI and
    drops a copy that waited too long in the queue; a broken CUI fails its own job (no
    `MessageSyncFailedException` from the job, `failBatch()` is gone). The three upload/download jobs
    never run twice at once for a CUI. Their loops no longer skip rows past the first 1,000. The
    per-CUI jobs a run without a CUI dispatches are never dropped as stale: they wait behind each
    other however many CUIs there are. A per-CUI job your scheduler dispatches itself still is.
26. **`RetryRateLimitedUploads`** counts `retry_max_age_days` from when a row failed (`processed_at`),
    and no longer throws a `TypeError` when `EFACTURA_RATE_LIMIT_RETRY_MAX_DAYS` is set.
27. **Upload files and morph maps:** while an upload waits after a rate limit, a refresh cooldown or a
    transient failure, `xml_path` (and `getEfacturaXmlPath()`) is null and the unsent file is deleted.
    New rows store `uploadable_type` as the model's morph class, so apps with a morph map see their
    uploads through the trait; rows stored under the class name are still found when re-queued.
28. **PostgreSQL token expiry:** migration `000009` makes `efactura_tokens.expires_at` a
    `timestamp with time zone`, converting the stored values `AT TIME ZONE` your `app.timezone`
    (the zone they were written in): run it with the `APP_TIMEZONE` that wrote them. `EfacturaToken`
    now writes dates with their offset on PostgreSQL. If your own code updates `expires_at` through
    the query builder with a Carbon value, write through the model instead: a builder binding has
    no offset and is read in the session time zone. Other databases are unchanged.
29. **Tokens only?** Set `pipeline_tables` (`EFACTURA_PIPELINE_TABLES`) to `false` and only the
    token migrations are loaded and published; the upload/message pipeline then throws
    `PipelineTablesDisabledException` instead of failing on a missing table. `efactura:auth` and
    `efactura:reconcile` now also refuse to run while `enabled` is false.
30. **Response archives are checked** before they are stored (SDK `ArchiveInspector`, which needs
    `ext-zip` and `ext-dom`). Migration `000010` adds `efactura_uploads.response_verification`:
    `verified` (it holds the uploaded invoice), `limits_only` (a rejection report, or the uploaded
    XML is no longer stored) or `mismatch` (kept as received and logged as an error). An archive
    that is not a readable ZIP within the limits counts as a failed download. Received-invoice
    archives get the same limits-only check before they are stored: one outside the limits counts
    against `EfacturaMessage::MAX_DOWNLOAD_ATTEMPTS` and fires no `InvoiceReceived`; a local failure
    is retried without counting.
31. **Status polling per upload:** migration `000010` also adds `efactura_uploads.next_poll_at`. A
    status run reads an upload again 1, 5, 15 or 60 minutes after a "still in progress" answer, as
    it ages (the SDK's `PollSchedule`), instead of on every run; `needsStatusCheck()` takes only due
    rows. `efactura:status --upload=<id>` still checks at once.
32. **Company lookup:** `CachedAnafLookup` caches a fail-closed company check per ANAF day; see
    [Company Lookup](#company-lookup-no-auth-required).
33. **Messages per company:** migration `000011` makes `efactura_messages` unique per
    `(efactura_token_id, message_id)`; sync looks a message up by both, so two connected companies
    that list the same ANAF id each keep their own row (sent for one, received for the other).

## Upgrading to v3.0 — read before deploying

v3 encrypts the ANAF credentials already in your database and adds a unique index to
`efactura_uploads`. **This upgrade requires a maintenance window with your queue workers stopped.**
That is not a recommendation — a worker left running across the migration can permanently orphan a
company's credentials, and no re-run of the migration will fix it.

Read this whole section before running anything. The steps are ordered deliberately: the
prerequisites all come **before** the commands, because doing them afterwards is how the outages
happen.

### Step 0 — Prerequisites, before you run any command

#### 0a. Back up `APP_KEY`, somewhere other than the database it protects

From v3, `APP_KEY` decrypts your ANAF credentials. Lose it and **every connected company must redo
the OAuth flow** (`php artisan efactura:auth {cui}`). It was never load-bearing for e-Factura
before, so it may never have been treated as a secret worth backing up. It is now.

If you are rotating it, set `APP_PREVIOUS_KEYS` — see [`APP_KEY` obligations](#app_key-now-protects-your-anaf-credentials--this-is-new-in-v3).

#### 0b. Audit for writes that bypass the model

`Builder::update()` and raw SQL skip casts, so `DB::table('efactura_tokens')->update([...])` — or
`EfacturaToken::where(...)->update([...])` — silently writes a value nothing can decrypt. Load the
model instance and update it, so the cast runs. Fix these **before** deploying; they fail silently.

#### 0c. Size the unique-index build

The index migration runs `ALTER TABLE efactura_uploads ADD UNIQUE (uploadable_type, uploadable_id)`.
This is **not free on a large table**:

```sql
SELECT COUNT(*) FROM efactura_uploads;
```

- **Up to ~100k rows:** seconds. Run it inline.
- **Millions of rows:** expect **minutes**, during which the build takes a brief exclusive metadata
  lock. Under concurrent write load it can fail outright on `innodb_lock_wait_timeout`.

With workers stopped (Step 1) there is no concurrent write load, which is most of the risk gone. If
the table is large enough that the window itself is the problem, build the index out of band with
[`pt-online-schema-change`](https://docs.percona.com/percona-toolkit/pt-online-schema-change.html)
or [`gh-ost`](https://github.com/github/gh-ost) instead, then mark the migration as run.

### Step 1 — Stop the queue workers (MANDATORY)

**Do this before `composer update`, and keep them down until after `php artisan migrate`.**

```bash
php artisan down
```

`php artisan down` is a necessary start but **is not sufficient on its own**:

- A worker already **mid-job** when you run it finishes that job — including a token refresh.
- Workers started with `queue:work --force` ignore maintenance mode entirely.
- Horizon needs `php artisan horizon:pause` (or `horizon:terminate`).

So: run `down`, then actually stop the worker processes (`supervisorctl stop laravel-worker:*`, or
your orchestrator's equivalent) and **confirm nothing is in flight** before you migrate.

#### Why this is mandatory, and not a step-6 afterthought

A v2 worker that is still alive while the migration runs will:

1. **Refresh a token and write PLAINTEXT into an already-encrypted column.** `$token->update([...])`
   from v2 code has no cast. The encryption migration has by then already been **recorded as run**,
   so the usual remedy — "just re-run the migration" — **does nothing at all**. That company throws
   `DecryptException` on every operation until it re-authorises. This is the one failure in this
   upgrade with no clean recovery.
2. **Read an already-encrypted row and send the ciphertext to ANAF as a Bearer token.** ANAF answers
   `401`, and the invoices in that batch park as `Failed`.

Neither is a race you can win by being quick. Stop the workers.

### Step 2 — Check for duplicate uploads (inside the window, writes stopped)

Run this **now**, with workers down — not the day before:

```sql
SELECT uploadable_type, uploadable_id, COUNT(*) AS copies
FROM efactura_uploads
GROUP BY uploadable_type, uploadable_id
HAVING COUNT(*) > 1;
```

v3 adds a unique `(uploadable_type, uploadable_id)` index, and its migration **aborts** if
pre-3.0 duplicates exist. Duplicates can mean an invoice was filed at ANAF more than once, so the
migration does not resolve them for you: reconcile each against ANAF, keep the row reflecting the
real filing, remove the rest.

**Why inside the window:** v2's `queueUpload()` has no duplicate guard, so a check run while v2 is
still accepting writes is only true for as long as it takes the next request to reintroduce a
duplicate. With writes stopped the check is authoritative. (Token encryption runs before this index,
so an abort no longer leaves tokens unreadable, but the later migrations do not run until it
succeeds: keep the workers stopped and the app down until it does. This check saves you that
failed deploy.)

### Step 3 — Update and migrate

```bash
composer update bee-coded/laravel-efactura
php artisan migrate
```

Between those two commands, e-Factura operations for existing companies fail with
`DecryptException: The payload is invalid.` **This is by design** (see below). Keep the gap short —
this is exactly what the window is for.

Four migrations ship; three are new in v3 and run **in this order**:

| Order | Migration | What it does |
|-------|-----------|--------------|
| 1st | `..._000004_encrypt_efactura_token_credentials` | Encrypts the plaintext `access_token` / `refresh_token` already in `efactura_tokens`. **Runs first on purpose**: it is what restores service for the v3 code you just deployed, so nothing that can abort is allowed to precede it |
| 2nd | `..._000005_add_failure_reason_to_efactura_uploads_table` | Adds the indexed `failure_reason` column; backfills `rate_limited` for rows whose legacy `errors` text carries a `RATE_LIMIT_EXCEEDED:` marker. Anything unrecognised stays `NULL` rather than being guessed. **Re-entrant** — an interrupted backfill resumes on the next `migrate` |
| 3rd | `..._000006_add_unique_uploadable_index_to_efactura_uploads_table` | Adds the unique `(uploadable_type, uploadable_id)` index. **Aborts on pre-3.0 duplicates** (Step 2). Ordered after encryption because it is the only one expected to abort; an abort stops the run, so the migrations after it have not run either |
| 4th | `..._000007_add_response_attempt_tracking_to_efactura_uploads_table` | Adds `response_attempts` / `response_failed_at` so a poisoned `/descarcare` body (2xx that isn't a ZIP) is retried a bounded number of times rather than on every run forever. Additive columns only; cannot abort |

### Step 4 — Bring it back up

```bash
php artisan queue:restart   # workers must come back on the NEW code
php artisan up
```

Start your worker processes again (`supervisorctl start laravel-worker:*`). `queue:restart` is the
belt-and-braces for any worker that survived Step 1: a stale one holds pre-v3 code in memory and
will keep firing `InvoiceFailed` on rate limits and writing plaintext credentials.

### If `migrate` aborts: how to get out of it

An abort is **not** a no-op at the database level. Migrations that already ran are committed and
recorded; only the aborting one is not. What you have depends on where it stopped:

| Aborted at | State | Do this |
|------------|-------|---------|
| `..._000004` (encryption) | Tokens partly/not encrypted. e-Factura is **down** | The migration is idempotent — fix the cause and re-run `php artisan migrate`. It resumes safely |
| `..._000005` (failure_reason) | Tokens **encrypted, service is up**. Column may exist without the backfill finished | Re-run `php artisan migrate`. It is re-entrant: it skips the DDL it already did and resumes the backfill |
| `..._000006` (unique index) | Tokens **encrypted**. The index is missing **and `000007` onwards have not run** (Laravel stops at the first failure). The code writes their columns, from queue workers and web requests alike, so with the app up every upload ANAF accepts is parked for reconciliation | Do not run Step 4: keep the queue workers stopped and the app down until `php artisan migrate` finishes. Resolve the duplicates (Step 2), then re-run `php artisan migrate`. If service must resume first, run each later migration (`000007` onwards) with `php artisan migrate --path=…` (one `--path` per file, or your published copies), then run Step 4. The duplicate race stays open until the index exists |

**Emergency lever — restore service immediately.** If you are down and need tokens working *now*
without waiting to resolve anything else, run the encryption migration on its own:

```bash
php artisan migrate --path=database/migrations/2024_01_01_000004_encrypt_efactura_token_credentials.php
```

That is safe to run at any point: it is idempotent, touches only `efactura_tokens`, and has no
dependency on the other two. (If you published the migrations into your app, point `--path` at your
copy instead.) Then sort out the rest at your own pace.

### OAuth tokens are now encrypted at rest

`efactura_tokens.access_token` and `refresh_token` are encrypted via Laravel's `encrypted` cast.
Before v3 they were stored in plaintext, so anyone with a database read, a backup, or a query log
held live ANAF credentials — enough to file and read legal tax documents for every connected
company. (The model's `$hidden` never protected against this; it only omits the fields from
`toArray()` / `toJson()` and has no bearing on what is written to disk.)

The cast has **no plaintext fallback**: a fallback would let a migration that never ran pass
unnoticed while your credentials stayed readable in the database. Encryption at rest has to be a
verifiable fact rather than a hope, so an unmigrated row fails loudly at the point of use instead of
quietly working.

The migration is **idempotent** — it skips values that are already ciphertext, so re-running it
cannot double-encrypt, and it is safe after a partial failure or on a table mixing migrated and
freshly-authorised rows. It is also **reversible**: `php artisan migrate:rollback` restores
plaintext, so downgrading to v2 (whose model has no cast) leaves no unreadable ciphertext behind.

A rollback attempted under the **wrong** `APP_KEY` (rotated key, or a production dump restored
elsewhere) **aborts loudly** rather than reporting success — it will not leave ciphertext sitting in
a column that a downgraded v2 would hand to ANAF as a Bearer token.

### `APP_KEY` now protects your ANAF credentials — this is new in v3

Your `APP_KEY` was never load-bearing for e-Factura before. It is now.

- **Losing or rotating `APP_KEY` orphans every token.** Without the old key present, nothing can be
  decrypted and **every connected company must complete the OAuth flow again**
  (`php artisan efactura:auth {cui}`). Back the key up somewhere other than the database it
  protects.
- **Rotate only with `APP_PREVIOUS_KEYS`:**

  ```dotenv
  APP_KEY=base64:<new key>
  APP_PREVIOUS_KEYS=base64:<previous key>
  ```

  Laravel then decrypts existing tokens with the old key and re-encrypts them with the new one as
  they are written.
- **Restoring a production database to staging or local now requires the production `APP_KEY`.**
  A dump loaded into an environment with a different key leaves every token unreadable. This
  workflow silently worked before v3 because the values were plaintext — it will not anymore.
  Either carry the matching key across, or re-authorise in that environment. (Re-authorising is
  frequently the better answer: a staging box holding working production ANAF credentials is its
  own problem.)

> **Writes that bypass the model now store unreadable plaintext.** `Builder::update()` and raw SQL
> skip casts, so `DB::table('efactura_tokens')->update(['access_token' => $raw])` — or
> `EfacturaToken::where(...)->update([...])` — silently writes a value nothing can decrypt. Load the
> model instance and update it, so the cast runs. Audit for raw writes before deploying.

### Other v3 breaking changes

- **`InvoiceFailed` no longer fires on rate limits.** Transient rate-limit hits now fire
  `InvoiceRateLimited` and are retried automatically; `InvoiceFailed` is a terminal signal that is
  safe to alert on. **Silent** — a listener that reacted to rate limits simply stops running.
- **The `RATE_LIMIT_EXCEEDED:` error-text marker is gone.** Listeners that substring-matched it now
  match nothing. Use the indexed `failure_reason` column / `FailureReason` enum instead.
- **`efactura:upload` now queues** one `ProcessSingleUpload` per row instead of uploading inline, so
  it **requires a queue worker**. **Silent** — without a worker it prints
  `Queued N pending upload(s) for processing.` and nothing is filed. Use `--sync` to restore the old
  inline behaviour (which now stops at the first rate limit rather than failing the whole backlog).
- **`resetForRateLimit()` → `resetForRetry()`.** It now covers every retryable failure reason and
  refuses non-retryable ones.
- **`markUploadAsFailed()` gained a 4th `FailureReason $reason` parameter**, defaulting to
  `FailureReason::Validation` — existing calls still compile and are treated as terminal.
- **`processPendingUploads()` returns `int`** (was `void`) and stops at the first rate limit.
- **`syncMessages()` / `syncAllMessages()` return `int`** (was `void`) and now **throw** instead of
  swallowing errors. `syncAllMessages()` keeps per-token isolation but raises
  `MessageSyncFailedException` if any token failed, so a batch can no longer report false success.
- **`queueUpload()` is idempotent** — one row per model, recycled on re-upload. It throws
  `DuplicateUploadException` when the existing row's delivery is `indeterminate`.
  On MySQL/MariaDB, call it after your own transaction commits: under REPEATABLE READ a caller
  transaction cannot see a row another worker inserted for the same model at the same moment, so
  that lost race surfaces as `UniqueConstraintViolationException` instead of returning the
  existing upload. The other worker's row stands and nothing is filed twice. PostgreSQL and SQLite
  return the existing upload either way. Inside a caller transaction the recycle's re-read also sees
  the snapshot, so a lost recycle race (another worker re-drove the failed row) surfaces as
  `DuplicateUploadException` ("kept changing"); the same advice applies.
- **Schedule the new `SweepStaleUploads` job.** Nothing else rescues an upload stranded in
  `uploading` by a SIGKILL'd worker.
- **New operational duty: `php artisan efactura:reconcile`.** Uploads whose delivery to ANAF cannot
  be proven are parked `Failed` / `indeterminate` and are never re-submitted automatically. See
  [Reconciliation](#reconciliation-indeterminate-uploads).
- **SDK v3 changes land in your `toEfacturaData()`**: `PartyData::$isVatPayer` is now **required and
  moved to the 4th constructor position** (use named arguments — a positional v2 call silently
  coerces your ONRC string to `isVatPayer: true`), and `InvoiceData::$taxAmountRon` is **required
  for non-RON invoices** and rejected for RON ones.
- **Queue workers must be stopped for the migration, not merely restarted after it** — see
  [Step 1](#step-1--stop-the-queue-workers-mandatory). A worker alive across the migration can
  permanently orphan a company's credentials, which no re-run of the migration will repair.

> **Full step-by-step upgrade guide:** ask the `efactura` MCP server for the `migration` topic
> (`get-wrapper-docs` → `topic: "migration"`). It covers each break, the symptom you will actually
> see, and the exact code change, in the order a real upgrade performs them.

## Features

- **Token Management** - OAuth token storage per CUI with automatic refresh
- **Background Jobs** - Ready-to-use jobs for invoice uploads, status checks, and message syncing
- **Model Integration** - Simple interface + trait pattern for your invoice models
- **Event-Driven** - Events for all key operations (uploads, failures, received invoices)
- **Minimal Setup** - Auto-discovery, publishable config and migrations

## Requirements

- PHP 8.4+
- Laravel 12 (laravel/framework 12.27.1 or later, required by the SDK) or Laravel 13
- `bee-coded/laravel-efactura-sdk` ^4.0 (installed automatically)
- ANAF SPV OAuth credentials
- A stable, backed-up `APP_KEY` — it encrypts the stored ANAF credentials
- A shared, persistent cache store (redis, memcached, database — **not `array`**), for the OAuth
  state and token-refresh locks
- A running queue worker on `config('efactura.queue')` — uploads are dispatched, not inline.
  `QUEUE_CONNECTION=sync` is not supported for uploads: a sync job's `release()` does nothing, so a
  rate-limited or cooling-down upload is not retried by its job and waits for
  `RetryRateLimitedUploads` (after `rate_limit.retry_window_hours`) or the next
  `ProcessPendingUploads` run. Without a queue worker, schedule `php artisan efactura:upload --sync`
  instead: it uploads inline, and its next run retries a row it left rate-limited. On the sync
  connection a batch job without a CUI also runs its per-CUI jobs inline, inside itself, so one
  CUI's exception propagates through it and stops that run for the remaining CUIs. It takes only
  `pending` rows, so a `failed` row with a retryable reason (`transient` or `rate_limited`, for
  example a storage failure or a refused grant) is not retried on this path; only
  `RetryRateLimitedUploads` re-drives those, and it dispatches jobs. Without a worker, reset such a
  row with `UploadService::resetForRetry($upload)` and the next `--sync` run uploads it

## Installation

```bash
composer require bee-coded/laravel-efactura
```

Publish the configuration and migrations:

```bash
php artisan vendor:publish --tag=efactura-config
php artisan vendor:publish --tag=efactura-migrations
php artisan migrate
```

## Configuration

### Environment Variables

```env
# SDK Configuration (required)
EFACTURA_SANDBOX=true
EFACTURA_CLIENT_ID=your-anaf-client-id
EFACTURA_CLIENT_SECRET=your-anaf-client-secret
EFACTURA_REDIRECT_URI=https://your-app.com/efactura/callback

# Package Configuration
EFACTURA_ENABLED=true
# false = tokens, OAuth and the raw client only: no upload/message tables, pipeline refuses
EFACTURA_PIPELINE_TABLES=true
EFACTURA_UPLOAD_ENABLED=true
EFACTURA_DOWNLOAD_RECEIVED=false
EFACTURA_SYNC_MESSAGES=true

# Storage
EFACTURA_STORAGE_DISK=local
EFACTURA_STORAGE_PATH=efactura

# Queue (null = default queue)
EFACTURA_QUEUE=null

# Rate Limit Handling
EFACTURA_RATE_LIMIT_RETRY_HOURS=24
EFACTURA_RATE_LIMIT_RETRY_BATCH=250
EFACTURA_RATE_LIMIT_RETRY_MAX_DAYS=7

# Status polling: permanent status-check failures before an upload is parked as indeterminate
EFACTURA_POLLING_MAX_STATUS_FAILURES=5

# Routes
EFACTURA_ROUTES_ENABLED=true
EFACTURA_ROUTES_PREFIX=efactura
EFACTURA_SUCCESS_REDIRECT=/
EFACTURA_ERROR_REDIRECT=/
```

### Config File

The configuration file (`config/efactura.php`) allows you to:

- Enable/disable the entire package or specific features
- Use tokens, OAuth and the raw client only (`pipeline_tables` false): only the token migrations
  are loaded and published, and the upload/message services, jobs and commands throw
  `PipelineTablesDisabledException`
- Choose the queue e-Factura jobs are dispatched to
- Configure file storage for XML and ZIP files
- Tune rate-limit retry behaviour, status polling (`polling.max_status_failures`) and periodic
  batch-job hardening
- Customize OAuth callback routes

> **Job schedules are not configured here.** There is no schedule or cron key in
> `config/efactura.php` — this package does not schedule anything for you. You register the
> schedules in your own application; see [Job Scheduling](#job-scheduling-required).

## Model Integration

### 1. Implement the Interface

Your invoice model must implement `EFacturaUploadableInterface`:

```php
<?php

namespace App\Models;

use BeeCoded\EFactura\Contracts\EFacturaUploadableInterface;
use BeeCoded\EFactura\Traits\HasEfacturaUpload;
use BeeCoded\EFacturaSdk\Data\Invoice\InvoiceData;
use BeeCoded\EFacturaSdk\Data\Invoice\PartyData;
use BeeCoded\EFacturaSdk\Data\Invoice\AddressData;
use BeeCoded\EFacturaSdk\Data\Invoice\InvoiceLineData;
use Illuminate\Database\Eloquent\Model;

class Invoice extends Model implements EFacturaUploadableInterface
{
    use HasEfacturaUpload;

    /**
     * Transform this model into SDK's InvoiceData DTO.
     */
    public function toEfacturaData(): InvoiceData
    {
        return new InvoiceData(
            invoiceNumber: $this->number,
            issueDate: $this->issued_at,
            dueDate: $this->due_at,
            currency: $this->currency,
            supplier: new PartyData(
                registrationName: $this->company->name,
                companyId: $this->company->vat_number,
                address: new AddressData(
                    street: $this->company->address,
                    city: $this->company->city,
                    postalZone: $this->company->postal_code,
                    countryCode: $this->company->country_code,
                ),
                isVatPayer: $this->company->is_vat_payer,
            ),
            customer: new PartyData(
                registrationName: $this->customer->name,
                companyId: $this->customer->vat_number,
                address: new AddressData(
                    street: $this->customer->address,
                    city: $this->customer->city,
                    postalZone: $this->customer->postal_code,
                    countryCode: $this->customer->country_code,
                ),
                isVatPayer: $this->customer->is_vat_payer,
            ),
            lines: $this->lines->map(fn ($line) => new InvoiceLineData(
                name: $line->description,
                quantity: $line->quantity,
                unitPrice: $line->unit_price,
                taxAmount: $line->vat_amount,  // Pre-computed VAT for this line
                taxPercent: $line->vat_rate,
            ))->all(),
            paymentIban: $this->company->iban,
            // BT-111 — the VAT total converted to RON. REQUIRED when currency is not RON,
            // and REJECTED when it is. Omit this line entirely if you only ever file in RON.
            taxAmountRon: $this->currency === 'RON' ? null : $this->vat_total_ron,
        );
    }

    /**
     * Get the CUI for this invoice (determines which token to use).
     */
    public function getEfacturaCui(): string
    {
        return $this->company->cui; // Without RO prefix
    }
}
```

> **SDK 4.0 checks more of the document before building it.** A Romanian address in Bucharest needs a
> sector it can resolve (`bucharestSector: 1..6` on `AddressData`, or a sector in the city or county),
> an invoice with a positive total needs a `dueDate` (BR-CO-25), currency and country codes must be
> uppercase ISO codes, and amounts may be passed as decimal strings (`float|string`), which keeps
> them exact. See the SDK's upgrade notes for the full list.

> **`isVatPayer` is required, and its position changed in SDK v3.0** — it is now the 4th `PartyData`
> constructor parameter, swapping places with `registrationNumber`, and has no default. **Always use
> named arguments, as above.** A v2-era *positional* call
> `new PartyData($name, $id, $address, 'J40/1234/2020')` now assigns the ONRC string to `isVatPayer`;
> from a caller without `declare(strict_types=1)` PHP silently coerces it to `true` and drops the
> registration number — no exception, and ANAF accepts the resulting document.

> **`taxAmountRon` is required for non-RON invoices (SDK v3.0)** and rejected for RON ones — see the
> `taxAmountRon` line above. Pass the converted amount your ledger already holds (the BNR-rate
> figure), not an exchange rate: ANAF cannot verify the conversion, so a wrong value is accepted and
> filed as a true statement of VAT owed.

> **Using `Date::use(CarbonImmutable::class)`?** Pass your `datetime` casts straight in, as above.
> `CarbonImmutable` is not a `Carbon` subclass, so this required **SDK v2.3.0+** — on earlier
> versions `issueDate: $this->issued_at` threw a `TypeError` (or, from a caller without
> `declare(strict_types=1)`, silently stringified the date and lost its timezone). The SDK
> normalises immutable dates to a mutable `Carbon` internally; nothing changes for apps on
> mutable `Carbon`.

### 2. Available Trait Methods

The `HasEfacturaUpload` trait provides:

```php
// Relationship
$invoice->efacturaUpload; // The EfacturaUpload model

// Status checks
$invoice->isUploadedToEfactura();  // bool
$invoice->getEfacturaStatus();     // ?UploadStatus enum
$invoice->isEfacturaProcessed();   // bool (completed or failed)

// File paths
$invoice->getEfacturaXmlPath();      // ?string (null while an unsent upload waits to be retried)
$invoice->getEfacturaResponsePath(); // ?string
$invoice->getEfacturaErrors();       // ?array

// Query scopes
Invoice::notUploadedToEfactura()->get();           // Not yet queued
Invoice::efacturaPending()->get();                  // Queued, awaiting upload
Invoice::efacturaInProgress()->get();               // Currently uploading/processing
Invoice::efacturaCompleted()->get();                // Successfully processed
Invoice::efacturaFailed()->get();                   // Failed
Invoice::efacturaProcessed()->get();                // Terminal state (completed or failed)
Invoice::efacturaAwaitingResponse()->get();         // Completed, response not downloaded yet (under the attempt cap)
Invoice::withEfacturaStatus(UploadStatus::Pending)->get(); // Specific status
```

## Usage

### Queue an Invoice for Upload

```php
use BeeCoded\EFactura\Facades\EFactura;

// Standard B2B upload
$upload = EFactura::queueUpload($invoice);

// With options
$upload = EFactura::queueUpload($invoice, [
    'standard' => 'UBL',      // UBL, CN, CII, RASP
    'extern' => false,        // External/non-Romanian supplier
    'self_billed' => false,   // Self-billed/autofactura
]);

// B2C upload
$upload = EFactura::queueB2CUpload($invoice);
$upload = EFactura::queueB2CUpload($creditNote, ['standard' => 'CN']);  // B2C takes the same options
```

### Queue a Credit Note for Upload

Set `invoiceTypeCode` to `CreditNote`, reference the original invoice, and queue it with `'standard' => 'CN'`. The package sends the standard you queue (default `UBL`), whatever the document type:

```php
use BeeCoded\EFacturaSdk\Data\Invoice\InvoiceData;
use BeeCoded\EFacturaSdk\Data\Invoice\InvoiceLineData;
use BeeCoded\EFacturaSdk\Data\Invoice\PrecedingInvoiceData;
use BeeCoded\EFacturaSdk\Enums\InvoiceTypeCode;

$creditNote = new InvoiceData(
    invoiceNumber: 'CN-2024-001',
    issueDate: now(),
    currency: 'RON',
    invoiceTypeCode: InvoiceTypeCode::CreditNote,
    precedingInvoices: [new PrecedingInvoiceData('INV-2024-001', '2024-01-15')],
    supplier: $supplier,
    customer: $customer,
    lines: [
        new InvoiceLineData(
            name: 'Returned product',
            quantity: -3,        // negative = items being credited
            unitPrice: 150.00,
            taxAmount: -85.50,   // sign follows quantity: -3 * 150.00 * 0.19
            taxPercent: 19,
        ),
    ],
);
```

> **Note:** The SDK (v1.1+) automatically negates credit note line quantities before sending to ANAF (which expects positive values in `<CreditNote>` documents). Pass **negative** quantities for items being credited and **positive** for debit-back lines (e.g., discount reversals).

```php
$upload = EFactura::queueUpload($creditNoteModel, ['standard' => 'CN']);
$upload = EFactura::queueB2CUpload($creditNoteModel, ['standard' => 'CN']);  // a B2C credit note
```

### The `taxAmount` Parameter (Required since SDK v2.0)

Every `InvoiceLineData` requires a `taxAmount` — the pre-computed VAT amount for that line. The SDK uses this value directly in the XML instead of recalculating VAT internally.

**Why this matters:** In v1.x, the SDK grouped lines by tax rate and recalculated VAT as `sum_of_bases × rate`. This caused rounding discrepancies (typically 0.01 RON) when your application used tax-included pricing, because extracting VAT by subtraction (`gross - net`) can produce different results than multiplying (`net × rate`) after rounding. By passing your pre-computed `taxAmount`, the XML total matches your application's total exactly.

**How to compute it:**

```php
// Tax-exclusive pricing (you store the net unit price):
$taxAmount = round(round($quantity * $unitPrice, 2) * $vatRate / 100, 2);

// Tax-inclusive pricing (you store the gross price and extract the net):
$basePrice = round($grossPrice / (1 + $vatRate / 100), 2);
$taxAmount = $grossPrice - $basePrice; // This is what you should pass
```

**Sign convention:** The `taxAmount` sign follows the quantity — negative for credit note lines (negative qty), positive for regular lines.

> **Upgrading from SDK v1.x:** Add `taxAmount` to every `InvoiceLineData` in your `toEfacturaData()` method. Pass the VAT amount your application already computes for each line item.

### Process Upload Immediately

```php
// Queue and process immediately
$upload = EFactura::queueUpload($invoice);
EFactura::processUpload($upload);
```

### Access the SDK Client

For advanced operations, get an authenticated SDK client:

> **Treat this client as an escape hatch.** If the SDK refreshes during one of these calls, it saves
> the new grant under its refresh lock (v4.0), but a failed save is only logged as a warning and not
> retried: ANAF rotates refresh tokens, so the stored token is then spent, and once `$client` goes out
> of scope the company must re-authorise from scratch.
>
> For anything that files or fetches on a company's behalf, prefer `executeWithClient()`, which
> refreshes an expiring token before your operation and saves any refresh the SDK makes during it
> (see [Token refresh and bookkeeping](#token-refresh-and-bookkeeping)):
>
> ```php
> use BeeCoded\EFactura\Services\TokenService;
>
> app(TokenService::class)->executeWithClient(
>     EFactura::getToken('12345678'),
>     fn ($client) => $client->getMessages(new ListMessagesParamsData(cif: '12345678', days: 30)),
> );
> ```
>
> If you must use `EFactura::client()`, call this afterwards: it retries a failed save (letting its
> exception propagate), fires `TokenRefreshed` only if it stored the grant, and records `last_used_at`:
> `app(TokenService::class)->handleClientTokenRefresh($client, EFactura::getToken($cui));`

```php
use BeeCoded\EFacturaSdk\Data\Invoice\ListMessagesParamsData;
use BeeCoded\EFacturaSdk\Enums\DocumentStandardType;
use BeeCoded\EFacturaSdk\Enums\MessageFilter;

$client = EFactura::client('12345678'); // CUI

// Validate XML
$result = $client->validateXml($xml, DocumentStandardType::FACT1);

// Convert to PDF
$pdf = $client->convertXmlToPdf($xml, DocumentStandardType::FACT1);

// Get messages — requires a ListMessagesParamsData
$messages = $client->getMessages(new ListMessagesParamsData(
    cif: '12345678',
    days: 30,                            // lookback window in days (1-60)
    filter: MessageFilter::InvoiceSent,  // optional; null = all message types
));
```

### Company Lookup (No Auth Required)

Use the SDK directly for company lookups:

```php
use BeeCoded\EFacturaSdk\Facades\AnafDetails;

$company = AnafDetails::getCompanyData('12345678');
$companies = AnafDetails::batchGetCompanyData(['12345678', '87654321']);
```

#### Automatic Retry on ANAF's Rate Limit

ANAF's public company-lookup endpoint is capped at **1 request/second**. The SDK
enforces this client-side and throws `RateLimitExceededException` when the limit
is exceeded — it does **not** retry. This wrapper decorates the SDK's
`AnafDetailsClientInterface` binding with `RetryingAnafDetailsClient`, which
**retries up to 5 total attempts** (configurable), sleeping the exception's
`retryAfterSeconds` (≥ 1s) between attempts, then re-throws if still limited.

The retry is **synchronous and blocking** — a fully rate-limited lookup can block
the request for up to ~`(attempts − 1)` seconds before re-throwing, so the
consuming app can surface a "registry busy — retry" message. Only the SDK's own
limiter refusal triggers a retry: ANAF's own 429 (a `RateLimitExceededException`
with `fromProvider`) is re-thrown at once, since asking again within seconds only
spends more of the 1 request/second. A `failure()` result (invalid CUI, malformed
response) passes straight through, and the SDK already retries transient
5xx/connection errors internally.

Because the binding is decorated, **no code change is needed** — anything
resolving `AnafDetailsClientInterface` (or the `AnafDetails` facade) gets the
resilient client transparently.

```env
# Total attempts before re-throwing RateLimitExceededException (default: 5)
EFACTURA_ANAF_LOOKUP_RETRY_ATTEMPTS=5
```

```php
// config/efactura.php
'anaf_lookup' => [
    'retry_attempts' => env('EFACTURA_ANAF_LOOKUP_RETRY_ATTEMPTS', 5),
],
```

#### Cached, fail-closed check: `CachedAnafLookup`

To answer "does this CUI exist and is it active today?" for a form or a validation rule, use
`CachedAnafLookup` with an explicit lookup client (not the retrying decorator, which sleeps):

```php
use BeeCoded\EFactura\Enums\AnafLookupStatus;
use BeeCoded\EFactura\Services\CachedAnafLookup;
use BeeCoded\EFacturaSdk\Services\ApiClients\AnafDetailsClient;

$lookup = new CachedAnafLookup(new AnafDetailsClient(strict: true, tries: 1, timeoutSeconds: 2));

$result = $lookup->resolve('RO12345678'); // AnafLookupResult: status, cui, queryDate, active
if ($result->status === AnafLookupStatus::Found && $result->active) {
    // ...
}

// Today's cached verdict only, never calls ANAF (null when there is none): apply your own
// per-user throttle before calling resolve().
$cached = $lookup->cachedRead('12345678');
```

`Found` (with `active`) is cached for the rest of the day and `NotFound` for 15 minutes in the default cache
store, as plain arrays, under a key holding the policy version, the lookup URL, the ANAF date
(Europe/Bucharest) and the CUI, so a verdict never outlives its day. `Found` needs exactly one
company with the CUI asked; `NotFound` needs ANAF's `notFound` to be exactly that CUI. Anything
else, including an answer that arrives after midnight, is `Unavailable`; a rate limit (the SDK's
own or ANAF's 429) is `Busy`. Neither is cached, and `resolve()` never throws for them. A cache
that cannot be read or written does not change ANAF's answer; it only means ANAF is asked again
next time. A CUI that is not 2 to 10 digits (an `RO` prefix is allowed) throws `InvalidArgumentException`.

## OAuth Flow

### 1. Redirect to ANAF

The package provides routes for OAuth:

```
GET /efactura/auth/{cui}  → Redirects to ANAF OAuth
GET /efactura/callback    → Handles OAuth callback
```

**Gate the redirect route.** Both routes run the `routes.middleware` stack, `['web', 'auth']` by
default, so a guest is sent to your login page. `auth` does not check that the user may act for
that company: any logged-in user could authorise any CUI, and the grant that comes back is stored
for it. Restrict `auth/{cui}` to users allowed to act for that CUI, for example with a `can:`
middleware in `config/efactura.php`:

```php
'middleware' => ['web', 'auth', 'can:authorize-efactura,cui'],
```

(a Gate you define that receives the `cui` route parameter), or set `EFACTURA_ROUTES_ENABLED=false`
and run the flow from your own controller with `EFactura::getAuthorizationUrl()` and your own
callback. The callback route needs the same logged-in browser session, including when the flow was
started with `php artisan efactura:auth`.

**The state is bound to who started it.** An authorization started through `efactura/auth/{cui}` is
tied to that browser session (a random nonce kept in the session) and to the logged-in user; the
callback refuses it from another session or for another user, so nobody can get a user to complete
a link they started and store that user's grant. A URL from `php artisan efactura:auth` is not bound
and completes in any logged-in browser. From your own controller, bind it the same way:

```php
// Starting it: one random nonce per browser session
$nonce = $request->session()->get('efactura_nonce');
if (!is_string($nonce)) {
    $request->session()->put('efactura_nonce', $nonce = Str::random(40));
}
$url = EFactura::getAuthorizationUrl($cui, $nonce, $request->user()->getAuthIdentifier());

// In your callback: null unless the same session and user started it
$cui = EFactura::tokenService()->validateOAuthState(
    $request->query('state'),
    $request->session()->get('efactura_nonce'),
    $request->user()->getAuthIdentifier(),
);
```

In your application:

```php
// In a controller or Livewire component
return redirect()->route('efactura.auth', ['cui' => '12345678']);
```

Or generate the URL yourself, bound to the session and user as shown above. Without the nonce and
user id the URL is unbound, like the one `php artisan efactura:auth` prints, and completes in any
logged-in browser: keep that form to the console.

```php
// From a web request: bound
$url = EFactura::getAuthorizationUrl('12345678', $nonce, $request->user()->getAuthIdentifier());

// From the console only: unbound
$url = EFactura::getAuthorizationUrl('12345678');
```

### 2. Handle the Callback

The package automatically:
- Validates the OAuth state (CSRF protection)
- Exchanges the code for tokens
- Stores the tokens in the database
- Fires the `TokenStored` event (a listener exception is reported and does not fail the callback)
- Redirects to your configured success/error URL

### 3. Listen to Events

```php
// In EventServiceProvider or a listener
use BeeCoded\EFactura\Events\TokenStored;

Event::listen(TokenStored::class, function (TokenStored $event) {
    $token = $event->token;

    // Notify user, log, etc.
    Log::info("e-Factura authorized for CUI: {$token->cui}");
});
```

## Token Refresh and Bookkeeping

Every ANAF call the package makes goes through `TokenService::executeWithClient()`:

0. **A deactivated token is refused** with a `RuntimeException` ("Token for CUI … has been
   deactivated") before anything is sent. If ANAF refused to refresh this grant (HTTP 400/401)
   within the last 15 minutes, the call throws a `RefreshCooldownException` (an
   `AuthenticationException`) without asking ANAF
   again, so one dead grant costs one refused request per 15 minutes instead of one per row. The
   cooldown is checked again after the refresh lock is taken, so workers that waited on the lock
   while it was refused do not ask either. The
   refusal is remembered per grant (the token id and its refresh token): after the company
   re-authorises, the new grant is tried at once. The token is never deactivated automatically.
1. **Before the call**, a token that expires within 120 seconds is refreshed under the
   `efactura:token_refresh:{cui}` cache lock (held for the refresh only, at most the SDK HTTP timeout
   plus 30 seconds, waiting up to 30 seconds for it). This is the only place a `LockTimeoutException` comes from, and when it is thrown nothing
   has been sent. If saving that refreshed grant fails, the failure is logged as critical (CUI and
   exception class only) and rethrown; nothing has been sent either.
2. **The call runs without any lock**, so calls for one company run in parallel.
3. **If the SDK refreshes during the call**, it saves the new grant through the package while it
   still holds the `efactura:token_refresh:{cui}` lock, so the next worker to take the lock reads the
   new refresh token instead of spending the old one. The save is one conditional write that only
   replaces a stored grant with an older expiry on an active token, so a stale or concurrent refresh
   never overwrites a newer one. `TokenRefreshed` fires only when the write stored the grant.
4. **After the call**, whether it returned or threw, the same write is tried again (it changes
   nothing once the grant is stored, and stores it if the save under the lock failed), and
   `last_used_at` is recorded.

This bookkeeping never replaces the call's result or exception: an upload index ANAF has assigned is
never lost to a failed `last_used_at` write. Its failures are logged, never thrown: as **critical**
("refreshed token could not be saved; re-authorisation may be needed") when the refreshed grant
could not be saved, because ANAF has already rotated it, and as a warning otherwise. Watch for that
critical entry: the company may have to re-authorise (`php artisan efactura:auth {cui}`).

## Job Scheduling (Required)

**Important:** This package provides jobs but does NOT schedule them automatically. You must register the job schedules in your application.

### Register Jobs in Your Scheduler

Add the following to your `bootstrap/app.php`:

```php
use BeeCoded\EFactura\Jobs\ProcessPendingUploads;
use BeeCoded\EFactura\Jobs\CheckUploadStatuses;
use BeeCoded\EFactura\Jobs\DownloadResponses;
use BeeCoded\EFactura\Jobs\DownloadReceivedInvoices;
use BeeCoded\EFactura\Jobs\SyncMessages;
use BeeCoded\EFactura\Jobs\RetryRateLimitedUploads;
use BeeCoded\EFactura\Jobs\SweepStaleUploads;

->withSchedule(function (Schedule $schedule): void {
    $schedule->job(new ProcessPendingUploads)->everyFiveMinutes();
    $schedule->job(new CheckUploadStatuses)->everyTenMinutes();
    $schedule->job(new DownloadResponses)->everyFifteenMinutes();
    $schedule->job(new RetryRateLimitedUploads)->everyThirtyMinutes();
    $schedule->job(new SweepStaleUploads)->everyFifteenMinutes();
    $schedule->job(new SyncMessages)->hourly();
    $schedule->job(new DownloadReceivedInvoices)->hourly(); // only if features.download_received is on
})
```

### Recommended schedule

This is the one recommended cadence per job; every other place in these docs refers to it. The
quotas named are the SDK's daily per-message and per-CUI limits (`config/efactura-sdk.php`).

| Job | Recommended cadence | Why |
|-----|---------------------|-----|
| `ProcessPendingUploads` | Every 5 minutes | Only dispatches queued uploads. Uploads count against the global per-minute limit (and, for RASP, a per-CUI daily one), not a per-document one |
| `CheckUploadStatuses` | Every 10 minutes | Reads only uploads whose next read is due (`next_poll_at`, the SDK's `PollSchedule`: 1, 5, 15, then 60 minutes as the upload ages; a 10-minute run rounds the shorter ones up), so an old upload uses about 24 of its index's daily status reads (SDK `status_per_day_message`, default 50). An upload at its limit is skipped until it resets; the others are still polled. Without a CUI it dispatches one job per active CUI with uploads due |
| `DownloadResponses` | Every 15 minutes | Each attempt uses one of the download id's daily downloads (SDK `download_per_day_message`, default 5); a successful download happens once. Without a CUI it dispatches one job per active CUI with responses to download |
| `RetryRateLimitedUploads` | Every 30 minutes | A safety net: `ProcessSingleUpload` normally re-drives its own rate-limited upload. The interval gives an exhausted quota time to recover |
| `SweepStaleUploads` | Every 15 minutes | With `jobs.stale_uploading_minutes` at 30, a stranded upload is parked within 45 minutes |
| `SyncMessages` | Hourly | Without a CUI it dispatches one job per active CUI. Each pages through the last 60 days: one paginated-list call per 500 messages (SDK `paginated_list_per_day_cui`, default 50,000) |
| `DownloadReceivedInvoices` | Hourly | Downloads what `SyncMessages` listed; each message's download uses one of its 5 daily downloads. Without a CUI it dispatches one job per active CUI with invoices to download |

A faster cadence than this mainly spends those quotas sooner. `jobs.max_staleness_seconds` (120)
is shorter than every interval, so a run delayed in the queue is dropped instead of overlapping the
next one; the per-CUI jobs a run without a CUI dispatches are never dropped, since they wait behind
each other. A per-CUI `CheckUploadStatuses`, `DownloadResponses` or `DownloadReceivedInvoices` run also
never starts while another run for the same CUI is still working (`WithoutOverlapping`; the second
one is dropped, not delayed), so a run that outlasts a short interval does not poll the same rows
twice.

Adjust the schedules to fit your application's needs. All batch jobs **except `RetryRateLimitedUploads` and `SweepStaleUploads`** accept an optional `$cui` parameter to process only a specific CUI:

```php
Schedule::job(new ProcessPendingUploads('12345678'))->everyFiveMinutes();
```

> **`RetryRateLimitedUploads` and `SweepStaleUploads` take no arguments** and always scan every CUI.
> Because PHP silently discards extra constructor arguments, `new RetryRateLimitedUploads('12345678')`
> will **not** filter by CUI — it processes *all* companies, with no error to tell you otherwise.

### Dispatch a Single Upload or Status Check

For immediate processing of a single upload, dispatch the single-model jobs:

```php
use BeeCoded\EFactura\Jobs\ProcessSingleUpload;
use BeeCoded\EFactura\Jobs\CheckSingleUploadStatus;

// Queue and dispatch a single upload immediately
$upload = EFactura::queueUpload($invoice);
ProcessSingleUpload::dispatch($upload);

// Check status for a specific upload
CheckSingleUploadStatus::dispatch($upload);
```

### Available Jobs

#### Batch Jobs (Scheduled)

Cadences: see [Recommended schedule](#recommended-schedule).

| Job | Purpose |
|-----|---------|
| `ProcessPendingUploads` | Upload all pending invoices to ANAF |
| `CheckUploadStatuses` | Check processing status at ANAF (see [Status check outcomes](#status-check-outcomes)) |
| `DownloadResponses` | Download response ZIPs |
| `DownloadReceivedInvoices` | Download received invoices |
| `SyncMessages` | Sync message list from ANAF |
| `RetryRateLimitedUploads` | Reset safe-to-resubmit failures back to pending |
| `SweepStaleUploads` | Park uploads stranded in `uploading` for reconciliation |

#### Single-Model Jobs (On-Demand)

| Job | Purpose |
|-----|---------|
| `ProcessSingleUpload` | Process a single upload immediately |
| `CheckSingleUploadStatus` | Check status for a single upload |

### Job Configuration

**Standard jobs** (batch processing, status checks, downloads):
- **Tries**: 3
- **Timeout**: 120 seconds
- **Backoff**: 60s, 180s, 300s (progressive)

> **Exception — `RetryRateLimitedUploads` declares no `$tries`.** It falls back to your queue
> worker's default (`--tries=1` unless configured otherwise), so it runs **once** and is not
> retried; its `$backoff` and `$maxExceptions` are consequently inert. It does set a 120s timeout
> and `uniqueFor = 600`. This is largely harmless — the job is an idempotent scanner and the next
> scheduled run re-scans — but don't count on it retrying a transient failure.

**Upload jobs** (`ProcessSingleUpload`) have rate-limit-aware retry:
- **Timeout**: 120 seconds
- **Max Exceptions**: 3 (actual errors only — rate-limit releases don't count)
- **Retry Window**: 24 hours (configurable via `EFACTURA_RATE_LIMIT_RETRY_HOURS`)
- When the SDK's global rate limit quota is exhausted **before** the row is claimed, the job releases
  itself back to the queue with a delay matching the quota reset time, instead of failing. The upload
  row keeps its state (only `updated_at` is touched, so the scheduler does not add a second job)
  and no event fires
- If the limit is hit **during** the upload, the row is released back to `pending` /
  `failure_reason = rate_limited`, `InvoiceRateLimited` fires (**not** `InvoiceFailed`), and the job
  releases with a 60-second delay. The row is deliberately **not** marked `Failed`: it never left the
  pipeline, and `Failed` would make `isEfacturaProcessed()` report `true` for an upload that flips
  back to `pending` a minute later
- Transient failures (auth blips) are re-driven after `EFACTURA_JOB_TRANSIENT_RETRY_DELAY` (default
  30s), capped at `EFACTURA_JOB_MAX_TRANSIENT_ATTEMPTS` (default 5) transient failures of one queued
  job, counted in the cache for the retry window (rate-limit releases do not count); once exhausted
  the row becomes
  `Failed` / `abandoned` and `InvoiceFailed` fires as a genuine terminal signal. The reason **must**
  change on give-up — while it stayed `transient`, `RetryRateLimitedUploads` resurrected the row it
  had just abandoned
- If the job dies permanently, `failed()` parks a row still stuck in `uploading` as `Failed` /
  `indeterminate` for reconciliation — it is never returned to `pending`, since the worker may have
  died after the POST reached ANAF. A row that was still `pending` at the deadline never reached
  ANAF, so it becomes `Failed` / `abandoned` instead and needs no reconciliation

### Queue Configuration

Jobs are dispatched to the queue specified in `config/efactura.php`:

```php
'queue' => env('EFACTURA_QUEUE', null), // null = default queue
```

Set `EFACTURA_QUEUE=efactura` in your `.env` to use a dedicated queue. This allows you to run a separate worker for e-Factura jobs:

```bash
php artisan queue:work --queue=efactura
```

### Rate Limit Configuration

The SDK provides client-side rate limiting to stay within ANAF quotas. Upload
jobs are rate-limit-aware and will delay instead of failing when quotas are
exhausted. For uploads that do fail due to rate limiting (e.g., race conditions),
schedule `RetryRateLimitedUploads` to automatically reset them.

```env
# How long upload jobs can keep retrying (hours, default: 24)
EFACTURA_RATE_LIMIT_RETRY_HOURS=24

# Max failed uploads to reset per retry run (default: 250)
EFACTURA_RATE_LIMIT_RETRY_BATCH=250

# Don't retry failed uploads that failed more than this many days ago (default: 7)
EFACTURA_RATE_LIMIT_RETRY_MAX_DAYS=7
```

### Queue Worker

Ensure your queue worker is running (a real connection such as `redis` or `database`; with
`QUEUE_CONNECTION=sync` an upload's rate-limit and cooldown releases do nothing, see
[Requirements](#requirements)):

```bash
php artisan queue:work
```

### Known limitations

- **Message sync capacity.** Every `SyncMessages` run lists one CUI's last 60 days from page 1 and
  has the job's 120-second timeout for all its pages. At roughly 2–3 seconds a page (one ANAF call
  and 500 row writes) that is about 40 pages, some 20,000 messages in 60 days. A CUI with more times
  out every run and its later pages are never synced. The timeout is not raised: your queue
  connection's `retry_after` would have to be raised above it too, or a running sync would be
  handed to a second worker.
- **A re-queued failed upload keeps its old XML file.** Queueing a failed upload again leaves
  `xml_path` naming the failed attempt's file until the next attempt is claimed, which stores a new
  file and leaves the old one on the disk. The row cannot tell whether ANAF received that file (an
  ANAF refusal) or the SDK refused it before sending, so the package does not delete it. Clean these
  up with your own retention if they matter.

## Artisan Commands

```bash
# Display OAuth URL for a CUI
php artisan efactura:auth 12345678

# Queue pending uploads onto the rate-limit-aware pipeline (requires a queue worker)
php artisan efactura:upload
php artisan efactura:upload --cui=12345678

# Process inline instead of queueing (no rate-limit retry; stops at the first rate limit)
php artisan efactura:upload --sync

# List uploads whose delivery to ANAF is UNKNOWN, and resolve them
php artisan efactura:reconcile
php artisan efactura:reconcile --filed=12 --index=5001130255
php artisan efactura:reconcile --not-filed=12
php artisan efactura:reconcile --not-filed=12 --despite-index   # not in SPV under its index; asks to confirm

# Check statuses and download responses
php artisan efactura:status
php artisan efactura:status --cui=12345678
php artisan efactura:status --upload=12   # fails, without "Done.", when the check was skipped or failed
                                         # and warns when the response download was skipped or failed

# Sync messages from ANAF
php artisan efactura:sync
php artisan efactura:sync --cui=12345678
```

## Events

Listen to these events for custom logic:

| Event | Fired When | Payload |
|-------|------------|---------|
| `TokenStored` | OAuth callback stores new token | `EfacturaToken $token` |
| `TokenRefreshed` | Token auto-refreshed | `EfacturaToken $token` |
| `InvoiceUploaded` | Invoice successfully uploaded | `EfacturaUpload $upload` |
| `InvoiceProcessed` | Response downloaded (success); check `$upload->response_verification` — a `mismatch` archive is kept and still fires this | `EfacturaUpload $upload` |
| `InvoiceFailed` | Upload or processing failed **terminally** | `EfacturaUpload $upload`, `array $errors` |
| `InvoiceRateLimited` | Upload hit ANAF's rate limit and **will be retried** | `EfacturaUpload $upload`, `array $errors`, `?int $retryAfterSeconds` |
| `InvoiceReceived` | Received invoice **or buyer message** (`MESAJ CUMPARATOR…`) downloaded; check `$message->details['tip']` (`FACTURA PRIMITA` for an invoice) before treating it as one | `EfacturaMessage $message` |

> **`InvoiceFailed` means the pipeline has given up.** It is safe to alert on. Transient
> rate-limit hits fire `InvoiceRateLimited` instead — the upload stays in the pipeline and is
> re-submitted automatically. Inspect `$upload->failure_reason` for the classification.

> **A throwing listener never changes the recorded state.** Every event is fired after its state
> is written, through `rescue()`: an exception from a synchronous listener is reported (`report()`)
> and not propagated, so the upload, message or token keeps the state it was given and a batch
> carries on. A throwing `TokenStored` listener leaves the OAuth callback redirecting to the success
> URL, without the exception's message.

### Example Listener

```php
<?php

namespace App\Listeners;

use BeeCoded\EFactura\Events\InvoiceFailed;
use App\Notifications\EfacturaFailedNotification;

class HandleFailedInvoice
{
    public function handle(InvoiceFailed $event): void
    {
        $upload = $event->upload;
        $errors = $event->errors;

        // Get the original invoice
        $invoice = $upload->uploadable;

        // Notify someone
        $invoice->company->owner->notify(
            new EfacturaFailedNotification($invoice, $errors)
        );
    }
}
```

## Database Schema

The package creates three tables (only `efactura_tokens` when `pipeline_tables` is false):

### `efactura_tokens`
Stores OAuth tokens per CUI.

`access_token` and `refresh_token` are **encrypted at rest** (Laravel's `encrypted` cast) as of
v3.0.0, and are therefore readable only with the `APP_KEY` that wrote them — see
[Upgrading to v3.0](#upgrading-to-v30--read-before-deploying). Both columns stay `text`: no schema
change is needed, since Crypt inflates a value roughly 1.8x and ANAF's JWTs are a kilobyte or two.
Nothing queries these columns by value, so encryption costs no lookups.

### `efactura_uploads`
Tracks upload status for your invoices (polymorphic relationship). `uploadable_type` is the model's
morph class: its morph map alias when your app defines one, otherwise its class name.

A model has **at most one** upload row: `(uploadable_type, uploadable_id)` is unique. Re-uploading
after a failure recycles that row rather than inserting another, so `efacturaUpload` always points
at the live attempt.

The `failure_reason` column carries the authoritative classification of a `Failed` upload
(`rate_limited`, `transient`, `abandoned`, `indeterminate`, `validation`, `configuration`). Only
`rate_limited` and `transient` are ever re-submitted automatically.

`response_attempts` / `response_failed_at` count permanent response-download failures (cap
`EfacturaUpload::MAX_RESPONSE_ATTEMPTS`, 3). `status_failures` (v4.0) counts permanent status-check
failures; at `polling.max_status_failures` the upload is parked as indeterminate, and any status
answer resets it.

`next_poll_at` (v4.0) is when the next status read is due (null = now): after each "still in
progress" answer it is set by the SDK's `PollSchedule` (1, 5, 15, then 60 minutes as the upload
ages), less a minute so the read is due on the scheduled run it aims at, but never under 60 seconds
(the 1-minute step stays 1 minute). `response_verification` (v4.0) says how the stored response archive was checked:
`verified`, `limits_only` or `mismatch` (see the `ResponseVerification` enum).

### `efactura_messages`
Stores synced messages from ANAF (sent, received, errors). A listed message without a usable id is
skipped with a warning, never stored. `download_attempts` (v4.0) counts permanent download failures
of a received invoice; at `EfacturaMessage::MAX_DOWNLOAD_ATTEMPTS` (3) it is no longer downloaded.

## Upload Status Flow

```
Pending → Uploading → Processing → Completed
                   ↘            ↘
                    → Failed ←────┘
```

- **Pending**: Queued, waiting to be uploaded
- **Uploading**: Currently being uploaded to ANAF
- **Processing**: Uploaded, waiting for ANAF to process
- **Completed**: Successfully processed, response available
- **Failed**: Upload or processing failed — see `failure_reason` for whether it will be retried

## Status Check Outcomes

`CheckUploadStatuses` (and `CheckSingleUploadStatus`, `efactura:status`) polls every `Processing`
upload with `DownloadService::checkStatus()`, which reads each answer through the SDK's
`StatusResponseData::verdict()`. A download id is ANAF's digits (1 to 64); any other id cannot be
downloaded and counts as no download id.

| ANAF answer or failure | Result |
|------------------------|--------|
| `in prelucrare` | Nothing changes; the next run polls again |
| `ok` with a download id | `Completed`; `DownloadResponses` then downloads the response and fires `InvoiceProcessed` |
| `ok` without a download id | `Completed` with `download_id = null` and a note in `errors`. Filed, but no response can be downloaded, so `InvoiceProcessed` never fires for it |
| `nok` with a download id | `Failed` / `validation` + `InvoiceFailed`; the error report is downloaded later |
| `nok` without a download id | `Failed` / `indeterminate` + `InvoiceFailed`, keeping the upload index: no verdict on the document |
| Only an error (an XML `<Errors/>` header or a JSON `eroare`: an unknown index, "Nu aveti dreptul") | `Failed` / `indeterminate` + `InvoiceFailed`, keeping the upload index and ANAF's errors |
| "XML cu erori nepreluat de sistem" | `Failed` / `validation` + `InvoiceFailed`; nothing to download |
| No recognisable status | `Failed` / `indeterminate` + `InvoiceFailed` |
| A permanent failure: a 4xx, an unreadable 2xx answer, an index the SDK refuses, any other error | Counted in `status_failures`; at `polling.max_status_failures` (default 5) `Failed` / `indeterminate` + `InvoiceFailed` |
| A transient failure: a 5xx, a network error, a token problem | Retried on the next run, not counted |
| The upload's per-message status limit (SDK `RateLimitScope::Status`) | This upload is skipped, not counted; the batch carries on |
| A global SDK limit, any other rate limit, or ANAF's 429 | The batch stops; the next run carries on |

An answer is recorded only while the upload is still `Processing`, and its event fires only for the
run that recorded it, so overlapping runs never announce one answer twice.

Response and received-invoice downloads follow the same rules: a permanent failure is counted (3
attempts), a transient one is retried, a per-message download limit (`RateLimitScope::Download`)
skips that row, and any other rate limit stops the batch. A downloaded file that cannot be written
to the storage disk is not counted: it is logged and downloaded again by the next run.

## Reconciliation (indeterminate uploads)

Some failures leave delivery genuinely **unknown**: the worker died around the POST (job timeout,
SIGKILL, deploy), the transport failed ambiguously (5xx, dropped connection), ANAF sent an
unreadable 2xx upload answer, or its answer had no usable index and status (a non-numeric index,
success without an index, no recognised status), or its status check gave no verdict (`nok` without
a download id, an error-only answer, no recognisable status, `polling.max_status_failures` permanent
failures). ANAF may or
may not have filed the document. An upload answer with a numeric index is not one of these: it is
polled like any other upload.

These are parked as `Failed` with `failure_reason = indeterminate` and are **never re-submitted
automatically** — re-sending would risk double-filing a legal invoice. `queueUpload()` also refuses
to recycle such a row, throwing `DuplicateUploadException`.

Resolving them requires a human, because ANAF's message listing carries no invoice number to match
on, and the index an indeterminate upload has (if any) did not give a verdict:

```bash
# What needs checking: CUI, upload index, upload time and parked time
php artisan efactura:reconcile

# You found it in SPV — hand it back to the normal status-check pipeline
php artisan efactura:reconcile --filed=12 --index=5001130255

# It never arrived — re-queue it for upload (an upload WITHOUT an upload index)
php artisan efactura:reconcile --not-filed=12

# SPV has no invoice under its upload index — re-queue it anyway (asks you to confirm)
php artisan efactura:reconcile --not-filed=12 --despite-index
```

An upload **with** an upload index was parked by a status check that gave no verdict. ANAF decides
that upload's outcome under its index, so look that index up in SPV and resolve it as filed with it;
that also has its status checked again, which records a rejection if that is the outcome. `--not-filed` refuses it, changing nothing: the command
prints why, suggests `--filed=<id> --index=<its index>`, and exits with a failure code. From code,
`UploadService::resolveIndeterminateAsNotFiled()` throws `InvalidArgumentException` for it. An
upload **without** an index was parked before ANAF answered: search SPV around its parked time.

When ANAF keeps answering an index with an error and SPV has no invoice under it, `--filed` only
parks the upload again. Add `--despite-index` to `--not-filed`: the command asks you to confirm that
you checked SPV under that index and the invoice is not there, then re-queues it. It refuses with
`--no-interaction`, and changes nothing if you answer no. The old index is cleared from the row,
kept in its `errors` and logged as a warning. From code, pass `despiteIndex: true` to
`resolveIndeterminateAsNotFiled()`. If the invoice is filed after all, it is filed twice.

`--index` is trimmed and must be 1 to 64 digits: otherwise the command prints the error and exits
with a failure code, changing nothing. From code, `UploadService::resolveIndeterminateAsFiled()`
throws `InvalidArgumentException` for such an index, and returns `false` when the upload is not
awaiting reconciliation.

Schedule `SweepStaleUploads` so rows stranded by a hard worker death are surfaced rather than sitting
in `uploading` forever. Listen for `InvoiceFailed` to be alerted when one appears.

## Logging

The SDK logs all API calls to a dedicated logging channel. Add the following channel to your `config/logging.php`:

```php
'channels' => [
    // ... other channels

    'efactura-sdk' => [
        'driver' => 'daily',
        'path' => storage_path('logs/efactura-sdk.log'),
        'level' => 'debug',
        'days' => 30,
    ],
],
```

You can customize the channel name via the `EFACTURA_LOG_CHANNEL` environment variable (defaults to `efactura-sdk`).

## Testing

For testing, use the SDK's sandbox mode:

```env
EFACTURA_SANDBOX=true
```

## Troubleshooting

### Token Not Found

Ensure you've completed the OAuth flow for the CUI:

```bash
php artisan efactura:auth 12345678
```

### Jobs Not Running

1. Verify the scheduler is registered
2. Check `config('efactura.enabled')` is `true`
3. Check feature flags are enabled
4. Review Laravel queue worker logs

### Upload Failures

Check the `failure_reason` and `errors` columns in the `efactura_uploads` table, or listen to the
`InvoiceFailed` event. `failure_reason` is the authoritative classification — never substring-match
`errors`:

```php
use BeeCoded\EFactura\Enums\FailureReason;

$upload->failure_reason?->isRetryable();        // will be re-submitted automatically
$upload->failure_reason?->needsReconciliation(); // a human must check ANAF first
```

Rows with `failure_reason = indeterminate` are never retried — resolve them with
`php artisan efactura:reconcile`.

### Nothing Uploads, But Nothing Errors

`efactura:upload` and every batch job only *dispatch* work as of v3. Confirm a queue worker is
running on `config('efactura.queue')`, or use `php artisan efactura:upload --sync`.

### `DecryptException: The payload is invalid.`

The token-encryption migration has not run. See
[Upgrading to v3.0](#upgrading-to-v30--read-before-deploying). If it *has* run, your `APP_KEY`
changed — restore it, or set `APP_PREVIOUS_KEYS`, or re-authorise each company.

## AI Assistant Integration (MCP)

This package and its SDK dependency both include MCP servers that help AI coding assistants understand the full e-Factura integration.

**Setup:** Add both to your AI tool's MCP configuration:

```json
{
  "mcpServers": {
    "efactura-sdk": {
      "command": "node",
      "args": ["vendor/bee-coded/laravel-efactura-sdk/mcp/dist/index.js"]
    },
    "efactura": {
      "command": "node",
      "args": ["vendor/bee-coded/laravel-efactura/mcp/dist/index.js"]
    }
  }
}
```

Requires Node.js 18+.

The wrapper MCP server provides these tools:

| Tool | Description |
|------|-------------|
| `get-wrapper-docs` | Documentation topics: overview, setup, upload-pipeline, token-management, commands, **migration** (the v3.1 → v4.0 and v2 → v3.0 upgrade guides) |
| `get-model-integration-guide` | Complete guide for integrating a model with e-Factura |
| `get-event-reference` | All 7 events: TokenStored, TokenRefreshed, InvoiceUploaded, InvoiceProcessed, InvoiceFailed, InvoiceRateLimited, InvoiceReceived |
| `get-job-reference` | All 9 background jobs with scheduling guidance |
| `get-wrapper-config-reference` | Full configuration schema with env vars and defaults |

The SDK MCP server (`efactura-sdk`) provides DTOs, enums, API reference, and SDK-level documentation.

## License

This package is open-sourced software licensed under the [Apache 2.0 License](LICENSE).

## Credits

- [BEE CODED](https://www.beecoded.io/)
