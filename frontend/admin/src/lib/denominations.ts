import { ApiError, type components, violationsOf } from "@business-tool/api-client"
import { requestErrorMessage } from "@business-tool/ui/lib/request-error"

import { i18n } from "../i18n"

/*
 * Denominations (ADR 0033 §2): the coins and notes of the organization's currency that a close
 * counts, with defaults by currency (all Polish ones for PLN). The API lists only the current
 * currency's. One no close or movement uses can be deleted; a used one is deactivated instead, and
 * can be reactivated. A denomination is not edited: a wrong one is deleted and added again.
 */

export type Denomination = components["schemas"]["Denomination"]
export type DenominationKind = Denomination["kind"]

/** The kinds, in the order the page and the form show them; their texts are `denominations.kind.<value>`. */
export const DENOMINATION_KINDS: readonly DenominationKind[] = ["coin", "note"]

/** The page's list (GET /denominations?includeDeactivated=true), split into the two groups it shows. */
export function splitDenominations(denominations: readonly Denomination[]): { active: Denomination[]; deactivated: Denomination[] } {
  return {
    active: denominations.filter((d) => d.active),
    deactivated: denominations.filter((d) => !d.active),
  }
}

/** Denominations of each kind, coins first, each by value as given. Kinds without any are left out. */
export function denominationsByKind(denominations: readonly Denomination[]): { kind: DenominationKind; denominations: Denomination[] }[] {
  return DENOMINATION_KINDS.map((kind) => ({ kind, denominations: denominations.filter((d) => d.kind === kind) })).filter(
    (group) => group.denominations.length > 0,
  )
}

function byValue(a: Denomination, b: Denomination): number {
  return a.value - b.value
}

/**
 * The list with an added, deactivated or reactivated denomination in place, in the API's order:
 * active ones first, then deactivated ones, each by value, lowest first.
 */
export function withDenomination(denominations: readonly Denomination[], updated: Denomination): Denomination[] {
  const { active, deactivated } = splitDenominations([...denominations.filter((d) => d.id !== updated.id), updated])
  return [...active.sort(byValue), ...deactivated.sort(byValue)]
}

/** The list without a deleted denomination. */
export function withoutDenomination(denominations: readonly Denomination[], id: string): Denomination[] {
  return denominations.filter((d) => d.id !== id)
}

/** Where to show a refused add: on the value, on the kind, or under the form. */
export interface DenominationFormErrors {
  value?: string
  kind?: string
  form?: string
}

/**
 * Turns the API's refusal of an add into messages for the form. A 409 is a value the currency
 * already has; deactivated denominations keep theirs, so point to the one to reactivate. `formatted`
 * is the value as the page shows it ("5,00 zł").
 */
export function denominationFormErrors(
  error: unknown,
  value: number,
  formatted: string,
  denominations: readonly Denomination[],
): DenominationFormErrors {
  if (error instanceof ApiError && error.status === 409 && error.code === "cash_flow.denomination_value_taken") {
    const existing = denominations.find((d) => d.value === value)
    return {
      value:
        existing && !existing.active
          ? i18n.t("denominations.error.valueTakenByDeactivated", { ns: "admin", value: formatted })
          : i18n.t("denominations.error.valueTaken", { ns: "admin", value: formatted }),
    }
  }
  if (error instanceof ApiError && error.status === 422) {
    const { value: valueError, kind: kindError, ...others } = violationsOf(error)
    const rest = Object.values(others).join(" ")
    const result: DenominationFormErrors = {}
    if (valueError) result.value = valueError
    else if (error.code === "cash_flow.invalid_denomination_value") result.value = requestErrorMessage(error)
    if (kindError) result.kind = kindError
    if (rest || (!result.value && !result.kind)) result.form = rest || requestErrorMessage(error)
    return result
  }
  return { form: requestErrorMessage(error) }
}
