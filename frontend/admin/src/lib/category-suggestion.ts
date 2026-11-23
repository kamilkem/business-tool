import type { components } from "@business-tool/api-client"

import type { ExpenseCategory } from "./expense-categories"
import type { Expense } from "./expenses"

/*
 * The category suggested from the seller's history (#55): stored on an imported expense and marked
 * `categorySuggested` until someone confirms it, and pre-filled while an expense is entered by hand.
 */

export type ExpenseCategorySuggestion = components["schemas"]["ExpenseCategorySuggestion"]

/** Which seller GET /expense-category-suggestion is asked about: the tax ID when typed, else the name; null for neither. */
export type SuggestionSeller = { sellerTaxId: string } | { sellerName: string }

export function suggestionSeller(values: { sellerTaxId: string; sellerName: string }): SuggestionSeller | null {
  const sellerTaxId = values.sellerTaxId.trim()
  if (sellerTaxId !== "") return { sellerTaxId }
  const sellerName = values.sellerName.trim()
  return sellerName === "" ? null : { sellerName }
}

/** The category a form shows and saves, and whether it is the seller's suggestion. */
export interface FormCategory {
  categoryId: string | null
  suggested: boolean
}

/**
 * The form's category with the seller's suggestion applied. A category the person picked (or the
 * one an edited expense already has) is never replaced; otherwise an active suggested category is
 * pre-filled, and follows the seller as it is typed.
 */
export function formCategory(options: {
  chosen: boolean
  categoryId: string | null
  suggestion: ExpenseCategorySuggestion | null | undefined
  activeCategories: readonly ExpenseCategory[]
}): FormCategory {
  if (options.chosen) return { categoryId: options.categoryId, suggested: false }
  const id = options.suggestion?.categoryId ?? null
  if (id !== null && options.activeCategories.some((category) => category.id === id)) return { categoryId: id, suggested: true }
  return { categoryId: options.categoryId, suggested: false }
}

/** True when the expense shows a pre-filled category nobody confirmed. */
export function needsConfirmation(expense: Pick<Expense, "categoryId" | "categorySuggested">): boolean {
  return expense.categorySuggested && expense.categoryId !== null
}
