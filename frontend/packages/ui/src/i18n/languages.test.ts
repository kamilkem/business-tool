import { describe, expect, test } from "vitest"

import { formattingLocale, resolveLanguage } from "./languages"

describe("resolveLanguage", () => {
  test("test_it_prefers_the_users_own_setting", () => {
    expect(resolveLanguage("en", ["pl-PL", "pl"])).toBe("en")
  })

  test("test_it_falls_back_to_the_first_supported_browser_language", () => {
    expect(resolveLanguage(null, ["de-DE", "pl-PL", "en-US"])).toBe("pl")
    expect(resolveLanguage("xx", ["PL"])).toBe("pl")
  })

  test("test_it_falls_back_to_english", () => {
    expect(resolveLanguage(undefined, ["de-DE", "uk"])).toBe("en")
    expect(resolveLanguage(null, [])).toBe("en")
  })
})

describe("formattingLocale", () => {
  test("test_it_formats_english_the_british_way_and_polish_the_polish_way", () => {
    const date = new Date("2026-11-21T12:00:00Z")
    const format = (language: "en" | "pl") =>
      new Intl.DateTimeFormat(formattingLocale(language), { timeZone: "Europe/Warsaw", dateStyle: "short" }).format(date)
    expect(format("en")).toBe("21/11/2026")
    expect(format("pl")).toBe("21.11.2026")
  })
})
