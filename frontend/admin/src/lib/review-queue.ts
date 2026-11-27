import { ApiError, violationsOf } from "@business-tool/api-client"
import { requestErrorMessage } from "@business-tool/ui/lib/request-error"

import { type Product, type PurchasedItem, normalizeDecimal } from "./product-prices"

/*
 * The review queue (ADR 0041 §3, §9): the purchased items that have no product yet (`unassigned`)
 * or only a suggested one (`suggested`), grouped by the name the invoice printed, to be assigned to
 * a product, to a new one, confirmed or excluded. The API lists items, newest invoice first; the
 * grouping, the selection and the confirm batches are this module's. Amounts stay minor units.
 */

export type QueueItem = PurchasedItem

/** The states the queue lists. */
export const QUEUE_STATUSES: ("unassigned" | "suggested")[] = ["unassigned", "suggested"]

/** How many items one request loads: the most the API gives. */
export const QUEUE_PAGE_SIZE = 100

/** The most items the API assigns in one call. */
export const MAX_ASSIGN_ITEMS = 500

// --- Search params ---------------------------------------------------------------------------------

export interface ReviewSearch {
  /** A part of the printed name. */
  q?: string
}

/** The page's search params: a name search, trimmed; empty or not text is no search. */
export function validateReviewSearch(search: Record<string, unknown>): ReviewSearch {
  const q = typeof search.q === "string" ? search.q.trim().slice(0, 512) : ""
  return { q: q === "" ? undefined : q }
}

// --- Grouping --------------------------------------------------------------------------------------

/** What makes two printed names the same: letter case and runs of white space do not. */
export function groupKey(name: string): string {
  return name.trim().replace(/\s+/g, " ").toLowerCase()
}

/** A suggested product and the items it is suggested for. */
export interface SuggestedLink {
  productId: string
  productName: string
  itemIds: string[]
}

export interface QueueGroup {
  /** The normalized name; stable while items come and go. */
  key: string
  /** The name as the newest item printed it. */
  name: string
  /** Newest first, as the API lists them. */
  items: QueueItem[]
  /** The newest invoice's issue date. */
  newestDate: string
  /** The sellers' names, most items first. */
  sellers: string[]
  /** The suggested products of the group's `suggested` items, with their items. */
  suggestions: SuggestedLink[]
}

/** The suggested links of the items that have one: `suggested` with a product, one per product. */
export function suggestedLinks(items: readonly QueueItem[]): SuggestedLink[] {
  const links = new Map<string, SuggestedLink>()
  for (const item of items) {
    if (item.status !== "suggested" || item.productId === null) continue
    const link = links.get(item.productId) ?? { productId: item.productId, productName: item.productName ?? "", itemIds: [] }
    link.itemIds.push(item.id)
    links.set(item.productId, link)
  }
  return [...links.values()]
}

/**
 * The items as groups of one printed name, the group with the newest item first (the API's order,
 * newest invoice first, is kept inside a group). An item listed twice (a page loaded again after a
 * change) counts once.
 */
export function groupByName(items: readonly QueueItem[]): QueueGroup[] {
  const groups = new Map<string, QueueItem[]>()
  const seen = new Set<string>()
  for (const item of items) {
    if (seen.has(item.id)) continue
    seen.add(item.id)
    const key = groupKey(item.name)
    const group = groups.get(key)
    if (group) group.push(item)
    else groups.set(key, [item])
  }
  return [...groups.entries()]
    .map(([key, list]) => {
      const newest = list.reduce((a, b) => (b.invoiceIssueDate > a.invoiceIssueDate ? b : a))
      const sellerCounts = new Map<string, number>()
      for (const item of list) sellerCounts.set(item.sellerName, (sellerCounts.get(item.sellerName) ?? 0) + 1)
      const sellers = [...sellerCounts.entries()]
        .sort(([nameA, a], [nameB, b]) => b - a || nameA.localeCompare(nameB))
        .map(([seller]) => seller)
      return { key, name: newest.name.trim(), items: list, newestDate: newest.invoiceIssueDate, sellers, suggestions: suggestedLinks(list) }
    })
    .sort((a, b) => (a.newestDate < b.newestDate ? 1 : a.newestDate > b.newestDate ? -1 : 0))
}

/** The items of the groups the ids belong to, in the groups' order, to act on a selection. */
export function selectedItems(groups: readonly QueueGroup[], selected: ReadonlySet<string>): QueueItem[] {
  return groups.flatMap((group) => group.items.filter((item) => selected.has(item.id)))
}

/** The ids still in the list, so a selection never holds an item that was assigned meanwhile. */
export function pruneSelection(groups: readonly QueueGroup[], selected: ReadonlySet<string>): ReadonlySet<string> {
  const present = new Set(groups.flatMap((group) => group.items.map((item) => item.id)))
  const kept = [...selected].filter((id) => present.has(id))
  return kept.length === selected.size ? selected : new Set(kept)
}

export type SelectionState = "none" | "some" | "all"

/** Whether none, some or all of the items are selected, for a group's checkbox. */
export function selectionState(items: readonly QueueItem[], selected: ReadonlySet<string>): SelectionState {
  const count = items.filter((item) => selected.has(item.id)).length
  return count === 0 ? "none" : count === items.length ? "all" : "some"
}

/** The selection with the items added, or removed when they were all selected already. */
export function toggleItems(selected: ReadonlySet<string>, items: readonly QueueItem[]): ReadonlySet<string> {
  const next = new Set(selected)
  if (selectionState(items, selected) === "all") for (const item of items) next.delete(item.id)
  else for (const item of items) next.add(item.id)
  return next
}

// --- Assigning -------------------------------------------------------------------------------------

/**
 * The measure a new product most likely has, from the unit its items were printed with: litres are
 * a volume, kilograms a mass, anything else (`szt.`, `op.`, no unit) pieces. Only the form's
 * starting point.
 */
export function measureGuess(unit: string | null): Exclude<Product["measure"], "none"> {
  const text = (unit ?? "").trim().toLowerCase().replace(/\.$/, "")
  if (["l", "lt", "litr", "litry", "ltr"].includes(text)) return "volume"
  if (["kg", "kilogram", "kilogramy"].includes(text)) return "mass"
  return "count"
}

/**
 * The package size every item already has when they all have the same one, and the same bundle count: what
 * the form starts with (of a single package when they are bundles, ADR 0048). Items that differ in the count
 * start with none, so that saving does not turn a bundle into a plain size or the other way round.
 */
export function commonPackageSize(items: readonly QueueItem[]): string | null {
  const [first] = items
  if (first === undefined || first.packageSize === null) return null
  return items.every((item) => item.packageSize === first.packageSize && item.bundleCount === first.bundleCount) ? first.packageSize : null
}

/** The package size field's unit: what the product is counted in; null for a product without one. */
export function packageSizeApplies(product: Pick<Product, "measure" | "excluded">): boolean {
  return product.measure !== "none" && !product.excluded
}

/** What the form sends as the package size: null (each item keeps its own) for an empty or unused field. */
export function packageSizeToSend(text: string, applies: boolean): string | null {
  return applies ? normalizeDecimal(text) : null
}

/** Where to show a refused assignment: on a field, or under the form. */
export interface AssignErrors {
  name?: string
  referenceQuantity?: string
  packageSize?: string
  form?: string
}

/** Turns the API's refusal of the product or the assignment into messages for the form's fields. */
export function assignErrors(error: unknown): AssignErrors {
  if (error instanceof ApiError && error.code === "purchasing.product_name_taken") {
    return { name: requestErrorMessage(error) }
  }
  if (error instanceof ApiError && error.code?.startsWith("purchasing.invalid_product_reference_quantity")) {
    return { referenceQuantity: requestErrorMessage(error) }
  }
  if (error instanceof ApiError && error.status === 422 && error.code === null) {
    const { name, referenceQuantity, packageSize, ...others } = violationsOf(error)
    const rest = Object.values(others).join(" ")
    const result: AssignErrors = {}
    if (name) result.name = name
    if (referenceQuantity) result.referenceQuantity = referenceQuantity
    if (packageSize) result.packageSize = packageSize
    if (rest || (!name && !referenceQuantity && !packageSize)) result.form = rest || requestErrorMessage(error)
    return result
  }
  return { form: requestErrorMessage(error) }
}

/** Refusals that mean the list on screen is out of date (an item or the product is gone). */
export const STALE_CODES = ["purchasing.purchased_item_not_found", "purchasing.purchased_item_product_not_found", "purchasing.product_not_found"]
