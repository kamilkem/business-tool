# 0017. Business dates and periods are value objects converted to UTC ranges with an explicit time zone

Status: Accepted

Date: 2026-11-11

## Context

ADR [0002](0002-locale-specifics-behind-organization-settings.md) §4 says that business dates
("today's revenue", "this pay period") are computed in the organization's time zone, and
ADR [0010](0010-money-minor-units-and-utc-timestamps.md) says that timestamps are stored in UTC.
Together they mean that every question of the form "what happened on this day" turns a local
calendar date into a range of UTC instants. "Which day is it now" turns an instant from the clock
(ADR [0015](0015-time-from-symfony-clock-passed-into-domain.md)) back into a local date.

The first users of this are listing shifts by date (#26) and the admin shifts table (#31). Payroll
periods and income days come later. Each could compute day boundaries by hand with `modify('midnight')`
or by adding 86 400 seconds. Both go wrong twice a year: in `Europe/Warsaw` the last Sunday of March
has 23 hours and the last Sunday of October has 25. Both also go wrong for an organization whose
offset is far from UTC, where a UTC date and a local date often differ. Some zones (e.g.
`America/Santiago`) move their clocks at midnight, so local 00:00 does not exist on that day.

## Decision

1. **Value objects in `App\Shared\Domain\Time`.** Every module's Domain may use them (Deptrac already
   allows Domain → Domain). They are not services, so `services.yaml` excludes the folder.
   - `BusinessDate`: a calendar date with no time and no zone (`of(2026, 11, 11)`).
   - `BusinessPeriod`: an inclusive range of business dates (`fromDates()`, `day()`,
     `month(2026, 11)`, `monthOf()`).
   - `UtcRange`: a half-open range of instants `[from, to)` in UTC, which is what both become for a
     query.
2. **The time zone is always a parameter.** Neither value object stores a zone. A conversion takes
   one: `BusinessDate::containing($instant, $timeZone)` gives the date an instant falls on ("today"
   is `containing($clock->now(), $timeZone)`), and `toUtcRange($timeZone)` gives the instants of a
   date or period. The zone comes from the organization's `timezone` setting. The server default
   zone is never used.
3. **A day starts at the first local time that exists on it**, normally 00:00. It ends where the
   next day starts, so a day lasts 23, 24 or 25 hours and consecutive days and periods never overlap
   or leave gaps. Queries use `>= from AND < to`.
4. **API dates are strict `YYYY-MM-DD`.** `BusinessDate::fromString()` and
   `BusinessPeriod::fromStrings()` reject anything else, and dates that do not exist, with
   `InvalidBusinessDateException`. A period whose last day is before its first gets
   `InvalidBusinessPeriodException`. Both extend `TimeException` and are mapped to 422. An Input
   still validates its own fields (`#[Assert\Date]` and similar), so these exceptions are a safety
   net, not the main validation.
5. **Calendar arithmetic** (`next()`, `previous()`, `dayCount()`) works on the date alone and does
   not depend on a time zone.

## Alternatives considered

- **Compute boundaries by hand where they are needed** (`setTime(0, 0)` in the organization's zone,
  then `+1 day`). Rejected: every module would repeat the DST and missing-midnight cases, and the
  repeated code drifts.
- **Store the time zone in `BusinessDate`.** Rejected: a business date is a calendar fact ("the
  25th of October"). One date is compared with another and parsed from a query string without a
  zone, which is needed only at the boundary with UTC instants.
- **Store a local date column next to each UTC timestamp.** Rejected for now: it duplicates data
  and goes stale when an organization changes its time zone. A module may still store a business
  date it decided on (e.g. a closed payroll period) as a `DATE` column.
- **A library (e.g. `brick/date-time`).** Rejected: the needed part is small, PHP's own
  `DateTimeZone` handles the transitions, and a library would be one more dependency that the
  Domain uses directly.

## Consequences

- Code that needs "today", "this month" or "shifts from X to Y" uses `BusinessDate` and
  `BusinessPeriod`. It never computes day boundaries by hand (CLAUDE.md, Patterns).
- Handlers that turn dates into ranges also need the organization's time zone, so they read the
  organization (as ADR 0002 already expected).
- Unit tests cover the DST days in `Europe/Warsaw`, a zone that changes at midnight
  (`America/Santiago`), zones far from UTC (`Pacific/Auckland`, `Pacific/Honolulu`), month and year
  boundaries, and instants one second either side of local midnight.
- A `DATE` column for a business date needs a Doctrine type or a conversion in the repository
  adapter. The first module that stores one adds it.
