import { formatDecimal } from "@business-tool/ui/lib/decimal"

/** A bundle holds at least two single packages, and at most this many (ADR 0048). */
export const MIN_BUNDLE_COUNT = 2
export const MAX_BUNDLE_COUNT = 9999

/**
 * A package as a person reads it: "20 × 0.5 L" for a bundle of twenty half-litre bottles, "0.7 L" for a
 * single package. `unit` is the product's unit, when it is known. The size per unit of the quantity is
 * the product of the two, which the API computes; nothing here multiplies (ADR 0010, 0048).
 */
export function packageLabel(packageSize: string, bundleCount: number | null, unit: string | null, locale?: string): string {
  const size = `${formatDecimal(packageSize, locale)}${unit ? ` ${unit}` : ""}`
  return bundleCount === null ? size : `${formatDecimal(String(bundleCount), locale)} × ${size}`
}

/** The bundle count a text stands for: a whole number from MIN_BUNDLE_COUNT to MAX_BUNDLE_COUNT; null for anything else. */
export function parseBundleCount(text: string): number | null {
  const trimmed = text.trim()
  if (!/^\d{1,4}$/.test(trimmed)) return null
  const count = Number(trimmed)
  return count >= MIN_BUNDLE_COUNT && count <= MAX_BUNDLE_COUNT ? count : null
}

/** What a form's Bundle option says: ticked with a valid count, unticked, or ticked with a count that is not valid. */
export type BundleInput = { state: "none" } | { state: "bundle"; count: number } | { state: "invalid" }

export function bundleInput(bundled: boolean, text: string): BundleInput {
  if (!bundled) return { state: "none" }
  const count = parseBundleCount(text)
  return count === null ? { state: "invalid" } : { state: "bundle", count }
}

/** The count to send: the valid one, or null (no bundle). */
export function bundleCountToSend(input: BundleInput): number | null {
  return input.state === "bundle" ? input.count : null
}

/** The bundle count that every item shares, when they all have the same package size and count; null otherwise (a plain size, or they differ). */
export function commonBundleCount(items: readonly { packageSize: string | null; bundleCount: number | null }[]): number | null {
  const [first] = items
  if (first === undefined || first.packageSize === null || first.bundleCount === null) return null
  return items.every((item) => item.packageSize === first.packageSize && item.bundleCount === first.bundleCount) ? first.bundleCount : null
}
