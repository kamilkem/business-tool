import type { ReactNode } from "react"
import { I18nextProvider } from "react-i18next"

import { useLanguage } from "@business-tool/ui/hooks/use-language"
import { LocaleContext } from "@business-tool/ui/hooks/use-locale"
import { i18n } from "@business-tool/ui/i18n/i18n"
import { formattingLocale } from "@business-tool/ui/i18n/languages"

/**
 * The root of each app (ADR 0028 §3, §7): gives components the app's i18next instance and writes
 * numbers, money and dates in the locale of the language shown (`en` → `en-GB`, `pl` → `pl-PL`).
 */
export function LanguageProvider({ children }: { children: ReactNode }) {
  return (
    <I18nextProvider i18n={i18n}>
      <FormattingLocale>{children}</FormattingLocale>
    </I18nextProvider>
  )
}

function FormattingLocale({ children }: { children: ReactNode }) {
  const language = useLanguage()
  return <LocaleContext value={formattingLocale(language)}>{children}</LocaleContext>
}
