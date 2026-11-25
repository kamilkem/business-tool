import { ApiError, type components, violationsOf } from "@business-tool/api-client"
import { requestErrorMessage } from "@business-tool/ui/lib/request-error"
import {
  type BusinessPeriod,
  addDays,
  compareBusinessDates,
  isBusinessDate,
  monthPeriod,
  periodError,
} from "@business-tool/ui/lib/date-time"

import { type Basis, type PriceChange, priceChangeOf } from "./purchase-analysis"
import { isCorrection } from "./expenses"

/*
 * The product detail of the purchase analysis (ADR 0041 §7, §9): the price history of one product
 * as a chart over the issue date, and the purchases behind it. The API sends every price point with
 * its price per reference quantity on both bases (minor units, never floats; ADR 0010); this module
 * picks a basis, gives each seller a colour slot and puts the points on a day axis. The change of a
 * point is the API's: against the product's previous price point from any seller, also one before
 * the period, as in the overview's "Change" column.
 */

export type Product = components["schemas"]["Product"]
export type ProductPriceHistory = components["schemas"]["ProductPriceHistory"]
export type PricePoint = components["schemas"]["ProductPricePoint"]
export type PurchasedItem = components["schemas"]["PurchasedItem"]

// --- Period ----------------------------------------------------------------------------------------

/** The months the product page starts with, this one included. */
export const DEFAULT_MONTHS = 12

/** The last 12 months: from the first day of the month 11 months before this one to the end of this one. */
export function defaultPeriod(thisMonth: BusinessPeriod): BusinessPeriod {
  const [year, month] = thisMonth.from.split("-").map(Number) as [number, number]
  const index = year * 12 + (month - 1) - (DEFAULT_MONTHS - 1)
  return { from: monthPeriod(Math.floor(index / 12), (index % 12) + 1).from, to: thisMonth.to }
}

export interface ProductSearch {
  from?: string
  to?: string
}

/**
 * The page's search params. A period the API would refuse is dropped, so the page falls back to the
 * last 12 months. Dropped keys are `undefined`, not left out: the router merges the raw search
 * params under the validated ones. The price history has no longest period.
 */
export function validateProductSearch(search: Record<string, unknown>): ProductSearch {
  const { from, to } = search
  const valid =
    typeof from === "string" &&
    typeof to === "string" &&
    isBusinessDate(from) &&
    isBusinessDate(to) &&
    periodError({ from, to }) === null
  return { from: valid ? from : undefined, to: valid ? to : undefined }
}

// --- Prices on a basis -----------------------------------------------------------------------------

/** The point's price per reference quantity on the basis, with its change; null when it has none on it. */
export function pointPrice(point: PricePoint, basis: Basis) {
  return basis === "net" ? point.net : point.gross
}

// --- Days on the chart's axis ----------------------------------------------------------------------

const EPOCH = "1970-01-01"
const DAY_MS = 86_400_000

/** A business date as a number of days since 1970-01-01, the chart's x. No time zone applies. */
export function dayNumber(date: string): number {
  return Math.round(compareBusinessDates(date, EPOCH) / DAY_MS)
}

/** The business date of a day number. */
export function dateOfDay(day: number): string {
  return addDays(EPOCH, day)
}

/**
 * Round tick values (in minor units) for a price axis that covers `domain` with about `count`
 * ticks, in steps of 1, 2, 2.5, 5 or 10 times a power of ten. The first and last tick are the
 * axis' ends, so use them as its domain: the data never touches the frame.
 */
export function priceTicks(domain: readonly [number, number], count = 6): number[] {
  const [low, high] = domain
  const rough = Math.max(1, (high - low) / Math.max(1, count - 1))
  const magnitude = 10 ** Math.floor(Math.log10(rough))
  const step = Math.max(1, Math.round([1, 2, 2.5, 5, 10].map((m) => m * magnitude).find((candidate) => candidate >= rough) ?? 10 * magnitude))
  const ticks: number[] = []
  for (let tick = Math.max(0, Math.floor(low / step) * step); ; tick += step) {
    ticks.push(tick)
    if (tick >= high) break
  }
  return ticks
}

/** The days an axis labels, about `maxTicks` of them: month starts for a long span, evenly spaced days for a short one. */
export function dateTicks(domain: readonly [number, number], maxTicks: number): { ticks: number[]; withDay: boolean } {
  const [first, last] = domain
  if (last - first <= 62) {
    const step = Math.max(1, Math.ceil((last - first) / maxTicks))
    const ticks: number[] = []
    for (let day = first; day <= last; day += step) ticks.push(day)
    return { ticks, withDay: true }
  }
  const starts: number[] = []
  const [year, month] = dateOfDay(first).split("-").map(Number) as [number, number]
  for (let index = year * 12 + (month - 1); ; index++) {
    const day = dayNumber(monthPeriod(Math.floor(index / 12), (index % 12) + 1).from)
    if (day > last) break
    if (day >= first) starts.push(day)
  }
  const every = Math.max(1, Math.ceil(starts.length / maxTicks))
  return { ticks: starts.filter((_, index) => index % every === 0), withDay: false }
}

// --- Sellers and their colours ---------------------------------------------------------------------

export type SellerSlot = 1 | 2 | 3 | "other"

export interface SellerSeries {
  /** The seller's tax ID, or its name when an invoice has no tax ID. */
  key: string
  /** The name on its latest invoice; null for the group of the other sellers. */
  name: string | null
  slot: SellerSlot
  /** How many of the product's purchases came from it (from all of them for the group). */
  count: number
  /** For the group of the other sellers: how many sellers it holds. */
  sellers: number
}

function sellerKey(point: PricePoint): string {
  return point.sellerTaxId ?? `name:${point.sellerName}`
}

/**
 * Assigns the colours: the three sellers the product was bought from most often (ties by name)
 * take the first three categorical slots in a fixed order, and all the rest share one muted slot.
 * The ranking uses every point whatever the basis, so the net/gross toggle never repaints a seller.
 */
export function sellerSeries(points: readonly PricePoint[]): { series: SellerSeries[]; slotOf: Map<string, SellerSlot> } {
  const counts = new Map<string, { name: string; count: number }>()
  // Oldest first, so the last name seen is the latest.
  for (const point of points) {
    const key = sellerKey(point)
    counts.set(key, { name: point.sellerName, count: (counts.get(key)?.count ?? 0) + 1 })
  }
  const ranked = [...counts.entries()].sort(
    ([, a], [, b]) => b.count - a.count || a.name.localeCompare(b.name),
  )
  const coloured: SellerSlot[] = [1, 2, 3]
  const slotOf = new Map<string, SellerSlot>()
  const series: SellerSeries[] = []
  let others: SellerSeries | null = null
  ranked.forEach(([key, { name, count }], index) => {
    const slot = coloured[index]
    if (slot !== undefined) {
      slotOf.set(key, slot)
      series.push({ key, name, slot, count, sellers: 1 })
      return
    }
    slotOf.set(key, "other")
    if (others === null) {
      others = { key: "other", name: null, slot: "other", count: 0, sellers: 0 }
      series.push(others)
    }
    others.count += count
    others.sellers += 1
  })
  return { series, slotOf }
}

// --- The chart's data ------------------------------------------------------------------------------

export interface ChartPoint {
  itemId: string
  /** Days since 1970-01-01, the x of the point. */
  x: number
  date: string
  /** Minor units per reference quantity on the basis. */
  price: number
  change: PriceChange | null
  sellerName: string
  slot: SellerSlot
}

export interface PriceChart {
  points: ChartPoint[]
  series: SellerSeries[]
  /** Points that have no price on the basis (a gross-only item without VAT has no net): in the table, not on the chart. */
  withoutPrice: number
}

/** The price points on the basis as the chart draws them, oldest first, with each seller's colour slot. */
export function buildPriceChart(points: readonly PricePoint[], basis: Basis, locale?: string): PriceChart {
  const { series, slotOf } = sellerSeries(points)
  const chartPoints: ChartPoint[] = []
  for (const point of points) {
    const price = pointPrice(point, basis)
    if (price === null) continue
    chartPoints.push({
      itemId: point.itemId,
      x: dayNumber(point.date),
      date: point.date,
      price: price.price,
      change: priceChangeOf(price, locale),
      sellerName: point.sellerName,
      slot: slotOf.get(sellerKey(point)) ?? "other",
    })
  }
  return { points: chartPoints, series, withoutPrice: points.length - chartPoints.length }
}

export interface PriceStats {
  latest: ChartPoint
  lowest: ChartPoint
  highest: ChartPoint
}

/**
 * The latest, lowest and highest price of the chart's points; the earliest purchase wins a tie for
 * the lowest and the latest one for the highest, so equal prices point at the date that matters
 * (when it was this cheap first, when it was last this dear). Null without points.
 */
export function priceStats(points: readonly ChartPoint[]): PriceStats | null {
  const latest = points.at(-1)
  if (latest === undefined) return null
  let lowest = latest
  let highest = latest
  for (const point of points) {
    if (point.price < lowest.price || (point.price === lowest.price && point.x < lowest.x)) lowest = point
    if (point.price >= highest.price) highest = point
  }
  return { latest, lowest, highest }
}

/** The days the x axis shows: the points' span with a margin, so a lone point or the ends are not on the frame. */
export function dayDomain(points: readonly ChartPoint[]): [number, number] {
  if (points.length === 0) return [0, 1]
  const days = points.map((point) => point.x)
  const first = Math.min(...days)
  const last = Math.max(...days)
  const margin = Math.max(3, Math.ceil((last - first) * 0.03))
  return [first - margin, last + margin]
}

/** The prices the y axis shows: from a bit under the lowest to a bit over the highest, never below 0. */
export function priceDomain(points: readonly ChartPoint[]): [number, number] {
  if (points.length === 0) return [0, 1]
  const prices = points.map((point) => point.price)
  const low = Math.min(...prices)
  const high = Math.max(...prices)
  const margin = Math.max(1, Math.ceil((high - low) * 0.15), Math.ceil(high * 0.05))
  return [Math.max(0, low - margin), high + margin]
}

// --- Items that are not price points ---------------------------------------------------------------

/** Why an item of the product is in no price (ADR 0041 §7), the first that applies. */
export type NotPricedReason = "excluded" | "noUnit" | "correction" | "otherCurrency" | "noPackageSize" | "noQuantity"

/**
 * Why a purchased item of the product is not one of its price points, or null when it is one. A
 * price point has a product with a unit that is not excluded, a package size, a positive quantity,
 * an invoice that is not a correction, and the organization's currency.
 */
export function notPricedReason(
  item: Pick<PurchasedItem, "invoiceKind" | "currency" | "packageSize" | "quantity">,
  product: Pick<Product, "measure" | "excluded">,
  currency: string,
): NotPricedReason | null {
  if (product.excluded) return "excluded"
  if (product.measure === "none") return "noUnit"
  if (isCorrection(item.invoiceKind)) return "correction"
  if (item.currency !== currency) return "otherCurrency"
  if (item.packageSize === null) return "noPackageSize"
  // Only the sign matters here, so a float is fine.
  if (!(Number(item.quantity) > 0)) return "noQuantity"
  return null
}

/** The product's items that are in no price point, with why; the points' own items are left out. */
export function itemsOutsidePrices<T extends Pick<PurchasedItem, "id" | "invoiceKind" | "currency" | "packageSize" | "quantity">>(
  items: readonly T[],
  pointIds: ReadonlySet<string>,
  product: Pick<Product, "measure" | "excluded">,
  currency: string,
): { item: T; reason: NotPricedReason | null }[] {
  return items
    .filter((item) => !pointIds.has(item.id))
    .map((item) => ({ item, reason: notPricedReason(item, product, currency) }))
}

/** The item's amount on the basis, in its invoice's currency; null when the invoice gave none to derive it from. */
export function itemAmount(item: Pick<PurchasedItem, "netAmount" | "grossAmount">, basis: Basis): number | null {
  return basis === "net" ? item.netAmount : item.grossAmount
}

// --- Product settings form -------------------------------------------------------------------------

export const PRODUCT_NAME_MAX_LENGTH = 128

export type Measure = Product["measure"]

export const MEASURES: readonly Measure[] = ["volume", "mass", "count", "none"]

/** What the settings form sends: only what changed, as a merge-patch body. */
export interface ProductChanges {
  name?: string
  categoryId?: string | null
  measure?: Measure
  referenceQuantity?: string | null
  excluded?: boolean
}

export interface ProductFormValues {
  name: string
  /** A category's id, or "" for none. */
  categoryId: string
  measure: Measure
  /** What the owner typed; ignored for `none`. */
  referenceQuantity: string
  excluded: boolean
}

export function formValuesOf(product: Product): ProductFormValues {
  return {
    name: product.name,
    categoryId: product.categoryId ?? "",
    measure: product.measure,
    referenceQuantity: product.referenceQuantity ?? "",
    excluded: product.excluded,
  }
}

/**
 * The merge-patch body that turns `product` into the form's values; null when nothing changed. A
 * measure without a unit sends `referenceQuantity: null` with it, as the API requires. A decimal
 * typed with a comma is sent with a point; it stays a string and never becomes a number.
 */
export function productChanges(product: Product, values: ProductFormValues): ProductChanges | null {
  const changes: ProductChanges = {}
  const name = values.name.trim()
  if (name !== product.name) changes.name = name
  const categoryId = values.categoryId === "" ? null : values.categoryId
  if (categoryId !== product.categoryId) changes.categoryId = categoryId
  if (values.measure !== product.measure) changes.measure = values.measure
  const reference = values.measure === "none" ? null : normalizeDecimal(values.referenceQuantity)
  if (values.measure === "none") {
    if (product.referenceQuantity !== null) changes.referenceQuantity = null
  } else if (values.measure !== product.measure || !sameDecimal(reference, product.referenceQuantity)) {
    // Sent with a changed measure too (null: the API reads it as 1), as the API ties the two together.
    changes.referenceQuantity = reference
  }
  if (values.excluded !== product.excluded) changes.excluded = values.excluded
  return Object.keys(changes).length === 0 ? null : changes
}

/** "0,5" and " 0.50 " as "0.50"; an empty text as null (the API reads it as 1). Other text is left for the API to refuse. */
export function normalizeDecimal(text: string): string | null {
  const trimmed = text.trim().replace(",", ".")
  return trimmed === "" ? null : trimmed
}

function sameDecimal(a: string | null, b: string | null): boolean {
  if (a === null || b === null) return a === b
  const [aWhole = "", aFraction = ""] = a.split(".")
  const [bWhole = "", bFraction = ""] = b.split(".")
  const whole = (digits: string) => digits.replace(/^0+(?=\d)/, "")
  const fraction = (digits: string) => digits.replace(/0+$/, "")
  return whole(aWhole) === whole(bWhole) && fraction(aFraction) === fraction(bFraction)
}

/** Where to show a refused settings change: on a field, or under the form. */
export interface ProductFormErrors {
  name?: string
  referenceQuantity?: string
  form?: string
}

/** Turns the API's refusal into messages for the form: a taken name on the name field, a refused reference on its field. */
export function productFormErrors(error: unknown): ProductFormErrors {
  if (error instanceof ApiError && error.code === "purchasing.product_name_taken") {
    return { name: requestErrorMessage(error) }
  }
  if (error instanceof ApiError && error.code?.startsWith("purchasing.invalid_product_reference_quantity")) {
    return { referenceQuantity: requestErrorMessage(error) }
  }
  if (error instanceof ApiError && error.status === 422 && error.code === null) {
    const { name, referenceQuantity, ...others } = violationsOf(error)
    const rest = Object.values(others).join(" ")
    const result: ProductFormErrors = {}
    if (name) result.name = name
    if (referenceQuantity) result.referenceQuantity = referenceQuantity
    if (rest || (!name && !referenceQuantity)) result.form = rest || requestErrorMessage(error)
    return result
  }
  return { form: requestErrorMessage(error) }
}

// --- Choosing a product ----------------------------------------------------------------------------

/**
 * The products a picker offers for the search text: not `exceptId` (the product itself), those whose
 * name contains the text, case-insensitively, by name. The match ignores diacritics, so "zubrowka"
 * finds "Żubrówka".
 */
export function matchProducts<T extends Pick<Product, "id" | "name">>(
  products: readonly T[],
  search: string,
  exceptId: string | null,
): T[] {
  const needle = fold(search.trim())
  return products
    .filter((product) => product.id !== exceptId && fold(product.name).includes(needle))
    .sort((a, b) => a.name.localeCompare(b.name))
}

function fold(text: string): string {
  return text.normalize("NFD").replace(/\p{M}/gu, "").toLowerCase().replace(/ł/g, "l")
}
