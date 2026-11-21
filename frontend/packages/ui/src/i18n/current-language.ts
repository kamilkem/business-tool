import { i18n } from "./i18n"
import { type Language, SOURCE_LANGUAGE, isLanguage, resolveLanguage } from "./languages"

/** The language this device last showed, for screens without a user (ADR 0028 §2). */
export const LANGUAGE_STORAGE_KEY = "business-tool.language"

function browserLanguages(): readonly string[] {
  return typeof navigator === "undefined" ? [] : navigator.languages
}

/** The language the app shows now. */
export function currentLanguage(): Language {
  return isLanguage(i18n.language) ? i18n.language : SOURCE_LANGUAGE
}

/** The language last shown on this device, or null (never shown, or storage unavailable). */
export function rememberedLanguage(): Language | null {
  try {
    const stored = localStorage.getItem(LANGUAGE_STORAGE_KEY)
    return isLanguage(stored) ? stored : null
  } catch {
    return null
  }
}

/**
 * The language for a signed-in user: their own setting (`/me` → `language`), else the first
 * browser language the UI ships, else English.
 */
export function languageForUser(userSetting: string | null | undefined): Language {
  return resolveLanguage(userSetting, browserLanguages())
}

/**
 * The language before anyone is signed in (sign-in, set or reset a password): the one this device
 * last showed, else the browser's, else English.
 */
export function languageForSignedOut(): Language {
  return resolveLanguage(rememberedLanguage(), browserLanguages())
}

/**
 * Shows the app in `language`: switches i18next (components re-render through `useTranslation()`
 * and `useLanguage()`), sets `<html lang>` and remembers it on this device for the next signed-out
 * screen. Nothing happens when it is already shown.
 */
export function showLanguage(language: Language): void {
  if (typeof document !== "undefined") document.documentElement.lang = language
  try {
    localStorage.setItem(LANGUAGE_STORAGE_KEY, language)
  } catch {
    // Private mode or blocked storage: the next signed-out screen falls back to the browser's.
  }
  if (i18n.language !== language) void i18n.changeLanguage(language)
}
