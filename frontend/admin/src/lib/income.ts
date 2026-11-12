import { ApiError, type components, violationsOf } from "@business-tool/api-client"
import {
  type BusinessDate,
  type BusinessPeriod,
  addDays,
  compareBusinessDates,
  isBusinessDate,
  periodError,
} from "@business-tool/ui/lib/date-time"
import type { MinorUnits } from "@business-tool/ui/lib/money"

import type { Register } from "./registers"

/*
 * Income (ADR 0022): one revenue entry per register and business date, with gross amounts per
 * payment method and an optional VAT breakdown by the organization's country.
 */

export type RevenueEntry = components["schemas"]["RevenueEntry"]
export type RevenueSummary = components["schemas"]["RevenueSummary"]
export type VatRate = components["schemas"]["VatRate"]
export type PaymentMethod = components["schemas"]["PaymentLineInput"]["method"]
export type RecordRevenueEntryInput = components["schemas"]["RevenueEntry.RecordRevenueEntryInput"]
export type UpdateRevenueEntryInput = components["schemas"]["RevenueEntry.UpdateRevenueEntryInput.jsonMergePatch"]

/** The fixed payment methods of v1, in the API's order (ADR 0022 §4). */
export const PAYMENT_METHODS: readonly PaymentMethod[] = ["cash", "card", "transfer", "voucher"]

export const PAYMENT_METHOD_LABELS: Record<PaymentMethod, string> = {
  cash: "Cash",
  card: "Card",
  transfer: "Transfer",
  voucher: "Voucher",
}

/** The longest period GET /revenue-entries accepts. */
export const REVENUE_ENTRIES_MAX_DAYS = 92

export const NOTE_MAX_LENGTH = 1000

/** "23%" for a numeric rate code; any other code ("zw") as the fiscal printer labels it. */
export function vatRateLabel(code: string): string {
  return /^\d+(\.\d+)?$/.test(code) ? `${code}%` : code
}

// --- Search params ---------------------------------------------------------------------------------

export interface IncomeSearch {
  from?: string
  to?: string
}

/**
 * The Income page's `?from=&to=`. A period the API would refuse is dropped as a whole, so the page
 * falls back to this month. Dropped keys are `undefined`, not left out: the router merges the raw
 * search params under the validated ones.
 */
export function validateIncomeSearch(search: Record<string, unknown>): IncomeSearch {
  const { from, to } = search
  if (
    typeof from === "string" &&
    typeof to === "string" &&
    isBusinessDate(from) &&
    isBusinessDate(to) &&
    periodError({ from, to }, REVENUE_ENTRIES_MAX_DAYS) === null
  ) {
    return { from, to }
  }
  return { from: undefined, to: undefined }
}

// --- Money in several currencies -------------------------------------------------------------------

/**
 * A sum that may hold more than one currency: entries keep the currency they were recorded in
 * (ADR 0022 §5), so amounts of different currencies are never added together. Usually one item.
 */
export type MoneyTotal = { currency: string; amount: MinorUnits }[]

function addTo(total: MoneyTotal, currency: string, amount: MinorUnits): MoneyTotal {
  const existing = total.find((item) => item.currency === currency)
  if (existing) return total.map((item) => (item === existing ? { currency, amount: item.amount + amount } : item))
  return [...total, { currency, amount }]
}

function sumOf(entries: readonly RevenueEntry[]): MoneyTotal {
  return entries.reduce<MoneyTotal>((total, entry) => addTo(total, entry.currency, entry.paymentsTotal), [])
}

// --- The month grid --------------------------------------------------------------------------------

export interface IncomeColumn {
  registerId: string
  name: string
  /** Whether the register takes new entries (an empty cell offers to add one). */
  active: boolean
}

export interface IncomeRow {
  date: BusinessDate
  /** One cell per column, in the columns' order: the register's entry for the day, or null. */
  cells: (RevenueEntry | null)[]
  total: MoneyTotal
}

export interface IncomeGrid {
  columns: IncomeColumn[]
  /** Every day of the period up to today, oldest first. */
  rows: IncomeRow[]
  /** Per column, in the columns' order. */
  columnTotals: MoneyTotal[]
  total: MoneyTotal
}

/**
 * The month view: a row per day with each register's entry and the day's total.
 *
 * The columns are the active registers, then any deactivated register with an entry in the period
 * (its entries stay listed, ADR 0022 §2), in the order of `registers` (the API's: active, then
 * deactivated, each by name). An entry of a register missing from `registers` gets a column too,
 * under the name the entry carries. Days after `today` have no row: nothing can be entered for them.
 */
export function incomeGrid(
  entries: readonly RevenueEntry[],
  registers: readonly Register[],
  period: BusinessPeriod,
  today: BusinessDate,
): IncomeGrid {
  const withEntries = new Set(entries.map((entry) => entry.registerId))
  const columns: IncomeColumn[] = registers
    .filter((register) => register.active || withEntries.has(register.id))
    .map((register) => ({ registerId: register.id, name: register.name, active: register.active }))
  for (const entry of entries) {
    if (!columns.some((column) => column.registerId === entry.registerId)) {
      columns.push({ registerId: entry.registerId, name: entry.registerName, active: false })
    }
  }

  const last = compareBusinessDates(period.to, today) > 0 ? today : period.to
  const rows: IncomeRow[] = []
  for (let date = period.from; compareBusinessDates(date, last) <= 0; date = addDays(date, 1)) {
    const ofDay = entries.filter((entry) => entry.businessDate === date)
    rows.push({
      date,
      cells: columns.map((column) => ofDay.find((entry) => entry.registerId === column.registerId) ?? null),
      total: sumOf(ofDay),
    })
  }

  return {
    columns,
    rows,
    columnTotals: columns.map((column) => sumOf(entries.filter((entry) => entry.registerId === column.registerId))),
    total: sumOf(entries),
  }
}

// --- VAT against payments --------------------------------------------------------------------------

/**
 * How much the VAT breakdown's gross total differs from the payments total (gross − payments), or
 * null when there is no breakdown or the two match. A difference is allowed (ADR 0022 §6): an
 * invoiced transfer, for example, is not on the fiscal report.
 */
export function vatDifference(paymentsTotal: MinorUnits, vatGrossTotal: MinorUnits, hasVatLines: boolean): MinorUnits | null {
  if (!hasVatLines || vatGrossTotal === paymentsTotal) return null
  return vatGrossTotal - paymentsTotal
}

// --- The entry form --------------------------------------------------------------------------------

export interface VatLineValues {
  gross: MinorUnits | null
  vat: MinorUnits | null
}

export interface EntryFormValues {
  registerId: string
  businessDate: BusinessDate
  /** An empty field is null: the method is not used. */
  payments: Record<PaymentMethod, MinorUnits | null>
  /** By rate code; a rate with an empty gross amount is not on the report. */
  vatLines: Record<string, VatLineValues>
  note: string
}

/**
 * Errors keyed by form field: `registerId`, `businessDate`, `note`, `payments` (the group),
 * `payment.<method>`, `vatLines` (the group), `vat.<rate>.gross`, `vat.<rate>.vat`, and `form` for
 * anything that names no field.
 */
export type EntryFormErrors = Record<string, string>

function emptyPayments(): Record<PaymentMethod, MinorUnits | null> {
  return { cash: null, card: null, transfer: null, voucher: null }
}

export function emptyEntryForm(registerId: string, businessDate: BusinessDate): EntryFormValues {
  return { registerId, businessDate, payments: emptyPayments(), vatLines: {}, note: "" }
}

export function entryFormFrom(entry: RevenueEntry): EntryFormValues {
  const payments = emptyPayments()
  for (const line of entry.payments) payments[line.method] = line.amount
  return {
    registerId: entry.registerId,
    businessDate: entry.businessDate,
    payments,
    vatLines: Object.fromEntries(entry.vatLines.map((line) => [line.rate, { gross: line.gross, vat: line.vat }])),
    note: entry.note ?? "",
  }
}

/** The payments total of the form, counting empty fields as nothing. */
export function formPaymentsTotal(values: EntryFormValues): MinorUnits {
  return PAYMENT_METHODS.reduce((sum, method) => sum + (values.payments[method] ?? 0), 0)
}

/** The VAT breakdown's gross total of the form, and whether it has any line. */
export function formVatGross(values: EntryFormValues, rates: readonly VatRate[]): { total: MinorUnits; hasLines: boolean } {
  const grosses = rates.map((rate) => values.vatLines[rate.code]?.gross ?? null).filter((gross) => gross !== null)
  return { total: grosses.reduce((sum, gross) => sum + gross, 0), hasLines: grosses.length > 0 }
}

/** The lines of a valid form, in the order the API gets them (for mapping its errors back). */
export interface EntryLines {
  payments: components["schemas"]["PaymentLineInput"][]
  vatLines: components["schemas"]["VatLineInput"][]
}

export type EntryLinesResult = { ok: true; lines: EntryLines } | { ok: false; errors: EntryFormErrors }

/**
 * Checks what the API would refuse anyway, so the form answers at once: at least one payment, a date
 * not after today, and a VAT amount for every rate that has a gross amount (0 for a rate without
 * VAT). The API checks again.
 */
export function entryLines(values: EntryFormValues, rates: readonly VatRate[], today: BusinessDate): EntryLinesResult {
  const errors: EntryFormErrors = {}
  if (values.registerId === "") errors.registerId = "Choose a register."
  if (!isBusinessDate(values.businessDate)) errors.businessDate = "Choose the day the daily report covers."
  else if (compareBusinessDates(values.businessDate, today) > 0) errors.businessDate = "The day can't be after today."

  const payments = PAYMENT_METHODS.flatMap((method) => {
    const amount = values.payments[method]
    return amount === null ? [] : [{ method, amount }]
  })
  if (payments.length === 0) errors.payments = "Enter at least one amount. Enter 0 for a register that sold nothing."

  const vatLines: components["schemas"]["VatLineInput"][] = []
  for (const rate of rates) {
    const line = values.vatLines[rate.code]
    if (!line || line.gross === null) {
      if (line && line.vat !== null && line.vat !== 0) errors[`vat.${rate.code}.gross`] = "Enter the gross amount."
      continue
    }
    const vat = rate.zeroVat ? 0 : line.vat
    if (vat === null) errors[`vat.${rate.code}.vat`] = "Enter the VAT, as printed."
    else if (vat > line.gross) errors[`vat.${rate.code}.vat`] = "The VAT can't be more than the gross amount."
    else vatLines.push({ rate: rate.code, gross: line.gross, vat })
  }

  return Object.keys(errors).length > 0 ? { ok: false, errors } : { ok: true, lines: { payments, vatLines } }
}

/**
 * Turns a refused write into errors on the form's fields. A 422's violations name lines by index
 * (`payments[1].amount`, `vatLines[0].vat`), which `lines` (what was sent) maps back to a method or
 * a rate. Domain 422s (a future date, a deactivated register) come with a message only.
 */
export function entryFormErrors(error: unknown, lines: EntryLines): EntryFormErrors {
  if (!(error instanceof ApiError)) {
    return { form: error instanceof Error ? error.message : "Something went wrong. Try again." }
  }
  const violations = violationsOf(error)
  if (Object.keys(violations).length === 0) return { form: error.message }

  const errors: EntryFormErrors = {}
  for (const [path, message] of Object.entries(violations)) {
    const field = fieldOf(path, lines)
    errors[field] = errors[field] ? `${errors[field]} ${message}` : message
  }
  return errors
}

function fieldOf(path: string, lines: EntryLines): string {
  const payment = /^payments\[(\d+)\](?:\.(\w+))?$/.exec(path)
  if (payment) {
    const method = lines.payments[Number(payment[1])]?.method
    return method ? `payment.${method}` : "payments"
  }
  const vat = /^vatLines\[(\d+)\](?:\.(\w+))?$/.exec(path)
  if (vat) {
    const rate = lines.vatLines[Number(vat[1])]?.rate
    const part = vat[2] === "vat" ? "vat" : "gross"
    return rate ? `vat.${rate}.${part}` : "vatLines"
  }
  if (["registerId", "businessDate", "note", "payments", "vatLines"].includes(path)) return path
  return "form"
}

/** A 409 on POST: the register already has an entry for that day (ADR 0022 §3). */
export function isDuplicateEntry(error: unknown): boolean {
  return error instanceof ApiError && error.status === 409
}
