import { describe, expect, it } from "vitest"

import {
  type DayCloseForm,
  MAX_PIECES,
  asksForDay,
  canLeaveStep,
  dateChoices,
  initialValues,
  nonCashTotal,
  parsePieces,
  piecesTotal,
  recordDayCloseBody,
  stepPieces,
  stepsOf,
  storageTotals,
  withAmount,
  withPieces,
} from "./day-close"

const BOX = { id: "box", name: "Kasetka", mode: "counted" } as const
const SAFE = { id: "safe", name: "Sejf", mode: "deposit" } as const
const COIN_50GR = { id: "d50gr", value: 50, kind: "coin" } as const
const COIN_5 = { id: "d5", value: 500, kind: "coin" } as const
const NOTE_100 = { id: "d100", value: 10_000, kind: "note" } as const

function form(overrides: Partial<DayCloseForm> = {}): DayCloseForm {
  return {
    currency: "PLN",
    canClose: true,
    proposedDate: "2026-11-21",
    today: "2026-11-22",
    mode: "day_end_time",
    dayEndsAt: "05:00",
    storages: [BOX, SAFE],
    denominations: [COIN_50GR, COIN_5, NOTE_100],
    registers: [{ id: "bar", name: "Bar" }],
    preselectedRegisterId: "bar",
    paymentMethods: [
      { id: "card", name: "Card" },
      { id: "blik", name: "BLIK" },
    ],
    proposedDateClosed: null,
    ...overrides,
  }
}

describe("piecesTotal", () => {
  it("test_it_adds_up_pieces_times_face_values_in_minor_units", () => {
    expect(piecesTotal(form().denominations, { d50gr: 3, d5: 2, d100: 4 })).toBe(3 * 50 + 2 * 500 + 4 * 10_000)
  })

  it("test_it_is_zero_with_nothing_entered", () => {
    expect(piecesTotal(form().denominations, undefined)).toBe(0)
    expect(piecesTotal(form().denominations, {})).toBe(0)
  })

  it("test_it_ignores_pieces_of_a_denomination_no_longer_offered", () => {
    expect(piecesTotal([COIN_5], { d5: 1, gone: 99 })).toBe(500)
  })
})

describe("storageTotals and nonCashTotal", () => {
  it("test_it_totals_each_storage_in_the_forms_order", () => {
    let values = initialValues(form())
    values = withPieces(values, "safe", "d100", 5)
    values = withPieces(values, "box", "d5", 3)
    values = withPieces(values, "box", "d50gr", 1)

    expect(storageTotals(form(), values)).toEqual([
      { storage: BOX, total: 1_550 },
      { storage: SAFE, total: 50_000 },
    ])
  })

  it("test_it_counts_an_empty_non_cash_field_as_zero", () => {
    const values = withAmount(initialValues(form()), "card", 123_45, false)
    expect(nonCashTotal(form(), values)).toBe(123_45)
  })
})

describe("dateChoices", () => {
  it("test_it_offers_only_the_proposed_date_in_day_end_time_mode", () => {
    expect(dateChoices(form())).toEqual(["2026-11-21"])
  })

  it("test_it_offers_today_and_yesterday_when_the_employee_picks", () => {
    expect(dateChoices(form({ mode: "employee_picks", today: "2026-12-01" }))).toEqual(["2026-12-01", "2026-11-30"])
  })
})

describe("stepsOf", () => {
  it("test_it_asks_for_deposits_first_then_counts_then_non_cash_then_the_summary", () => {
    const counted2 = { id: "box2", name: "Kasetka 2", mode: "counted" } as const
    expect(stepsOf(form({ storages: [BOX, counted2, SAFE] })).map((step) => (step.kind === "storage" ? step.storage.id : step.kind))).toEqual([
      "safe",
      "box",
      "box2",
      "payments",
      "summary",
    ])
  })

  it("test_it_asks_for_the_day_when_the_employee_picks_or_there_are_several_registers", () => {
    expect(asksForDay(form())).toBe(false)
    expect(stepsOf(form({ mode: "employee_picks" }))[0]).toEqual({ kind: "day" })
    expect(stepsOf(form({ registers: [{ id: "bar", name: "Bar" }, { id: "terrace", name: "Terrace" }] }))[0]).toEqual({ kind: "day" })
  })

  it("test_it_skips_the_non_cash_step_without_non_cash_methods", () => {
    expect(stepsOf(form({ paymentMethods: [] })).map((step) => step.kind)).not.toContain("payments")
  })
})

describe("initialValues and canLeaveStep", () => {
  it("test_it_preselects_the_only_register_and_the_proposed_date", () => {
    const values = initialValues(form({ preselectedRegisterId: null }))
    expect(values.registerId).toBe("bar")
    expect(values.date).toBe("2026-11-21")
  })

  it("test_it_needs_a_register_chosen_among_several", () => {
    const several = form({ registers: [{ id: "bar", name: "Bar" }, { id: "terrace", name: "Terrace" }], preselectedRegisterId: null })
    const values = initialValues(several)
    expect(values.registerId).toBeNull()
    expect(canLeaveStep({ kind: "day" }, several, values)).toBe(false)
    expect(canLeaveStep({ kind: "day" }, several, { ...values, registerId: "terrace" })).toBe(true)
  })

  it("test_it_holds_the_non_cash_step_while_a_field_is_not_an_amount_or_too_large", () => {
    const values = initialValues(form())
    expect(canLeaveStep({ kind: "payments" }, form(), values)).toBe(true)
    expect(canLeaveStep({ kind: "payments" }, form(), withAmount(values, "card", null, true))).toBe(false)
    expect(canLeaveStep({ kind: "payments" }, form(), withAmount(values, "card", 2_147_483_648, false))).toBe(false)
    expect(canLeaveStep({ kind: "payments" }, form(), withAmount(withAmount(values, "card", null, true), "card", 100, false))).toBe(true)
  })
})

describe("withPieces", () => {
  it("test_it_holds_the_storage_step_while_a_number_of_pieces_is_unreadable", () => {
    const invalid = withPieces(initialValues(form()), "box", "d5", null)
    expect(piecesTotal(form().denominations, invalid.pieces.box)).toBe(0)
    expect(canLeaveStep({ kind: "storage", storage: BOX }, form(), invalid)).toBe(false)
    expect(canLeaveStep({ kind: "storage", storage: SAFE }, form(), invalid)).toBe(true)
    expect(canLeaveStep({ kind: "storage", storage: BOX }, form(), withPieces(invalid, "box", "d5", 4))).toBe(true)
  })
})

describe("parsePieces and stepPieces", () => {
  it("test_it_reads_whole_numbers_and_an_empty_field_as_zero", () => {
    expect(parsePieces("12")).toBe(12)
    expect(parsePieces(" ")).toBe(0)
    expect(parsePieces("1.5")).toBeNull()
    expect(parsePieces("-1")).toBeNull()
    expect(parsePieces(String(MAX_PIECES + 1))).toBeNull()
  })

  it("test_it_steps_within_zero_and_the_maximum", () => {
    expect(stepPieces(0, -1)).toBe(0)
    expect(stepPieces(2, 1)).toBe(3)
    expect(stepPieces(MAX_PIECES, 1)).toBe(MAX_PIECES)
  })
})

describe("recordDayCloseBody", () => {
  it("test_it_sends_every_denomination_of_a_counted_storage_and_only_the_deposited_ones", () => {
    let values = initialValues(form())
    values = withPieces(values, "box", "d5", 3)
    values = withPieces(values, "safe", "d100", 2)
    values = withPieces(values, "safe", "d5", 0)
    values = withAmount(values, "card", 45_000, false)

    expect(recordDayCloseBody(form(), values)).toEqual({
      registerId: "bar",
      date: "2026-11-21",
      storages: [
        {
          storageId: "box",
          lines: [
            { denominationId: "d50gr", pieces: 0 },
            { denominationId: "d5", pieces: 3 },
            { denominationId: "d100", pieces: 0 },
          ],
        },
        { storageId: "safe", lines: [{ denominationId: "d100", pieces: 2 }] },
      ],
      payments: [
        { paymentMethodId: "card", amount: 45_000 },
        { paymentMethodId: "blik", amount: 0 },
      ],
    })
  })

  it("test_it_sends_the_picked_date", () => {
    const picks = form({ mode: "employee_picks" })
    expect(recordDayCloseBody(picks, { ...initialValues(picks), date: "2026-11-22" }).date).toBe("2026-11-22")
  })
})
