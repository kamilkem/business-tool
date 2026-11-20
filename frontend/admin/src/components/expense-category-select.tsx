import { Select, SelectContent, SelectItem, SelectSeparator, SelectTrigger, SelectValue } from "@business-tool/ui/components/select"
import { cn } from "@business-tool/ui/lib/utils"

import type { ExpenseCategory } from "@/lib/expense-categories"
import { type Expense, categoryOptions } from "@/lib/expenses"

/** Radix Select values cannot be empty: this one stands for "no category". */
const NONE = "none"

/**
 * The category picker of one expense: the active categories, or none. An archived category the
 * expense still has is shown, but cannot be picked again.
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
  const options = categoryOptions(activeCategories, expense)
  const label = expense.invoiceNumber ? `${expense.sellerName}, ${expense.invoiceNumber}` : expense.sellerName

  return (
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
        aria-label={`Category of ${label}`}
        className={cn("w-44 max-w-full", !expense.categoryId && "border-dashed text-muted-foreground", className)}
        onClick={(event) => event.stopPropagation()}
      >
        <SelectValue />
      </SelectTrigger>
      <SelectContent onClick={(event) => event.stopPropagation()}>
        <SelectItem value={NONE}>Uncategorized</SelectItem>
        {options.length > 0 && <SelectSeparator />}
        {options.map((option) => (
          <SelectItem key={option.id} value={option.id} disabled={option.archived}>
            {option.name}
            {option.archived && <span className="text-muted-foreground"> (archived)</span>}
          </SelectItem>
        ))}
      </SelectContent>
    </Select>
  )
}
