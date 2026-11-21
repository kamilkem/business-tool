import { ApiError } from "@business-tool/api-client"
import { afterEach, beforeEach, describe, expect, it } from "vitest"

import { i18n } from "../i18n"
import {
  type EntryFormValues,
  type RevenueEntry,
  type VatRate,
  emptyEntryForm,
  entryFormErrors,
  entryFormFrom,
  entryLines,
  formPaymentsTotal,
  formVatGross,
  incomeGrid,
  validateIncomeSearch,
  vatDifference,
  vatRateLabel,
} from "./income"
import type { EntryFormMethod } from "./payment-methods"
import type { Register } from "./registers"

/** The entry form's rows: the organization's methods in its order. */
const METHODS: EntryFormMethod[] = [
  { id: "m-cash", name: "Cash", active: true },
  { id: "m-card", name: "Card", active: true },
  { id: "m-transfer", name: "Transfer", active: true },
  { id: "m-voucher", name: "Voucher", active: true },
]

function register(name: string, active = true): Register {
  return {
    id: `register-${name}`,
    name,
    active,
    createdAt: "2026-10-01T10:00:00+00:00",
    deactivatedAt: active ? null : "2026-11-05T10:00:00+00:00",
  }
}

function entry(registerName: string, businessDate: string, paymentsTotal: number, currency = "PLN"): RevenueEntry {
  return {
    id: `entry-${registerName}-${businessDate}`,
    registerId: `register-${registerName}`,
    registerName,
    businessDate,
    currency,
    payments: [{ paymentMethodId: "m-cash", paymentMethodName: "Cash", amount: paymentsTotal }],
    vatLines: [],
    paymentsTotal,
    vatGrossTotal: 0,
    vatTotal: 0,
    note: null,
    createdBy: "owner",
    createdAt: `${businessDate}T22:00:00+00:00`,
    updatedBy: null,
    updatedAt: null,
  }
}

const bar = register("Bar")
const kitchen = register("Kitchen")
const terrace = register("Terrace", false)
const cellar = register("Cellar", false)

const PL_RATES: VatRate[] = [
  { code: "23", zeroVat: false },
  { code: "8", zeroVat: false },
  { code: "5", zeroVat: false },
  { code: "0", zeroVat: true },
  { code: "zw", zeroVat: true },
]

const november = { from: "2026-11-01", to: "2026-11-30" }

describe("incomeGrid", () => {
  it("test_it_builds_a_row_per_day_with_each_registers_entry_and_the_days_total", () => {
    const entries = [entry("Bar", "2026-11-01", 100_00), entry("Kitchen", "2026-11-01", 40_00), entry("Bar", "2026-11-03", 70_00)]

    const grid = incomeGrid(entries, [bar, kitchen], november, "2026-11-30")

    expect(grid.columns.map((c) => c.name)).toEqual(["Bar", "Kitchen"])
    expect(grid.rows).toHaveLength(30)
    expect(grid.rows[0]).toEqual({
      date: "2026-11-01",
      cells: [entries[0], entries[1]],
      total: [{ currency: "PLN", amount: 140_00 }],
    })
    expect(grid.rows[1]).toEqual({ date: "2026-11-02", cells: [null, null], total: [] })
    expect(grid.rows[2]?.cells).toEqual([entries[2], null])
    expect(grid.columnTotals).toEqual([[{ currency: "PLN", amount: 170_00 }], [{ currency: "PLN", amount: 40_00 }]])
    expect(grid.total).toEqual([{ currency: "PLN", amount: 210_00 }])
  })

  it("test_it_stops_at_today_so_future_days_have_no_row", () => {
    const grid = incomeGrid([], [bar], november, "2026-11-12")

    expect(grid.rows.map((r) => r.date).at(-1)).toBe("2026-11-12")
    expect(grid.rows).toHaveLength(12)
    expect(incomeGrid([], [bar], { from: "2026-12-01", to: "2026-12-31" }, "2026-11-12").rows).toEqual([])
  })

  it("test_it_shows_a_deactivated_register_only_when_it_has_entries_in_the_period", () => {
    const entries = [entry("Terrace", "2026-11-02", 30_00)]

    const grid = incomeGrid(entries, [bar, kitchen, cellar, terrace], november, "2026-11-30")

    expect(grid.columns).toEqual([
      { registerId: bar.id, name: "Bar", active: true },
      { registerId: kitchen.id, name: "Kitchen", active: true },
      { registerId: terrace.id, name: "Terrace", active: false },
    ])
  })

  it("test_it_gives_an_unknown_registers_entry_a_column_under_the_entrys_name", () => {
    const grid = incomeGrid([entry("Old till", "2026-11-02", 30_00)], [bar], november, "2026-11-30")

    expect(grid.columns.at(-1)).toEqual({ registerId: "register-Old till", name: "Old till", active: false })
    expect(grid.rows[1]?.cells.at(-1)?.paymentsTotal).toBe(30_00)
  })

  it("test_it_never_adds_amounts_of_different_currencies", () => {
    const entries = [entry("Bar", "2026-11-01", 100_00, "PLN"), entry("Kitchen", "2026-11-01", 20_00, "EUR")]

    const grid = incomeGrid(entries, [bar, kitchen], november, "2026-11-30")

    expect(grid.rows[0]?.total).toEqual([
      { currency: "PLN", amount: 100_00 },
      { currency: "EUR", amount: 20_00 },
    ])
  })
})

describe("vatDifference", () => {
  it("test_it_gives_the_gross_minus_payments_difference_when_they_dont_match", () => {
    expect(vatDifference(1000_00, 900_00, true)).toBe(-100_00)
    expect(vatDifference(1000_00, 1010_00, true)).toBe(10_00)
  })

  it("test_it_has_no_difference_when_they_match_or_there_is_no_breakdown", () => {
    expect(vatDifference(1000_00, 1000_00, true)).toBeNull()
    expect(vatDifference(1000_00, 0, false)).toBeNull()
  })
})

describe("entry form", () => {
  function filled(next: Partial<EntryFormValues>): EntryFormValues {
    return { ...emptyEntryForm("register-Bar", "2026-11-10"), ...next }
  }

  it("test_it_sums_the_payments_and_the_vat_gross_of_the_form", () => {
    const values = filled({
      payments: { "m-cash": 100_00, "m-card": 250_50, "m-transfer": null, "m-voucher": 0, "m-gone": 99 },
      vatLines: { "23": { gross: 300_00, vat: 56_10 }, "8": { gross: null, vat: null } },
    })

    // Only the form's rows count.
    expect(formPaymentsTotal(values, METHODS)).toBe(350_50)
    expect(formVatGross(values, PL_RATES)).toEqual({ total: 300_00, hasLines: true })
    expect(formVatGross(filled({}), PL_RATES)).toEqual({ total: 0, hasLines: false })
  })

  it("test_it_builds_the_lines_in_the_forms_order_with_zero_vat_for_exempt_rates", () => {
    const values = filled({
      payments: { "m-voucher": 0, "m-cash": null, "m-card": 250_00 },
      vatLines: { zw: { gross: 50_00, vat: null }, "23": { gross: 200_00, vat: 37_40 } },
    })

    expect(entryLines(values, METHODS, PL_RATES, "2026-11-12")).toEqual({
      ok: true,
      lines: {
        payments: [
          { paymentMethodId: "m-card", amount: 250_00 },
          { paymentMethodId: "m-voucher", amount: 0 },
        ],
        vatLines: [
          { rate: "23", gross: 200_00, vat: 37_40 },
          { rate: "zw", gross: 50_00, vat: 0 },
        ],
      },
    })
  })

  it("test_it_refuses_no_payment_a_future_day_a_missing_vat_and_vat_above_gross", () => {
    const values = filled({
      businessDate: "2026-11-13",
      vatLines: { "23": { gross: 100_00, vat: null }, "8": { gross: 10_00, vat: 11_00 }, "5": { gross: null, vat: 5_00 } },
    })

    const result = entryLines(values, METHODS, PL_RATES, "2026-11-12")

    expect(result).toEqual({
      ok: false,
      errors: {
        businessDate: "The day can't be after today.",
        payments: "Enter at least one amount. Enter 0 for a register that sold nothing.",
        "vat.23.vat": "Enter the VAT, as printed.",
        "vat.8.vat": "The VAT can't be more than the gross amount.",
        "vat.5.gross": "Enter the gross amount.",
      },
    })
  })

  it("test_it_fills_the_form_from_an_entry", () => {
    const recorded: RevenueEntry = {
      ...entry("Bar", "2026-11-03", 300_00),
      payments: [
        { paymentMethodId: "m-cash", paymentMethodName: "Cash", amount: 100_00 },
        { paymentMethodId: "m-card", paymentMethodName: "Card", amount: 200_00 },
      ],
      vatLines: [{ rate: "23", gross: 300_00, vat: 56_10, net: 243_90 }],
      note: "Event",
    }

    expect(entryFormFrom(recorded)).toEqual({
      registerId: "register-Bar",
      businessDate: "2026-11-03",
      payments: { "m-cash": 100_00, "m-card": 200_00 },
      vatLines: { "23": { gross: 300_00, vat: 56_10 } },
      note: "Event",
    })
  })

  it("test_it_maps_the_apis_violations_back_to_the_methods_and_rates_sent", () => {
    const lines = {
      payments: [
        { paymentMethodId: "m-card", amount: 1 },
        { paymentMethodId: "m-voucher", amount: 2 },
      ],
      vatLines: [{ rate: "zw", gross: 1, vat: 0 }],
    }
    const error = new ApiError(422, {
      violations: [
        { propertyPath: "payments[1].amount", message: "Too large." },
        { propertyPath: "vatLines[0].vat", message: "Must be 0." },
        { propertyPath: "vatLines[0].rate", message: "Unknown rate." },
        { propertyPath: "note", message: "Too long." },
        { propertyPath: "something", message: "Odd." },
      ],
    })

    expect(entryFormErrors(error, lines)).toEqual({
      "payment.m-voucher": "Too large.",
      "vat.zw.vat": "Must be 0.",
      "vat.zw.gross": "Unknown rate.",
      note: "Too long.",
      form: "Odd.",
    })
  })

  it("test_it_shows_a_domain_refusal_without_violations_under_the_form", () => {
    const error = new ApiError(422, {
      detail: "The business date 2026-11-13 is after today (2026-11-12).",
      code: "income.revenue_entry_in_the_future",
      parameters: { businessDate: "2026-11-13", today: "2026-11-12" },
    })

    expect(entryFormErrors(error, { payments: [], vatLines: [] })).toEqual({
      form: "13 Nov 2026 is after today (12 Nov 2026).",
    })
  })

  it("test_it_shows_a_refused_payment_method_on_its_field", () => {
    const error = new ApiError(422, {
      detail: "The payment method \"Voucher\" is deactivated and takes no new lines.",
      code: "income.invalid_payment_lines.method_deactivated",
      parameters: { paymentMethodId: "m-voucher", name: "Voucher" },
    })
    const lines = { payments: [{ paymentMethodId: "m-cash", amount: 1 }, { paymentMethodId: "m-voucher", amount: 2 }], vatLines: [] }

    expect(entryFormErrors(error, lines)).toEqual({
      "payment.m-voucher": "“Voucher” is deactivated and can't be added to an entry.",
    })
    expect(entryFormErrors(error, { payments: [], vatLines: [] })).toEqual({
      form: "“Voucher” is deactivated and can't be added to an entry.",
    })
  })
})

describe("validateIncomeSearch", () => {
  it("test_it_keeps_a_valid_period_and_drops_one_the_api_would_refuse", () => {
    expect(validateIncomeSearch({ from: "2026-10-01", to: "2026-10-31" })).toEqual({ from: "2026-10-01", to: "2026-10-31" })
    expect(validateIncomeSearch({ from: "2026-10-31", to: "2026-10-01" })).toEqual({ from: undefined, to: undefined })
    expect(validateIncomeSearch({ from: "2026-01-01", to: "2026-12-31" })).toEqual({ from: undefined, to: undefined })
    expect(validateIncomeSearch({ from: "nope" })).toEqual({ from: undefined, to: undefined })
  })
})

describe("vatRateLabel", () => {
  it("test_it_labels_numeric_rates_as_percentages_and_keeps_other_codes", () => {
    expect(vatRateLabel("23")).toBe("23%")
    expect(vatRateLabel("zw")).toBe("zw")
  })
})

describe("in Polish", () => {
  beforeEach(async () => {
    await i18n.changeLanguage("pl")
  })
  afterEach(async () => {
    await i18n.changeLanguage("en")
  })

  it("test_it_explains_errors_in_polish", () => {
    expect(entryLines(emptyEntryForm("", "2026-11-13"), METHODS, PL_RATES, "2026-11-12")).toEqual({
      ok: false,
      errors: {
        registerId: "Wybierz kasę.",
        businessDate: "Ten dzień nie może być późniejszy niż dzisiaj.",
        payments: "Podaj co najmniej jedną kwotę. Dla kasy bez sprzedaży wpisz 0.",
      },
    })
  })
})
