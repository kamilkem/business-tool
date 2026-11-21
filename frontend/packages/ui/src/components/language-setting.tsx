import type { components, ApiClient } from "@business-tool/api-client"
import { useTranslation } from "react-i18next"

import { Field, FieldDescription, FieldError, FieldLabel } from "@business-tool/ui/components/field"
import { LanguageSelect } from "@business-tool/ui/components/language-select"
import { useLanguage } from "@business-tool/ui/hooks/use-language"
import { useSaveLanguage } from "@business-tool/ui/hooks/use-save-language"
import { cn } from "@business-tool/ui/lib/utils"

export interface LanguageSettingProps {
  api: ApiClient
  /** The new `/me` after the change. */
  onSaved: (me: components["schemas"]["Me"]) => void
  /** A taller field, for the timeclock's touch screens. */
  touch?: boolean
}

/** The signed-in user's language on the account page of either app (ADR 0028 §2). */
export function LanguageSetting({ api, onSaved, touch = false }: LanguageSettingProps) {
  const { t } = useTranslation()
  const language = useLanguage()
  const { save, saving, error } = useSaveLanguage(api, onSaved)

  return (
    <Field data-invalid={error ? true : undefined}>
      <FieldLabel htmlFor="account-language">{t("language.label")}</FieldLabel>
      <LanguageSelect
        id="account-language"
        value={language}
        onValueChange={save}
        disabled={saving}
        className={cn(touch && "h-11")}
      />
      <FieldDescription>{t("language.description")}</FieldDescription>
      {error && <FieldError>{error}</FieldError>}
    </Field>
  )
}
