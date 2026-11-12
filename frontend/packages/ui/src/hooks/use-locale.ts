import { createContext, use } from "react"

/**
 * The locale in which numbers, money, dates and times are written (BCP 47, e.g. "pl-PL").
 *
 * The UI language is not decided yet (#21). Until it is, no app provides this context and the
 * browser's locale is used. When it is, an app sets it once at its root:
 * `<LocaleContext value="pl-PL">`, and every formatter follows.
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
