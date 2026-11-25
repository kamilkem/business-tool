# 0039. KSeF backlogs of more than 64 invoices are imported with the package export, and the import shows what is left and when it should be done

Status: Accepted

Date: 2026-11-25

## Context

ADR [0023](0023-ksef-purchase-invoice-import.md) §4 imports purchase invoices one XML at a time
(`GET /invoices/ksef/{ksefNumber}`), which KSeF allows 64 times an hour per NIP (8/s, 16/min, 64/h;
`limity/limity-api.md`). It rejected the package export (`POST /invoices/exports`) for v1 because a venue's
volume fits the synchronous limits, and named it "the step up if an organization's volume outgrows them".
Its Consequences accepted that "a backlog is slow".

Two things changed that. ADR [0038](0038-ksef-import-start-date-chosen-by-owner.md) lets owners and managers
move the start date back as far as 1 February 2026, so a first import can have hundreds or thousands of
invoices waiting. One at a time, that is hours to days of night runs (ADR 0038 §6). And the request pacer
(#223) now keeps each endpoint within KSeF's limits, so a run that meets the hourly download limit ends
before KSeF answers 429 (`KsefRequestPacer`). It ends sooner, but the backlog still drains no faster. The
admin shows only how far the import got (`syncedUntil`), not how much is left or when it should finish.

What KSeF's documentation says about the export (API 2.9.0 in production, 2.10.0 on TEST;
`pobieranie-faktur/pobieranie-faktur.md`, `pobieranie-faktur/przyrostowe-pobieranie-faktur.md`,
`limity/limity-api.md`, `bezpieczenstwo/klucze-publiczne-do-szyfrowania.md`, `api-changelog.md` and the
OpenAPI, read on 2026-11-25):

- **Request.** `POST /invoices/exports` takes the same `filters` as the metadata query (`subjectType`,
  `dateRange` of at most 100 days with `dateType = PermanentStorage` and `restrictToPermanentStorageHwmDate`),
  an `encryption` object, and an optional `compressionType` (`Zip`, the default, or `TarGz`). It answers
  `201` with a `referenceNumber`. At most 10 exports run at once per context (error `21182`). The
  `encryption` object carries a 32-byte AES key encrypted with RSA-OAEP SHA-256 under KSeF's
  `SymmetricKeyEncryption` public key, a 16-byte IV, and the key's `publicKeyId`.
- **Status.** `GET /invoices/exports/{referenceNumber}` returns status `100` (in progress), `200` (done),
  `210` (expired), `415` (KSeF cannot decrypt the key), `420` (`from` is past the high-water mark), and
  `500`/`550` (failed or cancelled by the system, "try again"). The status is kept for 7 days, then the
  endpoint answers `410 Gone`. The links to the package parts are generated on each status call and expire
  (`expirationDate`).
- **Package.** A package holds up to 10,000 invoices or 1 GB compressed. When it reaches either limit,
  `isTruncated` is set and `lastPermanentStorageDate` says where the next export starts; otherwise
  `permanentStorageHwmDate` does. Invoices come in ascending order of the filter's date. The package is a
  ZIP split into parts of at most 50 MB. Each part is encrypted on its own with AES-256-CBC and PKCS#7 under
  the key and IV of the request, and comes with the SHA-256 of the part before and after encryption. Parts
  are downloaded from their URL without the access token and outside the API limits. The archive holds
  `{ksefNumber}.xml` for each invoice and `_metadata.json`: `{"invoices": [InvoiceMetadata, …]}`, the same
  records as the metadata query (KSeF number, form code, `invoiceHash`, `permanentStorageDate`,
  `issueDate`). It is always included since 27 October 2025. So the package carries everything the import
  needs, and no metadata query is required for the invoices in it.
- **Limits.** Export requests: 8/s, 16/min, 20/h (raised from 4/s and 8/min in 2.4.0). Status calls:
  10/s, 60/min, 600/h. MF recommends the export for high volumes and single downloads only for low ones.

Constraints from earlier decisions: jobs are idempotent, safe to interrupt, flush once per unit, record
their progress after it, and record expected outcomes instead of throwing them (ADR
[0025](0025-background-jobs-scheduler-and-job-bus.md)); per-organization secrets live in `App\Secret`
(ADR [0026](0026-per-organization-secrets-encrypted-with-app-keyring.md)); the cursor, the windows and the
KSeF number as the idempotency key stay as ADR 0023 §4 and ADR 0038 have them; tests never reach the
network (ADR 0023 §8).

## Decision

This ADR replaces the "Package export" alternative of ADR 0023 with a decision, supersedes its
consequence that a backlog is slow, and supersedes point 6 of ADR 0038 (pacing). The queries, the cursor,
the start date and the issue-date filter stay as they were.

1. **Single downloads until more than 64 invoices are waiting, then the export.** A run queries metadata
   from the cursor as before. Before downloading a page's invoices, it counts the ones waiting on that
   page: FA(3), issued on or after the start date, and not in Expenses yet. Once the run has seen more than
   64 waiting invoices (KSeF's hourly download limit), it stops downloading one at a time and imports the
   rest with the export, from the cursor. A run with 64 or fewer waiting (the usual nightly run) does what
   it does today. Because a page holds 250 records, a large backlog is usually recognised on the first page,
   before any single download.
2. **The export.** One export per window: `Subject2`, `PermanentStorage` from the cursor to the earlier of
   100 days later and the run's start, `restrictToPermanentStorageHwmDate = true`, `Zip`. The AES key and IV
   are encrypted with KSeF's current `SymmetricKeyEncryption` certificate (chosen and cached like the token
   key, ADR 0023 §2) and sent with its `publicKeyId`. Both API limits get their own counter in the pacer
   (`KsefApiLimit`).
3. **The key is derived, never stored.** The job picks a random key id (UUID v7) for each export. The key
   and IV are HMAC-SHA-256 of the key id and the context's NIP under the application secret
   (`kernel.secret`, `APP_SECRET`), with separate labels for key and IV, computed in the adapter. The
   connection keeps the export's reference number, key id, range and start time, which are not secrets.
   Anyone with the database alone cannot decrypt a package. If `APP_SECRET` changes while an export is
   pending, its package no longer decrypts: the run drops it and starts a new export from the cursor.
4. **Waiting for the package.** In the run, the status is polled every 5 seconds for at most 5 minutes
   (and never past the run's time budget). That is far inside its limits, and the jobs worker, which
   imports one organization at a time, is held up only briefly. A package not ready by then stays on the
   connection, and the next run (scheduled or "Import now") polls it again before anything else.
5. **Importing the package.** Parts are downloaded in order, each checked against its encrypted SHA-256,
   decrypted, checked against its SHA-256, and appended to a temporary file. The ZIP is then read entry by
   entry, so memory does not grow with the package. `_metadata.json` gives the records in
   permanent-storage order. Each record goes through the same rules as a single download: passed over when
   issued before the start date, counted as skipped when not FA(3), passed over when its KSeF number is in
   Expenses, checked against `invoiceHash`, parsed, and stored with its XML. Each invoice is one flush and
   moves the cursor to its permanent-storage date. After the whole package, the cursor moves to
   `lastPermanentStorageDate` if it was truncated (and the next export starts there), else to the earlier
   of the high-water mark and the window's end, and the export is cleared from the connection.
6. **Interruptions and outcomes.** A run that runs out of time in the middle of a package keeps the export.
   The next run asks for its status again, which gives fresh part links, downloads it again, and passes over
   records below the cursor and invoices already stored. An expired export (`210`, `410`) is dropped and a
   new one started from the cursor. `420` means there is nothing past the high-water mark: the run has
   caught up. Transient failures (`500`, `550`) and failures on our side (`415`, a part that does not match
   its hash, an archive that does not decrypt or open, a missing XML) drop the export and are recorded as
   `unavailable` or `request_failed`. 429, rejected credentials and KSeF being unavailable are recorded as
   before (ADR 0023 §5, ADR 0025), never thrown.
7. **Progress: what is left and when it should be done.** Each run records the method it ended with
   (`single` or `export`) and how many waiting invoices it knows are left: exact when the run caught up
   with the high-water mark, otherwise a minimum (what it counted and did not import yet). A pending export
   whose package is not ready yet has no count. The connection returns them (`importBacklogCount`,
   `importBacklogIsMinimum`, `lastImportMethod`, `exportPending`) and an estimate, `importEstimatedAt`.
   That is the start of the scheduled run expected to finish the backlog: one run when 64 or fewer are left
   or an export is pending, otherwise one run per 10,000 invoices (one package each). The runs are counted
   in the schedule of ADR 0023 §5, from KSeF's `Retry-After` if it is later. The schedule moves from
   `Ui\Scheduler\DefaultSchedule` into the KSeF domain (`KsefImportSchedule`), so the estimate and the cron
   entry cannot drift apart. The admin's import card shows the count ("at least" for a minimum), the
   estimate, and that KSeF is preparing a package.

## Alternatives considered

- **Always the export.** Fewer code paths. But a nightly run with a handful of invoices would wait
  for an asynchronous package, use one of 20 export requests an hour, and need a key and a temporary
  file for a few invoices. MF itself recommends single downloads for low volumes.
- **Count the whole backlog with the metadata query first.** The issue's first idea. It is exact,
  but 10,000 invoices are 40 pages, twice the metadata query's hourly limit. The count would cost more
  than the import, and the single path would query the same pages again. Counting as pages arrive
  needs no extra request.
- **A threshold other than 64.** Below 64, the single path finishes within one run's download limit.
  Above it, a single-download run leaves invoices for the next hour, while an export takes minutes.
  The limit itself is the natural boundary.
- **Store the AES key with `App\Secret`.** It works across runs, but App\Secret writes only on an
  owner's request (ADR 0026). Writing an ephemeral key from a job would need a system actor and a
  secret kind that is created and deleted every night. A derived key needs neither and leaves nothing
  to leak in the database.
- **Keep the key in memory and never resume across runs.** Simpler, but a package that takes longer
  than the wait would be requested again on every run, against 20 export requests an hour. The issue
  asked for the reference number to be kept.
- **`TarGz` compression.** MF recommends it for large packages, but PHP reads ZIP entry by entry with
  the `zip` extension we already install. A venue's packages are a few megabytes; ZIP is enough.
- **The whole package in memory.** Simpler, but up to 1 GB compressed: the temporary file and
  entry-by-entry reading keep memory flat.
- **An estimate in the frontend.** The frontend knows neither the schedule nor the per-run rates. The
  API returns the estimate with the count it is based on.

## Consequences

- A first import of a year's history takes one or a few runs instead of several nights. Nightly runs
  with few invoices behave as before.
- The connection gets nullable columns for the pending export (reference number, key id, range,
  start time) and for the last run's method and backlog. Existing connections have no export and no
  count until their next run.
- `APP_SECRET` now also protects the package key. Rotating it costs at most one export request.
- The job writes a temporary file as large as the package being imported (a few megabytes for a
  venue, at most 1 GB) and deletes it when the package is done or the run fails.
- The adapter grows by three operations (start, status, package), with a `SymmetricKeyEncryption` key
  and `openssl` for AES. The tests use recorded or OpenAPI-derived status responses and an encrypted
  test package, built by a script in `tests/Fixtures/Ksef/` with a fixed test secret.
- The estimate is an estimate: "Import now" can finish sooner, and a 429 or KSeF being unavailable can
  make it later. It is recomputed on every read.
- The status line of ADR 0023 notes that this ADR supersedes its "Package export" alternative and its
  consequence on backlogs, and the status line of ADR 0038 notes that this ADR supersedes its point 6.
