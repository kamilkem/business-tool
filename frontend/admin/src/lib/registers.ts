import { ApiError, type components, violationsOf } from "@business-tool/api-client"
import { requestErrorMessage } from "@business-tool/ui/lib/request-error"

/*
 * Registers (ADR 0022 §2): tills with their own fiscal printer. Deactivated, never deleted,
 * because their revenue entries stay on the Income page.
 */

export type Register = components["schemas"]["Register"]

/** The page's list (GET /registers?includeDeactivated=true), split into the two groups it shows. */
export function splitRegisters(registers: readonly Register[]): { active: Register[]; deactivated: Register[] } {
  return {
    active: registers.filter((r) => r.active),
    deactivated: registers.filter((r) => !r.active),
  }
}

function byName(a: Register, b: Register): number {
  return a.name.localeCompare(b.name)
}

/**
 * The list with an added, renamed or deactivated register in place, in the API's order:
 * active ones first, then deactivated ones, each by name. Lets a write's response update
 * the cache without a refetch.
 */
export function withRegister(registers: readonly Register[], updated: Register): Register[] {
  const others = registers.filter((r) => r.id !== updated.id)
  const { active, deactivated } = splitRegisters([...others, updated])
  return [...active.sort(byName), ...deactivated.sort(byName)]
}

/** Where to show a refused add or rename: on the name field, or under the form. */
export interface RegisterFormErrors {
  name?: string
  form?: string
}

/**
 * Turns the API's refusal of a name into messages for the form. A 409 is a duplicate name;
 * deactivated registers count too, so say so when the clash is with one of them. `registers`
 * is the list with deactivated ones, to name which register already has it.
 */
export function registerFormErrors(error: unknown, name: string, registers: readonly Register[]): RegisterFormErrors {
  if (error instanceof ApiError && error.status === 409) {
    const written = name.trim()
    const existing = registers.find((r) => r.name === written)
    return {
      name: existing && !existing.active
        ? `A deactivated register is already named “${written}”. Deactivated registers keep their names, so pick another one.`
        : `A register named “${written}” already exists.`,
    }
  }
  if (error instanceof ApiError && error.status === 422) {
    const { name: nameError, ...others } = violationsOf(error)
    const rest = Object.values(others).join(" ")
    const result: RegisterFormErrors = {}
    if (nameError) result.name = nameError
    // A 422 that names no field (or another one) still needs to be seen.
    if (rest || !nameError) result.form = rest || requestErrorMessage(error)
    return result
  }
  return { form: requestErrorMessage(error) }
}
