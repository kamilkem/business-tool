# 0027. Payroll v1 is hourly pay for a calendar month, gross only: a run is generated from ended shifts, approved by an owner, and locks its shifts

Status: Accepted

Date: 2026-11-20

## Context

Payroll is the next milestone (#60–#66). Worked time already exists: shifts with server-set times,
corrections by owners and managers, and shift change requests from employees
(ADR [0016](0016-clock-in-on-own-phone-server-time.md)). ADR 0016 already noted that a shift's times
are money. The spike (#59) had to settle what v1 pays, for which period, and what happens to shifts
once pay is approved.

How the first customer, a bar in Poland, pays its staff (answers of the product owner):

- **Everyone works on an *umowa zlecenie* (a civil contract) paid by the hour.** Each person has a
  usual hourly rate. On some days (New Year's Eve) one person gets a different rate for that day.
  Raises happen from a date.
- **The bar pays gross; the accountant does the rest.** Social security contributions, income tax
  and net pay are computed by the accountant from what the bar sends.
- **Bonuses and advances happen**: a fixed amount added to or taken off a person's month. Tips are
  shared outside the app.
- **Shifts run past midnight** (Friday 20:00 → Saturday 03:00), sometimes past the end of a month.
  The bar thinks of such a shift as Friday's.
- **Rounding of hours varies** and is chosen when the month is settled: exact minutes, or each shift
  rounded to 15, 30 or 60 minutes, up, down or to the nearest.
- **Managers prepare the payroll, owners approve and pay it.** Who may do what should become a
  per-organization setting later.

Constraints from earlier decisions: country-specific rules only behind the organization's country
(ADR [0002](0002-locale-specifics-behind-organization-settings.md) §3); money is integer minor units
with a currency (ADR [0010](0010-money-minor-units-and-utc-timestamps.md)); members are deactivated,
never deleted, and their history stays (ADR [0014](0014-deactivate-members-never-delete.md)); business
dates and periods are `BusinessDate`/`BusinessPeriod` in the organization's time zone
(ADR [0017](0017-business-dates-and-periods-as-value-objects.md)); the admin is for owners and managers
(ADR [0021](0021-admin-for-owners-and-managers.md)); modules talk only through the buses
(ADR [0004](0004-layered-modules-and-cqrs-buses.md)).

## Decision

1. **A new module, `App\Payroll`**, with three tenant-aware aggregates: `HourlyRate`, `DayRate` and
   `PayrollRun` (with its `Paycheck`s). It reads shifts from `App\TimeTracking` and members from
   `App\Organization` through the query bus only.
2. **v1 pays by the hour and computes gross only.** Every paid person has an hourly rate; v1 has no
   monthly salaries, overtime, night supplements, contributions, tax or net pay. Pay is country-neutral
   arithmetic (time × rate + adjustments), so v1 has **no country-specific rules** and stores no
   contract type: that every person is on an *umowa zlecenie* is the accountant's knowledge, not
   data. When contract types, a statutory minimum rate or contributions come, they are rules in the
   Payroll domain keyed by the organization's country (as Income keys VAT rates by country, ADR
   [0022](0022-income-daily-report-per-register.md) §6); an integration with an authority would be
   its own module (ADR [0024](0024-expenses-independent-of-ksef.md)). Tips are not recorded.
3. **Rates.**
   - **`HourlyRate`**: a person's usual rate from a business date (`effectiveFrom`, a `DATE` column)
     until the next one, in minor units and the organization's currency. One rate per person and
     date (409 for a second). A raise is a new rate from a date; earlier shifts keep the old one.
   - **`DayRate`**: a person's rate for one business date, replacing their hourly rate for the
     shifts of that date. One per person and date.
   - The rate of a shift is the person's day rate for the shift's business date (point 5) if there is
     one, else the hourly rate in effect on that date.
   - Rates and day rates can be added, changed and deleted, except for a date inside a locked period
     (point 8): that is a 409.
   - The rate's currency is the organization's when it is set. A rate in another currency (the
     organization changed its currency) does not count as a rate for that run's currency (point 6).
4. **The pay period is a calendar month in the organization's time zone** (`BusinessPeriod::month()`).
   There is one `PayrollRun` per organization and month (409 for a second), stored with the month's
   first day as a `DATE`.
5. **A shift belongs to the business date it started on**, in the organization's time zone
   (`BusinessDate::containing($startedAt, $timeZone)`), as the shifts list already shows it. The whole
   shift counts for that date's rate and that date's month: Friday 20:00 → Saturday 03:00 is Friday's,
   and 31 October 20:00 → 1 November 03:00 is all October. A shift is never split at midnight or at
   the end of a month. Its length is the real time between start and end, so a night across a DST
   change is an hour shorter or longer.
6. **A run is generated as a draft and regenerated until it is right.**
   - Generating takes the month's **ended** shifts of every member who has any (deactivated members
     included, ADR 0014) and builds one `Paycheck` per person, with one line per shift: shift id,
     business date, length, paid length, rate, amount. The lines are a snapshot, so an approved run
     never changes when rates or shifts change later.
   - **Rounding is chosen at generation** and kept on the run: a unit (`none`, `15`, `30` or `60`
     minutes) and a direction (`nearest`, `up`, `down`; nearest rounds a half up). It applies to each
     shift's length. With `none`, the length is exact to the second.
   - **Amounts are exact until the end:** a paycheck's pay for hours is the sum of
     paid seconds × rate in minor units, divided by 3600 once and rounded half up to a minor unit. No
     floats (ADR 0010).
   - **Adjustments**: a draft paycheck takes any number of lines with a label and a signed amount in
     minor units: a bonus (+), an advance already paid (−). The paycheck's total is pay for hours plus
     adjustments. An adjustment may also give a paycheck to a member with no shifts that month.
     Regenerating keeps the adjustments and the rounding (unless new rounding is given) and rebuilds
     the shift lines.
   - A shift with no rate for its date is listed on the paycheck with no amount, and the run cannot be
     approved until the rate exists and the run is regenerated.
   - The run copies the organization's currency when it is generated. A draft can be deleted.
7. **Approval.** Only an owner approves, and only when:
   - the month has ended in the organization's time zone (a draft of the current month is a preview);
   - no shift that started in the month is still open, and no shift change request about the month is
     pending (the manager decides it first);
   - every shift line has a rate, and no paycheck's total is negative (an advance larger than the
     month's pay is split, with the rest as a line in a later month);
   - the run is up to date: approval recomputes the run from the current shifts, rates and
     adjustments, and if anything differs from the draft, it is refused (409) and the run has to be
     regenerated and reviewed again.
   A refused approval is a 409 that names the reason. Approval records who approved and when, and
   publishes `PayrollRunApproved`.
8. **An approved run locks its month's shifts, in Time tracking.**
   - `App\TimeTracking` gets a `LockedShiftPeriod` (a tenant-aware aggregate: first and last business
     date, the id of whatever locked it, when). An event handler in Time tracking,
     `LockShiftsOnPayrollRunApproved`, creates it; `UnlockShiftsOnPayrollRunReopened` removes it. Both
     are idempotent (keyed by the run id). Time tracking does not know what a payroll run is beyond
     that id.
   - Inside a locked period, nothing about shifts changes: entering a shift (`Shift::record()`),
     correcting one (`Shift::correct()`, whether its old or its new start falls in the period),
     deleting one (#28) and submitting or approving a shift change request about it are refused with
     `ShiftPeriodLockedException` (409). The shift's business date (point 5) decides whether it is in
     the period. Clocking in and out are not affected: approval needs the month to be over with no
     open shifts.
   - Payroll rates and day rates for a date in a locked period are refused too (point 3).
   - The run is approved first and the period locked right after, synchronously, as with other event
     handlers; the gap between the two is accepted.
9. **Reopening and paying.**
   - An owner reopens an approved run back to draft while **no paycheck is marked paid**; the event
     `PayrollRunReopened` unlocks the shifts. A mistake found after a payment is fixed with an
     adjustment in a later month.
   - An owner marks a paycheck of an approved run paid, with the business date and a method (`cash`,
     `transfer`), and can undo the mark. A run whose paychecks are all paid is shown as paid; that
     state is derived, not stored.
10. **Who does what.** Owners and managers manage everyone's rates, day rates and adjustments,
   including their own, and generate, regenerate and delete drafts. Only owners approve, reopen and
   mark paid. These are domain rules in the model (403 otherwise), for now fixed in code; making them
   a per-organization setting is a later permissions feature. Employees read only their own rates and
   their own paychecks from approved runs, and an estimate for the current month (the time clock,
   #66).
11. **No background jobs.** Generating a month is a few hundred shifts at most and runs in the request;
   ADR [0025](0025-background-jobs-scheduler-and-job-bus.md)'s jobs are not needed in v1.
12. **Export for the accountant**: a CSV of an approved run, one row per paycheck (person, paid time,
   pay for hours, adjustments, total), with the shift lines available as a second CSV. PDF is not in
   v1.

## Alternatives considered

- **Split shifts at midnight and at the end of the month** (the first wording of #61). Exact per
  calendar day, but the bar thinks of a night as the day it started, a New Year's Eve day rate would
  stop paying at midnight, and the admin and time clock already list a shift under its start date.
- **One current rate per person, no history.** Simpler, but a raise would change every unapproved
  month, and regenerating an old draft would give a different result.
- **A day rate for everyone on a date.** Not how the bar works; it sets a different rate for one
  person. Can be added later on top of personal day rates.
- **Fixed rounding per organization.** The bar chooses it when it settles a month; keeping it on the
  run also records how each month was computed.
- **Compute contributions, tax and net for Poland.** Needs per-person tax data (student status, tax
  relief, other employment) and yearly upkeep of the rules; the accountant does it already.
- **Contract types and monthly salaries now**, or **overtime and night supplements** from the Polish
  labour code. Not used by the first bar, where everyone is on *umowa zlecenie*.
- **A statutory minimum hourly rate check.** Rejected by the product owner for v1.
- **Tips as a paycheck line or a pool split by hours.** Rejected for v1; the bar shares tips outside
  the app.
- **Payroll asks Time tracking at write time whether a period is locked**, or Time tracking asks
  Payroll. Either makes one module's write rules depend on the other's data in both directions. A
  locked period that Time tracking owns keeps its own rules self-contained, and the lock is reusable
  for another reason to close a month.
- **Approve without checking that the draft is current.** The reviewer could approve numbers that no
  longer match the shifts being locked.
- **Never reopen an approved run**, or **reopen even after payments.** The first makes every mistake
  an adjustment; the second loses the record of what was paid.
- **Managers approve too.** The product owner wants an owner's approval for money that leaves.

## Consequences

- Payroll reads shifts through a Time tracking query (#61) that returns each shift with its business
  date and length (ended and open), and the pending change requests for a period. The query no longer
  splits shifts at a boundary.
- Time tracking gains a locked period and a new 409 on every shift write, change request and the
  shift delete of #28, which then needs no soft delete: a locked shift cannot be deleted.
- The admin needs Payroll screens for rates, day rates, runs, adjustments and payments, and the time
  clock a view of the employee's own pay (#64, #66). A new member with shifts but no rate blocks
  approval until a rate is set.
- An organization that changes its time zone after a month is locked sees that month's lock and shift
  dates converted with the new zone; accepted, as for the rest of the app.
- Approved runs are the payroll cost the monthly business summary (#67) adds up.
- Contract types, salaries, supplements, contributions, tax, net pay, tips, a minimum rate check and
  configurable permissions are follow-ups; each one that is country-specific goes behind the
  organization's country.
