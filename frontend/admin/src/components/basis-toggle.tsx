import { useTranslation } from "react-i18next"

import { type Basis } from "@/lib/purchase-analysis"

/** Net or gross: two buttons of one group, as ExpensesNav's links are. Shared by the overview and the product page. */
export function BasisToggle({ basis, onChange }: { basis: Basis; onChange: (basis: Basis) => void }) {
  const { t } = useTranslation("admin")
  const options: Basis[] = ["net", "gross"]
  return (
    <div role="group" aria-label={t("analysis.basis.label")} className="inline-flex gap-1 rounded-lg bg-muted p-1">
      {options.map((option) => (
        <button
          key={option}
          type="button"
          aria-pressed={basis === option}
          onClick={() => onChange(option)}
          className="rounded-md px-3 py-1.5 text-sm font-medium text-muted-foreground transition-colors hover:text-foreground aria-pressed:bg-background aria-pressed:text-foreground aria-pressed:shadow-sm"
        >
          {t(`analysis.basis.${option}`)}
        </button>
      ))}
    </div>
  )
}
