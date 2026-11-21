import { ApiError, type components, violationsOf } from "@business-tool/api-client"
import { requestErrorMessage } from "@business-tool/ui/lib/request-error"

import { i18n } from "../i18n"

/*
 * Payment methods (ADR 0030): a list each organization keeps and orders. A method no revenue entry
 * has used can be deleted; a used one is deactivated instead, and can be reactivated.
 */

export type PaymentMethod = components["schemas"]["PaymentMethod"]

/** The list (GET /payment-methods?includeDeactivated=true), split into the two groups it shows. */
export function splitPaymentMethods(methods: readonly PaymentMethod[]): { active: PaymentMethod[]; deactivated: PaymentMethod[] } {
  return {
    active: methods.filter((m) => m.active),
    deactivated: methods.filter((m) => !m.active),
  }
}

/**
 * The list with a renamed method in place, or an added, deactivated or reactivated one where the
 * API now lists it: a new or reactivated method at the end of the active ones, a deactivated one
 * at the end of the deactivated ones (their own order is the API's, kept here). Lets a write's
 * response update the cache without a refetch.
 */
export function withPaymentMethod(methods: readonly PaymentMethod[], updated: PaymentMethod): PaymentMethod[] {
  const previous = methods.find((m) => m.id === updated.id)
  if (previous && previous.active === updated.active) return methods.map((m) => (m.id === updated.id ? updated : m))
  const { active, deactivated } = splitPaymentMethods(methods.filter((m) => m.id !== updated.id))
  return updated.active ? [...active, updated, ...deactivated] : [...active, ...deactivated, updated]
}

export function withoutPaymentMethod(methods: readonly PaymentMethod[], id: string): PaymentMethod[] {
  return methods.filter((m) => m.id !== id)
}

/** The list with the active methods in a new order (PUT /payment-methods/order); deactivated ones stay last. */
export function reorderedPaymentMethods(methods: readonly PaymentMethod[], activeIds: readonly string[]): PaymentMethod[] {
  const { active, deactivated } = splitPaymentMethods(methods)
  const byId = new Map(active.map((m) => [m.id, m]))
  const ordered = activeIds.flatMap((id) => {
    const method = byId.get(id)
    return method ? [method] : []
  })
  return [...ordered, ...active.filter((m) => !activeIds.includes(m.id)), ...deactivated]
}

/** Moves the item at `from` to `to`, shifting the ones between (a drag and drop). */
export function moveItem<T>(items: readonly T[], from: number, to: number): T[] {
  const next = [...items]
  const [moved] = next.splice(from, 1)
  if (moved === undefined) return next
  next.splice(to, 0, moved)
  return next
}

/** A row of the entry form: one per method it offers. */
export interface EntryFormMethod {
  id: string
  name: string
  active: boolean
}

/**
 * The methods an entry form offers: the active ones in the organization's order, then any
 * deactivated method the entry already has a line for (it may keep it, ADR 0030 §4), under the
 * name the entry carries when the list no longer has the method.
 */
export function entryFormMethods(
  methods: readonly PaymentMethod[],
  lines: readonly { paymentMethodId: string; paymentMethodName: string }[] = [],
): EntryFormMethod[] {
  const rows: EntryFormMethod[] = methods.filter((m) => m.active).map(({ id, name }) => ({ id, name, active: true }))
  for (const line of lines) {
    if (rows.some((row) => row.id === line.paymentMethodId)) continue
    const method = methods.find((m) => m.id === line.paymentMethodId)
    rows.push({ id: line.paymentMethodId, name: method?.name ?? line.paymentMethodName, active: false })
  }
  return rows
}

/** Where to show a refused add or rename: on the name field, or under the form. */
export interface PaymentMethodFormErrors {
  name?: string
  form?: string
}

/**
 * Turns the API's refusal of a name into messages for the form. A 409 is a duplicate name;
 * deactivated methods count too, so say so when the clash is with one of them.
 */
export function paymentMethodFormErrors(error: unknown, name: string, methods: readonly PaymentMethod[]): PaymentMethodFormErrors {
  if (error instanceof ApiError && error.status === 409) {
    const written = name.trim()
    const existing = methods.find((m) => m.name === written)
    return {
      name: existing && !existing.active
        ? i18n.t("paymentMethods.error.nameTakenByDeactivated", { ns: "admin", name: written })
        : i18n.t("paymentMethods.error.nameTaken", { ns: "admin", name: written }),
    }
  }
  if (error instanceof ApiError && error.status === 422) {
    const { name: nameError, ...others } = violationsOf(error)
    const rest = Object.values(others).join(" ")
    const result: PaymentMethodFormErrors = {}
    if (nameError) result.name = nameError
    if (rest || !nameError) result.form = rest || requestErrorMessage(error)
    return result
  }
  return { form: requestErrorMessage(error) }
}
