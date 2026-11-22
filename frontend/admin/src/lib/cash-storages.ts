import { ApiError, type components, violationsOf } from "@business-tool/api-client"
import { requestErrorMessage } from "@business-tool/ui/lib/request-error"

import { i18n } from "../i18n"

/*
 * Cash storages (ADR 0033 §3): a cash box, a safe, … Each is counted at the close (the count
 * replaces its balance) or takes deposits (the employee enters only what they put in). One no close
 * or movement refers to can be deleted; a used one is deactivated instead, and can be reactivated.
 */

export type CashStorage = components["schemas"]["CashStorage"]
export type CashStorageMode = CashStorage["mode"]

/** The modes, in the order the form offers them; their texts are `cashStorages.mode.<value>`. */
export const CASH_STORAGE_MODES: readonly CashStorageMode[] = ["counted", "deposit"]

/** The longest name the API takes (ADR 0033 §3). */
export const CASH_STORAGE_NAME_MAX_LENGTH = 64

/** The page's list (GET /cash-storages?includeDeactivated=true), split into the two groups it shows. */
export function splitCashStorages(storages: readonly CashStorage[]): { active: CashStorage[]; deactivated: CashStorage[] } {
  return {
    active: storages.filter((s) => s.active),
    deactivated: storages.filter((s) => !s.active),
  }
}

function byName(a: CashStorage, b: CashStorage): number {
  return a.name.localeCompare(b.name)
}

/**
 * The list with an added, changed, deactivated or reactivated storage in place, in the API's
 * order: active ones first, then deactivated ones, each by name. Lets a write's response update
 * the cache without a refetch.
 */
export function withCashStorage(storages: readonly CashStorage[], updated: CashStorage): CashStorage[] {
  const { active, deactivated } = splitCashStorages([...storages.filter((s) => s.id !== updated.id), updated])
  return [...active.sort(byName), ...deactivated.sort(byName)]
}

/** The list without a deleted storage. */
export function withoutCashStorage(storages: readonly CashStorage[], id: string): CashStorage[] {
  return storages.filter((s) => s.id !== id)
}

/** The body of a PATCH: only what changed, so an unchanged name is not sent again. */
export function cashStoragePatch(
  storage: CashStorage,
  values: { name: string; mode: CashStorageMode },
): { name?: string; mode?: CashStorageMode } {
  const patch: { name?: string; mode?: CashStorageMode } = {}
  if (values.name.trim() !== storage.name) patch.name = values.name
  if (values.mode !== storage.mode) patch.mode = values.mode
  return patch
}

/** Where to show a refused add or change: on the name field, on the mode, or under the form. */
export interface CashStorageFormErrors {
  name?: string
  mode?: string
  form?: string
}

/**
 * Turns the API's refusal of an add or change into messages for the form. A 409 is a duplicate
 * name; deactivated storages keep theirs, so say so when the clash is with one of them. `storages`
 * is the list with deactivated ones, to tell which.
 */
export function cashStorageFormErrors(error: unknown, name: string, storages: readonly CashStorage[]): CashStorageFormErrors {
  if (error instanceof ApiError && error.status === 409 && error.code === "cash_flow.storage_name_taken") {
    const written = name.trim()
    const existing = storages.find((s) => s.name === written)
    return {
      name:
        existing && !existing.active
          ? i18n.t("cashStorages.error.nameTakenByDeactivated", { ns: "admin", name: written })
          : i18n.t("cashStorages.error.nameTaken", { ns: "admin", name: written }),
    }
  }
  if (error instanceof ApiError && error.status === 422) {
    const { name: nameError, mode: modeError, ...others } = violationsOf(error)
    const rest = Object.values(others).join(" ")
    const result: CashStorageFormErrors = {}
    // A violation of the name and the domain's own refusal (`cash_flow.invalid_storage_name`, no field) both belong on it.
    if (nameError) result.name = nameError
    else if (error.code === "cash_flow.invalid_storage_name") result.name = requestErrorMessage(error)
    if (modeError) result.mode = modeError
    if (rest || (!result.name && !result.mode)) result.form = rest || requestErrorMessage(error)
    return result
  }
  return { form: requestErrorMessage(error) }
}
