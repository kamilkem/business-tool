import { describe, expect, it } from "vitest"

import { longShiftHours } from "./long-shift"
import type { Shift } from "./queries"

function shift(startedAt: string, endedAt: string | null = null): Shift {
  return {
    id: "0193a1b2-1111-7000-8000-000000000001",
    userId: "0193a1b2-0000-7000-8000-000000000001",
    startedAt,
    endedAt,
    note: null,
    source: "clock",
    correctedBy: null,
    correctedAt: null,
  }
}

describe("longShiftHours", () => {
  const start = "2026-11-22T18:00:00+00:00"

  it("test_it_gives_the_hours_so_far_once_the_open_shift_is_past_the_limit", () => {
    expect(longShiftHours(shift(start), 12, new Date("2026-11-23T06:00:00Z"))).toBeNull()
    expect(longShiftHours(shift(start), 12, new Date("2026-11-23T06:00:01Z"))).toBe(12)
    expect(longShiftHours(shift(start), 12, new Date("2026-11-23T09:59:00Z"))).toBe(15)
    expect(longShiftHours(shift(start), 16, new Date("2026-11-23T09:59:00Z"))).toBeNull()
  })

  it("test_it_uses_the_default_limit_until_the_settings_load", () => {
    expect(longShiftHours(shift(start), undefined, new Date("2026-11-23T05:00:00Z"))).toBeNull()
    expect(longShiftHours(shift(start), undefined, new Date("2026-11-23T07:00:00Z"))).toBe(13)
  })

  it("test_it_says_nothing_without_an_open_shift", () => {
    const later = new Date("2026-11-24T12:00:00Z")

    expect(longShiftHours(null, 12, later)).toBeNull()
    expect(longShiftHours(undefined, 12, later)).toBeNull()
    expect(longShiftHours(shift(start, "2026-11-23T09:00:00+00:00"), 12, later)).toBeNull()
  })
})
