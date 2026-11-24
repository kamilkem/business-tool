# 0038. Owners and managers choose the business date from which KSeF purchase invoices are imported

Status: Accepted

Date: 2026-11-24

## Context

ADR [0023](0023-ksef-purchase-invoice-import.md) §4 fixed where the import starts: "The first run
starts at the first day of the previous month, in the organization's time zone, before the connection
was created." The cursor then follows KSeF's permanent-storage date, so an invoice issued on
30 September but stored in KSeF on 1 October is imported with October's.

The first organization in production wants a longer history than that: the expenses of earlier months
of 2026, which are in KSeF, should not be typed in by hand. Others may want less (a connection made in
the middle of a year whose earlier months were already booked). A fixed rule cannot serve both, and
the rule is invisible in the admin, so an owner cannot tell where the import began.

The owner also wants the start to be a date of issue: with the import set to start on 1 October 2026,
an invoice issued on 30 September 2026 but stored in KSeF on 1 October belongs to September's books and
should not be imported. ADR 0023 keeps the permanent-storage date for the queries for a good reason
(only it, with the high-water mark, guarantees that no invoice is missed between runs), so the issue
date can only filter what those queries return.

Constraints from earlier decisions: a date the user picks is a business date in the organization's
time zone (ADR [0017](0017-business-dates-and-periods-as-value-objects.md)); the cursor is a UTC
instant, windows may overlap, and the KSeF number, unique per organization, makes the import
idempotent (ADR 0023 §4); KSeF allows 64 invoice downloads an hour per NIP, so a long history takes
several runs (ADR 0023 §5); the admin is for owners and managers, who already start "Import now"
(ADR [0021](0021-admin-for-owners-and-managers.md), ADR 0023 §5); KSeF API 2.0 is mandatory from
1 February 2026.

## Decision

This ADR supersedes the sentence of ADR 0023 §4 on where the first run starts, and adds a filter by
issue date to the import. The rest of §4 (queries by permanent-storage date, the cursor, the
high-water mark) stands.

1. **A start date on the connection.** `KsefConnection` keeps an optional `syncStartDate`, a
   `BusinessDate` (a `DATE` column). While it is not set, the default is the old rule: the first day
   of the month before the one the connection was created in, in the organization's time zone.
2. **The start is a date of issue.** The import takes the invoices issued on or after the start date
   (chosen or default). The queries stay as ADR 0023 §4 has them, by permanent-storage date from the
   start date's first moment in the organization's time zone; an invoice in the results whose issue
   date (the metadata's `issueDate`, the invoice's `P_1`) is earlier is passed over without being
   downloaded, and the cursor moves past it. It is not counted as skipped: it is outside the range, not
   unreadable. An invoice is stored in KSeF on or after its issue date, so starting the queries at the
   start date misses nothing issued from it.
3. **Who and how.** Owners and managers set it with `POST /ksef-connection/sync-start-date`
   (`{"syncStartDate": "YYYY-MM-DD"}`) and go back to the default with `DELETE` on the same path, as
   the token has `POST` and `DELETE`; they are the same people who start "Import now". It needs a connection (a token saved once), not a working token, so it can be
   set before the first run. The connection returns both the chosen date (`syncStartDate`, null for
   the default) and the default (`defaultSyncStartDate`), so the admin always shows where the import
   starts.
4. **Validation.** The date is a valid `Y-m-d` date (422 from the input), not after today in the
   organization's time zone, and not before 1 February 2026, when KSeF API 2.0 became mandatory; both
   are domain rules with their own error codes (422). Invoices from KSeF's voluntary period are out of
   scope. A date before the connection was created is allowed: that is the point.
5. **Changing it after imports ran.** The next run starts at the later of the cursor and the start
   date's first moment. The new start (chosen or default) is compared with the previous one:
   - **Earlier:** the cursor is cleared, so the next run starts at the new date and walks forward
     again. Invoices already imported are passed over by their KSeF number, so nothing is imported
     twice; the run only spends metadata queries on the windows it repeats.
   - **Later:** the cursor stays. If the cursor is already past the new date, nothing changes; if not,
     runs not done yet start at the new date, and the days between the cursor and it are not imported.
   - Imported invoices are never deleted or changed by a new start date, whichever way it moves.
   An earlier start re-reads the windows from the new date, so invoices passed over before for their
   issue date are imported then.
6. **Pacing stays as it was.** A long history is imported by the scheduled night runs and "Import now"
   within KSeF's limits (ADR 0023 §5): 100-day windows, 64 downloads an hour, the cursor kept after
   each invoice. The sync status shows the progress (`syncedUntil`).

## Alternatives considered

- **A start date only before the first run.** Simpler (no cursor reset), but an owner who sees that
  the import began too late has no way to fetch the earlier months, which is the case that prompted
  this.
- **A start instant (date and time) or a UTC date.** Owners think in days of their own calendar; a
  `BusinessDate` matches the rest of the app and ADR 0017.
- **Deleting invoices before a later start date.** Expenses owns the purchase invoices (ADR
  [0024](0024-expenses-independent-of-ksef.md)), and they may already be categorised or booked; the
  start date only says what to fetch next, and deleting stays an Expenses decision.
- **Owners only.** Choosing the start changes what is fetched, not credentials; managers already run
  imports, and ADR 0023 §2 limits only the token to owners.
- **Querying KSeF by issue date** (`dateType = Issue`). ADR 0023 rejected it: only the
  permanent-storage date with the high-water mark guarantees that an invoice stored late is not
  missed between runs. Filtering the storage-date results by issue date gives the owner's rule without
  giving that up.
- **Filtering by the issue date in the XML.** It is the same date, but it is known only after the
  download, which spends the 64-an-hour limit on invoices that are then thrown away.
- **No lower bound.** A date before KSeF 2.0 holds nothing the import can read and only wastes the
  hourly metadata query limit.

## Consequences

- An organization can import its whole 2026 history from KSeF without typing it in, spread over a few
  nights at 64 invoices an hour.
- Moving the date earlier repeats metadata queries over windows already done (20 an hour, 100 days
  each): cheap for a venue's volume.
- Moving the date later leaves a gap that is not imported; the admin says so next to the field.
- `KsefConnection` gets a nullable `sync_start_date` column; existing connections keep the default and
  behave as before.
- The rule applies to the default start too: from now on, an invoice issued before the first day of
  the previous month but stored in KSeF after it is not imported for a new connection. Invoices
  already imported stay.
- The KSeF client reads `issueDate` from the metadata (`KsefInvoiceMetadata::$issueDate`); a response
  without it is malformed.
- New error codes `ksef.invalid_sync_start_date.in_the_future` and
  `ksef.invalid_sync_start_date.before_ksef`.
- ADR 0023's status line notes that this ADR supersedes the start rule of its point 4.
