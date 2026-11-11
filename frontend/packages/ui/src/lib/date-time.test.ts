import { describe, expect, it } from "vitest"

import {
  addDays,
  browserTimeZone,
  businessDateOf,
  compareBusinessDates,
  createDateTimeFormat,
  dayCount,
  formatDuration,
  isBusinessDate,
  isWholeMonth,
  minutesBetween,
  monthOf,
  monthPeriod,
  periodContains,
  periodError,
  shiftPeriod,
  thisMonth,
  today,
} from "./date-time"

const WARSAW = "Europe/Warsaw"
// en-GB: 24-hour clock and day-month order, so the expected strings don't depend on the machine.
const warsaw = createDateTimeFormat(WARSAW, "en-GB")

describe("test setup", () => {
  it("test_it_runs_in_a_browser_zone_that_is_not_the_organizations", () => {
    expect(browserTimeZone()).toBe("America/New_York")
    expect(new Date("2026-11-11T12:00:00Z").getHours()).toBe(7)
  })
})

describe("instants in the organization's time zone", () => {
  it("test_it_shows_the_organizations_time_not_the_browsers", () => {
    // 13:02 UTC is 14:02 in Warsaw (CET) and 08:02 in New York.
    expect(warsaw.time("2026-11-11T13:02:00+00:00")).toBe("14:02")
    expect(warsaw.date("2026-11-11T13:02:00+00:00")).toBe("11 Nov 2026")
    expect(warsaw.dateTime("2026-11-11T13:02:00+00:00")).toBe("11 Nov 2026, 14:02")
    expect(createDateTimeFormat("America/New_York", "en-GB").time("2026-11-11T13:02:00+00:00")).toBe("08:02")
  })

  it("test_it_takes_custom_fields_for_a_date", () => {
    expect(warsaw.date("2026-11-11T23:30:00Z", { weekday: "long", day: "numeric", month: "long" })).toBe(
      "Thursday 12 November",
    )
  })

  it("test_it_skips_the_missing_hour_when_warsaw_moves_to_summer_time", () => {
    // 29 March 2026: 02:00 CET becomes 03:00 CEST at 01:00 UTC.
    expect(warsaw.time("2026-03-29T00:59:00Z")).toBe("01:59")
    expect(warsaw.time("2026-03-29T01:00:00Z")).toBe("03:00")
    expect(warsaw.dateTime("2026-03-29T01:00:00Z")).toBe("29 Mar 2026, 03:00")
  })

  it("test_it_shows_the_repeated_hour_twice_when_warsaw_moves_to_winter_time", () => {
    // 25 October 2026: 03:00 CEST becomes 02:00 CET at 01:00 UTC, so 02:30 happens twice.
    expect(warsaw.time("2026-10-25T00:30:00Z")).toBe("02:30")
    expect(warsaw.time("2026-10-25T01:30:00Z")).toBe("02:30")
    expect(warsaw.dateOf("2026-10-25T00:30:00Z")).toBe("2026-10-25")
    expect(warsaw.dateOf("2026-10-25T01:30:00Z")).toBe("2026-10-25")
  })

  it("test_it_measures_a_night_shift_over_the_clock_change_in_real_minutes", () => {
    // 22:00 CEST on 24 Oct to 06:00 CET on 25 Oct: eight hours on the wall clock, nine worked.
    const startedAt = "2026-10-24T20:00:00Z"
    const endedAt = "2026-10-25T05:00:00Z"
    expect(warsaw.time(startedAt)).toBe("22:00")
    expect(warsaw.time(endedAt)).toBe("06:00")
    expect(formatDuration(minutesBetween(startedAt, endedAt))).toBe("9 h")
  })

  it("test_it_refuses_an_unknown_time_zone", () => {
    expect(() => createDateTimeFormat("Europe/Atlantis")).toThrow(RangeError)
  })

  it("test_it_refuses_a_string_that_is_not_an_instant", () => {
    expect(() => warsaw.time("yesterday")).toThrow(RangeError)
  })
})

describe("business dates", () => {
  it("test_it_puts_an_instant_on_the_date_of_the_organizations_zone", () => {
    // 23:30 UTC on 31 Oct is 00:30 on 1 Nov in Warsaw and 19:30 on 31 Oct in New York.
    expect(businessDateOf("2026-10-31T23:30:00Z", WARSAW)).toBe("2026-11-01")
    expect(businessDateOf("2026-10-31T23:30:00Z", "America/New_York")).toBe("2026-10-31")
  })

  it("test_it_starts_a_warsaw_day_at_local_midnight_in_summer_and_in_winter", () => {
    expect(businessDateOf("2026-07-14T21:59:59Z", WARSAW)).toBe("2026-07-14")
    expect(businessDateOf("2026-07-14T22:00:00Z", WARSAW)).toBe("2026-07-15")
    expect(businessDateOf("2026-11-14T22:59:59Z", WARSAW)).toBe("2026-11-14")
    expect(businessDateOf("2026-11-14T23:00:00Z", WARSAW)).toBe("2026-11-15")
  })

  it("test_it_gives_today_in_the_organizations_zone", () => {
    const now = new Date("2026-11-11T23:30:00Z")
    expect(today(WARSAW, now)).toBe("2026-11-12")
    expect(warsaw.today(now)).toBe("2026-11-12")
    expect(today("America/New_York", now)).toBe("2026-11-11")
  })

  it("test_it_formats_a_business_date_without_shifting_it_by_the_browsers_zone", () => {
    // new Date("2026-11-01") is midnight UTC, which is still 31 October in New York.
    expect(warsaw.businessDate("2026-11-01")).toBe("1 Nov 2026")
    expect(createDateTimeFormat("Pacific/Auckland", "en-GB").businessDate("2026-11-01")).toBe("1 Nov 2026")
    expect(warsaw.businessDate("2026-11-01", { weekday: "short", day: "numeric" })).toBe("Sun 1")
  })

  it("test_it_adds_calendar_days_across_clock_changes_and_year_ends", () => {
    expect(addDays("2026-03-28", 1)).toBe("2026-03-29")
    expect(addDays("2026-03-29", 1)).toBe("2026-03-30")
    expect(addDays("2026-10-31", 1)).toBe("2026-11-01")
    expect(addDays("2026-11-01", 1)).toBe("2026-11-02") // New York's clock change
    expect(addDays("2026-12-31", 1)).toBe("2027-01-01")
    expect(addDays("2028-02-28", 1)).toBe("2028-02-29")
    expect(addDays("2026-03-01", -1)).toBe("2026-02-28")
  })

  it("test_it_accepts_only_strict_dates_that_exist", () => {
    expect(isBusinessDate("2026-11-11")).toBe(true)
    expect(isBusinessDate("2028-02-29")).toBe(true)
    expect(isBusinessDate("2026-02-29")).toBe(false)
    expect(isBusinessDate("2026-11-31")).toBe(false)
    expect(isBusinessDate("2026-1-5")).toBe(false)
    expect(isBusinessDate("2026-11-11T00:00:00Z")).toBe(false)
    expect(isBusinessDate("")).toBe(false)
    expect(() => addDays("2026-02-30", 1)).toThrow(RangeError)
  })

  it("test_it_compares_dates", () => {
    expect(compareBusinessDates("2026-11-01", "2026-11-02")).toBeLessThan(0)
    expect(compareBusinessDates("2026-11-02", "2026-11-02")).toBe(0)
    expect(compareBusinessDates("2027-01-01", "2026-12-31")).toBeGreaterThan(0)
  })
})

describe("business periods", () => {
  it("test_it_builds_whole_months", () => {
    expect(monthPeriod(2026, 11)).toEqual({ from: "2026-11-01", to: "2026-11-30" })
    expect(monthPeriod(2026, 2)).toEqual({ from: "2026-02-01", to: "2026-02-28" })
    expect(monthPeriod(2028, 2)).toEqual({ from: "2028-02-01", to: "2028-02-29" })
    expect(monthPeriod(2026, 12)).toEqual({ from: "2026-12-01", to: "2026-12-31" })
    expect(monthOf("2026-10-25")).toEqual({ from: "2026-10-01", to: "2026-10-31" })
    expect(() => monthPeriod(2026, 13)).toThrow(RangeError)
  })

  it("test_it_gives_this_month_in_the_organizations_zone_at_a_month_boundary", () => {
    // Already November in Warsaw, still October in New York (the browser).
    const now = new Date("2026-10-31T23:30:00Z")
    expect(thisMonth(WARSAW, now)).toEqual({ from: "2026-11-01", to: "2026-11-30" })
    expect(warsaw.thisMonth(now)).toEqual({ from: "2026-11-01", to: "2026-11-30" })
    expect(thisMonth("America/New_York", now)).toEqual({ from: "2026-10-01", to: "2026-10-31" })
    // New Year's Eve: 23:30 UTC is already 2027 in Warsaw.
    expect(thisMonth(WARSAW, new Date("2026-12-31T23:30:00Z"))).toEqual({ from: "2027-01-01", to: "2027-01-31" })
  })

  it("test_it_steps_whole_months_by_month", () => {
    expect(shiftPeriod(monthPeriod(2026, 11), 1)).toEqual(monthPeriod(2026, 12))
    expect(shiftPeriod(monthPeriod(2026, 12), 1)).toEqual(monthPeriod(2027, 1))
    expect(shiftPeriod(monthPeriod(2026, 1), -1)).toEqual(monthPeriod(2025, 12))
    expect(shiftPeriod(monthPeriod(2026, 3), -1)).toEqual(monthPeriod(2026, 2))
  })

  it("test_it_steps_a_custom_period_by_its_length", () => {
    expect(shiftPeriod({ from: "2026-11-01", to: "2026-11-15" }, 1)).toEqual({ from: "2026-11-16", to: "2026-11-30" })
    expect(shiftPeriod({ from: "2026-11-11", to: "2026-11-11" }, -1)).toEqual({ from: "2026-11-10", to: "2026-11-10" })
    expect(isWholeMonth({ from: "2026-11-01", to: "2026-11-15" })).toBe(false)
  })

  it("test_it_counts_days_both_ends_included", () => {
    expect(dayCount(monthPeriod(2026, 10))).toBe(31) // 25 October has 25 hours in Warsaw
    expect(dayCount(monthPeriod(2026, 3))).toBe(31) // 29 March has 23
    expect(dayCount({ from: "2026-11-11", to: "2026-11-11" })).toBe(1)
    expect(periodContains(monthPeriod(2026, 11), "2026-11-30")).toBe(true)
    expect(periodContains(monthPeriod(2026, 11), "2026-12-01")).toBe(false)
  })

  it("test_it_explains_why_a_typed_period_cant_be_used", () => {
    expect(periodError({ from: "2026-11-01", to: "2026-11-30" })).toBeNull()
    expect(periodError({ from: "", to: "2026-11-30" })).toBe("Enter both dates.")
    expect(periodError({ from: "2026-11-30", to: "2026-11-01" })).toBe("The end date is before the start date.")
    expect(periodError({ from: "2026-01-01", to: "2026-04-03" }, 92)).toBe("Choose at most 92 days.")
    expect(periodError({ from: "2026-01-01", to: "2026-04-02" }, 92)).toBeNull()
  })

  it("test_it_names_a_period", () => {
    expect(warsaw.period(monthPeriod(2026, 11))).toBe("November 2026")
    expect(warsaw.period({ from: "2026-11-11", to: "2026-11-11" })).toBe("11 Nov 2026")
    expect(warsaw.period({ from: "2026-11-01", to: "2026-11-15" })).toMatch(/^1\s*–\s*15 Nov 2026$/)
  })
})

describe("durations", () => {
  it("test_it_formats_whole_minutes_as_hours_and_minutes", () => {
    expect(formatDuration(450)).toBe("7 h 30 min")
    expect(formatDuration(480)).toBe("8 h")
    expect(formatDuration(45)).toBe("45 min")
    expect(formatDuration(59.9)).toBe("59 min")
    expect(formatDuration(0)).toBe("0 min")
    expect(formatDuration(-5)).toBe("0 min")
  })

  it("test_it_counts_whole_minutes_between_instants", () => {
    expect(minutesBetween("2026-11-11T08:00:00Z", "2026-11-11T15:30:59Z")).toBe(450)
    expect(minutesBetween("2026-11-11T08:00:00Z", "2026-11-11T07:00:00Z")).toBe(0)
  })
})
