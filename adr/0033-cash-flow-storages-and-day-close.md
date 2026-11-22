# 0033. Cash flow tracks cash by denomination in storages; an end-of-day close by any member counts it and books the day's revenue entry

Status: Accepted

Date: 2026-11-22

## Context

The first customer, a bar in Poland, wants to know how much physical cash it holds and where, and
wants the day's cash takings to come from counting the cash rather than from someone typing a total
(#178, which supersedes #111). Income today is one revenue entry per register and business date,
entered by an owner or manager in the admin (ADR [0022](0022-income-daily-report-per-register.md)
§3, §7), with lines per payment method of the organization (ADR
[0030](0030-payment-methods-per-organization.md)).

The product owner decided the following with the session that coordinates the issues (recorded in
#178), and answered the spike's open questions on 2026-11-22:

- **Cash storages** (a cash box, a safe, …) are a list per organization, **not linked to registers**.
  Each one is handled at the close in one of two ways. In a **counted** storage the employee counts
  every denomination, and the count replaces its balance. In a **deposit** storage the employee
  doesn't know the balance and enters only what they put in.
- **Denominations** are configurable per organization, with defaults by currency: all Polish coins
  and notes for PLN.
- **Owners and managers add or subtract cash** on any storage: change from the bank, a move between
  storages, a bank run.
- **The end-of-day close** is done by an employee in the time clock at any time. It is suggested at
  clock-out but not forced. Managers can also do it in the admin. It goes **straight in**, with no
  approval, and owners and managers review and correct it later.
- **Cash income** is the change in the storages. For a counted storage, it is the closing count
  minus the expected opening (the last balance, plus or minus the movements since). For a deposit
  storage, it is the amount deposited. A payment method can be marked as **cash**. The close fills
  that method's line of the revenue entry with the cash income, and the employee enters the day's
  amounts for the non-cash methods.
- Answers to the spike's questions:
  - The close books into **one register, picked at the close**.
  - There is **one close per day**, and the admin corrects it.
  - **The organization chooses how a close gets its date**: a day-end time, or the employee picks.
  - **The employee sees only what they enter.**
  - **The organization's currency** is used.
  - Reporting comes **later, as a P2 issue**.
  - **Default storages by country.**

Constraints from earlier decisions:

- Money is integer minor units with a currency (ADR
  [0010](0010-money-minor-units-and-utc-timestamps.md)).
- Business dates are `BusinessDate` in the organization's time zone (ADR
  [0017](0017-business-dates-and-periods-as-value-objects.md)).
- Modules talk only through the buses (ADR [0004](0004-layered-modules-and-cqrs-buses.md)).
- The time clock is the employees' app, and the admin is for owners and managers (ADR
  [0016](0016-clock-in-on-own-phone-server-time.md), ADR
  [0021](0021-admin-for-owners-and-managers.md)).
- A list item is deleted only while unused, and deactivated otherwise (ADR 0030 §4, ADR
  [0032](0032-delete-registers-without-entries.md)).
- Error codes and translations follow ADR [0028](0028-ui-language-per-user-with-i18next.md).

## Decision

1. **A new module, `App\CashFlow`**, with five tenant-aware aggregates:
   - `Denomination`, `CashStorage`, `CashMovement`, `DayClose`;
   - `DayCloseSettings`, one per organization.

   It reads the organization and its members through the Organization module's queries. It reads
   registers and payment methods only through Income's queries, and it books income only through
   events that Income handles (point 9). Error codes are `cash_flow.<name>`.

2. **Denominations.**
   - A `Denomination` has a currency (ISO 4217), a face value in minor units, a kind (`coin` or
     `note`) and an optional deactivation moment. A value is unique per organization and currency.
   - They are listed by value. Only the denominations of the organization's current currency are
     offered anywhere.
   - **The defaults come by currency** (`DefaultDenominations`):
     - PLN gets 1, 2, 5, 10, 20 and 50 gr, 1, 2 and 5 zł (coins), and 10, 20, 50, 100, 200 and
       500 zł (notes).
     - A currency with no defaults starts empty.
     - A new organization gets the defaults of its currency from a handler of `OrganizationCreated`,
       as it gets its payment methods (ADR 0030 §2). Existing organizations get them from the
       migration.
   - Owners and managers add, deactivate and reactivate denominations. A denomination is deleted
     only while no close or movement uses it (409 `cash_flow.denomination_in_use`); otherwise it is
     deactivated. The API says whether one is `used`.
   - A count covers the active denominations. Pieces of a denomination deactivated since then count
     as zero, so the admin shows when a storage still holds such pieces, and a manager takes them
     out first with an adjustment.

3. **Cash storages.**
   - A `CashStorage` has a name (trimmed, 1 to 64 characters, unique in the organization,
     deactivated storages included, as for registers), a mode (`counted` or `deposit`) and an
     optional deactivation moment.
   - Owners and managers add, rename, change the mode of, deactivate and reactivate storages. A mode
     change applies from the next close.
   - A storage is deleted only while no close or movement refers to it (409
     `cash_flow.storage_in_use`); otherwise it is deactivated. The API says whether one is `used`.
   - **The defaults come by country** (`DefaultCashStorages`): "Kasetka" (counted) and "Sejf"
     (deposit) in Poland, and "Cash box" (counted) and "Safe" (deposit) everywhere else. This
     follows the default payment methods (ADR 0030 §2). They come from a handler of
     `OrganizationCreated`, as the owner. Existing organizations get them from the migration.
   - A storage starts empty. Its opening float is an adjustment (point 4).

4. **The ledger.** Cash moves through closes (point 6) and `CashMovement`s. A movement is one action
   by an owner or manager, at a moment (`occurredAt`), with lines of (storage, denomination, pieces).
   A movement is one of three kinds:
   - **`adjustment`**: one storage, a signed number of pieces per denomination, and an optional
     note. For example, change from the bank, a bank run or an opening float.
   - **`transfer`**: the same pieces out of one storage and into another. A transfer is one
     movement, so it is one aggregate.
   - **`count`**: an audit count of a **deposit** storage, outside a close. It records absolute
     pieces per denomination. A counted storage is counted only at a close (409
     `cash_flow.audit_count_needs_deposit_storage`). A mid-day count of the cash box would
     otherwise become the day's anchor and hide the takings so far from the close.

   Further rules:
   - **`occurredAt` is now by default.** An owner or manager may set an earlier moment, never a
     later one, for a bank run recorded late. Movements are not edited: a wrong one is deleted by an
     owner or manager and entered again.
   - **Balances are derived, not stored.** A storage's balance at a moment, per denomination, is its
     last count at or before that moment (a close's count or an audit count), plus every signed line
     after it. Ledger order is (`occurredAt`, id). A count's discrepancy is its counted value minus
     that balance just before it, also derived.
   - **A deposit storage never goes below zero pieces of any denomination** at any point of its
     ledger: 409 `cash_flow.storage_balance_negative`, checked for every write and every deletion.
     A counted storage is not checked between closes, because the day's takings are in it but not
     counted, so its per-denomination balance between counts is only an estimate.
   - Volumes are a few rows a day, so the balances and the history are SQL over the ledger. A stored
     balance would be a new decision.

5. **The date of a close is chosen by an organization setting** (`DayCloseSettings`). Owners and
   managers change it in the admin's organization settings.
   - **The day-end time** (`dayEndsAt`, 00:00 to 12:00 in the organization's time zone) is the local
     time before which a close belongs to the previous date. The default is **05:00**, so a bar
     closing at 02:30 on Saturday closes Friday, and a café closing at 18:00 closes the same day.
     The rule is `DayCloseDate::for($now, $timeZone, $dayEndsAt)` in the Domain, on the local wall
     clock, so a DST change does not move it.
   - **The mode**:
     - `day_end_time` (**the default**): the date follows from the day-end time, and the employee
       does not choose. The API takes no date from an employee (422 if one differs).
     - `employee_picks`: the employee picks today or yesterday (calendar dates in the organization's
       time zone). The time clock preselects the date the day-end time gives.
   - **Owners and managers**, in either app, may pick any date up to today, never a future one
     (422).
   - An organization without settings uses the defaults. The setting affects closes only; Income's
     and Payroll's dates are unchanged.

6. **The end-of-day close (`DayClose`).**
   - **Any active member may close**, employees included (ADR 0014). There is **one close per
     business date** in the organization: a second one is a 409 (`cash_flow.day_already_closed`,
     with the date and the close's id). A new close's date must be after the date of every existing
     close (409 `cash_flow.day_close_out_of_order`), so the ledger order matches the order of dates.
     A day nobody closed simply has its cash in the next close.
   - **A close covers every active storage**:
     - a **counted** storage gets a full count of every active denomination: the pieces that stay in
       it after the close, zeros allowed;
     - a **deposit** storage gets the pieces put into it, possibly none.

     A close also takes **one amount per active non-cash payment method** (zero allowed, at most
     `RevenueEntry::MAX_AMOUNT`) and **one active register** (point 8). It records the currency (the
     organization's), who closed and when (`closedAt`, the server's clock, ADR 0016 §4), and later
     who corrected it last and when.
   - The close sits in the ledger at `closedAt`. A storage's **expected opening** is its balance just
     before the close. **Cash income = Σ over counted storages (counted value − expected opening) +
     Σ over deposit storages (deposited value)**: the change in all the storages.
     - The close keeps the expected opening of each counted storage and the cash income, as values
       derived from the ledger and recomputed when the ledger before the close changes (point 7).
     - **A negative cash income** (a shortage, or a withdrawal nobody recorded) is saved and flagged
       on the close for review. Its cash line in income is booked as 0, because revenue lines are
       non-negative (ADR 0022 §4).
   - **What employees see** (product owner: only what they enter):
     - one read for the close form: the active storages (name and mode), the active denominations,
       the active registers, the active non-cash payment methods, the proposed date and the
       settings, and whether that date is already closed (by whom, when);
     - the response to their own close: their totals per storage and the cash income.

     Employees see no balance, no expected opening, no history and no other close
     (`cash_flow.access_forbidden.*`, 403). Owners and managers see everything.

7. **Corrections.** Owners and managers correct a close in the admin by editing its counts, deposits,
   non-cash amounts and register. The business date never changes. They may also delete a close.
   - Correcting or deleting a close, and creating or deleting a movement at a moment before a close,
     change the expected opening of **the first close after that moment on each counted storage it
     touches**. Later closes are anchored by that close's own count. A Cash flow event
     (`CashLedgerChanged`, with the storages and the moment) is handled in Cash flow by one
     `RecalculateDayCloseCommand` for each such close: one aggregate per command.
   - A close whose register, non-cash amounts or cash income changed publishes `DayCloseChanged`. A
     deleted one publishes `DayCloseDeleted`, and its cash then counts in the next close.
   - There is no change history and no locking in v1, as for revenue entries (ADR 0022 §8). Month
     closing (#112) adds them (Consequences).

8. **Register attribution.** The close is made for one active register, picked at the close. It is
   preselected when only one is active, and otherwise the register of the organization's last close
   is. The cash income and the non-cash amounts go to that register's revenue entry for the close's
   date. Other registers' entries are entered by hand, as before. A venue with several registers
   therefore books all its counted cash on one of them. That is accepted: ADR 0022 §6 already lets
   the VAT breakdown differ from the payments.

9. **Booking income, and the Income changes.** This supersedes ADR 0022 §7 in part, and §8 for
   entries booked from a close.
   - Cash flow publishes `DayCloseRecorded`, `DayCloseChanged` and `DayCloseDeleted` with primitives:
     the close's id, the register, the date, the currency, the cash income, the non-cash amounts by
     method id, and the acting member.
   - Income handles them with `BookDayCloseRevenueCommand` (an upsert keyed by the close's id) and
     `RemoveDayCloseRevenueCommand`.
   - `RevenueEntry` gets a nullable, unique `dayCloseId` and the named constructor
     `RevenueEntry::bookFromDayClose()`. The payment lines it writes:
     - the method marked as cash (#184; at most one active) gets `max(0, cash income)`;
     - each non-cash method gets its amount from the close.

     The VAT breakdown and the note are kept.
   - **The actor.** The entry is recorded, or changed, as the member who closed or corrected the
     close (`createdBy`, `updatedBy`), of any role, and is not checked with `IncomeAccess`: the
     authority is the close that Cash flow accepted. The booking commands are sent only by these
     event handlers, never by a Ui processor. Manual entries stay owners' and managers' only
     (ADR 0022 §7).
   - **An entry booked from a close belongs to it.** Its payment lines change only through the close:
     a manual edit that changes them, or a delete of the entry, is a 409
     (`income.revenue_entry_booked_by_day_close`). Its VAT breakdown and note stay editable in the
     admin, which links to the close.
   - **A manual entry that already exists** for that register and date is taken over by the close.
     Its payment lines are replaced, its VAT breakdown and note are kept, and it records who changed
     it. This way the close never blocks an employee at night. A correction to another register
     removes the old entry and books the new one.
   - With no active cash method, the close still works, and only the non-cash lines are booked.
   - Income gives Cash flow, without an actor check (Cash flow has checked its own actor), its active
     registers and its active payment methods with the cash flag. Cash flow asks for them before it
     saves a close, so a close that Income could not book is refused up front (422 for an inactive
     register or method). The event handlers run synchronously after the close is saved, so a
     failure between the two leaves a close without its entry. That gap is accepted, as in ADR
     [0027](0027-payroll-v1-hourly-gross-monthly-runs.md) §8. The upsert makes recording the close
     again, or correcting it, book it.

10. **No background jobs** (ADR [0025](0025-background-jobs-scheduler-and-job-bus.md)): a close is a
    few rows, and recalculation touches one close per storage. A reminder for a day nobody closed is
    a later feature.

## Alternatives considered

- **Several closes per date (per shift), each adding to the entry.** The product owner chose one per
  day. Several closes would need an order within a day and partial entries, and would make a
  duplicate close indistinguishable from a second shift.
- **The employee splits the cash across registers, or a fixed register for closes in the
  settings.** The product owner chose a register picked at the close. A split makes the employee
  type per-register amounts that the count cannot check. A fixed register gives an employee no way
  to book a close to a second register.
- **The close's date from the employee's shift** (the date their open or last shift started on, as
  payroll counts a shift, ADR 0027 §5). Not chosen. The product owner wants the organization to
  choose between a day-end time and the employee picking. A manager closing without a shift would
  have no date.
- **Show the counted storage's expected opening to the employee, or hide the cash income too
  ("blind count").** With no expected closing amount, a blind count protects little. The cash income
  lets the employee check the close against the fiscal printer's report. The expected opening is not
  needed for counting.
- **Storages and denominations per currency.** One currency per organization covers the first
  customer, and closes copy the currency, as revenue entries do (ADR 0022 §5).
- **No default storages** (Cash flow switched on by adding one). The product owner chose defaults by
  country, as for payment methods. English defaults outside Poland keep that rule: a default list
  everywhere, named by country.
- **Stored balances** (a balance row per storage and denomination, updated with each movement).
  Faster reads, but every backdated movement, correction and delete would have to rewrite the rows
  after it. Derived balances anchored by counts recompute nothing beyond the next count.
- **Store a count as a difference from the expected balance.** Correcting an earlier movement would
  then make every later balance wrong. An absolute count resets the balance whatever came before.
- **A negative-balance check on counted storages.** Taking 200 zł notes out of the cash box in the
  evening would be refused, because the day's takings in it are not counted yet.
- **Audit counts of a counted storage.** The count would become the opening of the next close and
  move the day's takings out of income.
- **Refuse a close when a manual entry exists**, or book the close under the owner's name. The first
  would stop an employee at night over something a manager entered. The second would lose who
  entered the numbers.
- **Income asks Cash flow while it edits an entry, or Cash flow writes Income's tables.** The first
  makes the dependency go both ways, and the second breaks the module boundaries (ADR 0004). A
  `dayCloseId` on the entry keeps Income ignorant of Cash flow beyond an id, as the shift lock does
  for payroll runs (ADR 0027 §8).
- **A transaction across the close and the entry.** The buses run without transaction middleware
  (ADR 0004). Checking up front and making the booking an idempotent upsert covers the failure that
  matters.
- **Cash flow in the business summary or the dashboard now.** The product owner chose a later P2
  issue. The cash income already counts in income through the revenue entry.

## Consequences

- New tables come with the migration: denominations, storages, movements and their lines, closes and
  their lines, and settings. It also creates the default denominations (by currency) and storages
  (by country) for existing organizations. Every organization's time clock then offers "Close the
  day". An organization that does not use it deactivates or deletes its storages: the time clock
  offers the close only while at least one storage and one denomination of the organization's
  currency are active.
- Income changes: the `dayCloseId` column, `bookFromDayClose()`, the two booking commands and their
  event handlers, the 409 on editing or deleting a booked entry's payment lines, and queries without
  an actor for Cash flow. The admin's entry form shows a booked entry's payment lines read-only.
- A day's cash is only as right as the ledger. A bank run recorded late, with an earlier
  `occurredAt`, corrects the close it precedes and that close's entry. One recorded late at "now"
  understates one close and overstates the next. The admin's history and the negative-income flag are
  where a manager notices.
- Everything the close books comes from the counts, so the person closing can understate the cash.
  The control is the manager's review: comparing the cash income with the fiscal printer's daily
  report. An "expected cash from the fiscal report" check is a possible later feature.
- A venue with several registers books its counted cash on the register picked at the close, so
  per-register cash totals are only as accurate as that choice.
- An organization that changes its currency is offered only the denominations of the new one. Their
  defaults are not added automatically: no event announces a settings change yet. Older closes keep
  their currency, and a manager empties the old currency's balances with adjustments.
- **Month closing (#112)** will lock Cash flow too. Inside a closed month, no close may be recorded,
  corrected or deleted, and no movement may be created or deleted with an `occurredAt` in it,
  because each of these would rebook Income. The likely shape is a locked period owned by Cash
  flow, as Time tracking owns its lock (ADR 0027 §8). #112's ADR decides whether closing a month is
  one concept across modules.
- Reporting: a "cash on hand" card for the dashboard is a P2 follow-up. The business summary (ADR
  [0029](0029-reporting-module-composes-module-queries.md)) is unchanged.
- New screens: Cash flow settings (denominations, storages) and a Cash flow page (balances, history,
  adjustments, transfers, audit counts) in the admin; the close settings on the organization
  settings page; close review and correction in the admin; a "Close the day" screen in the time
  clock.
