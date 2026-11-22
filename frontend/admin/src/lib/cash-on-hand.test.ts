import { describe, expect, it } from "vitest"

import type { CashBalance } from "./cash-flow"
import { cashOnHandOf, lastCloseDay, lastClosePeriod } from "./cash-on-hand"
import { DAY_CLOSES_MAX_DAYS, type DayClose } from "./day-closes"

function balance(overrides: Partial<CashBalance> = {}): CashBalance {
  return {
    storageId: "safe",
    name: "Sejf",
    mode: "deposit",
    active: true,
    currency: "PLN",
    lines: [],
    total: 50_000,
    estimate: false,
    countedAt: null,
    ...overrides,
  }
}

function close(id: string, date: string, overrides: Partial<DayClose> = {}): DayClose {
  return {
    id,
    date,
    registerId: "r1",
    registerName: "Bar",
    currency: "PLN",
    cashIncome: 10_000,
    shortage: false,
    storages: [],
    payments: [],
    closedBy: "u1",
    closedByFirstName: "Anna",
    closedByLastName: "Nowak",
    closedAt: `${date}T22:00:00Z`,
    updatedBy: null,
    updatedByFirstName: null,
    updatedByLastName: null,
    updatedAt: null,
    ...overrides,
  }
}

describe("cash on hand", () => {
  it("test_it_adds_up_every_storage_and_keeps_their_order", () => {
    const safe = balance()
    const box = balance({ storageId: "box", name: "Kasetka", mode: "counted", total: 12_345 })
    const old = balance({ storageId: "old", name: "Stara", active: false, total: 500 })

    const onHand = cashOnHandOf([safe, box, old])

    expect(onHand.total).toBe(62_845)
    expect(onHand.storages.map((storage) => storage.storageId)).toEqual(["safe", "box", "old"])
  })

  it("test_it_is_an_estimate_when_a_counted_storage_is_estimated", () => {
    expect(cashOnHandOf([balance(), balance({ storageId: "box", mode: "counted", estimate: true })]).estimate).toBe(true)
    expect(cashOnHandOf([balance(), balance({ storageId: "box", mode: "counted" })]).estimate).toBe(false)
  })

  it("test_it_is_zero_without_storages", () => {
    expect(cashOnHandOf([])).toEqual({ total: 0, estimate: false, storages: [] })
  })

  it("test_it_includes_a_negative_estimate_in_the_total", () => {
    expect(cashOnHandOf([balance(), balance({ storageId: "box", total: -2000, estimate: true })]).total).toBe(48_000)
  })
})

describe("the last close", () => {
  it("test_it_looks_as_far_back_as_the_api_lists_closes", () => {
    expect(lastClosePeriod("2026-11-23")).toEqual({ from: "2026-08-24", to: "2026-11-23" })
    expect(DAY_CLOSES_MAX_DAYS).toBe(92)
  })

  it("test_it_picks_the_latest_date_whatever_the_order", () => {
    const day = lastCloseDay([close("c1", "2026-11-20"), close("c3", "2026-11-22"), close("c2", "2026-11-21")])

    expect(day?.date).toBe("2026-11-22")
    expect(day?.closes.map((c) => c.id)).toEqual(["c3"])
    expect(day?.shortage).toBe(false)
  })

  it("test_it_keeps_every_register_that_closed_the_latest_date", () => {
    const day = lastCloseDay([
      close("c1", "2026-11-22", { registerId: "r1" }),
      close("c2", "2026-11-22", { registerId: "r2" }),
      close("c0", "2026-11-21"),
    ])

    expect(day?.closes.map((c) => c.id)).toEqual(["c1", "c2"])
  })

  it("test_it_flags_a_shortage_on_any_close_of_the_latest_date", () => {
    const day = lastCloseDay([
      close("c1", "2026-11-22"),
      close("c2", "2026-11-22", { registerId: "r2", cashIncome: -500, shortage: true }),
    ])

    expect(day?.shortage).toBe(true)
  })

  it("test_it_ignores_a_shortage_of_an_earlier_date", () => {
    const day = lastCloseDay([close("c1", "2026-11-21", { cashIncome: -500, shortage: true }), close("c2", "2026-11-22")])

    expect(day?.shortage).toBe(false)
  })

  it("test_it_is_null_without_a_close", () => {
    expect(lastCloseDay([])).toBeNull()
  })
})
