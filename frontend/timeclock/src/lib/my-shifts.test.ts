import { createDateTimeFormat } from "@business-tool/ui/lib/date-time"
import type { Shift } from "@business-tool/ui/lib/shifts"
import { describe, expect, it } from "vitest"

import { monthKey, monthPeriodOf, shiftChange, shiftCount, shiftHours, validateMyShiftsSearch } from "./my-shifts"

// The tests run with TZ=America/New_York (vitest.config.mts); the bar is in Warsaw.
const warsaw = createDateTimeFormat("Europe/Warsaw", "en-GB")

const ME = "0193a1b2-0000-7000-8000-000000000001"
const MANAGER = "0193a1b2-0000-7000-8000-000000000002"
const NOVEMBER = { from: "2026-11-01", to: "2026-11-30" }

function shift(overrides: Partial<Shift>): Shift {
  return {
    id: "0193a1b2-1111-7000-8000-000000000001",
    userId: ME,
    firstName: "Eve",
    lastName: "Wiśniewska",
    date: "2026-11-10",
    startedAt: "2026-11-10T15:00:00+00:00",
    endedAt: "2026-11-10T22:30:00+00:00",
    durationMinutes: 450,
    note: null,
    source: "clock",
    correctedBy: null,
    correctedAt: null,
    ...overrides,
  }
}

describe("the month in the search params", () => {
  it("test_it_keeps_a_valid_month", () => {
    expect(validateMyShiftsSearch({ month: "2026-10" })).toEqual({ month: "2026-10" })
  })

  it("test_it_drops_anything_that_is_not_a_month_and_overrides_the_raw_param", () => {
    for (const month of ["2026-13", "2026-00", "2026-1", "2026-10-01", "", 202610, null]) {
      const search = validateMyShiftsSearch({ month })
      expect(Object.keys(search)).toEqual(["month"])
      expect(search.month).toBeUndefined()
    }
  })

  it("test_it_turns_the_month_into_a_whole_month_period_or_falls_back_to_this_month", () => {
    expect(monthPeriodOf("2026-10", NOVEMBER)).toEqual({ from: "2026-10-01", to: "2026-10-31" })
    expect(monthPeriodOf("2028-02", NOVEMBER)).toEqual({ from: "2028-02-01", to: "2028-02-29" })
    expect(monthPeriodOf(undefined, NOVEMBER)).toEqual(NOVEMBER)
  })

  it("test_it_writes_a_period_back_as_its_month", () => {
    expect(monthKey(NOVEMBER)).toBe("2026-11")
    expect(monthKey(monthPeriodOf(monthKey({ from: "2026-01-01", to: "2026-01-31" }), NOVEMBER))).toBe("2026-01")
  })
})

describe("shiftHours", () => {
  it("test_it_shows_the_start_and_end_on_the_warsaw_clock", () => {
    expect(shiftHours(warsaw, shift({}))).toBe("16:00 – 23:30")
  })

  it("test_it_adds_the_weekday_when_a_night_shift_ends_on_the_next_date_in_warsaw", () => {
    // 22:00 Tuesday to 04:00 Wednesday in Warsaw; in New York both are on Tuesday.
    const night = shift({ startedAt: "2026-11-10T21:00:00+00:00", endedAt: "2026-11-11T03:00:00+00:00" })
    expect(shiftHours(warsaw, night)).toBe("22:00 – Wed 04:00")
  })

  it("test_it_keeps_a_shift_on_one_date_in_warsaw_that_crosses_midnight_in_new_york", () => {
    // 05:00 to 07:30 on 11 Nov in Warsaw is 23:00 to 01:30 in New York.
    const early = shift({ date: "2026-11-11", startedAt: "2026-11-11T04:00:00+00:00", endedAt: "2026-11-11T06:30:00+00:00" })
    expect(shiftHours(warsaw, early)).toBe("05:00 – 07:30")
  })

  it("test_it_shows_an_open_shift_as_running", () => {
    expect(shiftHours(warsaw, shift({ endedAt: null, durationMinutes: null }))).toBe("16:00 – now")
  })

  it("test_it_shows_the_hour_after_the_clocks_go_back", () => {
    // 25 Oct 2026: 02:30 CEST (00:30 UTC) to 02:30 CET (01:30 UTC), an hour later on the same wall time.
    const repeated = shift({ date: "2026-10-25", startedAt: "2026-10-25T00:30:00+00:00", endedAt: "2026-10-25T01:30:00+00:00" })
    expect(shiftHours(warsaw, repeated)).toBe("02:30 – 02:30")
  })
})

describe("shiftChange", () => {
  it("test_it_says_nothing_about_a_shift_as_clocked", () => {
    expect(shiftChange(warsaw, shift({}), ME)).toBeNull()
  })

  it("test_it_says_when_a_manager_corrected_a_clocked_shift_in_warsaw_time", () => {
    const corrected = shift({ correctedBy: MANAGER, correctedAt: "2026-11-12T00:00:00+00:00" })
    expect(shiftChange(warsaw, corrected, ME)).toBe("Changed by a manager on 12 Nov 2026, 01:00")
  })

  it("test_it_says_when_a_manager_entered_the_shift", () => {
    const entered = shift({ source: "manager", correctedBy: MANAGER, correctedAt: "2026-11-11T09:15:00+00:00" })
    expect(shiftChange(warsaw, entered, ME)).toBe("Entered by a manager on 11 Nov 2026, 10:15")
  })

  it("test_it_says_you_when_an_owner_changed_their_own_shift", () => {
    const own = shift({ correctedBy: ME, correctedAt: "2026-11-11T09:15:00+00:00" })
    expect(shiftChange(warsaw, own, ME)).toBe("Changed by you on 11 Nov 2026, 10:15")
  })
})

describe("shiftCount", () => {
  it("test_it_counts_shifts_in_words", () => {
    expect(shiftCount(1)).toBe("1 shift")
    expect(shiftCount(12)).toBe("12 shifts")
  })
})
