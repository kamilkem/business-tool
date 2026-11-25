import type { components } from "@business-tool/api-client"
import { type BusinessPeriod, isBusinessDate, periodError } from "@business-tool/ui/lib/date-time"

import { i18n } from "../i18n"
import type { KsefConnection } from "./ksef"

/*
 * Expenses: purchase invoices imported from KSeF or entered by hand (ADR 0023, ADR 0024), listed by
 * issue date with totals per currency.
 */

/** GET /expenses/{id}, and what POST and PATCH /expenses return: an expense with its items (ADR 0040). */
export type Expense = components["schemas"]["Expense"]
/** An expense as GET /expenses lists it: everything but its items. Every Expense is one. */
export type ExpenseListItem = components["schemas"]["ExpenseListItem"]
export type ExpenseList = components["schemas"]["ExpenseList"]
export type ExpenseCurrencyTotal = components["schemas"]["ExpenseCurrencyTotal"]

/** The longest period GET /expenses accepts. */
export const EXPENSES_MAX_DAYS = 366

/** How long after "Import now" another request is accepted (ADR 0023 §5). */
export const IMPORT_REQUEST_INTERVAL_MS = 15 * 60 * 1000

// --- Search params ---------------------------------------------------------------------------------

export interface ExpensesSearch {
  from?: string
  to?: string
  /** A part of the seller's name or tax ID. */
  seller?: string
}

/**
 * The Expenses page's search params. Anything the API would refuse is dropped, so the page falls
 * back to this month and no filter. Dropped keys are `undefined`, not left out: the router merges
 * the raw search params under the validated ones.
 */
export function validateExpensesSearch(search: Record<string, unknown>): ExpensesSearch {
  const { from, to, seller } = search
  const validPeriod =
    typeof from === "string" &&
    typeof to === "string" &&
    isBusinessDate(from) &&
    isBusinessDate(to) &&
    periodError({ from, to }, EXPENSES_MAX_DAYS) === null
  const trimmedSeller = typeof seller === "string" ? seller.trim() : ""

  return {
    from: validPeriod ? from : undefined,
    to: validPeriod ? to : undefined,
    seller: trimmedSeller === "" ? undefined : trimmedSeller,
  }
}

/** What GET /expenses is asked for: the period and the seller search, when there is one. */
export interface ExpensesFilter extends BusinessPeriod {
  seller?: string
}

export function expensesFilter(search: ExpensesSearch, period: BusinessPeriod): ExpensesFilter {
  return {
    ...period,
    ...(search.seller ? { seller: search.seller } : {}),
  }
}

export function hasFilters(search: ExpensesSearch): boolean {
  return Boolean(search.seller)
}

// --- Labels ----------------------------------------------------------------------------------------

export function sourceLabel(source: string): string {
  switch (source) {
    case "ksef":
      return "KSeF"
    case "manual":
      return i18n.t("expenses.source.manual", { ns: "admin" })
    default:
      return source
  }
}

const KINDS = [
  "regular",
  "correction",
  "advance",
  "settlement",
  "simplified",
  "advance_correction",
  "settlement_correction",
] as const

/** An invoice's kind (FA(3) RodzajFaktury) in words; an unknown one as the API gives it. */
export function kindLabel(kind: string): string {
  const known = KINDS.find((k) => k === kind)
  return known ? i18n.t(`expenses.kind.${known}`, { ns: "admin" }) : kind
}

export function isCorrection(kind: string): boolean {
  return kind === "correction" || kind.endsWith("_correction")
}

/** "23%" for a numeric rate code; any other code ("zw", "np") as the invoice prints it. */
export function vatRateLabel(code: string): string {
  return /^\d+(\.\d+)?$/.test(code) ? `${code}%` : code
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

// --- Items ------------------------------------------------------------------------------------------

/** A row of the invoice as printed (ADR 0040). */
export type ExpenseItem = components["schemas"]["ExpenseItem"]

/** Which of the optional item columns any row fills in. */
export interface ItemColumns {
  quantity: boolean
  unitNetPrice: boolean
  unitGrossPrice: boolean
  netAmount: boolean
  vatRate: boolean
  grossAmount: boolean
}

/**
 * The columns to show for an invoice's items: a column every row leaves empty is hidden, since a
 * net-based invoice often has no gross value per item and a gross-based one no net value.
 */
export function itemColumns(items: readonly ExpenseItem[]): ItemColumns {
  const any = (filled: (item: ExpenseItem) => boolean) => items.some(filled)
  return {
    quantity: any((item) => item.quantity !== null || item.unit !== null),
    unitNetPrice: any((item) => item.unitNetPrice !== null),
    unitGrossPrice: any((item) => item.unitGrossPrice !== null),
    netAmount: any((item) => item.netAmount !== null),
    vatRate: any((item) => item.vatRate !== null),
    grossAmount: any((item) => item.grossAmount !== null),
  }
}
