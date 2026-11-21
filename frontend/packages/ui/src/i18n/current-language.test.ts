import { afterEach, beforeEach, describe, expect, test, vi } from "vitest"

import {
  LANGUAGE_STORAGE_KEY,
  currentLanguage,
  languageForSignedOut,
  languageForUser,
  rememberedLanguage,
  showLanguage,
} from "./current-language"
import { i18n } from "./i18n"

/** A browser with `languages`, an empty or given localStorage and an `<html>` element. */
function browser(languages: string[], stored: Record<string, string> = {}) {
  const storage = new Map(Object.entries(stored))
  vi.stubGlobal("navigator", { languages })
  vi.stubGlobal("localStorage", {
    getItem: (key: string) => storage.get(key) ?? null,
    setItem: (key: string, value: string) => void storage.set(key, value),
  })
  const html = { lang: "en" }
  vi.stubGlobal("document", { documentElement: html })
  return { storage, html }
}

describe("current language", () => {
  beforeEach(() => i18n.changeLanguage("en"))
  afterEach(() => vi.unstubAllGlobals())

  test("test_it_uses_the_users_setting_over_the_browser", () => {
    browser(["pl-PL"])
    expect(languageForUser("en")).toBe("en")
  })

  test("test_it_uses_the_browser_language_when_the_user_never_chose", () => {
    browser(["de-DE", "pl-PL"])
    expect(languageForUser(null)).toBe("pl")
  })

  test("test_it_shows_signed_out_screens_in_the_language_last_shown_on_the_device", () => {
    browser(["en-US"], { [LANGUAGE_STORAGE_KEY]: "pl" })
    expect(languageForSignedOut()).toBe("pl")
  })

  test("test_it_shows_signed_out_screens_in_the_browser_language_on_a_new_device", () => {
    browser(["pl"])
    expect(rememberedLanguage()).toBeNull()
    expect(languageForSignedOut()).toBe("pl")
  })

  test("test_it_ignores_a_stored_language_the_ui_no_longer_ships", () => {
    browser(["en-GB"], { [LANGUAGE_STORAGE_KEY]: "de" })
    expect(languageForSignedOut()).toBe("en")
  })

  test("test_it_falls_back_to_the_browser_when_storage_is_blocked", () => {
    browser(["pl-PL"])
    vi.stubGlobal("localStorage", {
      getItem: () => {
        throw new Error("SecurityError")
      },
      setItem: () => {
        throw new Error("SecurityError")
      },
    })
    expect(languageForSignedOut()).toBe("pl")
    expect(() => showLanguage("pl")).not.toThrow()
    expect(currentLanguage()).toBe("pl")
  })

  test("test_it_switches_translations_html_lang_and_remembers_the_language", () => {
    const { storage, html } = browser(["en-GB"])

    showLanguage("pl")

    expect(currentLanguage()).toBe("pl")
    expect(i18n.t("language.label")).toBe("Język")
    expect(html.lang).toBe("pl")
    expect(storage.get(LANGUAGE_STORAGE_KEY)).toBe("pl")
  })
})
