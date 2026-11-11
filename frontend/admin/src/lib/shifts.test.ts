import { describe, expect, it } from "vitest"

import { grandTotal, periodOf, type Shift, totalsPerEmployee, validateShiftsSearch } from "./shifts"

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

describe("validateShiftsSearch", () => {
  it("test_it_keeps_a_valid_period_and_person", () => {
    expect(validateShiftsSearch({ from: "2026-11-01", to: "2026-11-30", userId: ANNA })).toEqual({
      from: "2026-11-01",
      to: "2026-11-30",
      userId: ANNA,
    })
  })

  it("test_it_drops_a_period_the_api_would_refuse", () => {
    expect(validateShiftsSearch({ from: "2026-11-01" })).toEqual({})
    expect(validateShiftsSearch({ from: "2026-02-30", to: "2026-03-01" })).toEqual({})
    expect(validateShiftsSearch({ from: "2026-11-30", to: "2026-11-01" })).toEqual({})
    expect(validateShiftsSearch({ from: "2026-01-01", to: "2026-04-03" })).toEqual({})
    expect(validateShiftsSearch({ from: 20261101, to: "2026-11-30", userId: "" })).toEqual({})
  })

  it("test_it_overrides_the_raw_params_it_drops", () => {
    const search = validateShiftsSearch({ from: "2026-01-01", to: "2026-12-31", userId: "" })

    expect(Object.keys(search).sort()).toEqual(["from", "to", "userId"])
    expect({ from: "2026-01-01", to: "2026-12-31", ...search }).toEqual({ from: undefined, to: undefined, userId: undefined })
  })

  it("test_it_accepts_a_period_of_exactly_92_days", () => {
    expect(validateShiftsSearch({ from: "2026-01-01", to: "2026-04-02" })).toEqual({ from: "2026-01-01", to: "2026-04-02" })
    expect(validateShiftsSearch({ from: "2026-01-01", to: "2026-04-03" })).toEqual({})
  })

  it("test_it_falls_back_to_the_given_period_without_one_in_the_search", () => {
    const november = { from: "2026-11-01", to: "2026-11-30" }
    expect(periodOf({}, november)).toEqual(november)
    expect(periodOf({ from: "2026-10-05", to: "2026-10-11" }, november)).toEqual({ from: "2026-10-05", to: "2026-10-11" })
  })
})
