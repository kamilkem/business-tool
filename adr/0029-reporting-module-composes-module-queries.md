# 0029. The monthly business summary is a query-only `App\Reporting` module that combines other modules' queries through the bus

Status: Accepted

Date: 2026-11-21

## Context

The admin dashboard (#68) shows each month's income, expenses, payroll and result, with a trend over
several months. Issue #67 needs an API for it. The numbers belong to three modules that already
answer their own queries through the query bus:

- Income: `GetRevenueSummaryQuery` (#43, ADR [0022](0022-income-daily-report-per-register.md));
- Expenses: `ListPurchaseInvoicesQuery` with totals per currency (#53, ADR
  [0024](0024-expenses-independent-of-ksef.md));
- Payroll: `ListPayrollRunsQuery` (#62, ADR [0027](0027-payroll-v1-hourly-gross-monthly-runs.md)).
  ADR 0027 already said that approved runs are the payroll cost the summary adds up.

Each of these keeps the currency it was recorded in, so a period can hold amounts in more than one
currency (ADR [0010](0010-money-minor-units-and-utc-timestamps.md)). Business dates and months are
in the organization's time zone (ADR [0017](0017-business-dates-and-periods-as-value-objects.md)).
Modules talk only through the buses, and Deptrac enforces that (ADR
[0004](0004-layered-modules-and-cqrs-buses.md)). None of the three modules is a natural owner of
the other two's numbers.

The product owner answered the open questions on 2026-11-21. They are recorded in the decision below.

## Decision

1. **A new module, `App\Reporting`**, with Application and Domain code only: no entities, no
   repositories, no Doctrine mapping. It asks for the members and the organization through the
   Organization module's queries. It reads income, expenses and payroll only through the queries
   listed above, on the query bus. It never reads another module's repository, and it stores
   nothing.
2. **One endpoint, `GET /business-summary?from=YYYY-MM&to=YYYY-MM`**, returns one summary per
   calendar month: each month is `BusinessPeriod::month()` in the organization's time zone.
   - A request covers at most 12 months, so the dashboard's trend chart is one request.
   - The current month is allowed and flagged as incomplete (a preview).
   - A month after the current one is a 422.
3. **Owners and managers only** (ADR [0021](0021-admin-for-owners-and-managers.md)). An employee
   gets a 403 from Reporting's own check, before any other module is asked.
4. **Amounts per currency**, in minor units: the organization's current currency first, always
   present, and other currencies by code. The modules' figures are never converted or added across
   currencies. Per month and currency:
   - **Income**: gross is the revenue total (the sum of the payment lines). VAT is the VAT total of
     the entries' VAT breakdown. Net is gross minus VAT.
   - **Expenses**: the gross, VAT and net totals of the purchase invoices **issued** in the month.
     Invoices carry no payment date. Corrections count with their own, possibly negative, amounts.
   - **Payroll cost**: the total of the month's **approved** run. A draft run's total is reported
     separately as an estimate, with the run's status (`none`, `draft`, `approved`), and is not
     part of the result.
   - **Result**: net income − net expenses − payroll cost.
5. The pattern is the usual API read: a provider, then `GetBusinessSummaryQuery` on the query bus,
   then a View, then `BusinessSummaryResource::fromView()`. The handler asks each source query once
   per month (payroll once for the whole period). It adds the answers up in PHP, in the same way the
   source modules already add up their own lists.

## Alternatives considered

- **Put the summary in Income, Expenses or Payroll.** One business module would then depend on the
  other two, and no module owns the result.
- **Call the module `App\Dashboard`**, after the issue's label. The summary is a report, not UI.
  Later reports and exports of the same numbers fit under Reporting; a dashboard is one of their
  consumers.
- **A SQL read model across the modules' tables.** Faster for long periods, but it reads other
  modules' storage, breaks the Deptrac boundaries and duplicates their rules: how revenue VAT is
  totalled, and how invoice corrections and run visibility work.
- **Count draft payroll runs.** A draft changes until it is approved, so the result would move
  without anyone deciding it. It is shown instead as an estimate.
- **A result on gross amounts.** For a VAT payer, the VAT in income and expenses is owed to or
  claimed from the tax office, not the business's money. Gross figures are still in the response.
- **One request per month.** The trend chart would make up to 12 requests.

## Consequences

- The admin dashboard (#68) needs one request for its cards and trend chart.
- The summary is only as fast as the source queries. With 12 months, that is 24 source queries plus
  one for payroll, each reading a month of rows. This is acceptable at the volume of a small
  business; a cached or SQL read model would be a new decision.
- When a source module changes what its totals mean (another VAT rule, payment dates on invoices),
  the summary follows that module's query. Reporting does not hold the rules.
- A month with amounts in a second currency has a separate result per currency. The dashboard shows
  the organization's currency and flags the others.
- A new kind of income or cost (for example, payroll beyond hourly gross) enters the result when
  Reporting starts asking that module's query, and the response grows a field for it.
