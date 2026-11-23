import { ApiError } from "@business-tool/api-client"
import { afterEach, beforeEach, describe, expect, it } from "vitest"

import { i18n } from "../i18n"
import {
  type ExpenseFormValues,
  breakdownMismatch,
  breakdownTotals,
  emptyExpenseForm,
  expenseBody,
  expenseFormErrors,
  expenseFormFrom,
  formNet,
  isEditable,
  isForeign,
} from "./expense-form"
import type { Expense } from "./expenses"
import type { VatRate } from "./income"

const RATES: VatRate[] = [
  { code: "23", zeroVat: false },
  { code: "8", zeroVat: false },
  { code: "zw", zeroVat: true },
]

function form(overrides: Partial<ExpenseFormValues> = {}): ExpenseFormValues {
  return { ...emptyExpenseForm("PLN", "2026-11-20"), sellerName: "Makro", grossTotal: 12300, vatTotal: 2300, ...overrides }
}

function expense(overrides: Partial<Expense> = {}): Expense {
  return {
    id: "0196a7a0-0000-7000-8000-0000000000e1",
    source: "manual",
    externalId: null,
    invoiceNumber: null,
    sellerTaxId: null,
    sellerName: "Makro",
    issueDate: "2026-11-03",
    kind: "regular",
    correctedExternalId: null,
    currency: "PLN",
    taxCurrency: "PLN",
    netTotal: 10000,
    vatTotal: 2300,
    grossTotal: 12300,
    vatLines: [{ rate: "23", net: 10000, vat: 2300, vatInTaxCurrency: null }],
    categoryId: null,
    categoryName: null,
    categorySuggested: false,
    hasDocument: false,
    documentFormat: null,
    createdAt: "2026-11-04T03:00:00+00:00",
    ...overrides,
  }
}

function violations(...list: [string, string][]): ApiError {
  return new ApiError(422, {
    detail: "Validation failed",
    violations: list.map(([propertyPath, message]) => ({ propertyPath, message })),
  })
}

describe("isEditable", () => {
  it("test_it_lets_only_a_manual_expense_be_changed", () => {
    expect(isEditable({ source: "manual" })).toBe(true)
    expect(isEditable({ source: "ksef" })).toBe(false)
  })
})

describe("expenseFormFrom", () => {
  it("test_it_fills_the_form_from_an_expense_with_empty_text_for_missing_values", () => {
    const values = expenseFormFrom(expense({ categoryId: "c1", categoryName: "Food" }))

    expect(values).toEqual({
      sellerName: "Makro",
      sellerTaxId: "",
      invoiceNumber: "",
      issueDate: "2026-11-03",
      kind: "regular",
      currency: "PLN",
      grossTotal: 12300,
      vatTotal: 2300,
      vatLines: { "23": { net: 10000, vat: 2300, vatInTaxCurrency: null } },
      categoryId: "c1",
    })
  })
})

describe("formNet and isForeign", () => {
  it("test_it_computes_net_as_gross_minus_vat_once_both_are_entered", () => {
    expect(formNet({ grossTotal: 12300, vatTotal: 2300 })).toBe(10000)
    expect(formNet({ grossTotal: 12300, vatTotal: null })).toBeNull()
  })

  it("test_it_treats_only_a_valid_other_code_as_foreign", () => {
    expect(isForeign({ currency: "eur " }, "PLN")).toBe(true)
    expect(isForeign({ currency: "PLN" }, "PLN")).toBe(false)
    expect(isForeign({ currency: "EU" }, "PLN")).toBe(false)
  })
})

describe("breakdownTotals and breakdownMismatch", () => {
  it("test_it_sums_the_rates_that_have_something_entered", () => {
    const values = form({
      vatLines: {
        "23": { net: 10000, vat: 2300, vatInTaxCurrency: null },
        "8": { net: null, vat: null, vatInTaxCurrency: null },
        zw: { net: 500, vat: null, vatInTaxCurrency: null },
      },
    })

    expect(breakdownTotals(values, RATES)).toEqual({ net: 10500, vat: 2300, gross: 12800 })
    expect(breakdownMismatch(values, RATES)).toBe(true)
  })

  it("test_it_reports_no_mismatch_without_a_breakdown_or_when_it_adds_up", () => {
    expect(breakdownTotals(form(), RATES)).toBeNull()
    expect(breakdownMismatch(form(), RATES)).toBe(false)
    expect(breakdownMismatch(form({ vatLines: { "23": { net: 10000, vat: 2300, vatInTaxCurrency: null } } }), RATES)).toBe(false)
  })
})

describe("expenseBody", () => {
  it("test_it_builds_the_request_with_trimmed_text_null_for_blanks_and_net_from_the_totals", () => {
    const result = expenseBody(
      form({
        sellerName: "  Makro ",
        sellerTaxId: " ",
        invoiceNumber: " P/1 ",
        currency: "pln",
        vatLines: { "23": { net: 10000, vat: 2300, vatInTaxCurrency: 999 }, zw: { net: 500, vat: null, vatInTaxCurrency: null } },
        categoryId: "c1",
      }),
      RATES,
      "PLN",
    )

    expect(result).toEqual({
      ok: true,
      body: {
        sellerName: "Makro",
        sellerTaxId: null,
        invoiceNumber: "P/1",
        issueDate: "2026-11-20",
        kind: "regular",
        currency: "PLN",
        netTotal: 10000,
        vatTotal: 2300,
        grossTotal: 12300,
        vatLines: [
          { rate: "23", net: 10000, vat: 2300, vatInTaxCurrency: null },
          { rate: "zw", net: 500, vat: 0, vatInTaxCurrency: null },
        ],
        categoryId: "c1",
      },
    })
  })

  it("test_it_refuses_a_form_without_seller_totals_or_a_valid_currency", () => {
    const result = expenseBody(form({ sellerName: " ", grossTotal: null, vatTotal: null, currency: "E1" }), RATES, "PLN")

    expect(result.ok).toBe(false)
    expect(!result.ok && Object.keys(result.errors).sort()).toEqual(["currency", "grossTotal", "sellerName", "vatTotal"])
  })

  it("test_it_allows_negative_amounts_only_on_a_correction", () => {
    const negative = { grossTotal: -12300, vatTotal: -2300 }

    const regular = expenseBody(form(negative), RATES, "PLN")
    expect(!regular.ok && regular.errors.grossTotal).toBe("Only a correction has negative amounts.")
    expect(expenseBody(form({ ...negative, kind: "correction" }), RATES, "PLN").ok).toBe(true)
  })

  it("test_it_asks_for_the_net_and_vat_of_a_rate_that_has_something_entered", () => {
    const result = expenseBody(
      form({ vatLines: { "23": { net: null, vat: 2300, vatInTaxCurrency: null }, "8": { net: 1000, vat: null, vatInTaxCurrency: null } } }),
      RATES,
      "PLN",
    )

    expect(!result.ok && result.errors).toEqual({ "vat.23.net": "Enter the net amount.", "vat.8.vat": "Enter the VAT, as printed." })
  })

  it("test_it_asks_for_the_vat_in_the_organization_currency_when_the_expense_is_foreign", () => {
    const lines = { "23": { net: 10000, vat: 2300, vatInTaxCurrency: null }, zw: { net: 500, vat: null, vatInTaxCurrency: null } }

    const missing = expenseBody(form({ currency: "EUR", vatLines: lines }), RATES, "PLN")
    expect(!missing.ok && missing.errors).toEqual({ "vat.23.vatInTaxCurrency": "Enter the VAT in PLN." })

    const complete = expenseBody(
      form({ currency: "EUR", vatLines: { ...lines, "23": { ...lines["23"], vatInTaxCurrency: 9800 } } }),
      RATES,
      "PLN",
    )
    expect(complete.ok && complete.body.vatLines).toEqual([
      { rate: "23", net: 10000, vat: 2300, vatInTaxCurrency: 9800 },
      { rate: "zw", net: 500, vat: 0, vatInTaxCurrency: 0 },
    ])
  })
})

describe("expenseFormErrors", () => {
  const sent = { vatLines: [{ rate: "23", net: 1, vat: 1, vatInTaxCurrency: null }, { rate: "8", net: 1, vat: 1, vatInTaxCurrency: null }] }

  it("test_it_maps_violations_to_fields_and_vat_lines_to_their_rate", () => {
    const error = violations(
      ["sellerName", "This value should not be blank."],
      ["vatLines[1].vat", "Too large."],
      ["vatLines[0].vatInTaxCurrency", "Too small."],
      ["netTotal", "Too large."],
      ["somethingElse", "Odd."],
    )

    expect(expenseFormErrors(error, sent)).toEqual({
      sellerName: "This value should not be blank.",
      "vat.8.vat": "Too large.",
      "vat.23.vatInTaxCurrency": "Too small.",
      grossTotal: "Too large.",
      form: "Odd.",
    })
  })

  it("test_it_shows_a_refusal_without_violations_on_the_form", () => {
    const unknownRate = new ApiError(422, {
      detail: "English detail.",
      code: "expenses.invalid_purchase_invoice.unknown_vat_rate",
      parameters: { rate: "7", countryCode: "PL" },
    })
    expect(expenseFormErrors(unknownRate, sent)).toEqual({ form: "7 is not a VAT rate in this country." })
    expect(expenseFormErrors(new Error("Network down"), sent)).toEqual({
      form: "Could not reach the server. Check your connection and try again.",
    })
  })
})

describe("in Polish", () => {
  beforeEach(async () => {
    await i18n.changeLanguage("pl")
  })
  afterEach(async () => {
    await i18n.changeLanguage("en")
  })

  it("test_it_explains_what_is_missing_in_polish", () => {
    const result = expenseBody(form({ sellerName: " ", currency: "zł", grossTotal: null }), RATES, "PLN")

    expect(result).toEqual({
      ok: false,
      errors: {
        sellerName: "Podaj nazwę sprzedawcy.",
        currency: "Podaj trzyliterowy kod waluty, np. EUR.",
        grossTotal: "Podaj kwotę brutto.",
      },
    })
  })
})
