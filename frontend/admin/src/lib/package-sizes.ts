import { type components } from "@business-tool/api-client"

import { normalizeDecimal } from "./product-prices"

/*
 * The package size of a product's other items (ADR 0048, #271). When the owner sets a package size on
 * an item (a product made from a proposal, items assigned, one item edited), the product may have other
 * items with no size, or another one, under other units. A size is not assumed equal across units (a
 * `szt.` of 0.7 L is not a `CO` of 6 × 0.7 L), so the owner is asked, group by group, and nothing is
 * applied without a yes. The API groups the product's items by unit and size; what to ask is decided here.
 */

export type PackageSizeGroup = components["schemas"]["ProductPackageSizeGroup"]

/** What the owner just did: the size now on the items they set, on which units, and whether it replaced one. */
export interface SizeAnswer {
  /** The size, as the API stores it (`0.7`, not `0,70`). */
  size: string
  /** The unit keys of the items it was set on; `null` for items with no unit. */
  units: readonly (string | null)[]
  /** An existing size was changed, not set where there was none: the items with another size are asked about too. */
  changed: boolean
}

/** The groups to ask about, in the order the dialog lists them. */
export interface PackageSizeQuestion {
  /** No size, under another unit than the one just set. */
  fill: PackageSizeGroup[]
  /** Another size, under a unit just set. Only when an existing size was changed. */
  sameUnit: PackageSizeGroup[]
  /** Another size, under another unit. Only when an existing size was changed. */
  otherUnits: PackageSizeGroup[]
}

/**
 * A unit as the API groups it (ItemReader::unitKeyOf()): lower case, no diacritics, no trailing dot or space; null for none.
 * `szt.`, `SZT` and `szt` are one unit.
 */
export function unitKeyOf(unit: string | null): string | null {
  if (unit === null) return null
  const key = unit
    .toLowerCase()
    .replace(/ł/g, "l")
    .replace(/ß/g, "ss")
    .normalize("NFD")
    .replace(/\p{M}/gu, "")
    .trim()
    .replace(/[. ]+$/, "")
  return key === "" ? null : key.slice(0, 64)
}

/** Whether two sizes are the same number (`0.7` and `0.70`); no size equals only no size. */
export function sameSize(a: string | null, b: string | null): boolean {
  if (a === null || b === null) return a === b
  const [aWhole = "", aFraction = ""] = a.split(".")
  const [bWhole = "", bFraction = ""] = b.split(".")
  const whole = (digits: string) => digits.replace(/^0+(?=\d)/, "")
  const fraction = (digits: string) => digits.replace(/0+$/, "")
  return whole(aWhole) === whole(bWhole) && fraction(aFraction) === fraction(bFraction)
}

/** What to ask after `answer`, from the product's groups as they are after the change. */
export function packageSizeQuestion(groups: readonly PackageSizeGroup[], answer: SizeAnswer): PackageSizeQuestion {
  const justSet = new Set(answer.units)
  const question: PackageSizeQuestion = { fill: [], sameUnit: [], otherUnits: [] }
  for (const group of groups) {
    const here = justSet.has(group.unitKey)
    if (group.packageSize === null) {
      if (!here) question.fill.push(group)
    } else if (answer.changed && !sameSize(group.packageSize, answer.size)) {
      ;(here ? question.sameUnit : question.otherUnits).push(group)
    }
  }
  return question
}

/** Whether there is anything to ask. */
export function isAsking(question: PackageSizeQuestion): boolean {
  return question.fill.length + question.sameUnit.length + question.otherUnits.length > 0
}

/** How many items the groups hold. */
export function countItems(groups: readonly PackageSizeGroup[]): number {
  return groups.reduce((total, group) => total + group.count, 0)
}

/** What identifies a group in a list of choices. */
export function groupId(group: Pick<PackageSizeGroup, "unitKey" | "packageSize">): string {
  return JSON.stringify([group.unitKey, group.packageSize])
}

/**
 * What the product's groups say after a proposal became a product, whose items got the size their names state:
 * the size most items have (the first of equals: groups come by unit) and the units that have one, so that the
 * units with no size are asked about. Null when no item has a size or none lacks one: nothing to ask.
 */
export function answerAfterCreate(groups: readonly PackageSizeGroup[]): SizeAnswer | null {
  const sized = groups.filter((group) => group.packageSize !== null)
  const [first] = sized
  if (first === undefined || first.packageSize === null) return null
  const totals = new Map<string, number>()
  for (const group of sized) totals.set(group.packageSize ?? "", (totals.get(group.packageSize ?? "") ?? 0) + group.count)
  let size = first.packageSize
  for (const [candidate, total] of totals) if (total > (totals.get(size) ?? 0)) size = candidate
  return { size, units: sized.map((group) => group.unitKey), changed: false }
}

/** The groups' keys the dialog sends back for the chosen ones: what `POST /products/{id}/package-sizes` takes. */
export function targetsOf(groups: readonly PackageSizeGroup[], chosen: ReadonlySet<string>): { unitKey: string | null; packageSize: string | null }[] {
  return groups.filter((group) => chosen.has(groupId(group))).map((group) => ({ unitKey: group.unitKey, packageSize: group.packageSize }))
}

/** The size typed in the follow-up dialog as the API takes it; null when empty (nothing to send). */
export function sizeToApply(text: string): string | null {
  return normalizeDecimal(text)
}
