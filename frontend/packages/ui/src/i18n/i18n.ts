import i18next from "i18next"

import en from "../locales/en/ui.json"
import pl from "../locales/pl/ui.json"
import { LANGUAGES, SOURCE_LANGUAGE } from "./languages"

/**
 * The one i18next instance of an app (ADR 0028). `packages/ui` owns the `ui` namespace (strings of
 * shared components and helpers); each app adds its own namespace with `addResourceBundle()` before
 * rendering, and switches with `changeLanguage()` once it knows the user's language.
 *
 * Catalogs are bundled, not fetched: they are small, and `init()` with inline resources is
 * synchronous, so pure helpers such as `requestErrorMessage()` can call `i18n.t()` at any time.
 */
export const i18n = i18next.createInstance()

void i18n.init({
  resources: { en: { ui: en }, pl: { ui: pl } },
  lng: SOURCE_LANGUAGE,
  fallbackLng: SOURCE_LANGUAGE,
  supportedLngs: [...LANGUAGES],
  ns: ["ui"],
  defaultNS: "ui",
  initAsync: false,
  // React escapes text itself.
  interpolation: { escapeValue: false },
  returnNull: false,
})
