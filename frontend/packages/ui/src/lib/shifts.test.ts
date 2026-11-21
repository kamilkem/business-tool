import { describe, expect, it } from "vitest"

import { canBeDeleted, grandTotal, type Shift, totalsPerEmployee } from "./shifts"

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
