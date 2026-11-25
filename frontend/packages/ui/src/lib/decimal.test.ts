import { describe, expect, it } from "vitest"

import { formatDecimal, formatDecimalMoney } from "./decimal"

// Intl writes some numbers with no-break spaces (U+00A0, U+202F); compare them as plain spaces.
const plain = (text: string) => text.replace(/[  ]/g, " ")

describe("formatDecimal", () => {
  it("test_it_uses_the_locales_separators", () => {
    expect(formatDecimal("1234.5", "en-GB")).toBe("1,234.5")
    expect(formatDecimal("1234.5", "pl-PL")).toBe("1234,5")
    expect(plain(formatDecimal("12345.5", "pl-PL"))).toBe("12 345,5")
  })

  it("test_it_keeps_up_to_eight_decimals", () => {
    expect(formatDecimal("0.12345678", "en-GB")).toBe("0.12345678")
    expect(formatDecimal("0.12345678", "pl-PL")).toBe("0,12345678")
    expect(formatDecimal("0.123456785", "en-GB")).toBe("0.12345679")
  })

  it("test_it_trims_trailing_zeros", () => {
    expect(formatDecimal("2.500", "en-GB")).toBe("2.5")
    expect(formatDecimal("3.00000000", "pl-PL")).toBe("3")
    expect(formatDecimal("10", "en-GB")).toBe("10")
  })

  it("test_it_formats_negative_quantities", () => {
    expect(formatDecimal("-2", "en-GB")).toBe("-2")
    expect(formatDecimal("-0.75", "pl-PL")).toBe("-0,75")
  })

  it("test_it_keeps_digits_beyond_what_a_float_holds", () => {
    // As a float, 12345678901234567.25 is 12345678901234568.
    expect(formatDecimal("12345678901234567.25", "en-GB")).toBe("12,345,678,901,234,567.25")
    // 2^53 + 1 with a tail of 8 decimals: a float drops both.
    expect(formatDecimal("9007199254740993.00000001", "en-GB")).toBe("9,007,199,254,740,993.00000001")
  })

  it("test_it_shows_anything_that_is_not_a_decimal_as_it_is", () => {
    expect(formatDecimal("1e3", "en-GB")).toBe("1e3")
    expect(formatDecimal("", "en-GB")).toBe("")
  })
})

describe("formatDecimalMoney", () => {
  it("test_it_formats_a_unit_price_in_the_currency_with_its_decimals", () => {
    expect(plain(formatDecimalMoney("12.5", "PLN", "pl-PL"))).toBe("12,50 zł")
    expect(plain(formatDecimalMoney("12.5", "PLN", "en-GB"))).toBe("PLN 12.50")
    expect(plain(formatDecimalMoney("1250", "JPY", "en-GB"))).toBe("JP¥1,250")
  })

  it("test_it_keeps_up_to_eight_decimals_and_trims_trailing_zeros", () => {
    expect(plain(formatDecimalMoney("0.12345000", "PLN", "pl-PL"))).toBe("0,12345 zł")
    expect(plain(formatDecimalMoney("0.12345678", "EUR", "en-GB"))).toBe("€0.12345678")
    expect(plain(formatDecimalMoney("4.10000000", "PLN", "pl-PL"))).toBe("4,10 zł")
  })

  it("test_it_formats_negative_prices_and_digits_beyond_what_a_float_holds", () => {
    expect(plain(formatDecimalMoney("-3.99", "PLN", "pl-PL"))).toBe("-3,99 zł")
    expect(plain(formatDecimalMoney("12345678901234567.01", "PLN", "en-GB"))).toBe("PLN 12,345,678,901,234,567.01")
  })
})
