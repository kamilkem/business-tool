import { describe, expect, it } from "vitest"

import { periodOf, validateShiftsSearch } from "./shifts"

const ANNA = "0193a1b2-0000-7000-8000-000000000001"

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
