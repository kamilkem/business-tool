import { useTranslation } from "react-i18next"

import { currentLanguage } from "../i18n/current-language"
import type { Language } from "../i18n/languages"

/** The language the app shows now; re-renders when it changes (`showLanguage()`). */
export function useLanguage(): Language {
  // Subscribes to i18next's languageChanged; the value itself comes from the one instance.
  useTranslation()
  return currentLanguage()
}
