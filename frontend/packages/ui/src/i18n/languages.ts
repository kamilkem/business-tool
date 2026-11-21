/**
 * The UI languages (ADR 0028). English is the source language: every key is written in English
 * first, and English is the fallback for anything else.
 */
export const LANGUAGES = ["en", "pl"] as const

export type Language = (typeof LANGUAGES)[number]

export const SOURCE_LANGUAGE: Language = "en"

export function isLanguage(value: unknown): value is Language {
  return typeof value === "string" && (LANGUAGES as readonly string[]).includes(value)
}

/**
 * The language to show: the user's own setting (`/me` → `language`) when there is one, else the
 * first of the browser's languages that the UI ships ("pl-PL" → "pl"), else English.
 */
export function resolveLanguage(userSetting: string | null | undefined, browserLanguages: readonly string[]): Language {
  if (isLanguage(userSetting)) return userSetting
  for (const tag of browserLanguages) {
    const primary = tag.split("-")[0]?.toLowerCase()
    if (isLanguage(primary)) return primary
  }
  return SOURCE_LANGUAGE
}

const FORMATTING_LOCALES: Record<Language, string> = {
  // British English writes dates day first (21/11/2026) and 24-hour times, as the rest of Europe does.
  en: "en-GB",
  pl: "pl-PL",
}

/**
 * The locale numbers, money, dates and times are written in for a UI language: the one an app puts
 * into `<LocaleContext>` (`hooks/use-locale`), so formatting follows the language, not the browser.
 */
export function formattingLocale(language: Language): string {
  return FORMATTING_LOCALES[language]
}
