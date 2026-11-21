import { ApiError, type components, violationsOf } from "@business-tool/api-client"
import { requestErrorMessage } from "@business-tool/ui/lib/request-error"

/*
 * Expense categories: what an expense was for (rent, food, ...). Archived, never deleted, because
 * the expenses that have one keep it; an archived category can be restored.
 */

export type ExpenseCategory = components["schemas"]["ExpenseCategory"]

/** The page's list (GET /expense-categories?includeArchived=true), split into the two groups it shows. */
export function splitCategories(categories: readonly ExpenseCategory[]): {
  active: ExpenseCategory[]
  archived: ExpenseCategory[]
} {
  return {
    active: categories.filter((c) => !c.archived),
    archived: categories.filter((c) => c.archived),
  }
}

function byName(a: ExpenseCategory, b: ExpenseCategory): number {
  return a.name.localeCompare(b.name)
}

/**
 * The list with an added, renamed, archived or restored category in place, in the API's order:
 * active ones first, then archived ones, each by name. Lets a write's response update the cache
 * without a refetch.
 */
export function withCategory(categories: readonly ExpenseCategory[], updated: ExpenseCategory): ExpenseCategory[] {
  const others = categories.filter((c) => c.id !== updated.id)
  const { active, archived } = splitCategories([...others, updated])
  return [...active.sort(byName), ...archived.sort(byName)]
}

/** Where to show a refused add or rename: on the name field, or under the form. */
export interface CategoryFormErrors {
  name?: string
  form?: string
}

/**
 * Turns the API's refusal of a name into messages for the form. A 409 is a duplicate name;
 * archived categories count too, so say so (and that it can be restored) when the clash is with
 * one of them. `categories` is the list with archived ones, to name which category has it.
 */
export function categoryFormErrors(
  error: unknown,
  name: string,
  categories: readonly ExpenseCategory[],
): CategoryFormErrors {
  if (error instanceof ApiError && error.status === 409) {
    const written = name.trim()
    const existing = categories.find((c) => c.name === written)
    return {
      name: existing?.archived
        ? `An archived category is already named “${written}”. Restore it, or pick another name.`
        : `A category named “${written}” already exists.`,
    }
  }
  if (error instanceof ApiError && error.status === 422) {
    const { name: nameError, ...others } = violationsOf(error)
    const rest = Object.values(others).join(" ")
    const result: CategoryFormErrors = {}
    if (nameError) result.name = nameError
    // A 422 that names no field (or another one) still needs to be seen.
    if (rest || !nameError) result.form = rest || requestErrorMessage(error)
    return result
  }
  return { form: requestErrorMessage(error) }
}
