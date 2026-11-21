/**
 * Money as integer minor units plus an ISO 4217 currency code (ADR 0010): 1250 PLN is 12,50 zł,
 * 1250 JPY is ¥1,250, 1250 KWD is 1.250 KWD.
 *
 * Nothing here divides an amount into a float. Formatting splits the integer's digits into a
 * decimal string and gives that string to `Intl.NumberFormat`, which formats it exactly; parsing
 * reads the integer and fraction digits as strings and joins them into a `BigInt`. So formatting
 * works for any integer (`bigint` beyond 2^53 too), and parsing returns a `number` up to
 * `Number.MAX_SAFE_INTEGER` minor units (about 90 trillion PLN) and reports anything larger.
 *
 * A currency's number of decimals comes from `Intl` (CLDR): 2 for PLN and EUR, 0 for JPY, 3 for KWD.
 *
 * The locale decides how an amount is written ("12,50 zł" in pl-PL, "PLN 12.50" in en-US). React
 * code gets it from `useLocale()` (`hooks/use-locale`).
 */

import { i18n } from "../i18n/i18n"

/** An amount in a currency's smallest unit (grosze for PLN, yen for JPY), as the API sends it. */
export type MinorUnits = number

/** Why a typed amount cannot be read. */
export type MoneyParseError = "invalid" | "too-many-decimals" | "negative" | "too-large"

/** An empty text is `minor: null`; anything that is not an amount is an error. */
export type MoneyParseResult = { ok: true; minor: MinorUnits | null } | { ok: false; error: MoneyParseError }

export interface ParseMoneyOptions {
  /** Accept a leading minus sign ("-12,50"). Off by default: most amounts are never negative. */
  allowNegative?: boolean
}

const numberFormats = new Map<string, Intl.NumberFormat>()

/** Creating an `Intl.NumberFormat` is slow, and tables format hundreds of cells: reuse them. */
function numberFormat(locale: string | undefined, options: Intl.NumberFormatOptions): Intl.NumberFormat {
  const key = JSON.stringify([locale ?? null, options])
  let cached = numberFormats.get(key)
  if (!cached) {
    cached = new Intl.NumberFormat(locale, options)
    numberFormats.set(key, cached)
  }
  return cached
}

/** A decimal string such as "-1234.50". `Intl.NumberFormat` formats it exactly (no float). */
type DecimalString = `${number}`

declare global {
  namespace Intl {
    interface NumberFormat {
      // ES2023 (Intl.NumberFormat v3) formats numeric strings exactly; the workspace compiles
      // against the ES2022 types, which only know numbers and bigints.
      format(value: DecimalString): string
    }
  }
}

function formatDecimal(format: Intl.NumberFormat, decimal: DecimalString): string {
  return format.format(decimal)
}

/** Number of decimals of a currency: 2 for PLN, 0 for JPY, 3 for KWD. Throws for an unknown code. */
export function currencyFractionDigits(currency: string): number {
  // The digits belong to the currency, not the locale, so any locale gives the same answer.
  return numberFormat("en", { style: "currency", currency }).resolvedOptions().maximumFractionDigits ?? 2
}

/** "-1234.50" for -123450 in a 2-decimal currency; "1250" for 1250 JPY. */
function toDecimalString(minor: MinorUnits | bigint, currency: string): DecimalString {
  if (typeof minor === "number" && !Number.isSafeInteger(minor)) {
    throw new RangeError(`Money must be a safe integer of minor units, got ${minor}.`)
  }
  const digits = currencyFractionDigits(currency)
  const value = BigInt(minor)
  const negative = value < 0n
  const absolute = (negative ? -value : value).toString().padStart(digits + 1, "0")
  const integer = absolute.slice(0, absolute.length - digits)
  const fraction = absolute.slice(absolute.length - digits)
  return `${negative ? "-" : ""}${integer}${digits > 0 ? `.${fraction}` : ""}` as DecimalString
}

/**
 * An amount with its currency, in the locale's style: `formatMoney(123450, "PLN", "pl-PL")` →
 * "1234,50 zł", `formatMoney(123450, "PLN", "en-US")` → "PLN 1,234.50".
 */
export function formatMoney(minor: MinorUnits | bigint, currency: string, locale?: string): string {
  return formatDecimal(numberFormat(locale, { style: "currency", currency }), toDecimalString(minor, currency))
}

/**
 * The amount alone, with all the currency's decimals and the locale's separators, as
 * `<MoneyInput>` shows it: `formatMoneyAmount(123450, "PLN", "pl-PL")` → "1234,50". `parseMoney()`
 * reads it back.
 */
export function formatMoneyAmount(minor: MinorUnits | bigint, currency: string, locale?: string): string {
  const digits = currencyFractionDigits(currency)
  const format = numberFormat(locale, { minimumFractionDigits: digits, maximumFractionDigits: digits })
  return formatDecimal(format, toDecimalString(minor, currency))
}

/** The currency as the locale writes it next to an amount ("zł" in pl-PL, "PLN" in en-US). */
export function currencySymbol(currency: string, locale?: string): { symbol: string; before: boolean } {
  const parts = numberFormat(locale, { style: "currency", currency }).formatToParts(1)
  const index = parts.findIndex((part) => part.type === "currency")
  const number = parts.findIndex((part) => part.type === "integer")
  return { symbol: parts[index]?.value ?? currency, before: index < number }
}

/** The locale's decimal and grouping separators ("," and " " in pl-PL, "." and "," in en-US). */
function separators(locale: string | undefined): { decimal: string; group: string } {
  const parts = numberFormat(locale, { useGrouping: true }).formatToParts(12345.6)
  return {
    decimal: parts.find((part) => part.type === "decimal")?.value ?? ".",
    group: parts.find((part) => part.type === "group")?.value ?? ",",
  }
}

const MINUS = /^[-−]/
// Spaces of every kind (pl-PL and fr-FR group with no-break spaces) and the apostrophes of de-CH.
const IGNORED = /[\s'’]/g
const BODY = /^[0-9.,]*$/

/**
 * Reads a typed amount into minor units, with no floats: the integer and fraction digits are read
 * as strings. Accepts "12,50" and "12.50" in every locale, grouping ("1 234,50", "1.234,50",
 * "1,234.50"), and "12," or ",5" while the user is still typing.
 *
 * A single "." or "," is the decimal separator, except the locale's grouping separator followed by
 * exactly three digits: "1,234" is 1234 in en-US, while "1,234" in pl-PL is 1.234 (too many
 * decimals for PLN). With both, the last one is the decimal separator.
 */
export function parseMoney(
  text: string,
  currency: string,
  locale?: string,
  { allowNegative = false }: ParseMoneyOptions = {},
): MoneyParseResult {
  const trimmed = text.trim()
  if (trimmed === "") return { ok: true, minor: null }

  const negative = MINUS.test(trimmed)
  const body = (negative ? trimmed.slice(1) : trimmed).replace(IGNORED, "")
  if (!BODY.test(body) || !/[0-9]/.test(body)) return { ok: false, error: "invalid" }
  if (negative && !allowNegative) return { ok: false, error: "negative" }

  const split = splitDecimal(body, separators(locale))
  if (!split) return { ok: false, error: "invalid" }
  const [integer, fraction] = split

  const digits = currencyFractionDigits(currency)
  if (fraction.length > digits) return { ok: false, error: "too-many-decimals" }

  const absolute = BigInt(`${integer || "0"}${fraction.padEnd(digits, "0")}`)
  if (absolute > BigInt(Number.MAX_SAFE_INTEGER)) return { ok: false, error: "too-large" }
  // BigInt has no -0, so "-0,00" is 0.
  return { ok: true, minor: Number(negative ? -absolute : absolute) }
}

/** Integer digits (grouping removed) and fraction digits, or null when the separators make no sense. */
function splitDecimal(body: string, locale: { decimal: string; group: string }): [string, string] | null {
  const marks = [...body].filter((char) => char === "." || char === ",")
  if (marks.length === 0) return [body, ""]

  const last = body.search(/[.,][^.,]*$/)
  const lastMark = body.charAt(last)
  const kinds = new Set(marks)

  let decimalAt: number | null
  if (kinds.size === 2) {
    // "1.234,50" / "1,234.50": the last mark is the decimal separator and appears only once.
    decimalAt = marks.filter((mark) => mark === lastMark).length === 1 ? last : -1
  } else if (marks.length > 1) {
    // "1.234.567" / "1,234,567": grouping only.
    decimalAt = null
  } else {
    const fractionLength = body.length - last - 1
    const isGrouping = lastMark !== locale.decimal && lastMark === locale.group && fractionLength === 3
    decimalAt = isGrouping ? null : last
  }
  if (decimalAt === -1) return null

  const integerPart = decimalAt === null ? body : body.slice(0, decimalAt)
  const fraction = decimalAt === null ? "" : body.slice(decimalAt + 1)
  const groups = integerPart.split(/[.,]/)
  // Grouping marks sit between digits: "1,,234", ",234" and "1," are not amounts.
  if (groups.length > 1 && groups.some((group) => group === "")) return null
  return [groups.join(""), fraction]
}

/** A short English message for a parse error, for a `<FieldError>` under the input. */
export function moneyErrorMessage(error: MoneyParseError, currency: string): string {
  switch (error) {
    case "invalid":
      return i18n.t("money.error.invalid")
    case "negative":
      return i18n.t("money.error.negative")
    case "too-large":
      return i18n.t("money.error.tooLarge")
    case "too-many-decimals": {
      const digits = currencyFractionDigits(currency)
      return digits === 0
        ? i18n.t("money.error.noDecimals", { currency })
        : i18n.t("money.error.tooManyDecimals", { currency, count: digits })
    }
  }
}
