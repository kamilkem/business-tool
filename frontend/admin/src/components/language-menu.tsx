import { LanguagesIcon } from "lucide-react"
import { useTranslation } from "react-i18next"
import { toast } from "sonner"

import {
  DropdownMenuRadioGroup,
  DropdownMenuRadioItem,
  DropdownMenuSub,
  DropdownMenuSubContent,
  DropdownMenuSubTrigger,
} from "@business-tool/ui/components/dropdown-menu"
import { useLanguage } from "@business-tool/ui/hooks/use-language"
import { useSaveLanguage } from "@business-tool/ui/hooks/use-save-language"
import { LANGUAGES, LANGUAGE_NAMES, isLanguage } from "@business-tool/ui/i18n/languages"

import { api } from "@/lib/api"
import { useMeSaved } from "@/lib/use-me-saved"

/** The language submenu of the account menu (ADR 0028 §2): saved to the user, switched at once. */
export function LanguageMenu() {
  const { t } = useTranslation()
  const language = useLanguage()
  const { save } = useSaveLanguage(api, useMeSaved())

  async function choose(value: string) {
    if (!isLanguage(value) || value === language) return
    const failure = await save(value)
    if (failure) toast.error(failure)
  }

  return (
    <DropdownMenuSub>
      <DropdownMenuSubTrigger>
        <LanguagesIcon />
        {t("language.label")}
        <span className="ml-auto text-xs text-muted-foreground">{LANGUAGE_NAMES[language]}</span>
      </DropdownMenuSubTrigger>
      <DropdownMenuSubContent>
        <DropdownMenuRadioGroup value={language} onValueChange={choose}>
          {LANGUAGES.map((option) => (
            <DropdownMenuRadioItem key={option} value={option} lang={option}>
              {LANGUAGE_NAMES[option]}
            </DropdownMenuRadioItem>
          ))}
        </DropdownMenuRadioGroup>
      </DropdownMenuSubContent>
    </DropdownMenuSub>
  )
}
