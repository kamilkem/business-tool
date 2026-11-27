import type { components } from "@business-tool/api-client"
import { formatDecimal } from "@business-tool/ui/lib/decimal"
import { isBusinessDate, periodError } from "@business-tool/ui/lib/date-time"

import { i18n } from "../i18n"

/*
 * The Analysis overview (ADR 0041 §8, §9): what the organization bought in a period, as a tree of
 * categories of any depth down to products. The API sends the categories flat with their parent's
 * id and both money bases (net and gross) at once; this module nests them, picks a basis, and
 * words the quantities and price changes. Money stays integer minor units and quantities decimal
 * strings: nothing here divides an amount into a float (ADR 0010).
 */

export type PurchaseAnalysis = components["schemas"]["PurchaseAnalysis"]
export type AnalysisProduct = components["schemas"]["PurchaseAnalysisProduct"]
export type AnalysisAmounts = components["schemas"]["PurchaseAnalysisAmounts"]
export type AnalysisPrice = components["schemas"]["PurchaseAnalysisPrice"]
export type AnalysisLeftOut = components["schemas"]["PurchaseAnalysisLeftOut"]

/** The longest period GET /purchase-analysis accepts. */
export const ANALYSIS_MAX_DAYS = 366

// --- Search params ---------------------------------------------------------------------------------

export interface AnalysisSearch {
  from?: string
  to?: string
}

/**
 * The page's search params. A period the API would refuse is dropped, so the page falls back to
 * this month. Dropped keys are `undefined`, not left out: the router merges the raw search params
 * under the validated ones.
 */
export function validateAnalysisSearch(search: Record<string, unknown>): AnalysisSearch {
  const { from, to } = search
  const valid =
    typeof from === "string" &&
    typeof to === "string" &&
    isBusinessDate(from) &&
    isBusinessDate(to) &&
    periodError({ from, to }, ANALYSIS_MAX_DAYS) === null
  return { from: valid ? from : undefined, to: valid ? to : undefined }
}

// --- Net or gross ----------------------------------------------------------------------------------

export type Basis = "net" | "gross"

export const DEFAULT_BASIS: Basis = "net"

/** Where the viewer's choice is kept in the browser (not shared, not on the server; ADR 0041 §6). */
export const BASIS_STORAGE_KEY = "business-tool.admin.purchase-analysis.basis"

function browserStorage(): Pick<Storage, "getItem" | "setItem"> | null {
  try {
    return window.localStorage
  } catch {
    // Blocked site data, private windows.
    return null
  }
}

/** The remembered basis; net when nothing valid is stored or the storage is not available. */
export function readBasis(storage: Pick<Storage, "getItem"> | null = browserStorage()): Basis {
  try {
    const stored = storage?.getItem(BASIS_STORAGE_KEY)
    return stored === "gross" ? "gross" : DEFAULT_BASIS
  } catch {
    return DEFAULT_BASIS
  }
}

/** Remembers the basis; the choice still works for this visit when the storage refuses. */
export function saveBasis(basis: Basis, storage: Pick<Storage, "setItem"> | null = browserStorage()): void {
  try {
    storage?.setItem(BASIS_STORAGE_KEY, basis)
  } catch {
    // Quota or blocked storage: nothing to do.
  }
}

export function amountOf(amounts: AnalysisAmounts, basis: Basis): number {
  return basis === "net" ? amounts.net : amounts.gross
}

/** The product's average price per reference quantity on the basis; null when it has no price point on it. */
export function averagePriceOf(product: AnalysisProduct, basis: Basis): number | null {
  return basis === "net" ? product.averageNetPrice : product.averageGrossPrice
}

/** The product's last price point on the basis, with its change; null when it has none on it. */
export function lastPriceOf(product: AnalysisProduct, basis: Basis): AnalysisPrice | null {
  return basis === "net" ? product.lastNetPrice : product.lastGrossPrice
}

// --- The tree --------------------------------------------------------------------------------------

/** What the "Uncategorized" group has as its id: not a category's. */
export const UNCATEGORIZED_ID = "uncategorized"

export type AnalysisNode =
  | {
      kind: "category" | "uncategorized"
      id: string
      name: string
      /** The spend on the basis, its subcategories and products included. */
      spend: number
      /** Its subcategories, then its own products; each group by spend, the largest first. */
      children: AnalysisNode[]
    }
  | { kind: "product"; id: string; product: AnalysisProduct; spend: number }

function bySpend(a: AnalysisNode, b: AnalysisNode): number {
  if (a.spend !== b.spend) return b.spend - a.spend
  return nameOf(a).localeCompare(nameOf(b))
}

function nameOf(node: AnalysisNode): string {
  return node.kind === "product" ? node.product.name : node.name
}

/**
 * The tree the overview shows: top level categories, then the "Uncategorized" group when there are
 * products in no category. A category with no product in it or below it in the period is left out
 * (the API lists every category), while one whose spend is zero or negative stays. The items
 * without a product are not in the tree: they are their own row.
 */
export function buildAnalysisTree(analysis: PurchaseAnalysis, basis: Basis): AnalysisNode[] {
  const productsIn = new Map<string | null, AnalysisProduct[]>()
  const known = new Set(analysis.categories.map((category) => category.id))
  for (const product of analysis.products) {
    // A category that is gone since the snapshot cannot happen in one response; keep the product visible if it does.
    const key = product.categoryId !== null && known.has(product.categoryId) ? product.categoryId : null
    productsIn.set(key, [...(productsIn.get(key) ?? []), product])
  }
  const childrenOf = new Map<string | null, typeof analysis.categories>()
  for (const category of analysis.categories) {
    const key = category.parentId !== null && known.has(category.parentId) ? category.parentId : null
    childrenOf.set(key, [...(childrenOf.get(key) ?? []), category])
  }

  const productNodes = (products: readonly AnalysisProduct[] = []): AnalysisNode[] =>
    products
      .map((product): AnalysisNode => ({ kind: "product", id: product.id, product, spend: amountOf(product.spend, basis) }))
      .sort(bySpend)

  const categoryNodes = (parent: string | null): AnalysisNode[] =>
    (childrenOf.get(parent) ?? [])
      .flatMap((category): AnalysisNode[] => {
        const subcategories = categoryNodes(category.id)
        const products = productNodes(productsIn.get(category.id))
        if (subcategories.length === 0 && products.length === 0) return []
        return [
          {
            kind: "category",
            id: category.id,
            name: category.name,
            spend: amountOf(category.total, basis),
            children: [...subcategories, ...products],
          },
        ]
      })
      .sort(bySpend)

  const roots = categoryNodes(null)
  const uncategorized = productNodes(productsIn.get(null))
  if (uncategorized.length > 0) {
    roots.push({
      kind: "uncategorized",
      id: UNCATEGORIZED_ID,
      name: i18n.t("analysis.uncategorized", { ns: "admin" }),
      spend: uncategorized.reduce((sum, node) => sum + node.spend, 0),
      children: uncategorized,
    })
  }
  return roots
}

/** The ids of every node that has children, at any depth: what "Expand all" opens. */
export function branchIds(nodes: readonly AnalysisNode[]): string[] {
  return nodes.flatMap((node) => (node.kind === "product" ? [] : [node.id, ...branchIds(node.children)]))
}

/** Whether the period has anything to show: a product, or items waiting for one. */
export function hasPurchases(analysis: PurchaseAnalysis): boolean {
  return analysis.products.length > 0 || analysis.notAssigned.count > 0
}

// --- Share -----------------------------------------------------------------------------------------

/**
 * `part` as a share of `total` in tenths of a percent (123 is 12.3 %), rounded half away from zero
 * with integer arithmetic. Null when there is no share to speak of: the total is zero or negative.
 */
export function shareTenths(part: number, total: number): number | null {
  if (total <= 0) return null
  const numerator = BigInt(part) * 1000n
  const denominator = BigInt(total)
  const magnitude = numerator < 0n ? -numerator : numerator
  const rounded = (2n * magnitude + denominator) / (2n * denominator)
  return Number(numerator < 0n ? -rounded : rounded)
}

/** "12.3 %" as a locale writes it: `formatShare(123, "pl-PL")` → "12,3%", `formatShare(1000, "en-GB")` → "100%". */
export function formatShare(tenths: number, locale?: string): string {
  const magnitude = Math.abs(tenths)
  const decimal = `${tenths < 0 ? "-" : ""}${Math.floor(magnitude / 10)}.${magnitude % 10}`
  return `${formatDecimal(decimal, locale)}%`
}

// --- Quantities and prices -------------------------------------------------------------------------

export function unitOf(measure: string): string | null {
  switch (measure) {
    case "volume":
      return i18n.t("analysis.unit.volume", { ns: "admin" })
    case "mass":
      return i18n.t("analysis.unit.mass", { ns: "admin" })
    case "count":
      return i18n.t("analysis.unit.count", { ns: "admin" })
    default:
      return null
  }
}

const ONE = /^1(\.0*)?$/

/**
 * What one price is per: the reference quantity with its unit, "0.5 L"; just "L" for a reference of
 * 1, which is how most products are compared. Null for a product without a unit.
 */
export function referenceLabel(
  product: Pick<AnalysisProduct, "measure" | "referenceQuantity">,
  locale?: string,
): string | null {
  const unit = unitOf(product.measure)
  if (unit === null || product.referenceQuantity === null) return null
  return ONE.test(product.referenceQuantity) ? unit : `${formatDecimal(product.referenceQuantity, locale)} ${unit}`
}

/**
 * The quantity bought, in reference quantities: "12 × 0.5 L" (twelve halves of a litre), or "12 L"
 * when the reference is 1. Null when the product has no price point in the period.
 */
export function quantityLabel(product: AnalysisProduct, locale?: string): string | null {
  const reference = referenceLabel(product, locale)
  if (product.quantity === null || reference === null || product.referenceQuantity === null) return null
  const quantity = formatDecimal(product.quantity, locale)
  return ONE.test(product.referenceQuantity) ? `${quantity} ${reference}` : `${quantity} × ${reference}`
}

export type ChangeDirection = "up" | "down" | "flat"

export interface PriceChange {
  direction: ChangeDirection
  /** Minor units, signed. */
  amount: number
  /** "+3.45 %" as a locale writes it; null when the previous price was 0. */
  percent: string | null
}

/** How a last price moved against the previous purchase; null for a first purchase. */
export function priceChangeOf(price: AnalysisPrice | null, locale?: string): PriceChange | null {
  if (price === null || price.change === null) return null
  const direction: ChangeDirection = price.change > 0 ? "up" : price.change < 0 ? "down" : "flat"
  return { direction, amount: price.change, percent: formatChangePercent(price.changePercent, locale) }
}

/**
 * The API's percentage string with its sign: "-3.45" → "−3,45%", "10" → "+10%", "0" → "0%". The
 * string goes to the formatter as it is, so it never becomes a float.
 */
export function formatChangePercent(percent: string | null, locale?: string): string | null {
  if (percent === null) return null
  const negative = percent.startsWith("-")
  const magnitude = formatDecimal(negative ? percent.slice(1) : percent, locale)
  if (/^[0.,\s]*$/.test(magnitude)) return `${magnitude}%`
  return `${negative ? "−" : "+"}${magnitude}%`
}

// --- Left out --------------------------------------------------------------------------------------

export type LeftOutKey = "otherCurrency" | "withoutNet" | "withoutGross" | "withoutPackageSize"

export interface LeftOutNote {
  key: LeftOutKey
  count: number
}

/**
 * What the screen says was not counted, in the order that matters: items in another currency, the
 * ones missing an amount on the shown basis (the other basis is not the screen's business), and
 * the ones whose package size is unknown (in the spend, not in quantities or prices).
 */
export function leftOutNotes(leftOut: AnalysisLeftOut, basis: Basis): LeftOutNote[] {
  const notes: LeftOutNote[] = [
    { key: "otherCurrency", count: leftOut.otherCurrency },
    basis === "net"
      ? { key: "withoutNet", count: leftOut.withoutNet }
      : { key: "withoutGross", count: leftOut.withoutGross },
    { key: "withoutPackageSize", count: leftOut.withoutPackageSize },
  ]
  return notes.filter((note) => note.count > 0)
}
