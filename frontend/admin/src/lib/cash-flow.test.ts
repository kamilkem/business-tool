import { describe, expect, it } from "vitest"

import {
  CASH_MOVEMENTS_MAX_DAYS,
  type CashBalance,
  type CashMovement,
  type MovementFormValues,
  affectedCloses,
  balanceAfter,
  cashFlowHistory,
  cashOnHand,
  closesAfterPeriod,
  countDiscrepancy,
  emptyMovementForm,
  enteredLater,
  formDenominations,
  heldLines,
  movementAmount,
  movementFormTotal,
  movementRequest,
  retiredLines,
  storageChoices,
  validateCashFlowSearch,
} from "./cash-flow"
import type { DayClose } from "./day-closes"
import type { Denomination } from "./denominations"

const ZONE = "Europe/Warsaw"
const NOW = new Date("2026-11-22T12:00:00Z")

function denomination(id: string, value: number, overrides: Partial<Denomination> = {}): Denomination {
  return {
    id,
    currency: "PLN",
    value,
    kind: value >= 1000 ? "note" : "coin",
    active: true,
    used: true,
    createdAt: "2026-11-01T00:00:00Z",
    deactivatedAt: null,
    ...overrides,
  }
}

const DENOMINATIONS = [denomination("d500", 500), denomination("d10000", 10_000), denomination("d2", 2, { active: false })]

function balance(overrides: Partial<CashBalance> = {}): CashBalance {
  return {
    storageId: "safe",
    name: "Sejf",
    mode: "deposit",
    active: true,
    currency: "PLN",
    lines: [
      { denominationId: "d500", currency: "PLN", value: 500, kind: "coin", active: true, pieces: 4, amount: 2000 },
      { denominationId: "d10000", currency: "PLN", value: 10_000, kind: "note", active: true, pieces: 0, amount: 0 },
      { denominationId: "d2", currency: "PLN", value: 2, kind: "coin", active: false, pieces: 3, amount: 6 },
    ],
    total: 2006,
    estimate: false,
    countedAt: null,
    ...overrides,
  }
}

function movement(overrides: Partial<CashMovement> = {}): CashMovement {
  return {
    id: "m1",
    kind: "adjustment",
    occurredAt: "2026-11-20T10:00:00Z",
    storageId: "box",
    storageName: "Kasetka",
    targetStorageId: null,
    targetStorageName: null,
    currency: "PLN",
    lines: [{ denominationId: "d10000", value: 10_000, kind: "note", pieces: 2, amount: 20_000 }],
    total: 20_000,
    discrepancy: null,
    note: null,
    createdBy: "u1",
    createdByFirstName: "Anna",
    createdByLastName: "Nowak",
    createdAt: "2026-11-20T10:00:05Z",
    ...overrides,
  }
}

function close(id: string, date: string, closedAt: string, storages: { id: string; mode: "counted" | "deposit" }[]): DayClose {
  return {
    id,
    date,
    registerId: "r1",
    registerName: "Bar",
    currency: "PLN",
    cashIncome: 0,
    shortage: false,
    storages: storages.map((storage) => ({
      storageId: storage.id,
      name: storage.id,
      mode: storage.mode,
      total: 0,
      expectedOpening: storage.mode === "counted" ? 0 : null,
      cashIncome: 0,
      lines: [],
    })),
    payments: [],
    closedBy: "u2",
    closedByFirstName: "Jan",
    closedByLastName: "Kowalski",
    closedAt,
    updatedBy: null,
    updatedByFirstName: null,
    updatedByLastName: null,
    updatedAt: null,
  }
}

function form(overrides: Partial<MovementFormValues> = {}): MovementFormValues {
  return { ...emptyMovementForm("adjustment", "safe", NOW, ZONE), ...overrides }
}

describe("the URL", () => {
  it("test_it_keeps_a_valid_period_and_storage", () => {
    expect(validateCashFlowSearch({ from: "2026-11-01", to: "2026-11-30", storageId: "safe" })).toEqual({
      from: "2026-11-01",
      to: "2026-11-30",
      storageId: "safe",
    })
  })

  it("test_it_drops_an_unreadable_or_too_long_period_but_keeps_the_storage", () => {
    expect(validateCashFlowSearch({ from: "2026-01-01", to: "2026-12-31", storageId: "safe" })).toEqual({
      from: undefined,
      to: undefined,
      storageId: "safe",
    })
    expect(validateCashFlowSearch({ from: "x", to: 3, storageId: "" })).toEqual({ from: undefined, to: undefined, storageId: undefined })
  })
})

describe("balances", () => {
  it("test_it_lists_the_held_lines_including_a_counted_storages_negative_estimate", () => {
    const lines = balance({
      lines: [
        { denominationId: "a", currency: "PLN", value: 500, kind: "coin", active: true, pieces: 0, amount: 0 },
        { denominationId: "b", currency: "PLN", value: 1000, kind: "note", active: true, pieces: -2, amount: -2000 },
      ],
    })
    expect(heldLines(lines).map((line) => line.denominationId)).toEqual(["b"])
  })

  it("test_it_flags_pieces_of_deactivated_denominations_still_held", () => {
    expect(retiredLines(balance()).map((line) => line.denominationId)).toEqual(["d2"])
    expect(retiredLines(balance({ lines: [] }))).toEqual([])
  })

  it("test_it_adds_up_the_cash_on_hand", () => {
    expect(cashOnHand([balance(), balance({ storageId: "box", total: 15_000 })])).toBe(17_006)
    expect(cashOnHand([])).toBe(0)
  })
})

describe("history", () => {
  const adjustment = movement({ id: "m1", occurredAt: "2026-11-20T10:00:00Z" })
  const transfer = movement({
    id: "m2",
    kind: "transfer",
    occurredAt: "2026-11-21T08:00:00Z",
    storageId: "box",
    targetStorageId: "safe",
    targetStorageName: "Sejf",
  })
  const closeOf20 = close("c1", "2026-11-20", "2026-11-21T01:30:00Z", [
    { id: "box", mode: "counted" },
    { id: "safe", mode: "deposit" },
  ])

  it("test_it_merges_movements_and_closes_newest_first", () => {
    const entries = cashFlowHistory([transfer, adjustment], [closeOf20])
    expect(entries.map((entry) => `${entry.type}:${entry.id}`)).toEqual(["movement:m2", "close:c1", "movement:m1"])
  })

  it("test_it_orders_entries_at_the_same_moment_by_id_newest_first", () => {
    const entries = cashFlowHistory([movement({ id: "a" }), movement({ id: "b" })], [])
    expect(entries.map((entry) => entry.id)).toEqual(["b", "a"])
  })

  it("test_it_keeps_only_what_touched_the_filtered_storage", () => {
    const onSafe = cashFlowHistory([transfer, adjustment], [closeOf20], "safe")
    expect(onSafe.map((entry) => entry.id)).toEqual(["m2", "c1"])
    const closeEntry = onSafe[1]
    expect(closeEntry?.type === "close" && closeEntry.storages.map((storage) => storage.storageId)).toEqual(["safe"])
    expect(cashFlowHistory([], [closeOf20], "elsewhere")).toEqual([])
  })

  it("test_it_signs_a_transfer_as_the_filtered_storage_saw_it", () => {
    expect(movementAmount(transfer, "box")).toBe(-20_000)
    expect(movementAmount(transfer, "safe")).toBe(20_000)
    expect(movementAmount(transfer)).toBe(20_000)
    expect(movementAmount(movement({ total: -500 }), "box")).toBe(-500)
  })

  it("test_it_tells_a_movement_entered_later_than_it_happened", () => {
    expect(enteredLater(movement())).toBe(false)
    expect(enteredLater(movement({ createdAt: "2026-11-21T09:00:00Z" }))).toBe(true)
  })
})

describe("deleting a movement", () => {
  const closes = [
    close("c19", "2026-11-19", "2026-11-20T01:00:00Z", [{ id: "box", mode: "counted" }]),
    close("c21", "2026-11-21", "2026-11-22T01:00:00Z", [
      { id: "box", mode: "counted" },
      { id: "safe", mode: "deposit" },
    ]),
    close("c20", "2026-11-20", "2026-11-21T01:00:00Z", [
      { id: "box", mode: "counted" },
      { id: "safe", mode: "deposit" },
    ]),
  ]

  it("test_it_finds_the_first_close_after_the_movement_that_counted_its_storage", () => {
    expect(affectedCloses(movement({ storageId: "box" }), closes).map((c) => c.id)).toEqual(["c20"])
  })

  it("test_it_changes_no_close_for_a_deposit_storage_or_after_the_last_close", () => {
    expect(affectedCloses(movement({ storageId: "safe" }), closes)).toEqual([])
    expect(affectedCloses(movement({ occurredAt: "2026-11-22T10:00:00Z" }), closes)).toEqual([])
  })

  it("test_it_finds_one_close_per_counted_storage_a_transfer_touches", () => {
    const later = close("c22", "2026-11-22", "2026-11-23T01:00:00Z", [{ id: "drawer", mode: "counted" }])
    const transfer = movement({ kind: "transfer", storageId: "box", targetStorageId: "drawer" })
    expect(affectedCloses(transfer, [...closes, later]).map((c) => c.id)).toEqual(["c20", "c22"])
  })

  it("test_it_looks_for_closes_from_the_day_before_up_to_today", () => {
    expect(closesAfterPeriod(movement({ occurredAt: "2026-11-20T10:00:00Z" }), ZONE, "2026-11-22")).toEqual({
      from: "2026-11-19",
      to: "2026-11-22",
    })
    const old = closesAfterPeriod(movement({ occurredAt: "2026-01-10T10:00:00Z" }), ZONE, "2026-11-22")
    expect(old.from).toBe("2026-01-09")
    expect(old.to).toBe("2026-04-10")
    expect(CASH_MOVEMENTS_MAX_DAYS).toBe(92)
  })
})

describe("the form's denominations", () => {
  it("test_it_offers_the_active_denominations_with_what_the_storage_holds", () => {
    const offered = formDenominations({ kind: "adjustment", direction: "add" }, DENOMINATIONS, balance())
    expect(offered.map((d) => [d.id, d.held])).toEqual([
      ["d500", 4],
      ["d10000", 0],
    ])
  })

  it("test_it_offers_deactivated_denominations_held_only_for_taking_cash_out", () => {
    const offered = formDenominations({ kind: "adjustment", direction: "remove" }, DENOMINATIONS, balance())
    expect(offered.map((d) => [d.id, d.active])).toEqual([
      ["d2", false],
      ["d500", true],
      ["d10000", true],
    ])
    expect(formDenominations({ kind: "transfer", direction: "remove" }, DENOMINATIONS, balance()).map((d) => d.id)).toEqual([
      "d500",
      "d10000",
    ])
  })

  it("test_it_knows_no_holdings_without_a_balance", () => {
    expect(formDenominations({ kind: "count", direction: "add" }, DENOMINATIONS, undefined).map((d) => d.held)).toEqual([null, null])
  })
})

describe("the form's totals", () => {
  const offered = formDenominations({ kind: "adjustment", direction: "remove" }, DENOMINATIONS, balance())

  it("test_it_adds_up_the_pieces_typed_and_ignores_unreadable_ones", () => {
    expect(movementFormTotal(form({ pieces: { d500: 3, d10000: 1, d2: null } }), offered)).toBe(11_500)
  })

  it("test_it_signs_the_total_of_cash_taken_out", () => {
    expect(movementFormTotal(form({ direction: "remove", pieces: { d500: 2, d2: 3 } }), offered)).toBe(-1006)
  })

  it("test_it_shows_the_balance_after_an_adjustment_or_a_transfer", () => {
    expect(balanceAfter(form({ direction: "remove", pieces: { d500: 2 } }), offered, balance())).toBe(1006)
    expect(balanceAfter(form({ kind: "transfer", pieces: { d500: 4 } }), offered, balance())).toBe(6)
    expect(balanceAfter(form({ kind: "count", pieces: { d500: 1 } }), offered, balance())).toBe(500)
    expect(balanceAfter(form({ earlier: true, pieces: { d500: 1 } }), offered, balance())).toBeNull()
  })

  it("test_it_shows_an_audit_counts_discrepancy_against_the_balance_now", () => {
    const counted = formDenominations({ kind: "count", direction: "add" }, DENOMINATIONS, balance())
    expect(countDiscrepancy(form({ kind: "count", pieces: { d500: 3 } }), counted, balance())).toBe(1500 - 2006)
    expect(countDiscrepancy(form({ kind: "count", pieces: { d10000: 1 } }), counted, balance({ total: 10_000 }))).toBe(0)
    expect(countDiscrepancy(form({ kind: "count", earlier: true }), counted, balance())).toBeNull()
    expect(countDiscrepancy(form({ kind: "adjustment" }), counted, balance())).toBeNull()
  })
})

describe("the form's request", () => {
  const offered = formDenominations({ kind: "adjustment", direction: "add" }, DENOMINATIONS, balance())

  it("test_it_sends_an_adjustments_non_zero_lines_signed_with_a_trimmed_note", () => {
    const result = movementRequest(form({ direction: "remove", pieces: { d500: 2, d10000: 0 }, note: "  bank run " }), offered, ZONE, NOW)
    expect(result).toEqual({
      ok: true,
      request: { kind: "adjustment", body: { storageId: "safe", lines: [{ denominationId: "d500", pieces: -2 }], note: "bank run" } },
    })
  })

  it("test_it_sends_a_transfer_between_two_storages", () => {
    const result = movementRequest(form({ kind: "transfer", targetStorageId: "box", pieces: { d10000: 1 } }), offered, ZONE, NOW)
    expect(result).toEqual({
      ok: true,
      request: {
        kind: "transfer",
        body: { fromStorageId: "safe", toStorageId: "box", lines: [{ denominationId: "d10000", pieces: 1 }], note: null },
      },
    })
  })

  it("test_it_sends_every_denomination_of_a_count_zeros_included", () => {
    const result = movementRequest(form({ kind: "count", pieces: { d500: 4 } }), offered, ZONE, NOW)
    expect(result.ok && result.request.body.lines).toEqual([
      { denominationId: "d500", pieces: 4 },
      { denominationId: "d10000", pieces: 0 },
    ])
  })

  it("test_it_sends_an_earlier_moment_with_the_organizations_offset", () => {
    const result = movementRequest(
      form({ pieces: { d500: 1 }, earlier: true, moment: { date: "2026-11-21", time: "23:15", occurrence: "first" } }),
      offered,
      ZONE,
      NOW,
    )
    expect(result.ok && result.request.body.occurredAt).toBe("2026-11-21T23:15:00+01:00")
  })

  it("test_it_picks_the_second_of_a_repeated_time", () => {
    const result = movementRequest(
      form({ pieces: { d500: 1 }, earlier: true, moment: { date: "2026-10-25", time: "02:30", occurrence: "second" } }),
      offered,
      ZONE,
      NOW,
    )
    expect(result.ok && result.request.body.occurredAt).toBe("2026-10-25T02:30:00+01:00")
  })

  it("test_it_refuses_what_the_api_would", () => {
    const problems = (values: Partial<MovementFormValues>) => {
      const result = movementRequest(form(values), offered, ZONE, NOW)
      return result.ok ? [] : result.problems
    }
    expect(problems({ storageId: "", pieces: { d500: 1 } })).toEqual(["storage"])
    expect(problems({ pieces: {} })).toEqual(["nothing"])
    expect(problems({ pieces: { d500: null } })).toEqual(["pieces"])
    expect(problems({ kind: "transfer", pieces: { d500: 1 } })).toEqual(["target"])
    expect(problems({ kind: "transfer", targetStorageId: "safe", pieces: { d500: 1 } })).toEqual(["sameStorage"])
    expect(problems({ pieces: { d500: 1 }, note: "x".repeat(256) })).toEqual(["note"])
    expect(problems({ pieces: { d500: 1 }, earlier: true, moment: { date: "", time: "10:00", occurrence: "first" } })).toEqual([
      "momentInvalid",
    ])
    expect(
      problems({ pieces: { d500: 1 }, earlier: true, moment: { date: "2026-03-29", time: "02:30", occurrence: "first" } }),
    ).toEqual(["momentSkipped"])
    expect(
      problems({ pieces: { d500: 1 }, earlier: true, moment: { date: "2026-11-22", time: "14:00", occurrence: "first" } }),
    ).toEqual(["momentInFuture"])
  })

  it("test_it_accepts_an_empty_count", () => {
    expect(movementRequest(form({ kind: "count" }), offered, ZONE, NOW).ok).toBe(true)
  })
})

describe("the form's storages", () => {
  const storages = [
    balance({ storageId: "box", mode: "counted" }),
    balance({ storageId: "safe" }),
    balance({ storageId: "old", active: false }),
  ]

  it("test_it_audits_active_deposit_storages_only", () => {
    expect(storageChoices("count", storages).sources.map((s) => s.storageId)).toEqual(["safe"])
  })

  it("test_it_transfers_from_any_storage_into_an_active_one", () => {
    const { sources, targets } = storageChoices("transfer", storages)
    expect(sources.map((s) => s.storageId)).toEqual(["box", "safe", "old"])
    expect(targets.map((s) => s.storageId)).toEqual(["box", "safe"])
  })
})
