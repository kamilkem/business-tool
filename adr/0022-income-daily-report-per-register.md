# 0022. Income is one entry per register and business date: gross amounts per payment method, and a VAT breakdown chosen by the organization's country

Status: Accepted (point 2 superseded in part by [0032](0032-delete-registers-without-entries.md), point 4 superseded by [0030](0030-payment-methods-per-organization.md), points 7 and 8 superseded in part by [0033](0033-cash-flow-storages-and-day-close.md), point 8 superseded by [0034](0034-income-month-close-and-entry-history.md))

Date: 2026-11-12

## Context

Income is the next milestone (#41–#44): owners and managers record what the venue took and see
totals for a month. Later, a monthly business summary (#67) combines income with expenses and payroll
cost (#59). Before the module scaffold (#41), the spike (#40) had to settle what one income record is.

How the first customer, a bar in Poland, closes a day:

- It has **several registers, each with its own fiscal printer**. Every printer prints its own daily
  report, with sales by VAT rate.
- An owner or manager enters the totals afterwards, from each register's daily report and the
  counted payments. Employees do not enter income.
- It wants the **VAT breakdown** from the daily report in the app. VAT rates are country-specific, so
  they have to sit behind the organization's country (ADR
  [0002](0002-locale-specifics-behind-organization-settings.md) §3).
- The VAT totals on a daily report do not always match the payments taken: a transfer for an
  invoiced event, for example, is not on the fiscal report.

Constraints from earlier decisions: money is integer minor units with a currency (ADR
[0010](0010-money-minor-units-and-utc-timestamps.md)); a business date is a `BusinessDate` and never
computed by hand (ADR [0017](0017-business-dates-and-periods-as-value-objects.md)); the admin is for
owners and managers (ADR [0021](0021-admin-for-owners-and-managers.md)); every business row is
tenant-aware (ADR [0001](0001-multi-tenancy-shared-database-organization-filter.md)).

## Decision

1. **A new module, `App\Income`**, with two tenant-aware aggregates: `Register` and `RevenueEntry`.
2. **Registers are a small list per organization.** A `Register` has a name (unique within the
   organization) and can be deactivated, never deleted, because entries refer to it (as members, ADR
   [0014](0014-deactivate-members-never-delete.md)). A deactivated register takes no new entries, but
   its existing entries stay visible and editable. Owners and managers add, rename and deactivate
   registers. An organization with a single register simply has one.
3. **One `RevenueEntry` per register and business date.** The pair (register, business date) is
   unique in the organization: a second entry for it is a conflict (409). A correction is an edit of
   that entry, not another entry. The business date is the day the daily report covers, chosen by the
   person entering it (not derived from the time of entry). It is stored as a `DATE` column through a
   Doctrine type for `BusinessDate`, the first one in the code (ADR 0017, Consequences). A date after
   today in the organization's time zone is rejected (422). The register and the date of an entry do
   not change: a wrong one is deleted and entered again.
4. **Gross amounts per payment method.** An entry holds one line per payment method used, each a
   non-negative integer amount in minor units. Each method appears at most once, and at least one
   line is required (zero is allowed, for a register that sold nothing). The methods are a fixed set
   in v1, an enum: `cash`, `card`, `transfer`, `voucher`. They are stored as lines (method, amount),
   not as one column per method, so that a per-organization list can replace the enum later without
   reshaping the data. The entry's total is the sum of its lines; it is computed, not stored.
5. **Currency.** The entry copies the organization's currency when it is created and keeps it. If an
   organization changes its currency, older entries keep theirs, and summaries give totals per
   currency.
6. **VAT breakdown, optional and by country.** An entry may also hold VAT lines copied from the
   register's daily report: a rate, the gross amount and the VAT amount, as printed (minor units, VAT
   not recomputed, net = gross − VAT). Each rate appears at most once, VAT is between 0 and the gross
   amount, and is 0 for a 0% or exempt rate.
   - **The rates come from the organization's country**, defined in code in the Income domain. For
     `PL` they are `23`, `8`, `5`, `0` and `zw` (exempt). A country with no rates defined takes no VAT
     lines (422), and the admin does not offer them.
   - **The breakdown is optional.** An entry can be saved with payment lines only, and the VAT lines
     added in a later edit.
   - **The VAT totals do not have to match the payment totals.** Both are stored as entered. The
     admin shows the difference when they don't match; the API does not reject it.
7. **Owners and managers only.** Registers and entries, reads and writes, need the role `owner` or
   `manager` (a domain rule in the model; another role gets 403). Entries are entered in the admin;
   the time clock has nothing for income in v1.
8. **Edits are open, the last change is recorded.** Owners and managers may edit or delete any entry
   at any time. An entry records `createdBy` and `createdAt`, and `updatedBy` and `updatedAt` after the
   first edit (as `Shift` records who corrected it). Deleting removes the entry. There is no locking
   of past days and no change history in v1.
9. **Tips are not income.** They are not entered here; if they are recorded at all, it is with
   payroll (#59).

## Alternatives considered

- **Any number of entries per day, summed.** More flexible (an extra "correction" entry, a label per
  register), but nothing stops a day from being entered twice, and the month view and summary have to
  add up rows without knowing whether they overlap. The unique (register, date) pair turns a duplicate
  into a conflict the user sees.
- **One entry per business date for the whole venue.** Simplest, but with several fiscal printers the
  person entering would add up the daily reports by hand, and a per-register VAT report would be
  lost.
- **A day aggregate with lines per register.** Keeps a whole day in one record, which would also be
  the natural thing to lock. But one record for all registers means every edit of one register
  rewrites the day, and day locking is not needed in v1.
- **Payment methods configurable per organization now.** Real venues add methods (meal vouchers,
  BLIK, delivery platforms), but four fixed ones cover the first bar. Storing lines keeps the change
  small later.
- **Require the VAT breakdown, or require it to match the payments.** Rejected by the first customer:
  the breakdown is not always at hand when the totals are entered, and invoiced transfers are
  legitimately missing from the fiscal report.
- **Recompute VAT from the gross amount.** Fiscal printers round per receipt, so a recomputed VAT
  differs from the printed one by a few minor units. Storing what is printed keeps the numbers the
  accountant sees.
- **Employees enter the totals at the end of a shift.** Not how the first bar works; a later option,
  like the shift change requests of ADR [0016](0016-clock-in-on-own-phone-server-time.md).
- **Locking past days or a full change history.** Useful once months are closed (and payroll runs are
  approved), but nothing closes a month yet.

## Consequences

- An organization needs at least one register before it can enter income; the admin's Income page
  asks for one when there is none. The development fixtures create registers for the bar.
- The first `DATE` column for a business date comes with a Doctrine type, which later modules (a
  closed payroll period) reuse.
- VAT rates are data in the Income domain keyed by country. A second country adds its rates there.
  Rates that change over time (e.g. Poland's 22% before 2011) are not modelled: a rate code is
  accepted for any date. A rate change is the moment to give rates a validity period.
- Summaries (#43, and #67 later) give totals per payment method, per VAT rate, per register and
  overall, per currency.
- A deleted entry leaves no trace, and an edit keeps only who changed it last and when. Accepted for
  v1; month close with locking and a change history are follow-ups, as are configurable payment
  methods and entry by employees.
- Registers add endpoints and an admin screen that the original Income issues did not have.
