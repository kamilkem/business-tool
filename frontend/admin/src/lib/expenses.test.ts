import { afterEach, beforeEach, describe, expect, it } from "vitest"

import { i18n } from "../i18n"
import type { ExpenseCategory } from "./expense-categories"
import {
  type Expense,
  type ExpenseCurrencyTotal,
  type ExpenseList,
  categoryOptions,
  documentFileName,
  expensesFilter,
  hasFilters,
  importAvailability,
  isCorrection,
  isImportPending,
  kindLabel,
  sourceLabel,
  validateExpensesSearch,
  visibleTotals,
  withExpense,
} from "./expenses"
import type { KsefConnection } from "./ksef"

const CATEGORY_ID = "0196a7a0-0000-7000-8000-000000000001"

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
    categoryId: null,
    categoryName: null,
    hasDocument: true,
    documentFormat: "FA (3)",
    createdAt: "2026-11-04T03:00:00+00:00",
    ...overrides,
  }
}

function category(id: string, name: string, archived = false): ExpenseCategory {
  return { id, name, archived, createdAt: "2026-11-01T10:00:00+00:00", archivedAt: archived ? "2026-11-02T10:00:00+00:00" : null }
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
    syncedUntil: null,
    lastSyncAt: null,
    lastSyncSucceededAt: null,
    lastSyncError: null,
    lastSyncErrorMessage: null,
    lastSyncImportedCount: 0,
    lastSyncSkippedCount: 0,
    syncRetryNotBefore: null,
    lastImportRequestedAt: null,
    createdAt: "2026-11-13T10:00:00+00:00",
    ...overrides,
  }
}

describe("validateExpensesSearch", () => {
  it("test_it_keeps_a_valid_period_and_filters", () => {
    expect(
      validateExpensesSearch({ from: "2026-11-01", to: "2026-11-30", categoryId: CATEGORY_ID, seller: "  hurt " }),
    ).toEqual({ from: "2026-11-01", to: "2026-11-30", categoryId: CATEGORY_ID, uncategorized: undefined, seller: "hurt" })
  })

  it("test_it_drops_a_period_the_api_would_refuse", () => {
    expect(validateExpensesSearch({ from: "2026-11-30", to: "2026-11-01" })).toMatchObject({ from: undefined, to: undefined })
    expect(validateExpensesSearch({ from: "2025-01-01", to: "2026-11-30" })).toMatchObject({ from: undefined, to: undefined })
    expect(validateExpensesSearch({ from: "2026-11-01" })).toMatchObject({ from: undefined, to: undefined })
  })

  it("test_it_accepts_the_uncategorized_flag_as_boolean_or_string_and_drops_the_category_with_it", () => {
    expect(validateExpensesSearch({ uncategorized: true, categoryId: CATEGORY_ID })).toMatchObject({
      uncategorized: true,
      categoryId: undefined,
    })
    expect(validateExpensesSearch({ uncategorized: "true" }).uncategorized).toBe(true)
    expect(validateExpensesSearch({ uncategorized: "no" }).uncategorized).toBeUndefined()
  })

  it("test_it_drops_a_category_id_that_is_not_a_uuid_and_a_blank_seller", () => {
    expect(validateExpensesSearch({ categoryId: "drinks", seller: "   " })).toMatchObject({
      categoryId: undefined,
      seller: undefined,
    })
  })
})

describe("expensesFilter", () => {
  const period = { from: "2026-11-01", to: "2026-11-30" }

  it("test_it_sends_only_the_filters_that_are_set", () => {
    expect(expensesFilter({}, period)).toEqual(period)
    expect(expensesFilter({ uncategorized: true, seller: "hurt" }, period)).toEqual({ ...period, uncategorized: true, seller: "hurt" })
    expect(expensesFilter({ categoryId: CATEGORY_ID }, period)).toEqual({ ...period, categoryId: CATEGORY_ID })
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

describe("categoryOptions", () => {
  const active = [category("a", "Beverages"), category("b", "Rent")]

  it("test_it_offers_the_active_categories", () => {
    expect(categoryOptions(active, { categoryId: "b", categoryName: "Rent" }).map((o) => o.id)).toEqual(["a", "b"])
  })

  it("test_it_adds_the_expenses_own_archived_category_marked_as_archived", () => {
    expect(categoryOptions(active, { categoryId: "x", categoryName: "Old" })).toContainEqual({ id: "x", name: "Old", archived: true })
  })
})

describe("withExpense", () => {
  it("test_it_replaces_the_expense_and_keeps_the_totals", () => {
    const list: ExpenseList = {
      from: "2026-11-01",
      to: "2026-11-30",
      items: [expense(), expense({ id: "other" })],
      totals: [total("PLN", 2)],
    }
    const updated = expense({ categoryId: "a", categoryName: "Beverages" })
    const result = withExpense(list, updated)
    expect(result.items[0]).toBe(updated)
    expect(result.items[1]).toBe(list.items[1])
    expect(result.totals).toBe(list.totals)
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

  it("test_it_names_an_archived_category_without_a_name_in_polish", () => {
    expect(categoryOptions([], { categoryId: "gone", categoryName: null })).toEqual([
      { id: "gone", name: "Zarchiwizowana kategoria", archived: true },
    ])
  })
})
