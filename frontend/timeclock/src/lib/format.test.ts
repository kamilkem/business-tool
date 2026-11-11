import { createDateTimeFormat } from "@business-tool/ui/lib/date-time"
import { describe, expect, it } from "vitest"

import { elapsedMs, formatClockTime, formatElapsed, formatWorked } from "./format"

// The tests run with TZ=America/New_York (vitest.config.ts); the bar is in Warsaw.
const warsaw = createDateTimeFormat("Europe/Warsaw", "en-GB")

describe("formatClockTime", () => {
  it("test_it_shows_only_the_time_for_a_shift_that_started_today_in_warsaw", () => {
    const now = new Date("2026-11-11T22:30:00Z") // 23:30 in Warsaw
    expect(formatClockTime(warsaw, "2026-11-11T21:10:00Z", now)).toBe("22:10")
  })

  it("test_it_adds_the_weekday_when_the_shift_started_yesterday_in_warsaw_but_today_in_the_browser", () => {
    // 00:30 on Thursday in Warsaw; still Wednesday evening in New York.
    const now = new Date("2026-11-11T23:30:00Z")
    expect(formatClockTime(warsaw, "2026-11-11T22:10:00Z", now)).toBe("Wed 23:10")
  })

  it("test_it_shows_the_time_after_the_clock_change_in_warsaw", () => {
    const now = new Date("2026-10-25T05:00:00Z") // 06:00 CET
    expect(formatClockTime(warsaw, now.toISOString(), now)).toBe("06:00")
    expect(formatClockTime(warsaw, "2026-10-24T20:00:00Z", now)).toBe("Sat 22:00")
  })
})

describe("durations on the clock screen", () => {
  it("test_it_formats_worked_time", () => {
    expect(formatWorked(59_000)).toBe("less than a minute")
    expect(formatWorked(elapsedMs("2026-11-11T08:00:00Z", "2026-11-11T15:30:00Z"))).toBe("7 h 30 min")
    expect(formatWorked(elapsedMs("2026-10-24T20:00:00Z", "2026-10-25T05:00:00Z"))).toBe("9 h")
  })

  it("test_it_formats_the_running_timer", () => {
    expect(formatElapsed(2 * 3_600_000 + 5 * 60_000 + 9_000)).toBe("2:05:09")
    expect(formatElapsed(elapsedMs("2026-11-11T08:00:00Z", new Date("2026-11-11T07:59:00Z")))).toBe("0:00:00")
  })
})
