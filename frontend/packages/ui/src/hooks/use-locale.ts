import { createContext, use } from "react"

/**
 * The locale in which numbers, money, dates and times are written (BCP 47, e.g. "pl-PL").
 *
 * It follows the UI language (ADR 0028 §7): an app sets it once at its root from the language it
 * shows, `<LocaleContext value={formattingLocale(language)}>` (`i18n/languages`: "en" → "en-GB",
 * "pl" → "pl-PL"), and every formatter follows. Until an app does, the browser's locale is used.
 */
export const LocaleContext = createContext<string | null>(null)

/** The browser's locale, as `Intl` resolves it. */
export function browserLocale(): string {
  return new Intl.NumberFormat().resolvedOptions().locale
}

/** The locale from `<LocaleContext>`, or the browser's. */
export function useLocale(): string {
  return use(LocaleContext) ?? browserLocale()
}
