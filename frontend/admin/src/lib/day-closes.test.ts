import { describe, expect, it } from "vitest"

import {
  DAY_CLOSES_MAX_DAYS,
  type DayClose,
  type DayCloseForm,
  bookedCashOf,
  cashIncomeOf,
  correctDayCloseBody,
  correctionEditor,
  dayClosesTable,
  nonCashTotal,
  nonCashTotalOf,
  problemsOf,
  recordDayCloseBody,
  recordEditor,
  storageCashIncome,
  validateDayClosesSearch,
  valuesOfClose,
  valuesOfForm,
  withAmount,
  withPieces,
} from "./day-closes"

const COIN_5 = { id: "d5", value: 500, kind: "coin" } as const
const NOTE_100 = { id: "d100", value: 10_000, kind: "note" } as const
const NOTE_200 = { id: "d200", value: 20_000, kind: "note" } as const

function form(overrides: Partial<DayCloseForm> = {}): DayCloseForm {
  return {
    currency: "PLN",
    canClose: true,
    proposedDate: "2026-11-21",
    today: "2026-11-22",
    mode: "day_end_time",
    dayEndsAt: "05:00",
    storages: [
      { id: "box", name: "Kasetka", mode: "counted" },
      { id: "safe", name: "Sejf", mode: "deposit" },
    ],
    denominations: [NOTE_100, COIN_5],
    registers: [{ id: "bar", name: "Bar" }],
    preselectedRegisterId: "bar",
    paymentMethods: [{ id: "card", name: "Card" }],
    proposedDateClosed: null,
    ...overrides,
  }
}

const line = (denomination: { id: string; value: number; kind: "coin" | "note" }, pieces: number) => ({
  denominationId: denomination.id,
  value: denomination.value,
  kind: denomination.kind,
  pieces,
  amount: denomination.value * pieces,
})

/** Kasetka counted 600 zł against an expected 500 zł, 100 zł into Sejf: a cash income of 200 zł. */
function close(overrides: Partial<DayClose> = {}): DayClose {
  return {
    id: "close-1",
    date: "2026-11-20",
    registerId: "bar",
    registerName: "Bar",
    currency: "PLN",
    cashIncome: 20_000,
    shortage: false,
    storages: [
      {
        storageId: "box",
        name: "Kasetka",
        mode: "counted",
        total: 60_000,
        expectedOpening: 50_000,
        cashIncome: 10_000,
        lines: [line(COIN_5, 0), line(NOTE_100, 6)],
      },
      {
        storageId: "safe",
        name: "Sejf",
        mode: "deposit",
        total: 10_000,
        expectedOpening: null,
        cashIncome: 10_000,
        lines: [line(NOTE_100, 1)],
      },
    ],
    payments: [{ paymentMethodId: "card", name: "Card", amount: 45_000 }],
    closedBy: "anna",
    closedByFirstName: "Anna",
    closedByLastName: "Nowak",
    closedAt: "2026-11-21T01:30:00+00:00",
    updatedBy: null,
    updatedByFirstName: null,
    updatedByLastName: null,
    updatedAt: null,
    ...overrides,
  }
}

describe("validateDayClosesSearch", () => {
  it("test_it_keeps_a_valid_period", () => {
    expect(validateDayClosesSearch({ from: "2026-11-01", to: "2026-11-30" })).toEqual({ from: "2026-11-01", to: "2026-11-30" })
  })

  it("test_it_drops_an_unreadable_reversed_or_too_long_period", () => {
    const none = { from: undefined, to: undefined }
    expect(validateDayClosesSearch({ from: "2026-11-01" })).toEqual(none)
    expect(validateDayClosesSearch({ from: "2026-11-30", to: "2026-11-01" })).toEqual(none)
    expect(validateDayClosesSearch({ from: "2026-01-01", to: "2026-12-31" })).toEqual(none)
    expect(DAY_CLOSES_MAX_DAYS).toBe(92)
  })
})

describe("dayClosesTable", () => {
  it("test_it_has_a_column_per_storage_and_method_of_any_close_with_blanks_where_a_close_has_none", () => {
    const older = close({
      id: "close-0",
      storages: [close().storages[0]!],
      payments: [{ paymentMethodId: "blik", name: null, amount: 1_000 }],
    })
    const table = dayClosesTable([close(), older])

    expect(table.storages.map((s) => s.id)).toEqual(["box", "safe"])
    expect(table.payments).toEqual([
      { id: "card", name: "Card" },
      { id: "blik", name: null },
    ])
    expect(table.rows[0]).toMatchObject({ storageTotals: [60_000, 10_000], payments: [45_000, null] })
    expect(table.rows[1]).toMatchObject({ storageTotals: [60_000, null], payments: [null, 1_000] })
  })

  it("test_it_counts_shortages_and_books_them_as_zero_cash", () => {
    const table = dayClosesTable([close(), close({ id: "close-2", cashIncome: -3_000, shortage: true })])

    expect(table.bookedCash).toBe(20_000)
    expect(table.shortages).toBe(1)
  })

  it("test_it_is_empty_without_closes", () => {
    expect(dayClosesTable([])).toEqual({ storages: [], payments: [], rows: [], bookedCash: 0, shortages: 0 })
  })
})

describe("bookedCashOf and nonCashTotalOf", () => {
  it("test_it_books_a_shortage_as_zero", () => {
    expect(bookedCashOf(-500)).toBe(0)
    expect(bookedCashOf(500)).toBe(500)
  })

  it("test_it_adds_up_the_non_cash_amounts_of_a_close", () => {
    expect(nonCashTotalOf(close({ payments: [{ paymentMethodId: "a", name: "A", amount: 100 }, { paymentMethodId: "b", name: null, amount: 250 }] }))).toBe(350)
  })
})

describe("correctionEditor", () => {
  it("test_it_covers_the_close_s_storages_in_their_mode_at_the_close", () => {
    const editor = correctionEditor(close(), form({ storages: [{ id: "box", name: "Kasetka", mode: "deposit" }] }))

    expect(editor.kind).toBe("correct")
    expect(editor.storages.map((s) => [s.id, s.mode, s.expectedOpening])).toEqual([
      ["box", "counted", 50_000],
      ["safe", "deposit", null],
    ])
  })

  it("test_it_offers_the_close_s_denominations_and_the_active_ones_lowest_first", () => {
    const editor = correctionEditor(close(), form({ denominations: [NOTE_200, COIN_5] }))

    expect(editor.storages[0]!.denominations.map((d) => d.id)).toEqual(["d5", "d100", "d200"])
    expect(editor.storages[1]!.denominations.map((d) => d.id)).toEqual(["d5", "d100", "d200"])
  })

  it("test_it_offers_no_active_denomination_of_another_currency", () => {
    const editor = correctionEditor(close(), form({ currency: "EUR", denominations: [NOTE_200] }))

    expect(editor.storages[0]!.denominations.map((d) => d.id)).toEqual(["d5", "d100"])
  })

  it("test_it_keeps_the_close_s_payment_methods_and_its_own_register_even_if_deactivated", () => {
    const editor = correctionEditor(
      close({ registerId: "old", registerName: null, payments: [{ paymentMethodId: "gone", name: null, amount: 0 }] }),
      form(),
    )

    expect(editor.payments).toEqual([{ id: "gone", name: null }])
    expect(editor.registers).toEqual([
      { id: "old", name: null },
      { id: "bar", name: "Bar" },
    ])
  })

  it("test_it_does_not_repeat_an_active_register", () => {
    expect(correctionEditor(close(), form()).registers).toEqual([{ id: "bar", name: "Bar" }])
  })
})

describe("cashIncomeOf", () => {
  it("test_it_is_the_count_minus_the_expected_opening_plus_the_deposits", () => {
    const editor = correctionEditor(close(), form())
    const values = valuesOfClose(close())

    expect(cashIncomeOf(editor, values)).toBe(20_000)
    expect(cashIncomeOf(editor, withPieces(values, "box", "d100", 4))).toBe(40_000 - 50_000 + 10_000)
  })

  it("test_it_gives_each_storage_s_share", () => {
    const editor = correctionEditor(close(), form())
    const values = withPieces(valuesOfClose(close()), "box", "d5", 2)

    expect(storageCashIncome(editor.storages[0]!, values)).toBe(11_000)
    expect(storageCashIncome(editor.storages[1]!, values)).toBe(10_000)
  })

  it("test_it_is_unknown_for_a_new_close_with_a_counted_storage", () => {
    const editor = recordEditor(form())

    expect(cashIncomeOf(editor, valuesOfForm(form()))).toBeNull()
    expect(cashIncomeOf(recordEditor(form({ storages: [{ id: "safe", name: "Sejf", mode: "deposit" }] })), valuesOfForm(form()))).toBe(0)
  })
})

describe("problemsOf", () => {
  it("test_it_accepts_the_close_as_it_is", () => {
    expect(problemsOf(correctionEditor(close(), form()), valuesOfClose(close()), "2026-11-22")).toEqual([])
  })

  it("test_it_needs_a_register_offered", () => {
    const editor = recordEditor(form({ registers: [{ id: "a", name: "A" }, { id: "b", name: "B" }], preselectedRegisterId: null }))

    expect(problemsOf(editor, valuesOfForm(form({ registers: [], preselectedRegisterId: null })), "2026-11-22")).toContain("register")
    expect(problemsOf(editor, { ...valuesOfForm(form()), registerId: "elsewhere" }, "2026-11-22")).toContain("register")
  })

  it("test_it_refuses_a_new_close_after_today_but_never_checks_a_correction_s_date", () => {
    const values = { ...valuesOfForm(form()), date: "2026-11-23" }

    expect(problemsOf(recordEditor(form()), values, "2026-11-22")).toEqual(["date"])
    expect(problemsOf(recordEditor(form()), { ...values, date: "" }, "2026-11-22")).toEqual(["date"])
    expect(problemsOf(recordEditor(form()), { ...values, date: "2026-11-22" }, "2026-11-22")).toEqual([])
    expect(problemsOf(correctionEditor(close(), form()), { ...valuesOfClose(close()), date: "2026-11-23" }, "2026-11-22")).toEqual([])
  })

  it("test_it_holds_unreadable_pieces_and_amounts_until_fixed", () => {
    const editor = correctionEditor(close(), form())
    const broken = withAmount(withPieces(valuesOfClose(close()), "box", "d5", null), "card", null, true)

    expect(problemsOf(editor, broken, "2026-11-22")).toEqual(["pieces", "amounts"])
    const fixed = withAmount(withPieces(broken, "box", "d5", 3), "card", 100, false)
    expect(problemsOf(editor, fixed, "2026-11-22")).toEqual([])
  })

  it("test_it_refuses_an_amount_above_the_maximum", () => {
    const editor = correctionEditor(close(), form())

    expect(problemsOf(editor, withAmount(valuesOfClose(close()), "card", 2_147_483_648, false), "2026-11-22")).toEqual(["amounts"])
  })
})

describe("correctDayCloseBody", () => {
  it("test_it_sends_every_denomination_of_a_counted_storage_and_only_the_deposits_made", () => {
    const editor = correctionEditor(close(), form({ denominations: [NOTE_200] }))
    const values = withAmount(withPieces(valuesOfClose(close()), "safe", "d200", 2), "card", null, false)

    expect(correctDayCloseBody(editor, { ...values, registerId: "bar" })).toEqual({
      registerId: "bar",
      storages: [
        {
          storageId: "box",
          lines: [
            { denominationId: "d5", pieces: 0 },
            { denominationId: "d100", pieces: 6 },
            { denominationId: "d200", pieces: 0 },
          ],
        },
        {
          storageId: "safe",
          lines: [
            { denominationId: "d100", pieces: 1 },
            { denominationId: "d200", pieces: 2 },
          ],
        },
      ],
      payments: [{ paymentMethodId: "card", amount: 0 }],
    })
  })

  it("test_it_needs_a_register", () => {
    const editor = correctionEditor(close(), form())

    expect(() => correctDayCloseBody(editor, { ...valuesOfClose(close()), registerId: null })).toThrow()
  })
})

describe("recordDayCloseBody", () => {
  it("test_it_sends_the_picked_date_every_active_storage_and_method", () => {
    const editor = recordEditor(form())
    const values = withAmount(withPieces({ ...valuesOfForm(form()), date: "2026-11-19" }, "box", "d100", 3), "card", 12_345, false)

    expect(recordDayCloseBody(editor, values)).toEqual({
      registerId: "bar",
      date: "2026-11-19",
      storages: [
        {
          storageId: "box",
          lines: [
            { denominationId: "d5", pieces: 0 },
            { denominationId: "d100", pieces: 3 },
          ],
        },
        { storageId: "safe", lines: [] },
      ],
      payments: [{ paymentMethodId: "card", amount: 12_345 }],
    })
    expect(nonCashTotal(editor, values)).toBe(12_345)
  })

  it("test_it_starts_from_the_proposed_date_and_the_only_register", () => {
    expect(valuesOfForm(form({ preselectedRegisterId: null }))).toMatchObject({ date: "2026-11-21", registerId: "bar" })
    expect(valuesOfForm(form({ preselectedRegisterId: null, registers: [{ id: "a", name: "A" }, { id: "b", name: "B" }] })).registerId).toBeNull()
  })
})
