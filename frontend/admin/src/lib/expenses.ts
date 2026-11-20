import type { components } from "@business-tool/api-client"
import { type BusinessPeriod, isBusinessDate, periodError } from "@business-tool/ui/lib/date-time"

import type { ExpenseCategory } from "./expense-categories"
import type { KsefConnection } from "./ksef"

/*
 * Expenses: purchase invoices imported from KSeF or entered by hand (ADR 0023, ADR 0024), listed by
 * issue date with totals per currency, and sorted into categories.
 */

export type Expense = components["schemas"]["Expense"]
export type ExpenseList = components["schemas"]["ExpenseList"]
export type ExpenseCurrencyTotal = components["schemas"]["ExpenseCurrencyTotal"]

/** The longest period GET /expenses accepts. */
export const EXPENSES_MAX_DAYS = 366

/** How long after "Import now" another request is accepted (ADR 0023 §5). */
export const IMPORT_REQUEST_INTERVAL_MS = 15 * 60 * 1000

const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i

// --- Search params ---------------------------------------------------------------------------------

export interface ExpensesSearch {
  from?: string
  to?: string
  /** One category's expenses; never together with `uncategorized`. */
  categoryId?: string
  /** Only expenses without a category (the quick filter). */
  uncategorized?: true
  /** A part of the seller's name or tax ID. */
  seller?: string
}

/**
 * The Expenses page's search params. Anything the API would refuse is dropped, so the page falls
 * back to this month and no filter. Dropped keys are `undefined`, not left out: the router merges
 * the raw search params under the validated ones.
 */
export function validateExpensesSearch(search: Record<string, unknown>): ExpensesSearch {
  const { from, to, categoryId, uncategorized, seller } = search
  const validPeriod =
    typeof from === "string" &&
    typeof to === "string" &&
    isBusinessDate(from) &&
    isBusinessDate(to) &&
    periodError({ from, to }, EXPENSES_MAX_DAYS) === null
  const isUncategorized = uncategorized === true || uncategorized === "true"
  const validCategory = !isUncategorized && typeof categoryId === "string" && UUID.test(categoryId)
  const trimmedSeller = typeof seller === "string" ? seller.trim() : ""

  return {
    from: validPeriod ? from : undefined,
    to: validPeriod ? to : undefined,
    categoryId: validCategory ? categoryId : undefined,
    uncategorized: isUncategorized ? true : undefined,
    seller: trimmedSeller === "" ? undefined : trimmedSeller,
  }
}

/** What GET /expenses is asked for: the period and the filters that are set. */
export interface ExpensesFilter extends BusinessPeriod {
  categoryId?: string
  uncategorized?: true
  seller?: string
}

export function expensesFilter(search: ExpensesSearch, period: BusinessPeriod): ExpensesFilter {
  return {
    ...period,
    ...(search.categoryId ? { categoryId: search.categoryId } : {}),
    ...(search.uncategorized ? { uncategorized: true as const } : {}),
    ...(search.seller ? { seller: search.seller } : {}),
  }
}

export function hasFilters(search: ExpensesSearch): boolean {
  return Boolean(search.categoryId || search.uncategorized || search.seller)
}

// --- Labels ----------------------------------------------------------------------------------------

export function sourceLabel(source: string): string {
  switch (source) {
    case "ksef":
      return "KSeF"
    case "manual":
      return "Entered by hand"
    default:
      return source
  }
}

const KIND_LABELS: Record<string, string> = {
  regular: "Invoice",
  correction: "Correction",
  advance: "Advance invoice",
  settlement: "Settlement invoice",
  simplified: "Simplified invoice",
  advance_correction: "Advance correction",
  settlement_correction: "Settlement correction",
}

export function kindLabel(kind: string): string {
  return KIND_LABELS[kind] ?? kind
}

export function isCorrection(kind: string): boolean {
  return kind === "correction" || kind.endsWith("_correction")
}

/** "23%" for a numeric rate code; any other code ("zw", "np") as the invoice prints it. */
export function vatRateLabel(code: string): string {
  return /^\d+(\.\d+)?$/.test(code) ? `${code}%` : code
}

// --- Categories ------------------------------------------------------------------------------------

export interface CategoryOption {
  id: string
  name: string
  /** Shown so an archived category an expense still has reads right, but not offered. */
  archived: boolean
}

/**
 * The categories an expense's picker shows: the active ones, plus its own category when that one is
 * archived (it keeps it until changed, but nobody can pick it again).
 */
export function categoryOptions(active: readonly ExpenseCategory[], expense: Pick<Expense, "categoryId" | "categoryName">): CategoryOption[] {
  const options = active.map((c) => ({ id: c.id, name: c.name, archived: false }))
  if (expense.categoryId && !options.some((o) => o.id === expense.categoryId)) {
    options.push({ id: expense.categoryId, name: expense.categoryName ?? "Archived category", archived: true })
  }
  return options
}

/** An expense list with one expense replaced, totals left as they were (a category change moves no money). */
export function withExpense(list: ExpenseList, updated: Expense): ExpenseList {
  return { ...list, items: list.items.map((item) => (item.id === updated.id ? updated : item)) }
}

/**
 * The currencies to show totals for: the organization's currency always (the API puts it first,
 * with zeros when nothing was listed), others only when something is in them.
 */
export function visibleTotals(totals: readonly ExpenseCurrencyTotal[]): ExpenseCurrencyTotal[] {
  return totals.filter((total, index) => index === 0 || total.invoiceCount > 0)
}

// --- The original document -------------------------------------------------------------------------

/** A file name for the downloaded document: the invoice number made safe, or the expense id. */
export function documentFileName(expense: Pick<Expense, "id" | "invoiceNumber">, contentType: string): string {
  const base = (expense.invoiceNumber ?? "").replace(/[^\p{L}\p{N}._-]+/gu, "-").replace(/^-+|-+$/g, "") || expense.id
  return /xml/i.test(contentType) ? `${base}.xml` : base
}

// --- KSeF import -----------------------------------------------------------------------------------

export type ImportAvailability =
  /** No token is saved: nothing is imported. */
  | { kind: "no-token" }
  /** KSeF refused the token, or it no longer decrypts: an owner saves a new one. */
  | { kind: "rejected" }
  /** "Import now" works again at `until`: after the last request, or KSeF's rate limit. */
  | { kind: "wait"; until: Date; reason: "requested" | "rate-limited" }
  | { kind: "ready" }

/** Whether "Import now" would be accepted at `now`, the API's rules (ADR 0023 §5) on the client. */
export function importAvailability(connection: KsefConnection, now: Date): ImportAvailability {
  if (!connection.tokenSet || connection.status === "disabled") return { kind: "no-token" }
  if (connection.status === "credentials_rejected") return { kind: "rejected" }

  const retry = connection.syncRetryNotBefore ? new Date(connection.syncRetryNotBefore) : null
  const requested = connection.lastImportRequestedAt
    ? new Date(new Date(connection.lastImportRequestedAt).getTime() + IMPORT_REQUEST_INTERVAL_MS)
    : null
  // The later of the two decides; the rate limit names the reason when it ends last.
  if (retry && retry > now && (!requested || retry >= requested)) return { kind: "wait", until: retry, reason: "rate-limited" }
  if (requested && requested > now) return { kind: "wait", until: requested, reason: "requested" }
  return { kind: "ready" }
}

/**
 * Whether an import was requested and has not run yet (no run since the request), so the page keeps
 * asking for the status. Gives up after the request interval: a run that never started is not
 * waited for forever.
 */
export function isImportPending(connection: KsefConnection | null, now: Date): boolean {
  if (!connection?.lastImportRequestedAt) return false
  const requestedAt = new Date(connection.lastImportRequestedAt)
  if (now.getTime() - requestedAt.getTime() > IMPORT_REQUEST_INTERVAL_MS) return false
  return !connection.lastSyncAt || new Date(connection.lastSyncAt) < requestedAt
}
