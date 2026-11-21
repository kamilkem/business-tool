import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@business-tool/ui/components/select"
import { LANGUAGES, LANGUAGE_NAMES, type Language } from "@business-tool/ui/i18n/languages"
import { cn } from "@business-tool/ui/lib/utils"

export interface LanguageSelectProps {
  id?: string
  value: Language
  onValueChange: (language: Language) => void
  disabled?: boolean
  className?: string
}

/** Picks a UI language; each one is listed in its own name ("English", "Polski"). */
export function LanguageSelect({ id, value, onValueChange, disabled, className }: LanguageSelectProps) {
  return (
    <Select value={value} onValueChange={(next) => onValueChange(next as Language)} disabled={disabled}>
      <SelectTrigger id={id} className={cn("w-full", className)}>
        <SelectValue />
      </SelectTrigger>
      <SelectContent>
        {LANGUAGES.map((language) => (
          <SelectItem key={language} value={language} lang={language}>
            {LANGUAGE_NAMES[language]}
          </SelectItem>
        ))}
      </SelectContent>
    </Select>
  )
}
