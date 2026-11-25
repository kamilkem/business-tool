/**
 * Decimal strings as the API sends them for quantities and unit prices ("2.5", "0.12345678",
 * "-3"; ADR 0040): formatted in the locale's style without ever becoming a float. The string goes
 * to `Intl.NumberFormat` as it is (ES2023 formats numeric strings exactly), so "0.1" stays 0.1 and
 * "12345678901234567.25" keeps all its digits.
 *
 * Up to 8 fraction digits are shown and trailing zeros are dropped. The locale comes from
 * `useLocale()` (`hooks/use-locale`) in React code.
 */

import { currencyFractionDigits } from "./money"

/** The most fraction digits shown: enough for any unit price an invoice prints. */
export const MAX_DECIMAL_FRACTION_DIGITS = 8

const DECIMAL = /^-?\d+(\.\d+)?$/

const formats = new Map<string, Intl.NumberFormat>()

function numberFormat(locale: string | undefined, options: Intl.NumberFormatOptions): Intl.NumberFormat {
  const key = JSON.stringify([locale ?? null, options])
  let cached = formats.get(key)
  if (!cached) {
    cached = new Intl.NumberFormat(locale, options)
    formats.set(key, cached)
  }
  return cached
}

function format(decimal: string, locale: string | undefined, options: Intl.NumberFormatOptions): string {
  // Anything that is not a plain decimal string is shown as the API sent it, never guessed at.
  if (!DECIMAL.test(decimal)) return decimal
  return numberFormat(locale, options).format(decimal as `${number}`)
}

/**
 * A decimal string in the locale's style: `formatDecimal("1234.50", "pl-PL")` → "1234,5",
 * `formatDecimal("1234.50", "en-GB")` → "1,234.5".
 */
export function formatDecimal(decimal: string, locale?: string): string {
  return format(decimal, locale, { maximumFractionDigits: MAX_DECIMAL_FRACTION_DIGITS })
}

/**
 * A decimal string in a currency, with at least the currency's decimals and up to 8:
 * `formatDecimalMoney("12.5", "PLN", "pl-PL")` → "12,50 zł",
 * `formatDecimalMoney("0.12345000", "PLN", "pl-PL")` → "0,12345 zł".
 */
export function formatDecimalMoney(decimal: string, currency: string, locale?: string): string {
  const digits = currencyFractionDigits(currency)
  return format(decimal, locale, {
    style: "currency",
    currency,
    minimumFractionDigits: Math.min(digits, MAX_DECIMAL_FRACTION_DIGITS),
    maximumFractionDigits: MAX_DECIMAL_FRACTION_DIGITS,
  })
}
