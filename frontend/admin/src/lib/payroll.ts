import { type components, violationsOf } from "@business-tool/api-client"
import {
  type BusinessDate,
  type BusinessPeriod,
  addDays,
  compareBusinessDates,
  isBusinessDate,
  monthOf,
} from "@business-tool/ui/lib/date-time"
import type { MinorUnits } from "@business-tool/ui/lib/money"
import { type RequestErrorOptions, requestErrorMessage } from "@business-tool/ui/lib/request-error"

import { i18n } from "../i18n"
import { type Member, fullName } from "./members"

/*
 * Payroll v1 (ADR 0027): hourly and day rates per member, and one run per calendar month that is
 * generated as a draft, reviewed, approved by an owner (which locks the month's shifts) and paid.
 * The API stays the source of truth: everything below only decides what the page offers and how it
 * reads. Its 409s and 422s still reach the user.
 */

export type PayrollRun = components["schemas"]["PayrollRun"]
export type PayrollRunSummary = components["schemas"]["PayrollRunSummary"]
export type Paycheck = components["schemas"]["PayrollRunPaycheck"]
export type PaycheckLine = components["schemas"]["PayrollRunPaycheckLine"]
export type PaycheckAdjustment = components["schemas"]["PayrollRunAdjustment"]
export type HourlyRate = components["schemas"]["HourlyRate"]
export type DayRate = components["schemas"]["DayRate"]
export type RoundingUnit = PayrollRun["roundingUnit"]
export type RoundingDirection = PayrollRun["roundingDirection"]
export type PaymentMethod = components["schemas"]["PayrollRun.MarkPaycheckPaidInput"]["method"]

/** The longest adjustment label the API takes (PaycheckAdjustment::MAX_LABEL_LENGTH). */
export const ADJUSTMENT_LABEL_MAX_LENGTH = 100

// --- The month in the URL --------------------------------------------------------------------------

/** A calendar month, "YYYY-MM", as the API's `month` fields and the page's `?month=`. */
export type Month = string

const MONTH = /^(\d{4})-(0[1-9]|1[0-2])$/

export interface PayrollSearch {
  month?: Month
}

/**
 * The Payroll page's `?month=2026-10`. Without one it shows last month: the one to settle once a
 * month is over. An invalid month is `undefined` rather than left out: the router merges the raw
 * search params under the validated ones.
 */
export function validatePayrollSearch(search: Record<string, unknown>): PayrollSearch {
  const { month } = search
  return { month: typeof month === "string" && MONTH.test(month) ? month : undefined }
}

/** "2026-10" for any period starting in October 2026. */
export function monthKey(period: BusinessPeriod): Month {
  return period.from.slice(0, 7)
}

/** The month before the one `thisMonth` is: the page's default. */
export function previousMonth(thisMonth: BusinessPeriod): BusinessPeriod {
  return monthOf(addDays(thisMonth.from, -1))
}

/**
 * The month to show: the one in the URL, or last month. A month after this one is refused by the
 * API (422), so it falls back as well.
 */
export function payrollMonth(month: Month | undefined, thisMonth: BusinessPeriod): BusinessPeriod {
  if (month) {
    const period = monthOf(`${month}-01`)
    if (compareBusinessDates(period.from, thisMonth.from) <= 0) return period
  }
  return previousMonth(thisMonth)
}

// --- Run state -------------------------------------------------------------------------------------

/** What the page says about a month: no run yet, a draft, approved, or (part) paid (ADR 0027 §9). */
export type RunState = "none" | "draft" | "approved" | "partlyPaid" | "paid"

export function runState(run: Pick<PayrollRunSummary, "status" | "paid" | "paidPaychecks"> | null | undefined): RunState {
  if (!run) return "none"
  if (run.status === "draft") return "draft"
  if (run.paid) return "paid"
  return run.paidPaychecks > 0 ? "partlyPaid" : "approved"
}

/** "Draft", "Approved", …: a month's state as a badge. */
export function runStateLabel(state: RunState): string {
  return i18n.t(`payroll.state.${state}`, { ns: "admin" })
}

/** The run of a month among the list's summaries, or null. */
export function runOfMonth(runs: readonly PayrollRunSummary[], month: Month): PayrollRunSummary | null {
  return runs.find((run) => run.month === month) ?? null
}

// --- Rounding --------------------------------------------------------------------------------------

/** The rounding options, in the order the form offers them; `roundingUnitLabel()` and `roundingDirectionLabel()` name them. */
export const ROUNDING_UNITS: readonly RoundingUnit[] = ["none", "15", "30", "60"]
export const ROUNDING_DIRECTIONS: readonly RoundingDirection[] = ["nearest", "up", "down"]

const UNIT_KEYS = { none: "none", "15": "minutes15", "30": "minutes30", "60": "hour" } as const satisfies Record<RoundingUnit, string>

export function roundingUnitLabel(unit: RoundingUnit): string {
  return i18n.t(`payroll.rounding.unit.${UNIT_KEYS[unit]}`, { ns: "admin" })
}

export function roundingDirectionLabel(direction: RoundingDirection): string {
  return i18n.t(`payroll.rounding.direction.${direction}`, { ns: "admin" })
}

/**
 * "Exact time", or "Each shift to the nearest 15 minutes" / "Each shift rounded up to 1 hour". One
 * text per unit and direction, because Polish declines the length differently after each.
 */
export function roundingLabel(unit: RoundingUnit, direction: RoundingDirection): string {
  if (unit === "none") return roundingUnitLabel("none")
  return i18n.t(`payroll.rounding.summary.${direction}.${UNIT_KEYS[unit]}`, { ns: "admin" })
}

// --- Approval --------------------------------------------------------------------------------------

/**
 * Why the API would refuse to approve the draft, as far as the run shows it (ADR 0027 §7), so the
 * page says it before anyone clicks. The API checks again, including whether the draft is still up
 * to date with the shifts and rates, and its 409 names the reason.
 */
export function approvalBlockers(run: PayrollRun, today: BusinessDate): string[] {
  if (run.status !== "draft") return []
  const blockers: string[] = []
  if (compareBusinessDates(run.lastDate, today) >= 0) {
    blockers.push(i18n.t("payroll.blocker.monthNotOver", { ns: "admin" }))
  }
  if (run.openShifts > 0) {
    blockers.push(i18n.t("payroll.blocker.openShifts", { ns: "admin", count: run.openShifts }))
  }
  if (run.pendingChangeRequests > 0) {
    blockers.push(i18n.t("payroll.blocker.pendingRequests", { ns: "admin", count: run.pendingChangeRequests }))
  }
  if (run.linesWithoutRate > 0) {
    blockers.push(i18n.t("payroll.blocker.linesWithoutRate", { ns: "admin", count: run.linesWithoutRate }))
  }
  const negative = run.paychecks.filter((paycheck) => paycheck.total < 0).length
  if (negative > 0) {
    blockers.push(i18n.t("payroll.blocker.negativeTotals", { ns: "admin", count: negative }))
  }
  if (run.paychecks.length === 0) blockers.push(i18n.t("payroll.blocker.noPaychecks", { ns: "admin" }))
  return blockers
}

// --- Paychecks -------------------------------------------------------------------------------------

export interface PaycheckRow {
  paycheck: Paycheck
  name: string
  /** A member who no longer has access; their paycheck stays (ADR 0014). */
  deactivated: boolean
}

/** Paychecks with the members' names, by name; a user missing from the members list reads "Former member". */
export function paycheckRows(run: PayrollRun, members: readonly Member[]): PaycheckRow[] {
  return run.paychecks
    .map((paycheck) => {
      const member = members.find((m) => m.userId === paycheck.userId)
      return {
        paycheck,
        name: member ? fullName(member) : i18n.t("payroll.formerMember", { ns: "admin" }),
        deactivated: member ? member.deactivatedAt !== null : true,
      }
    })
    .sort((a, b) => a.name.localeCompare(b.name))
}

/** Whole minutes of a length the API gives in seconds (for `formatDuration`). */
export function minutesOf(seconds: number): number {
  return Math.floor(seconds / 60)
}

/** The sum of the shift lines' display amounts, which may differ from payForHours by a few minor units. */
export function linesAmount(paycheck: Paycheck): MinorUnits {
  return paycheck.lines.reduce((sum, line) => sum + (line.amount ?? 0), 0)
}

// --- Rates -----------------------------------------------------------------------------------------

/**
 * The periods an approved run locks (ADR 0027 §8): the months of the approved runs. Rates on those
 * dates cannot change (409), so the page shows them read-only.
 */
export function lockedPeriods(runs: readonly PayrollRunSummary[]): BusinessPeriod[] {
  return runs.filter((run) => run.status === "approved").map((run) => monthOf(`${run.month}-01`))
}

function overlaps(from: BusinessDate, to: BusinessDate | null, locked: readonly BusinessPeriod[]): boolean {
  return locked.some(
    (period) =>
      compareBusinessDates(from, period.to) <= 0 && (to === null || compareBusinessDates(to, period.from) >= 0),
  )
}

/** An hourly rate is locked when any date it is in effect on is in an approved month. */
export function isHourlyRateLocked(rate: HourlyRate, locked: readonly BusinessPeriod[]): boolean {
  return overlaps(rate.effectiveFrom, rate.effectiveTo, locked)
}

export function isDayRateLocked(rate: DayRate, locked: readonly BusinessPeriod[]): boolean {
  return overlaps(rate.date, rate.date, locked)
}

/** The hourly rate in effect on a date, from a member's rates (any order). */
export function hourlyRateOn(rates: readonly HourlyRate[], date: BusinessDate): HourlyRate | null {
  return (
    rates.find(
      (rate) =>
        compareBusinessDates(rate.effectiveFrom, date) <= 0 &&
        (rate.effectiveTo === null || compareBusinessDates(date, rate.effectiveTo) <= 0),
    ) ?? null
  )
}

export interface MemberRates {
  member: Member
  /** Newest first, as the API lists them. */
  hourly: HourlyRate[]
  /** Newest first. */
  day: DayRate[]
}

/**
 * One entry per member: active ones, then deactivated ones that have a rate (their history stays,
 * ADR 0014), each by name.
 */
export function ratesByMember(
  members: readonly Member[],
  hourly: readonly HourlyRate[],
  day: readonly DayRate[],
): MemberRates[] {
  return members
    .map((member) => ({
      member,
      hourly: hourly.filter((rate) => rate.userId === member.userId),
      day: day.filter((rate) => rate.userId === member.userId),
    }))
    .filter((entry) => entry.member.deactivatedAt === null || entry.hourly.length > 0 || entry.day.length > 0)
    .sort((a, b) => {
      const active = Number(a.member.deactivatedAt !== null) - Number(b.member.deactivatedAt !== null)
      return active !== 0 ? active : fullName(a.member).localeCompare(fullName(b.member))
    })
}

// --- Forms -----------------------------------------------------------------------------------------

/** Errors keyed by field, and `form` for anything that names no field. */
export type FormErrors = Record<string, string>

export interface RateFormValues {
  date: string
  amount: MinorUnits | null
}

/** The errors with one field's message replaced, or removed when `message` is null. */
export function withFieldError(errors: FormErrors, field: string, message: string | null): FormErrors {
  const next = { ...errors }
  delete next[field]
  if (message !== null) next[field] = message
  return next
}

/** What the API would refuse anyway: a date and an amount above zero. */
export function rateFormErrors(values: RateFormValues): FormErrors {
  const errors: FormErrors = {}
  if (!isBusinessDate(values.date)) errors.date = i18n.t("payroll.error.date", { ns: "admin" })
  if (values.amount === null) errors.amount = i18n.t("payroll.error.rateAmount", { ns: "admin" })
  else if (values.amount <= 0) errors.amount = i18n.t("payroll.error.rateNotPositive", { ns: "admin" })
  return errors
}

export interface AdjustmentFormValues {
  userId: string
  label: string
  amount: MinorUnits | null
}

export function adjustmentFormErrors(values: AdjustmentFormValues): FormErrors {
  const errors: FormErrors = {}
  if (values.userId === "") errors.userId = i18n.t("payroll.error.person", { ns: "admin" })
  const label = values.label.trim()
  if (label === "") errors.label = i18n.t("payroll.error.labelMissing", { ns: "admin" })
  else if (label.length > ADJUSTMENT_LABEL_MAX_LENGTH) errors.label = i18n.t("payroll.error.labelTooLong", { ns: "admin", count: ADJUSTMENT_LABEL_MAX_LENGTH })
  if (values.amount === null) errors.amount = i18n.t("payroll.error.adjustmentAmount", { ns: "admin" })
  else if (values.amount === 0) errors.amount = i18n.t("payroll.error.adjustmentZero", { ns: "admin" })
  return errors
}

export interface PaymentFormValues {
  paidOn: string
  method: PaymentMethod | ""
}

export function paymentFormErrors(values: PaymentFormValues, today: BusinessDate): FormErrors {
  const errors: FormErrors = {}
  if (!isBusinessDate(values.paidOn)) errors.paidOn = i18n.t("payroll.error.paidOn", { ns: "admin" })
  else if (compareBusinessDates(values.paidOn, today) > 0) errors.paidOn = i18n.t("payroll.error.futureDate", { ns: "admin" })
  if (values.method === "") errors.method = i18n.t("payroll.error.method", { ns: "admin" })
  return errors
}

/** How a paycheck was paid, in the order the form offers them; `paymentMethodLabel()` names them. */
export const PAYMENT_METHODS: readonly PaymentMethod[] = ["cash", "transfer"]

export function paymentMethodLabel(method: PaymentMethod): string {
  return i18n.t(`payroll.paymentMethod.${method}`, { ns: "admin" })
}

/**
 * A refused write as form errors: a 422's violations on the fields (`fields` maps the API's
 * property paths to the form's), anything else (a 409's reason, a domain 422) on the form, translated
 * from its code (`options.names` puts people's names in).
 */
export function apiFormErrors(
  error: unknown,
  fields: Record<string, string> = {},
  options: RequestErrorOptions = {},
): FormErrors {
  const violations = violationsOf(error)
  if (Object.keys(violations).length === 0) return { form: requestErrorMessage(error, options) }
  const errors: FormErrors = {}
  for (const [path, message] of Object.entries(violations)) {
    const field = fields[path] ?? "form"
    errors[field] = errors[field] ? `${errors[field]} ${message}` : message
  }
  return errors
}

// --- Export ----------------------------------------------------------------------------------------

export type ExportKind = "paychecks" | "shifts"

/** The name the CSV is saved under, as the API's Content-Disposition gives it. */
export function exportFileName(month: Month, kind: ExportKind): string {
  return `payroll-${month}-${kind}.csv`
}
