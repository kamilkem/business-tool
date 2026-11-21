import { unwrap } from "@business-tool/api-client"
import { requestErrorMessage } from "@business-tool/ui/lib/request-error"
import { useMutation, useQueryClient } from "@tanstack/react-query"
import { toast } from "sonner"

import { api } from "./api"
import { type Expense, type ExpenseList, withExpense } from "./expenses"

/**
 * Assigns or clears an expense's category (PATCH /expenses/{id}, merge-patch). Every cached list
 * shows the change at once; the lists are then refetched, so a filtered one (uncategorized, one
 * category) drops or gains the expense.
 */
export function useCategorizeExpense(organizationId: string, onSaved?: (expense: Expense) => void) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: ({ expense, categoryId }: { expense: Expense; categoryId: string | null }) =>
      unwrap(
        api.PATCH("/api/expenses/{id}", {
          params: { path: { id: expense.id } },
          body: { categoryId },
          headers: { "Content-Type": "application/merge-patch+json" },
        }),
      ),
    onSuccess: async (saved) => {
      queryClient.setQueriesData<ExpenseList>({ queryKey: ["expenses", organizationId] }, (list) =>
        list ? withExpense(list, saved) : list,
      )
      onSaved?.(saved)
      toast.success(saved.categoryName ? `Categorized as ${saved.categoryName}` : "Category cleared", {
        description: saved.invoiceNumber ? `${saved.sellerName}, ${saved.invoiceNumber}` : saved.sellerName,
      })
      await queryClient.invalidateQueries({ queryKey: ["expenses", organizationId] })
    },
    onError: async (error) => {
      toast.error("Could not change the category", { description: requestErrorMessage(error) })
      // An archived or removed category: the pickers get the current list.
      await queryClient.invalidateQueries({ queryKey: ["expense-categories", organizationId] })
    },
  })
}
