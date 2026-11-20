import { ApiError, type components, violationsOf } from "@business-tool/api-client"
import { type BusinessDate, isBusinessDate } from "@business-tool/ui/lib/date-time"
import type { MinorUnits } from "@business-tool/ui/lib/money"

import { type Expense, isCorrection } from "./expenses"
import type { VatRate } from "./income"

/*
 * The form for an expense entered by hand (#58): a cash receipt or an invoice that is not in KSeF.
 * Owners and managers add, change and delete them (POST, PATCH, DELETE /expenses); an imported
 * expense is read-only apart from its category.
 */

export type EnterExpenseInput = components["schemas"]["Expense.EnterExpenseInput"]
export type ExpenseVatLineInput = components["schemas"]["ExpenseVatLineInput"]
export type ExpenseKind = NonNullable<EnterExpenseInput["kind"]>

/** The kinds the form offers, the common ones first. */
export const EXPENSE_KINDS: readonly ExpenseKind[] = [
  "regular",
  "simplified",
  "correction",
  "advance",
  "settlement",
  "advance_correction",
  "settlement_correction",
]

/** Lengths the API refuses past (PurchaseInvoice::MAX_*). */
export const SELLER_NAME_MAX_LENGTH = 512
export const SELLER_TAX_ID_MAX_LENGTH = 32
export const INVOICE_NUMBER_MAX_LENGTH = 256

const CURRENCY_CODE = /^[A-Z]{3}$/

/** Only an expense entered by hand can be changed or deleted; an imported one keeps what the source says. */
export function isEditable(expense: Pick<Expense, "source">): boolean {
  return expense.source === "manual"
}

// --- Values ----------------------------------------------------------------------------------------

export interface ExpenseVatLineValues {
  net: MinorUnits | null
  vat: MinorUnits | null
  /** The VAT in the organization's currency; asked for only when the expense is in another one. */
  vatInTaxCurrency: MinorUnits | null
}

export interface ExpenseFormValues {
  sellerName: string
  sellerTaxId: string
  invoiceNumber: string
  issueDate: BusinessDate
  kind: ExpenseKind
  /** ISO 4217, upper case; the organization's by default. */
  currency: string
  /** The totals as printed. Net is not entered: it is gross − VAT. */
  grossTotal: MinorUnits | null
  vatTotal: MinorUnits | null
  /** By rate code; a rate with every field empty is not on the document. */
  vatLines: Record<string, ExpenseVatLineValues>
  categoryId: string | null
}

/**
 * Errors keyed by form field: `sellerName`, `sellerTaxId`, `invoiceNumber`, `issueDate`, `kind`,
 * `currency`, `grossTotal`, `vatTotal`, `categoryId`, `vatLines` (the group),
 * `vat.<rate>.net|vat|vatInTaxCurrency`, and `form` for anything that names no field.
 */
export type ExpenseFormErrors = Record<string, string>

export function emptyExpenseForm(currency: string, issueDate: BusinessDate): ExpenseFormValues {
  return {
    sellerName: "",
    sellerTaxId: "",
    invoiceNumber: "",
    issueDate,
    kind: "regular",
    currency,
    grossTotal: null,
    vatTotal: null,
    vatLines: {},
    categoryId: null,
  }
}

export function expenseFormFrom(expense: Expense): ExpenseFormValues {
  return {
    sellerName: expense.sellerName,
    sellerTaxId: expense.sellerTaxId ?? "",
    invoiceNumber: expense.invoiceNumber ?? "",
    issueDate: expense.issueDate,
    kind: (EXPENSE_KINDS as readonly string[]).includes(expense.kind) ? (expense.kind as ExpenseKind) : "regular",
    currency: expense.currency,
    grossTotal: expense.grossTotal,
    vatTotal: expense.vatTotal,
    vatLines: Object.fromEntries(
      expense.vatLines.map((line) => [
        line.rate,
        { net: line.net, vat: line.vat, vatInTaxCurrency: line.vatInTaxCurrency },
      ]),
    ),
    categoryId: expense.categoryId,
  }
}

/** Typed text as a currency code: trimmed and upper case. */
export function normalizeCurrency(text: string): string {
  return text.trim().toUpperCase()
}

/** Whether the amounts are in another currency than the one VAT is settled in. */
export function isForeign(values: Pick<ExpenseFormValues, "currency">, organizationCurrency: string): boolean {
  const currency = normalizeCurrency(values.currency)
  return CURRENCY_CODE.test(currency) && currency !== organizationCurrency
}

/** Net = gross − VAT, or null while either is empty. */
export function formNet(values: Pick<ExpenseFormValues, "grossTotal" | "vatTotal">): MinorUnits | null {
  return values.grossTotal === null || values.vatTotal === null ? null : values.grossTotal - values.vatTotal
}

function hasAny(line: ExpenseVatLineValues | undefined): line is ExpenseVatLineValues {
  return line !== undefined && (line.net !== null || line.vat !== null || line.vatInTaxCurrency !== null)
}

/** The VAT breakdown's sums over the rates that have something entered, or null without a breakdown. */
export function breakdownTotals(
  values: Pick<ExpenseFormValues, "vatLines">,
  rates: readonly VatRate[],
): { net: MinorUnits; vat: MinorUnits; gross: MinorUnits } | null {
  const lines = rates.map((rate) => values.vatLines[rate.code]).filter(hasAny)
  if (lines.length === 0) return null
  const net = lines.reduce((sum, line) => sum + (line.net ?? 0), 0)
  const vat = lines.reduce((sum, line) => sum + (line.vat ?? 0), 0)
  return { net, vat, gross: net + vat }
}

/**
 * Whether the breakdown adds up to other totals than the ones entered. The API stores both as given
 * (a document may round differently), so the form only points it out.
 */
export function breakdownMismatch(values: ExpenseFormValues, rates: readonly VatRate[]): boolean {
  const sums = breakdownTotals(values, rates)
  if (sums === null || values.grossTotal === null || values.vatTotal === null) return false
  return sums.gross !== values.grossTotal || sums.vat !== values.vatTotal
}

// --- Sending ---------------------------------------------------------------------------------------

/** What POST /expenses gets; PATCH gets the same fields (merge-patch, the VAT lines replaced whole). */
export type ExpenseBody = Required<Omit<EnterExpenseInput, "vatLines">> & { vatLines: ExpenseVatLineInput[] }

export type ExpenseBodyResult = { ok: true; body: ExpenseBody } | { ok: false; errors: ExpenseFormErrors }

/**
 * Checks what the API would refuse anyway, so the form answers at once, and builds the request body:
 * a seller, a date, a currency code, the gross and VAT totals (negative only on a correction), and
 * for every rate with something entered its net and VAT (0 at a rate without VAT) and, for an
 * expense in another currency, the VAT in the organization's. The API checks again.
 */
export function expenseBody(
  values: ExpenseFormValues,
  rates: readonly VatRate[],
  organizationCurrency: string,
): ExpenseBodyResult {
  const errors: ExpenseFormErrors = {}
  const sellerName = values.sellerName.trim()
  if (sellerName === "") errors.sellerName = "Enter the seller's name."
  if (!isBusinessDate(values.issueDate)) errors.issueDate = "Choose the date on the document."

  const currency = normalizeCurrency(values.currency)
  if (!CURRENCY_CODE.test(currency)) errors.currency = "Enter a three-letter currency code, e.g. EUR."
  const foreign = CURRENCY_CODE.test(currency) && currency !== organizationCurrency

  const negativeAllowed = isCorrection(values.kind)
  const checkAmount = (field: "grossTotal" | "vatTotal", missing: string) => {
    const amount = values[field]
    if (amount === null) errors[field] = missing
    else if (amount < 0 && !negativeAllowed) errors[field] = "Only a correction has negative amounts."
  }
  checkAmount("grossTotal", "Enter the gross total.")
  checkAmount("vatTotal", "Enter the VAT total. Enter 0 for a document without VAT.")

  const vatLines: ExpenseVatLineInput[] = []
  for (const rate of rates) {
    const line = values.vatLines[rate.code]
    if (!hasAny(line)) continue
    const key = (part: keyof ExpenseVatLineValues) => `vat.${rate.code}.${part}`
    const vat = rate.zeroVat ? 0 : line.vat
    let valid = true
    if (line.net === null) {
      errors[key("net")] = "Enter the net amount."
      valid = false
    }
    if (vat === null) {
      errors[key("vat")] = "Enter the VAT, as printed."
      valid = false
    }
    const vatInTaxCurrency = !foreign ? null : rate.zeroVat ? 0 : line.vatInTaxCurrency
    if (foreign && vatInTaxCurrency === null) {
      errors[key("vatInTaxCurrency")] = `Enter the VAT in ${organizationCurrency}.`
      valid = false
    }
    if (!negativeAllowed && [line.net, vat, vatInTaxCurrency].some((amount) => amount !== null && amount < 0)) {
      errors[key("net")] = "Only a correction has negative amounts."
      valid = false
    }
    if (valid && line.net !== null && vat !== null) {
      vatLines.push({ rate: rate.code, net: line.net, vat, vatInTaxCurrency })
    }
  }

  if (Object.keys(errors).length > 0 || values.grossTotal === null || values.vatTotal === null) {
    return { ok: false, errors }
  }
  return {
    ok: true,
    body: {
      sellerName,
      sellerTaxId: values.sellerTaxId.trim() || null,
      invoiceNumber: values.invoiceNumber.trim() || null,
      issueDate: values.issueDate,
      kind: values.kind,
      currency,
      netTotal: values.grossTotal - values.vatTotal,
      vatTotal: values.vatTotal,
      grossTotal: values.grossTotal,
      vatLines,
      categoryId: values.categoryId,
    },
  }
}

// --- Refusals --------------------------------------------------------------------------------------

const FIELDS = [
  "sellerName",
  "sellerTaxId",
  "invoiceNumber",
  "issueDate",
  "kind",
  "currency",
  "grossTotal",
  "vatTotal",
  "categoryId",
  "vatLines",
] as const

/**
 * Turns a refused write into errors on the form's fields. A 422's violations name VAT lines by index
 * (`vatLines[1].vat`), which `sent` (what was sent) maps back to a rate; the net total is not a field
 * of its own, so its violations show on the gross total. Domain refusals (a rate the country does not
 * have, VAT at a 0% rate) and other statuses come with a message only.
 */
export function expenseFormErrors(error: unknown, sent: Pick<ExpenseBody, "vatLines">): ExpenseFormErrors {
  if (!(error instanceof ApiError)) {
    return { form: error instanceof Error ? error.message : "Something went wrong. Try again." }
  }
  const violations = violationsOf(error)
  if (Object.keys(violations).length === 0) return { form: error.message }

  const errors: ExpenseFormErrors = {}
  for (const [path, message] of Object.entries(violations)) {
    const field = fieldOf(path, sent)
    errors[field] = errors[field] ? `${errors[field]} ${message}` : message
  }
  return errors
}

function fieldOf(path: string, sent: Pick<ExpenseBody, "vatLines">): string {
  const line = /^vatLines\[(\d+)\](?:\.(\w+))?$/.exec(path)
  if (line) {
    const rate = sent.vatLines[Number(line[1])]?.rate
    const part = line[2] === "vat" || line[2] === "vatInTaxCurrency" ? line[2] : "net"
    return rate ? `vat.${rate}.${part}` : "vatLines"
  }
  if (path === "netTotal") return "grossTotal"
  return (FIELDS as readonly string[]).includes(path) ? path : "form"
}
