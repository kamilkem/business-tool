import { describe, expect, it } from "vitest"

import {
  addDays,
  browserTimeZone,
  businessDateOf,
  compareBusinessDates,
  createDateTimeFormat,
  dayCount,
  formatDuration,
  formatUtcOffset,
  instantsAt,
  isBusinessDate,
  isWallTime,
  isWholeMonth,
  localDateTimeOf,
  minutesBetween,
  monthOf,
  monthPeriod,
  periodContains,
  periodError,
  shiftPeriod,
  thisMonth,
  toZonedIsoString,
  today,
  utcOffsetMinutes,
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

describe("wall-clock times in the organization's time zone", () => {
  const iso = (dates: Date[]) => dates.map((d) => d.toISOString())

  it("test_it_turns_a_date_and_time_in_the_organizations_zone_into_one_instant", () => {
    // 22:00 in Warsaw in winter is 21:00 UTC, and in summer 20:00 UTC; the browser is in New York.
    expect(iso(instantsAt({ date: "2026-11-11", time: "22:00" }, WARSAW))).toEqual(["2026-11-11T21:00:00.000Z"])
    expect(iso(instantsAt({ date: "2026-07-14", time: "22:00" }, WARSAW))).toEqual(["2026-07-14T20:00:00.000Z"])
    expect(iso(instantsAt({ date: "2026-11-11", time: "22:00" }, "America/New_York"))).toEqual([
      "2026-11-12T03:00:00.000Z",
    ])
    expect(iso(instantsAt({ date: "2026-11-11", time: "00:00" }, "UTC"))).toEqual(["2026-11-11T00:00:00.000Z"])
  })

  it("test_it_finds_no_instant_in_the_hour_skipped_when_warsaw_moves_to_summer_time", () => {
    // 29 March 2026: the clock jumps from 02:00 to 03:00, so 02:00-02:59 never happens.
    expect(instantsAt({ date: "2026-03-29", time: "02:00" }, WARSAW)).toEqual([])
    expect(instantsAt({ date: "2026-03-29", time: "02:30" }, WARSAW)).toEqual([])
    expect(iso(instantsAt({ date: "2026-03-29", time: "01:59" }, WARSAW))).toEqual(["2026-03-29T00:59:00.000Z"])
    expect(iso(instantsAt({ date: "2026-03-29", time: "03:00" }, WARSAW))).toEqual(["2026-03-29T01:00:00.000Z"])
  })

  it("test_it_finds_both_instants_in_the_hour_repeated_when_warsaw_moves_to_winter_time", () => {
    // 25 October 2026: the clock goes back from 03:00 to 02:00, so 02:00-02:59 happens twice.
    expect(iso(instantsAt({ date: "2026-10-25", time: "02:30" }, WARSAW))).toEqual([
      "2026-10-25T00:30:00.000Z",
      "2026-10-25T01:30:00.000Z",
    ])
    expect(iso(instantsAt({ date: "2026-10-25", time: "01:59" }, WARSAW))).toEqual(["2026-10-24T23:59:00.000Z"])
    expect(iso(instantsAt({ date: "2026-10-25", time: "03:00" }, WARSAW))).toEqual(["2026-10-25T02:00:00.000Z"])
  })

  it("test_it_handles_the_clock_changes_of_new_york_which_fall_on_other_days", () => {
    // 8 March 2026 at 02:00 EST and 1 November 2026 at 02:00 EDT; Warsaw has no change then.
    expect(instantsAt({ date: "2026-03-08", time: "02:30" }, "America/New_York")).toEqual([])
    expect(iso(instantsAt({ date: "2026-11-01", time: "01:30" }, "America/New_York"))).toEqual([
      "2026-11-01T05:30:00.000Z",
      "2026-11-01T06:30:00.000Z",
    ])
    expect(iso(instantsAt({ date: "2026-11-01", time: "01:30" }, WARSAW))).toEqual(["2026-11-01T00:30:00.000Z"])
  })

  it("test_it_handles_a_zone_whose_offset_is_not_whole_hours", () => {
    expect(iso(instantsAt({ date: "2026-11-11", time: "09:00" }, "Asia/Kathmandu"))).toEqual([
      "2026-11-11T03:15:00.000Z",
    ])
    expect(toZonedIsoString("2026-11-11T03:15:00Z", "Asia/Kathmandu")).toBe("2026-11-11T09:00:00+05:45")
  })

  it("test_it_refuses_a_malformed_date_or_time", () => {
    expect(isWallTime("09:05")).toBe(true)
    expect(isWallTime("23:59")).toBe(true)
    expect(isWallTime("24:00")).toBe(false)
    expect(isWallTime("9:05")).toBe(false)
    expect(isWallTime("09:05:00")).toBe(false)
    expect(() => instantsAt({ date: "2026-11-11", time: "25:00" }, WARSAW)).toThrow(RangeError)
    expect(() => instantsAt({ date: "2026-02-30", time: "10:00" }, WARSAW)).toThrow(RangeError)
  })

  it("test_it_reads_the_wall_clock_of_the_organizations_zone_at_an_instant", () => {
    expect(localDateTimeOf("2026-11-11T21:00:00Z", WARSAW)).toEqual({ date: "2026-11-11", time: "22:00" })
    // Past midnight in Warsaw, still the 11th in the browser's New York.
    expect(localDateTimeOf("2026-11-11T23:25:37Z", WARSAW)).toEqual({ date: "2026-11-12", time: "00:25" })
    // Both 02:30s of the repeated hour read as 02:30.
    expect(localDateTimeOf("2026-10-25T00:30:00Z", WARSAW)).toEqual({ date: "2026-10-25", time: "02:30" })
    expect(localDateTimeOf("2026-10-25T01:30:00Z", WARSAW)).toEqual({ date: "2026-10-25", time: "02:30" })
  })

  it("test_it_gives_the_offset_in_force_at_an_instant", () => {
    expect(utcOffsetMinutes("2026-10-25T00:30:00Z", WARSAW)).toBe(120)
    expect(utcOffsetMinutes("2026-10-25T01:30:00Z", WARSAW)).toBe(60)
    expect(utcOffsetMinutes("2026-11-11T12:00:00Z", "America/New_York")).toBe(-300)
    expect(formatUtcOffset(120)).toBe("+02:00")
    expect(formatUtcOffset(-300)).toBe("-05:00")
    expect(formatUtcOffset(0)).toBe("+00:00")
  })

  it("test_it_writes_an_instant_with_the_organizations_offset_for_the_api", () => {
    expect(toZonedIsoString("2026-11-11T21:00:00.789Z", WARSAW)).toBe("2026-11-11T22:00:00+01:00")
    expect(toZonedIsoString("2026-10-25T00:30:00Z", WARSAW)).toBe("2026-10-25T02:30:00+02:00")
    expect(toZonedIsoString("2026-10-25T01:30:00Z", WARSAW)).toBe("2026-10-25T02:30:00+01:00")
    expect(toZonedIsoString("2026-11-12T03:00:00Z", "America/New_York")).toBe("2026-11-11T22:00:00-05:00")
    // Round trip: the string names the same instant.
    for (const date of instantsAt({ date: "2026-10-25", time: "02:30" }, WARSAW)) {
      expect(new Date(toZonedIsoString(date, WARSAW)).getTime()).toBe(date.getTime())
    }
  })
})
