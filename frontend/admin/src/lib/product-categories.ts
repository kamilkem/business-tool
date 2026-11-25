import { ApiError, type components, violationsOf } from "@business-tool/api-client"
import { requestErrorMessage } from "@business-tool/ui/lib/request-error"

/*
 * Product categories (ADR 0041 §5): what the organization buys is grouped in a tree of any depth.
 * The API lists them flat, each with its parent's id; the page builds the tree.
 */

export type ProductCategory = components["schemas"]["ProductCategory"]

/** The longest name the API takes. */
export const PRODUCT_CATEGORY_NAME_MAX_LENGTH = 64

export interface CategoryNode {
  category: ProductCategory
  /** Its subcategories, by name. */
  children: CategoryNode[]
}

function byName(a: ProductCategory, b: ProductCategory): number {
  return a.name.localeCompare(b.name)
}

/**
 * The flat list as a tree, each level by name. A category whose parent is not in the list (it was
 * deleted since the list was read) is shown at the top level rather than dropped.
 */
export function buildTree(categories: readonly ProductCategory[]): CategoryNode[] {
  const nodes = new Map<string, CategoryNode>(categories.map((c) => [c.id, { category: c, children: [] }]))
  const roots: CategoryNode[] = []
  for (const node of nodes.values()) {
    const parent = node.category.parentId === null ? undefined : nodes.get(node.category.parentId)
    ;(parent ? parent.children : roots).push(node)
  }
  const sort = (level: CategoryNode[]) => {
    level.sort((a, b) => byName(a.category, b.category))
    level.forEach((node) => sort(node.children))
  }
  sort(roots)
  return roots
}

/** The ids of every category below `id`, at any depth (not `id` itself). */
export function descendantIds(categories: readonly ProductCategory[], id: string): Set<string> {
  const childrenOf = new Map<string, string[]>()
  for (const { id: childId, parentId } of categories) {
    if (parentId !== null) childrenOf.set(parentId, [...(childrenOf.get(parentId) ?? []), childId])
  }
  const found = new Set<string>()
  const pending = [...(childrenOf.get(id) ?? [])]
  while (pending.length > 0) {
    const next = pending.pop() as string
    if (found.has(next)) continue
    found.add(next)
    pending.push(...(childrenOf.get(next) ?? []))
  }
  return found
}

export interface ParentChoice {
  category: ProductCategory
  /** 0 for a top level category. */
  depth: number
  /** "Alcohol › Whisky": where it is in the tree, to tell equal names apart. */
  path: string
}

/**
 * The categories a category can go under, in tree order: any but itself and its own descendants,
 * which the API refuses. `movingId` is null when adding a new category, which can go anywhere.
 */
export function parentChoices(categories: readonly ProductCategory[], movingId: string | null): ParentChoice[] {
  const excluded = movingId === null ? new Set<string>() : descendantIds(categories, movingId).add(movingId)
  const choices: ParentChoice[] = []
  const visit = (level: CategoryNode[], depth: number, trail: string[]) => {
    for (const { category, children } of level) {
      if (excluded.has(category.id)) continue
      const path = [...trail, category.name]
      choices.push({ category, depth, path: path.join(" › ") })
      visit(children, depth + 1, path)
    }
  }
  visit(buildTree(categories), 0, [])
  return choices
}

/** The list with an added, renamed or moved category in place. Lets a write's response update the cache without a refetch. */
export function withCategory(categories: readonly ProductCategory[], updated: ProductCategory): ProductCategory[] {
  return [...categories.filter((c) => c.id !== updated.id), updated]
}

/** The list without a deleted category. */
export function withoutCategory(categories: readonly ProductCategory[], id: string): ProductCategory[] {
  return categories.filter((c) => c.id !== id)
}

/** Where to show a refused add, rename or move: on the name field, or under the form. */
export interface CategoryFormErrors {
  name?: string
  form?: string
}

/**
 * Turns the API's refusal of an add, rename or move into messages for the form. A taken name (409)
 * belongs to the name field; the other domain errors (a cycle, a parent deleted meanwhile) are
 * translated from their code and shown under the form, as is a 422 that names no field.
 */
export function categoryFormErrors(error: unknown): CategoryFormErrors {
  if (error instanceof ApiError && error.code === "purchasing.product_category_name_taken") {
    return { name: requestErrorMessage(error) }
  }
  if (error instanceof ApiError && error.status === 422 && error.code === null) {
    const { name: nameError, ...others } = violationsOf(error)
    const rest = Object.values(others).join(" ")
    const result: CategoryFormErrors = {}
    if (nameError) result.name = nameError
    if (rest || !nameError) result.form = rest || requestErrorMessage(error)
    return result
  }
  return { form: requestErrorMessage(error) }
}
