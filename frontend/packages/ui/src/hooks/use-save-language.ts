import { useState } from "react"
import { type ApiClient, type components, unwrap } from "@business-tool/api-client"

import { currentLanguage, showLanguage } from "../i18n/current-language"
import type { Language } from "../i18n/languages"
import { requestErrorMessage } from "../lib/request-error"

type Me = components["schemas"]["Me"]

/**
 * Changes the signed-in user's language (`PATCH /me`, ADR 0028 §2). The UI switches at once,
 * without a reload; if the API refuses or cannot be reached, it switches back and `error` says why.
 * `onSaved` gets the new `/me`, so the app can put it into its cache (the layout reads the
 * language from there on the next navigation). `save()` also resolves to the error, for callers that
 * are gone by then (a menu that closed).
 */
export function useSaveLanguage(api: ApiClient, onSaved: (me: Me) => void) {
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState<string | null>(null)

  async function save(language: Language): Promise<string | null> {
    const previous = currentLanguage()
    setError(null)
    setSaving(true)
    showLanguage(language)
    try {
      const me = await unwrap(
        api.PATCH("/api/me", {
          body: { language },
          headers: { "Content-Type": "application/merge-patch+json" },
        }),
      )
      onSaved(me)
      return null
    } catch (failure) {
      showLanguage(previous)
      const message = requestErrorMessage(failure)
      setError(message)
      return message
    } finally {
      setSaving(false)
    }
  }

  return { save, saving, error }
}
