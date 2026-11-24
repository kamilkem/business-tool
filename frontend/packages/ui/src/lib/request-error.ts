import { ApiError, type ErrorParameters, violationsOf } from "@business-tool/api-client"

import { currentLanguage } from "../i18n/current-language"
import { i18n } from "../i18n/i18n"
import { formattingLocale } from "../i18n/languages"
import { browserTimeZone, createDateTimeFormat, type DateTimeFormat, monthPeriod } from "./date-time"

/** "Wait 3 minutes" from a 429's `Retry-After`, or a vague wait when the API sent none. */
export function tooManyAttemptsMessage(retryAfterSeconds: number | null): string {
  if (retryAfterSeconds === null) return i18n.t("requestError.tooManyAttempts")
  if (retryAfterSeconds < 60) return i18n.t("requestError.tooManyAttemptsMinute")
  return i18n.t("requestError.tooManyAttemptsMinutes", { count: Math.ceil(retryAfterSeconds / 60) })
}

export interface RequestErrorOptions {
  /** Ids the reader knows by name: user ids to names ("Anna Nowak"). Unknown ids read "this person". */
  names?: Readonly<Record<string, string>>
  /** The signed-in user's id: an error about them says "you" where the catalog has a `_self` text. */
  selfId?: string
  /**
   * Formats instants in the organization's time zone (`useDateTimeFormat()`). Without it, the
   * browser's zone and the language's locale; business dates and months need no zone.
   */
  format?: DateTimeFormat
}

/**
 * The message for a failed request (ADR 0028 §5):
 * - a domain error's `code`, translated from `apiError.<code>` with its parameters (dates and times
 *   formatted, user ids as names);
 * - else the rate limit, a server error, a 422's first violation (translated by the API), or a
 *   message for the status;
 * - no connection at all.
 * The API's English `detail` is never shown.
 */
export function requestErrorMessage(error: unknown, options: RequestErrorOptions = {}): string {
  if (!(error instanceof ApiError)) return i18n.t("requestError.noConnection")
  const coded = codeMessage(error, options)
  if (coded !== null) return coded
  if (error.status === 429) return tooManyAttemptsMessage(error.retryAfter)
  if (error.status >= 500) return i18n.t("requestError.serverError")
  const violation = Object.values(violationsOf(error))[0]
  if (violation) return violation
  return statusMessage(error.status)
}

/** A refused sign-in: wrong credentials (401), else as any request. */
export function signInErrorMessage(error: unknown): string {
  if (error instanceof ApiError && error.status === 401) return i18n.t("requestError.wrongCredentials")
  return requestErrorMessage(error)
}

function statusMessage(status: number): string {
  switch (status) {
    case 400:
      return i18n.t("requestError.byStatus.400")
    case 401:
      return i18n.t("requestError.byStatus.401")
    case 403:
      return i18n.t("requestError.byStatus.403")
    case 404:
      return i18n.t("requestError.byStatus.404")
    case 409:
      return i18n.t("requestError.byStatus.409")
    case 422:
      return i18n.t("requestError.byStatus.422")
    default:
      return i18n.t("requestError.byStatus.other")
  }
}

// Parameters by meaning (the API's names, ADR 0028 §5), formatted before they go into the text.
const BUSINESS_DATES = new Set(["date", "from", "to", "today", "businessDate", "lockedFrom", "lockedTo", "lastClosedDate", "startDate", "earliest"])
const INSTANTS = new Set(["startedAt", "endedAt", "otherStartedAt", "otherEndedAt", "time", "now", "retryAt", "occurredAt", "at", "closedAt"])
const MONTHS = new Set(["month"])

/**
 * A variant of the text chosen from the parameters: `<code>_<context>` in the catalog, else the
 * code's own text. A null end is "still open" or "onwards", not a date.
 */
const CONTEXTS: Record<string, (parameters: ErrorParameters) => string | undefined> = {
  "time_tracking.shift_overlap": (p) => (p.otherEndedAt === null ? "open" : undefined),
  "payroll.pay_rate_period_locked": (p) => (p.to === null ? "onwards" : undefined),
}

/** The parameter that picks the plural form, where the API names it otherwise. */
const COUNTS: Record<string, string> = {
  "ksef.import_too_soon": "minutes",
}

function codeMessage(error: ApiError, options: RequestErrorOptions): string | null {
  const { code, parameters } = error
  const key = `apiError.${code}`
  // A plural text exists as `<key>_one`, `<key>_other`, …
  if (code === null || !(i18n.exists(key) || i18n.exists(`${key}_other`))) return null

  const format = options.format ?? createDateTimeFormat(browserTimeZone(), formattingLocale(currentLanguage()))
  const values: Record<string, unknown> = {}
  for (const [name, value] of Object.entries(parameters)) values[name] = formatted(name, value, format)

  const userId = parameters.userId
  let context = CONTEXTS[code]?.(parameters)
  if (typeof userId === "string") {
    values.user = options.names?.[userId] ?? i18n.t("requestError.someone")
    if (userId === options.selfId && i18n.exists(`${key}_self`)) context = "self"
  }
  const count = COUNTS[code]
  if (count !== undefined) values.count = parameters[count]

  // The key is dynamic (any code the API sends), so the typed-key check is the catalog test's job.
  return (i18n.t as (key: string, options: Record<string, unknown>) => string)(key, { ...values, context })
}

function formatted(name: string, value: ErrorParameters[string], format: DateTimeFormat): unknown {
  if (typeof value !== "string") return value
  try {
    if (BUSINESS_DATES.has(name) && /^\d{4}-\d{2}-\d{2}$/.test(value)) return format.businessDate(value)
    if (MONTHS.has(name) && /^\d{4}-\d{2}$/.test(value)) {
      return format.period(monthPeriod(Number(value.slice(0, 4)), Number(value.slice(5, 7))))
    }
    if (INSTANTS.has(name) && !Number.isNaN(Date.parse(value))) return format.dateTime(value)
  } catch {
    // A value that does not format (a date that is not one) is shown as the API sent it.
  }
  return value
}
