# 0034. An owner closes an Income month, which locks its revenue entries; every entry write is kept in an append-only change history

Status: Accepted

Date: 2026-11-23

## Context

ADR [0022](0022-income-daily-report-per-register.md) §8 left Income open: owners and managers edit or
delete any revenue entry at any time, an entry keeps only who changed it last, and a delete leaves no
trace. Once the accountant has a month's revenue, the bar wants that month to stay as it was sent,
and wants to see who changed what before that (#112).

Since then, end-of-day closes of Cash flow book revenue entries through events
(ADR [0033](0033-cash-flow-storages-and-day-close.md) §9). A close can be recorded, corrected or
deleted later, and a backdated cash movement, or a change to an earlier close, recalculates the
cash income of the next close (§7), which Income then books again. A closed month must not change
through that path either.

Payroll already locks a month of shifts when a run is approved (ADR
[0027](0027-payroll-v1-hourly-gross-monthly-runs.md) §8), through a lock Time tracking owns. The issue
asked whether "closing a month" should be one concept across modules.

Answers of the product owner (2026-11-23):

- Only owners close a month, and only once it has ended in the organization's time zone. A month
  with days or registers without an entry may be closed.
- Owners reopen and close again any number of times; every close and reopen (who, when) is shown,
  with no reason field.
- The change history records every write of an entry (created, changed, deleted, booked from a
  close): who, when, the payment lines, total, VAT lines and note before and after. It starts now,
  with no backfill. The admin shows it in the entry dialog, and the period's changes, deleted entries
  included, on the Income page.
- In a closed month every Income write is refused (409), VAT and note edits included; the month is
  reopened to correct it. Cash flow refuses up front (409) to record, correct or delete a close
  dated in a closed month, and a write whose recalculation would change the cash income of such a
  close.
- Income's month close is its own; payroll keeps its lock.

## Decision

1. **`RevenueMonth`, a tenant-aware aggregate of `App\Income`.** One per organization and month
   (unique; the month's first day, a `business_date`), created at the first close. It is closed or
   open (`closedBy`, `closedAt`, null while open) and keeps its log of actions as children
   (`RevenueMonthAction`: closed or reopened, who, when; ids generated in the domain, as for
   `Paycheck`), never removed.
   - `close()`: owners only (403 `income.revenue_month_close_forbidden`); the month must have ended
     in the organization's time zone (409 `income.revenue_month_not_ended`) and be open (409
     `income.revenue_month_already_closed`). Nothing else is checked.
   - `reopen()`: owners only; the month must be closed (409 `income.revenue_month_not_closed`).
   - Reads are owners' and managers' (ADR 0022 §7). An organization's month with no row is open.
2. **A closed month locks its entries in Income.** `RevenueMonthLocks` (a Domain value object of the
   closed months) refuses with 409 `income.revenue_month_closed` {month, businessDate} every write of
   an entry whose business date is in a closed month: recording, changing (VAT and note included,
   and also a PATCH that changes nothing), deleting, and booking or removing it from a close.
   Command handlers check it after the actor and the entry are known (so 403 and 404 come first) and
   before the line rules. Registers and payment methods are not locked: a register or method with
   entries is deactivated, not deleted (ADR 0030, ADR 0032).
3. **Cash flow refuses up front what Income could not book** (as ADR 0033 §9 does for inactive
   registers and methods). It asks Income's `ListClosedRevenueMonthsQuery` (no actor check, never
   sent from Ui) and answers 409 `cash_flow.income_month_closed`:
   - `.for_date` {businessDate, month}: recording, correcting or deleting a close dated in a closed
     month;
   - `.recalculated_close` {dayCloseId, businessDate, month}: a cash movement recorded or deleted, or
     a close corrected or deleted, when it would change the cash income of a close in a closed month.
     Before the write, Cash flow replays the ledger of each storage the write touches, from the
     write's position to the first close after it that counts that storage, with the write's new
     entry (or without it), and compares the expected openings with the close's. Only a change of
     the cash income is refused: a transfer between two storages counted by the same close changes
     nothing.
   With no closed month, nothing is replayed. Income checks its lock again when it books, so a close
   that slips through between the two (the buses run without transactions, ADR 0004) gets a 409
   after Cash flow saved it, as with the other gaps of ADR 0033 §9.
4. **`RevenueEntryChange`, an append-only change history in `App\Income`.** A tenant-aware row per
   write of an entry, never changed or deleted:
   - the entry's id (no foreign key: the entry may be gone), its register's id and name and its
     business date and currency at the time;
   - the kind: `created`, `changed`, `deleted` or `booked` (a close recorded, corrected or taking over
     the entry), and the close's id for a write that came from a close (a close's delete is a
     `deleted` with it);
   - who and when, and a snapshot before and after (null for created / deleted): payment lines with
     the method's id and name at the time, total, VAT lines and note, as JSON.

   The command handler that wrote the entry records it through `RevenueEntryHistory` right after the
   entry is saved, only when something changed. It is not a domain event: the history is Income's
   own record of its writes, and the handler already holds the entry before and after. The gap
   between the two saves is accepted (ADR 0004). It starts empty: earlier writes have no history.
5. **API.** `GET /revenue-months/{YYYY-MM}`, `POST /revenue-months/{YYYY-MM}/close` and
   `POST /revenue-months/{YYYY-MM}/reopen` return the month with its log and whether it has ended.
   `GET /revenue-entry-changes?entryId=` (one entry's history) or `?from=&to=` (the changes of entries
   dated in a period, at most 92 days, as for entries), newest first; owners and managers. `RevenueEntry.monthClosed`
   tells the admin which entries are locked.
6. **Income's month close is not payroll's.** The two lock different things for different reasons:
   an approved payroll run is money paid to people and stays locked once a paycheck is paid; a closed
   Income month is what was reported to the accountant and is reopened freely. Each module owns its
   lock (as Time tracking owns `LockedShiftPeriod`, ADR 0027 §8). A shared "accounting period" can
   come later, when a module needs to close several at once.

## Alternatives considered

- **One month close across modules.** One action would close income, shifts and expenses together,
  but payroll's rules (approval checks, paid paychecks) and Income's (reopen at will) differ, and a
  shared lock would need a module that each of them asks at write time.
- **Corrections after a close as adjusting entries.** Keeps a closed month untouched and still
  records a late correction, but the bar corrects by reopening, and a second entry for a register and
  date breaks ADR 0022 §3.
- **Cash flow writes anyway and Income does not book the change.** The cash ledger and income would
  silently disagree for a closed month.
- **Refuse any cash write before a closed month's close**, without replaying the ledger. Simpler, but
  a transfer between two counted storages, which changes no cash income, would be refused too.
- **History from domain events, or Doctrine change tracking.** Events would carry two snapshots
  through the bus for Income to write back to itself; a Doctrine listener would put Income's rule
  (what a change is) in Infrastructure and miss the close's id.
- **Field diffs instead of snapshots.** Smaller rows, but the admin then reconstructs the entry; a
  before and after snapshot is enough for an entry of a few lines.

## Consequences

- ADR 0022 §8 is superseded: entries in a closed month are locked, and every write leaves a trace.
- Every Income write handler loads the closed months and records history; Cash flow's ledger writes
  ask Income for the closed months and, while there are any, replay the ledger to the next close.
- The admin's Income page gets the month's state with Close and Reopen for owners, a read-only entry
  dialog with its history, and a list of the period's changes.
- The history grows with every write (a close a day, a few edits); it is never pruned in v1.
- A register renamed later keeps its old name in older history rows, as it was.
