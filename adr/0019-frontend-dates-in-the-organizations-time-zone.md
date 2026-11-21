# 0019. The frontend shows dates and times in the organization's time zone, with `Intl` and no date library

Status: Accepted (point 4 superseded by [0028](0028-ui-language-per-user-with-i18next.md))

Date: 2026-11-11

## Context

The API sends instants in UTC (ADR [0010](0010-money-minor-units-and-utc-timestamps.md)) and
business dates as `YYYY-MM-DD` in the organization's time zone
(ADR [0002](0002-locale-specifics-behind-organization-settings.md) §4,
ADR [0017](0017-business-dates-and-periods-as-value-objects.md)). `toLocaleTimeString()` and
`new Date("2026-11-01")` use the browser's zone. An owner travelling, an accountant abroad or a
phone with the wrong zone would see shifts at the wrong hour, on the wrong day, or in the wrong
month. The timeclock (#29) and the Team page did exactly that. The admin shifts table (#31), income,
expenses and payroll all need "today", "this month" and a period to ask the API for.

## Decision

1. **Helpers in `@business-tool/ui/lib/date-time`**, pure functions with the zone as a parameter,
   mirroring ADR 0017:
   - instants: `createDateTimeFormat(timeZone, locale?)` → `date()`, `time()`, `dateTime()`, all in
     that zone;
   - business dates (`"YYYY-MM-DD"` strings, as the API sends them): `businessDateOf(instant, zone)`,
     `today(zone)`, `addDays()`, `isBusinessDate()`, and `format.businessDate()`, which formats a
     calendar date with no zone;
   - periods (`{ from, to }`, inclusive, as the API's `?from=&to=`): `monthPeriod()`, `monthOf()`,
     `thisMonth(zone)`, `shiftPeriod()`, `dayCount()`, `periodError()`;
   - durations: `formatDuration(minutes)` → `"7 h 30 min"`, from instants or the API's whole minutes.
2. **`Intl.DateTimeFormat` with `timeZone`**, no date library. Browsers and Node ship the time zone
   database; formatters are cached because tables format many cells.
3. **The zone comes from the selected organization**: `/me` returns
   `memberships[].organization.timezone`, which both apps already load before rendering. Each app
   provides it with `<OrganizationTimeZoneContext value={...}>` where it knows the membership, and
   components read it with `useOrganizationTimeZone()` / `useDateTimeFormat()`. Outside the provider
   the hook throws, so nothing falls back to the browser's zone unnoticed.
4. **The locale stays the browser's** (`undefined`); only the zone is the organization's. A screen
   may say which zone its times are in when it differs from the browser's (the timeclock does).
5. **A shared `PeriodPicker`** (`@business-tool/ui/components/period-picker`) chooses a month or a
   custom range and emits `{ from, to }`; "this month" is the organization's.

## Alternatives considered

- **`date-fns` + `date-fns-tz`, Luxon or Day.js.** Each adds its own date type or a wrapper around
  `Date` that every screen would use. What is needed (format in a zone, the date of an instant,
  calendar arithmetic on `YYYY-MM-DD`) is a small set of functions on top of `Intl`, the same
  reasoning as ADR 0017 for the backend.
- **`Temporal`.** The right model (`PlainDate`, `ZonedDateTime`), but missing from Node 22 (the
  tests' and CI's runtime) and not yet in every browser the apps must run on. The helpers' shapes (`YYYY-MM-DD` strings, a zone parameter) let them
  move to `Temporal` later without changing callers.
- **Fetch `/organizations/{id}` for the zone.** One more request before any time can be shown, in
  both apps; `/me` is already loaded.

## Consequences

- Screens never call `toLocale*String()`, `new Date("YYYY-MM-DD")` or `Intl.DateTimeFormat` directly
  for API data; they use the helpers (CLAUDE.md, Frontend conventions).
- A change of the organization's time zone in Settings reloads `/me` (as a rename already does), so
  every screen follows.
- Labels such as `"7 h 30 min"` are English, like the rest of the UI; translation is a separate
  decision.
