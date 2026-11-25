import { afterEach, beforeEach, describe, expect, it } from "vitest"

import { i18n } from "../i18n"
import {
  type Expense,
  type ExpenseCurrencyTotal,
  type ExpenseItem,
  documentFileName,
  expensesFilter,
  hasFilters,
  importAvailability,
  isCorrection,
  isImportPending,
  itemColumns,
  kindLabel,
  sourceLabel,
  validateExpensesSearch,
  visibleTotals,
} from "./expenses"
import type { KsefConnection } from "./ksef"

function expense(overrides: Partial<Expense> = {}): Expense {
  return {
    id: "0196a7a0-0000-7000-8000-0000000000e1",
    source: "ksef",
    externalId: "KSEF-1",
    invoiceNumber: "FV/2026/11/0042",
    sellerTaxId: "3947150208",
    sellerName: "Hurtownia",
    issueDate: "2026-11-03",
    kind: "regular",
    correctedExternalId: null,
    currency: "PLN",
    taxCurrency: "PLN",
    netTotal: 100000,
    vatTotal: 23000,
    grossTotal: 123000,
    vatLines: [{ rate: "23", net: 100000, vat: 23000, vatInTaxCurrency: null }],
    hasDocument: true,
    documentFormat: "FA (3)",
    createdAt: "2026-11-04T03:00:00+00:00",
    items: [],
    ...overrides,
  }
}

function total(currency: string, invoiceCount: number): ExpenseCurrencyTotal {
  return { currency, invoiceCount, netTotal: 0, vatTotal: 0, grossTotal: 0, vatLines: [] }
}

function connection(overrides: Partial<KsefConnection> = {}): KsefConnection {
  return {
    id: "connection-1",
    status: "active",
    tokenSet: true,
    tokenSetAt: "2026-11-13T10:00:00+00:00",
    nip: "5260250995",
    verifiedAt: "2026-11-13T10:00:00+00:00",
    lastCheckedAt: "2026-11-13T10:00:00+00:00",
    lastCheckError: null,
    lastCheckErrorMessage: null,
    syncStartDate: null,
    defaultSyncStartDate: "2026-10-01",
    syncedUntil: null,
    lastSyncAt: null,
    lastSyncSucceededAt: null,
    lastSyncError: null,
    lastSyncErrorMessage: null,
    lastSyncImportedCount: 0,
    lastSyncSkippedCount: 0,
    syncRetryNotBefore: null,
    lastImportRequestedAt: null,
    lastImportMethod: null,
    importBacklogCount: null,
    importBacklogIsMinimum: false,
    exportPending: false,
    importEstimatedAt: null,
    createdAt: "2026-11-13T10:00:00+00:00",
    ...overrides,
  }
}

describe("validateExpensesSearch", () => {
  it("test_it_keeps_a_valid_period_and_filters", () => {
    expect(
      validateExpensesSearch({ from: "2026-11-01", to: "2026-11-30", seller: "  hurt " }),
    ).toEqual({ from: "2026-11-01", to: "2026-11-30", seller: "hurt" })
  })

  it("test_it_drops_a_period_the_api_would_refuse", () => {
    expect(validateExpensesSearch({ from: "2026-11-30", to: "2026-11-01" })).toMatchObject({ from: undefined, to: undefined })
    expect(validateExpensesSearch({ from: "2025-01-01", to: "2026-11-30" })).toMatchObject({ from: undefined, to: undefined })
    expect(validateExpensesSearch({ from: "2026-11-01" })).toMatchObject({ from: undefined, to: undefined })
  })

  it("test_it_drops_a_blank_seller_and_the_removed_category_filters", () => {
    const search = validateExpensesSearch({ categoryId: "0196a7a0-0000-7000-8000-000000000001", uncategorized: "true", seller: "   " })
    expect(search).toEqual({ from: undefined, to: undefined, seller: undefined })
  })
})

describe("expensesFilter", () => {
  const period = { from: "2026-11-01", to: "2026-11-30" }

  it("test_it_sends_only_the_filters_that_are_set", () => {
    expect(expensesFilter({}, period)).toEqual(period)
    expect(expensesFilter({ seller: "hurt" }, period)).toEqual({ ...period, seller: "hurt" })
  })

  it("test_it_tells_whether_any_filter_is_set", () => {
    expect(hasFilters({ from: "2026-11-01", to: "2026-11-30" })).toBe(false)
    expect(hasFilters({ seller: "x" })).toBe(true)
  })
})

describe("kinds", () => {
  it("test_it_recognizes_every_kind_of_correction", () => {
    expect(isCorrection("correction")).toBe(true)
    expect(isCorrection("advance_correction")).toBe(true)
    expect(isCorrection("settlement_correction")).toBe(true)
    expect(isCorrection("regular")).toBe(false)
    expect(isCorrection("advance")).toBe(false)
  })

  it("test_it_labels_known_kinds_and_passes_unknown_ones_through", () => {
    expect(kindLabel("advance_correction")).toBe("Advance correction")
    expect(kindLabel("something_new")).toBe("something_new")
  })
})

describe("visibleTotals", () => {
  it("test_it_keeps_the_organizations_currency_even_when_empty_and_drops_other_empty_ones", () => {
    expect(visibleTotals([total("PLN", 0), total("EUR", 0), total("USD", 2)]).map((t) => t.currency)).toEqual(["PLN", "USD"])
  })
})

describe("documentFileName", () => {
  it("test_it_names_the_file_after_the_invoice_number", () => {
    expect(documentFileName(expense({ invoiceNumber: "FV/2026/11/0042" }), "application/xml")).toBe("FV-2026-11-0042.xml")
  })

  it("test_it_falls_back_to_the_id_and_adds_no_extension_for_other_types", () => {
    expect(documentFileName(expense({ id: "abc", invoiceNumber: null }), "application/octet-stream")).toBe("abc")
    expect(documentFileName(expense({ id: "abc", invoiceNumber: "///" }), "text/xml; charset=utf-8")).toBe("abc.xml")
  })
})

describe("importAvailability", () => {
  const now = new Date("2026-11-20T12:00:00Z")

  it("test_it_is_ready_without_a_recent_request", () => {
    expect(importAvailability(connection(), now)).toEqual({ kind: "ready" })
    expect(importAvailability(connection({ lastImportRequestedAt: "2026-11-20T11:44:59Z" }), now)).toEqual({ kind: "ready" })
  })

  it("test_it_waits_15_minutes_after_a_request", () => {
    expect(importAvailability(connection({ lastImportRequestedAt: "2026-11-20T11:50:00Z" }), now)).toEqual({
      kind: "wait",
      until: new Date("2026-11-20T12:05:00Z"),
      reason: "requested",
    })
  })

  it("test_it_waits_for_ksefs_rate_limit_when_it_ends_last", () => {
    expect(
      importAvailability(
        connection({ lastImportRequestedAt: "2026-11-20T11:50:00Z", syncRetryNotBefore: "2026-11-20T13:00:00Z" }),
        now,
      ),
    ).toEqual({ kind: "wait", until: new Date("2026-11-20T13:00:00Z"), reason: "rate-limited" })
    expect(importAvailability(connection({ syncRetryNotBefore: "2026-11-20T11:00:00Z" }), now)).toEqual({ kind: "ready" })
  })

  it("test_it_reports_a_rejected_or_missing_token", () => {
    expect(importAvailability(connection({ status: "credentials_rejected" }), now)).toEqual({ kind: "rejected" })
    expect(importAvailability(connection({ status: "disabled", tokenSet: false }), now)).toEqual({ kind: "no-token" })
  })
})

describe("isImportPending", () => {
  const now = new Date("2026-11-20T12:00:00Z")

  it("test_it_is_pending_until_a_run_follows_the_request", () => {
    expect(isImportPending(connection({ lastImportRequestedAt: "2026-11-20T11:58:00Z", lastSyncAt: "2026-11-20T03:00:00Z" }), now)).toBe(true)
    expect(isImportPending(connection({ lastImportRequestedAt: "2026-11-20T11:58:00Z", lastSyncAt: null }), now)).toBe(true)
    expect(isImportPending(connection({ lastImportRequestedAt: "2026-11-20T11:58:00Z", lastSyncAt: "2026-11-20T11:59:00Z" }), now)).toBe(false)
  })

  it("test_it_stops_waiting_after_the_request_interval_or_without_a_request", () => {
    expect(isImportPending(connection({ lastImportRequestedAt: "2026-11-20T11:40:00Z" }), now)).toBe(false)
    expect(isImportPending(connection(), now)).toBe(false)
    expect(isImportPending(null, now)).toBe(false)
  })
})

describe("itemColumns", () => {
  const item = (overrides: Partial<ExpenseItem> = {}): ExpenseItem => ({
    position: 1,
    name: "Piwo",
    sellerCode: null,
    gtin: null,
    unit: null,
    quantity: null,
    unitNetPrice: null,
    unitGrossPrice: null,
    discount: null,
    netAmount: null,
    grossAmount: null,
    vatAmount: null,
    vatRate: null,
    beforeCorrection: false,
    ...overrides,
  })

  it("test_it_hides_every_optional_column_when_no_row_fills_it", () => {
    expect(itemColumns([item(), item({ position: 2 })])).toEqual({
      quantity: false,
      unitNetPrice: false,
      unitGrossPrice: false,
      netAmount: false,
      vatRate: false,
      grossAmount: false,
    })
  })

  it("test_it_shows_a_column_that_any_row_fills", () => {
    const columns = itemColumns([
      item({ quantity: "2", unitNetPrice: "4.5", netAmount: 900, vatRate: "23" }),
      item({ position: 2, unit: "szt." }),
    ])
    expect(columns).toEqual({
      quantity: true,
      unitNetPrice: true,
      unitGrossPrice: false,
      netAmount: true,
      vatRate: true,
      grossAmount: false,
    })
  })

  it("test_it_shows_the_quantity_column_for_a_unit_alone", () => {
    expect(itemColumns([item({ unit: "kg" })]).quantity).toBe(true)
  })
})

describe("in Polish", () => {
  beforeEach(async () => {
    await i18n.changeLanguage("pl")
  })
  afterEach(async () => {
    await i18n.changeLanguage("en")
  })

  it("test_it_names_invoice_kinds_and_sources_in_polish", () => {
    expect(kindLabel("advance_correction")).toBe("Korekta faktury zaliczkowej")
    expect(kindLabel("unknown_kind")).toBe("unknown_kind")
    expect(sourceLabel("manual")).toBe("Wpisany ręcznie")
    expect(sourceLabel("ksef")).toBe("KSeF")
  })
})
