import { describe, expect, it } from "vitest"

import { canBeDeleted, grandTotal, hoursSince, isLongerThanMax, type Shift, totalsPerEmployee } from "./shifts"

const ANNA = "0193a1b2-0000-7000-8000-000000000001"
const BOB = "0193a1b2-0000-7000-8000-000000000002"
const CLARA = "0193a1b2-0000-7000-8000-000000000003"

let sequence = 0
function shift(userId: string, firstName: string, lastName: string, durationMinutes: number | null): Shift {
  sequence += 1
  return {
    id: `0193a1b2-1111-7000-8000-${String(sequence).padStart(12, "0")}`,
    userId,
    firstName,
    lastName,
    date: "2026-11-10",
    startedAt: "2026-11-10T16:00:00+00:00",
    endedAt: durationMinutes === null ? null : "2026-11-10T23:00:00+00:00",
    durationMinutes,
    note: null,
    source: "clock",
    correctedBy: null,
    correctedAt: null,
  }
}

describe("totalsPerEmployee", () => {
  it("test_it_sums_the_closed_shifts_of_each_person", () => {
    const totals = totalsPerEmployee([
      shift(ANNA, "Anna", "Nowak", 420),
      shift(BOB, "Bob", "Bartender", 300),
      shift(ANNA, "Anna", "Nowak", 135),
    ])

    expect(totals).toEqual([
      { userId: BOB, firstName: "Bob", lastName: "Bartender", shifts: 1, openShifts: 0, workedMinutes: 300 },
      { userId: ANNA, firstName: "Anna", lastName: "Nowak", shifts: 2, openShifts: 0, workedMinutes: 555 },
    ])
  })

  it("test_it_counts_an_open_shift_without_adding_time", () => {
    const [anna] = totalsPerEmployee([shift(ANNA, "Anna", "Nowak", 480), shift(ANNA, "Anna", "Nowak", null)])

    expect(anna).toMatchObject({ shifts: 2, openShifts: 1, workedMinutes: 480 })
  })

  it("test_it_lists_someone_whose_only_shift_is_open", () => {
    expect(totalsPerEmployee([shift(CLARA, "Clara", "Kowalska", null)])).toEqual([
      { userId: CLARA, firstName: "Clara", lastName: "Kowalska", shifts: 1, openShifts: 1, workedMinutes: 0 },
    ])
  })

  it("test_it_sorts_by_last_name_then_first_name_and_keeps_namesakes_apart", () => {
    const totals = totalsPerEmployee([
      shift(CLARA, "Ewa", "Nowak", 60),
      shift(BOB, "Adam", "Nowak", 60),
      shift(ANNA, "Ewa", "Nowak", 30),
    ])

    expect(totals.map((t) => [t.firstName, t.userId, t.workedMinutes])).toEqual([
      ["Adam", BOB, 60],
      ["Ewa", ANNA, 30],
      ["Ewa", CLARA, 60],
    ])
  })

  it("test_it_returns_nothing_for_a_period_without_shifts", () => {
    expect(totalsPerEmployee([])).toEqual([])
    expect(grandTotal([])).toEqual({ shifts: 0, openShifts: 0, workedMinutes: 0 })
  })

  it("test_it_adds_up_everyone_for_the_footer", () => {
    const totals = totalsPerEmployee([
      shift(ANNA, "Anna", "Nowak", 420),
      shift(BOB, "Bob", "Bartender", 300),
      shift(BOB, "Bob", "Bartender", null),
    ])

    expect(grandTotal(totals)).toEqual({ shifts: 3, openShifts: 1, workedMinutes: 720 })
  })
})

describe("canBeDeleted", () => {
  it("test_it_allows_deleting_an_ended_shift", () => {
    expect(canBeDeleted(shift(ANNA, "Anna", "Nowak", 420))).toBe(true)
  })

  it("test_it_does_not_offer_to_delete_a_running_shift", () => {
    expect(canBeDeleted(shift(ANNA, "Anna", "Nowak", null))).toBe(false)
  })
})

describe("isLongerThanMax", () => {
  const start = "2026-11-22T18:00:00+00:00"

  it("test_it_flags_an_open_shift_only_once_it_runs_past_the_limit", () => {
    const open = { startedAt: start, endedAt: null }

    expect(isLongerThanMax(open, 12, new Date("2026-11-23T06:00:00Z"))).toBe(false)
    expect(isLongerThanMax(open, 12, new Date("2026-11-23T06:00:01Z"))).toBe(true)
  })

  it("test_it_flags_a_finished_shift_by_its_own_end_whatever_the_time_now", () => {
    const later = new Date("2026-11-30T12:00:00Z")

    expect(isLongerThanMax({ startedAt: start, endedAt: "2026-11-23T06:00:00+00:00" }, 12, later)).toBe(false)
    expect(isLongerThanMax({ startedAt: start, endedAt: "2026-11-23T07:30:00+00:00" }, 12, later)).toBe(true)
    expect(isLongerThanMax({ startedAt: start, endedAt: "2026-11-23T07:30:00+00:00" }, 14, later)).toBe(false)
  })

  it("test_it_compares_instants_whatever_the_offset", () => {
    const warsaw = { startedAt: "2026-11-22T19:00:00+01:00", endedAt: "2026-11-23T08:00:00+01:00" }

    expect(isLongerThanMax(warsaw, 12, new Date())).toBe(true)
    expect(isLongerThanMax(warsaw, 13, new Date())).toBe(false)
  })
})

describe("hoursSince", () => {
  it("test_it_counts_whole_hours_from_the_start", () => {
    expect(hoursSince("2026-11-22T18:00:00+00:00", new Date("2026-11-23T07:59:59Z"))).toBe(13)
    expect(hoursSince("2026-11-22T18:00:00+00:00", new Date("2026-11-23T08:00:00Z"))).toBe(14)
  })

  it("test_it_never_goes_below_zero", () => {
    expect(hoursSince("2026-11-22T18:00:00+00:00", new Date("2026-11-22T17:00:00Z"))).toBe(0)
  })
})
