import { unwrap } from "@business-tool/api-client"
import { requestErrorMessage } from "@business-tool/ui/lib/request-error"
import { useMutation, useQueryClient } from "@tanstack/react-query"
import { useTranslation } from "react-i18next"
import { toast } from "sonner"

import { api } from "./api"
import { type Expense, type ExpenseList, withExpense } from "./expenses"

/**
 * Assigns, confirms (a suggested one, #55) or clears an expense's category (PATCH /expenses/{id},
 * merge-patch). Every cached list shows the change at once; the lists are then refetched, so a
 * filtered one (uncategorized, one category) drops or gains the expense.
 */
export function useCategorizeExpense(organizationId: string, onSaved?: (expense: Expense) => void) {
  const queryClient = useQueryClient()
  const { t } = useTranslation("admin")
  return useMutation({
    mutationFn: ({ expense, categoryId }: { expense: Expense; categoryId: string | null }) =>
      unwrap(
        api.PATCH("/api/expenses/{id}", {
          params: { path: { id: expense.id } },
          body: { categoryId },
          headers: { "Content-Type": "application/merge-patch+json" },
        }),
      ),
    onSuccess: async (saved, { expense }) => {
      queryClient.setQueriesData<ExpenseList>({ queryKey: ["expenses", organizationId] }, (list) =>
        list ? withExpense(list, saved) : list,
      )
      onSaved?.(saved)
      const confirmed = expense.categorySuggested && saved.categoryId === expense.categoryId
      const message = !saved.categoryName
        ? t("expenses.categoryCleared")
        : confirmed
          ? t("expenses.categoryConfirmed", { category: saved.categoryName })
          : t("expenses.categorized", { category: saved.categoryName })
      toast.success(message, {
        description: saved.invoiceNumber ? `${saved.sellerName}, ${saved.invoiceNumber}` : saved.sellerName,
      })
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: ["expenses", organizationId] }),
        // The seller's history changed (#55).
        queryClient.invalidateQueries({ queryKey: ["expense-category-suggestion", organizationId] }),
      ])
    },
    onError: async (error) => {
      toast.error(t("expenses.categorizeFailed"), { description: requestErrorMessage(error) })
      // An archived or removed category: the pickers get the current list.
      await queryClient.invalidateQueries({ queryKey: ["expense-categories", organizationId] })
    },
  })
}
