import { Badge } from "@business-tool/ui/components/badge"
import { Button } from "@business-tool/ui/components/button"
import { Select, SelectContent, SelectItem, SelectSeparator, SelectTrigger, SelectValue } from "@business-tool/ui/components/select"
import { cn } from "@business-tool/ui/lib/utils"
import { CheckIcon } from "lucide-react"
import { useTranslation } from "react-i18next"

import { needsConfirmation } from "@/lib/category-suggestion"
import type { ExpenseCategory } from "@/lib/expense-categories"
import { type Expense, categoryOptions } from "@/lib/expenses"

/** Radix Select values cannot be empty: this one stands for "no category". */
const NONE = "none"

/**
 * The category picker of one expense: the active categories, or none. An archived category the
 * expense still has is shown, but cannot be picked again. A category the import pre-filled from the
 * seller's history (#55) is marked "Suggested", with a button that confirms it; picking any
 * category confirms too.
 */
export function ExpenseCategorySelect({
  expense,
  activeCategories,
  disabled,
  onChange,
  className,
}: {
  expense: Expense
  activeCategories: readonly ExpenseCategory[]
  disabled?: boolean
  onChange: (categoryId: string | null) => void
  className?: string
}) {
  const { t } = useTranslation("admin")
  const options = categoryOptions(activeCategories, expense)
  const label = expense.invoiceNumber ? `${expense.sellerName}, ${expense.invoiceNumber}` : expense.sellerName
  const suggested = needsConfirmation(expense)

  const select = (
    <Select
      value={expense.categoryId ?? NONE}
      disabled={disabled}
      onValueChange={(value) => {
        const next = value === NONE ? null : value
        if (next !== expense.categoryId) onChange(next)
      }}
    >
      <SelectTrigger
        size="sm"
        aria-label={t("expenses.categoryOf", { expense: label })}
        className={cn("w-44 max-w-full", (!expense.categoryId || suggested) && "border-dashed", !expense.categoryId && "text-muted-foreground", className)}
        onClick={(event) => event.stopPropagation()}
      >
        <SelectValue />
      </SelectTrigger>
      <SelectContent onClick={(event) => event.stopPropagation()}>
        <SelectItem value={NONE}>{t("expenses.uncategorized")}</SelectItem>
        {options.length > 0 && <SelectSeparator />}
        {options.map((option) => (
          <SelectItem key={option.id} value={option.id} disabled={option.archived}>
            {option.name}
            {option.archived && <span className="text-muted-foreground"> {t("expenses.archived")}</span>}
          </SelectItem>
        ))}
      </SelectContent>
    </Select>
  )
  if (!suggested || !expense.categoryId) return select

  const categoryId = expense.categoryId
  return (
    <div className="flex flex-wrap items-center gap-1.5" onClick={(event) => event.stopPropagation()}>
      {select}
      <Badge variant="outline" title={t("expenses.suggestedHint")}>
        {t("expenses.suggested")}
      </Badge>
      <Button
        type="button"
        variant="outline"
        size="sm"
        disabled={disabled}
        aria-label={t("expenses.confirmCategoryOf", { category: expense.categoryName ?? "", expense: label })}
        onClick={() => onChange(categoryId)}
      >
        <CheckIcon aria-hidden />
        {t("expenses.confirmCategory")}
      </Button>
    </div>
  )
}
