import { describe, expect, it } from "vitest"

import { i18n } from "../i18n/i18n"

import {
  currencyFractionDigits,
  currencySymbol,
  formatMoney,
  formatMoneyAmount,
  moneyErrorMessage,
  parseMoney,
} from "./money"

// Intl writes some amounts with no-break spaces (U+00A0, U+202F); compare them as plain spaces.
const plain = (text: string) => text.replace(/[  ]/g, " ")

describe("currencyFractionDigits", () => {
  it("test_it_knows_how_many_decimals_a_currency_has", () => {
    expect(currencyFractionDigits("PLN")).toBe(2)
    expect(currencyFractionDigits("EUR")).toBe(2)
    expect(currencyFractionDigits("JPY")).toBe(0)
    expect(currencyFractionDigits("KWD")).toBe(3)
  })

  it("test_it_refuses_an_unknown_currency_code", () => {
    expect(() => currencyFractionDigits("ZZZZ")).toThrow(RangeError)
  })
})

describe("formatMoney", () => {
  it("test_it_formats_minor_units_in_the_locales_style", () => {
    expect(plain(formatMoney(123450, "PLN", "pl-PL"))).toBe("1234,50 zł")
    expect(plain(formatMoney(1234550, "PLN", "pl-PL"))).toBe("12 345,50 zł")
    expect(plain(formatMoney(123450, "PLN", "en-US"))).toBe("PLN 1,234.50")
    expect(plain(formatMoney(123450, "EUR", "de-DE"))).toBe("1.234,50 €")
  })

  it("test_it_formats_zero_and_negative_amounts", () => {
    expect(plain(formatMoney(0, "PLN", "pl-PL"))).toBe("0,00 zł")
    expect(plain(formatMoney(-5, "PLN", "pl-PL"))).toBe("-0,05 zł")
    expect(formatMoney(-123450, "EUR", "en-US")).toBe("-€1,234.50")
  })

  it("test_it_formats_currencies_without_and_with_three_decimals", () => {
    expect(formatMoney(1250, "JPY", "en-US")).toBe("¥1,250")
    expect(plain(formatMoney(1250, "KWD", "en-US"))).toBe("KWD 1.250")
    expect(plain(formatMoney(-1, "KWD", "en-US"))).toBe("-KWD 0.001")
  })

  it("test_it_formats_amounts_beyond_2_to_the_53_exactly_as_bigint", () => {
    expect(plain(formatMoney(2n ** 63n - 1n, "PLN", "en-US"))).toBe("PLN 92,233,720,368,547,758.07")
    expect(plain(formatMoney(Number.MAX_SAFE_INTEGER, "PLN", "en-US"))).toBe("PLN 90,071,992,547,409.91")
  })

  it("test_it_refuses_a_fraction_or_an_unsafe_number", () => {
    expect(() => formatMoney(12.5, "PLN", "en-US")).toThrow(RangeError)
    expect(() => formatMoney(2 ** 53, "PLN", "en-US")).toThrow(RangeError)
  })
})

describe("formatMoneyAmount", () => {
  it("test_it_formats_the_amount_without_the_currency", () => {
    expect(formatMoneyAmount(123450, "PLN", "pl-PL")).toBe("1234,50")
    expect(formatMoneyAmount(123450, "PLN", "en-US")).toBe("1,234.50")
    expect(formatMoneyAmount(1250, "JPY", "ja-JP")).toBe("1,250")
    expect(formatMoneyAmount(0, "PLN", "en-US")).toBe("0.00")
  })
})

describe("currencySymbol", () => {
  it("test_it_returns_the_symbol_and_its_side_for_the_locale", () => {
    expect(currencySymbol("PLN", "pl-PL")).toEqual({ symbol: "zł", before: false })
    expect(currencySymbol("PLN", "en-US")).toEqual({ symbol: "PLN", before: true })
    expect(currencySymbol("USD", "en-US")).toEqual({ symbol: "$", before: true })
  })
})

describe("parseMoney", () => {
  it("test_it_reads_a_comma_or_a_dot_as_the_decimal_separator_in_any_locale", () => {
    for (const locale of ["pl-PL", "en-US", "de-DE"]) {
      expect(parseMoney("12,50", "PLN", locale)).toEqual({ ok: true, minor: 1250 })
      expect(parseMoney("12.50", "PLN", locale)).toEqual({ ok: true, minor: 1250 })
      expect(parseMoney("12,5", "PLN", locale)).toEqual({ ok: true, minor: 1250 })
      expect(parseMoney("0.05", "PLN", locale)).toEqual({ ok: true, minor: 5 })
    }
  })

  it("test_it_reads_whole_amounts_and_partial_input_while_typing", () => {
    expect(parseMoney("12", "PLN", "pl-PL")).toEqual({ ok: true, minor: 1200 })
    expect(parseMoney("12,", "PLN", "pl-PL")).toEqual({ ok: true, minor: 1200 })
    expect(parseMoney(",5", "PLN", "pl-PL")).toEqual({ ok: true, minor: 50 })
    expect(parseMoney("  7.00 ", "PLN", "en-US")).toEqual({ ok: true, minor: 700 })
  })

  it("test_it_returns_null_for_an_empty_text", () => {
    expect(parseMoney("", "PLN", "pl-PL")).toEqual({ ok: true, minor: null })
    expect(parseMoney("   ", "PLN", "pl-PL")).toEqual({ ok: true, minor: null })
  })

  it("test_it_reads_zero", () => {
    expect(parseMoney("0", "PLN", "pl-PL")).toEqual({ ok: true, minor: 0 })
    expect(parseMoney("0,00", "PLN", "pl-PL")).toEqual({ ok: true, minor: 0 })
    expect(parseMoney("-0", "PLN", "pl-PL", { allowNegative: true })).toEqual({ ok: true, minor: 0 })
  })

  it("test_it_reads_negatives_only_when_allowed", () => {
    expect(parseMoney("-12,50", "PLN", "pl-PL", { allowNegative: true })).toEqual({ ok: true, minor: -1250 })
    expect(parseMoney("−12.50", "PLN", "en-US", { allowNegative: true })).toEqual({ ok: true, minor: -1250 })
    expect(parseMoney("-12,50", "PLN", "pl-PL")).toEqual({ ok: false, error: "negative" })
  })

  it("test_it_reads_grouping_separators", () => {
    expect(parseMoney("1 234,50", "PLN", "pl-PL")).toEqual({ ok: true, minor: 123450 })
    expect(parseMoney("1 234,50", "PLN", "pl-PL")).toEqual({ ok: true, minor: 123450 })
    expect(parseMoney("1.234,50", "PLN", "pl-PL")).toEqual({ ok: true, minor: 123450 })
    expect(parseMoney("1,234.50", "PLN", "en-US")).toEqual({ ok: true, minor: 123450 })
    expect(parseMoney("1,234,567", "PLN", "en-US")).toEqual({ ok: true, minor: 123456700 })
    expect(parseMoney("1.234.567,89", "EUR", "de-DE")).toEqual({ ok: true, minor: 123456789 })
    expect(parseMoney("1’234.50", "CHF", "de-CH")).toEqual({ ok: true, minor: 123450 })
  })

  it("test_it_reads_a_single_locale_grouping_separator_before_three_digits_as_grouping", () => {
    expect(parseMoney("1,234", "PLN", "en-US")).toEqual({ ok: true, minor: 123400 })
    expect(parseMoney("1.234", "EUR", "de-DE")).toEqual({ ok: true, minor: 123400 })
    // In pl-PL the grouping separator is a space, so "1,234" is 1.234: too many decimals for PLN.
    expect(parseMoney("1,234", "PLN", "pl-PL")).toEqual({ ok: false, error: "too-many-decimals" })
    expect(parseMoney("1.234", "PLN", "pl-PL")).toEqual({ ok: false, error: "too-many-decimals" })
  })

  it("test_it_rejects_more_decimals_than_the_currency_has", () => {
    expect(parseMoney("12,505", "PLN", "pl-PL")).toEqual({ ok: false, error: "too-many-decimals" })
    expect(parseMoney("12.5", "JPY", "ja-JP")).toEqual({ ok: false, error: "too-many-decimals" })
    expect(parseMoney("1.2345", "KWD", "en-US")).toEqual({ ok: false, error: "too-many-decimals" })
  })

  it("test_it_reads_currencies_without_and_with_three_decimals", () => {
    expect(parseMoney("1250", "JPY", "ja-JP")).toEqual({ ok: true, minor: 1250 })
    expect(parseMoney("1,250", "JPY", "ja-JP")).toEqual({ ok: true, minor: 1250 })
    expect(parseMoney("1.250", "KWD", "en-US")).toEqual({ ok: true, minor: 1250 })
    expect(parseMoney("1,25", "KWD", "pl-PL")).toEqual({ ok: true, minor: 1250 })
  })

  it("test_it_rejects_text_that_is_not_an_amount", () => {
    for (const text of ["abc", "12a", "-", ".", "1,,234", "1,2.3,4", "12 zł", "1e5", "+-1"]) {
      expect(parseMoney(text, "PLN", "pl-PL"), text).toEqual({ ok: false, error: "invalid" })
    }
  })

  it("test_it_rejects_amounts_beyond_the_largest_safe_integer", () => {
    expect(parseMoney("90071992547409,91", "PLN", "pl-PL")).toEqual({ ok: true, minor: Number.MAX_SAFE_INTEGER })
    expect(parseMoney("90071992547409,92", "PLN", "pl-PL")).toEqual({ ok: false, error: "too-large" })
    expect(parseMoney("-90071992547409,92", "PLN", "pl-PL", { allowNegative: true })).toEqual({
      ok: false,
      error: "too-large",
    })
  })

  it("test_it_reads_back_what_formatMoneyAmount_writes", () => {
    const cases: Array<[string, string]> = [
      ["PLN", "pl-PL"],
      ["PLN", "en-US"],
      ["EUR", "de-DE"],
      ["EUR", "fr-FR"],
      ["CHF", "de-CH"],
      ["JPY", "ja-JP"],
      ["KWD", "en-US"],
    ]
    const amounts = [0, 1, 5, 99, 100, 1250, 123450, -987654321, 1_000_000_000_00, Number.MAX_SAFE_INTEGER]
    for (const [currency, locale] of cases) {
      for (const minor of amounts) {
        const text = formatMoneyAmount(minor, currency, locale)
        expect(parseMoney(text, currency, locale, { allowNegative: true }), `${text} ${currency} ${locale}`).toEqual({
          ok: true,
          minor,
        })
      }
    }
  })
})

describe("moneyErrorMessage", () => {
  it("test_it_explains_the_decimal_limit_of_the_currency", () => {
    expect(moneyErrorMessage("too-many-decimals", "PLN")).toBe("PLN amounts have at most 2 decimal places.")
    expect(moneyErrorMessage("too-many-decimals", "JPY")).toBe("JPY amounts have no decimal places.")
    expect(moneyErrorMessage("invalid", "PLN")).toBe("Enter an amount, e.g. 12.50.")
  })

  it("test_it_explains_the_decimal_limit_in_polish", async () => {
    await i18n.changeLanguage("pl")
    try {
      expect(moneyErrorMessage("too-many-decimals", "PLN")).toBe("Kwoty w PLN mają najwyżej 2 miejsca po przecinku.")
      expect(moneyErrorMessage("too-many-decimals", "KWD")).toBe("Kwoty w KWD mają najwyżej 3 miejsca po przecinku.")
      expect(moneyErrorMessage("too-many-decimals", "JPY")).toBe("Kwoty w JPY nie mają części dziesiętnej.")
    } finally {
      await i18n.changeLanguage("en")
    }
  })
})
