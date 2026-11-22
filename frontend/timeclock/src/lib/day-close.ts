import type { components } from "@business-tool/api-client"
import { addDays, type BusinessDate } from "@business-tool/ui/lib/date-time"
import type { MinorUnits } from "@business-tool/ui/lib/money"

export type DayCloseForm = components["schemas"]["DayCloseForm"]
export type DayCloseReceipt = components["schemas"]["DayCloseReceipt"]
export type RecordDayCloseInput = components["schemas"]["DayCloseReceipt.RecordDayCloseInput"]
export type DayCloseStorage = components["schemas"]["DayCloseFormStorage"]
export type DayCloseDenomination = components["schemas"]["DayCloseFormDenomination"]

/** The most pieces of one denomination a line may have (the API's `CashMovement::MAX_PIECES`). */
export const MAX_PIECES = 1_000_000

/** The most a non-cash amount may be, in minor units (the API's `RevenueEntry::MAX_AMOUNT`). */
export const MAX_AMOUNT = 2_147_483_647

/** Pieces by denomination id; a denomination left out is zero. */
export type Pieces = Readonly<Record<string, number>>

/** What the person has entered so far. */
export interface DayCloseValues {
  date: BusinessDate
  registerId: string | null
  /** Pieces by storage id: what stays in a counted storage, what was put into a deposit one. */
  pieces: Readonly<Record<string, Pieces>>
  /** Non-cash amounts by payment method id, in minor units; null while the field is empty. */
  amounts: Readonly<Record<string, MinorUnits | null>>
  /** Payment methods whose field holds text that is not an amount. */
  invalidAmounts: readonly string[]
  /** `<storageId>:<denominationId>` of the fields whose text is not a number of pieces. */
  invalidPieces: readonly string[]
}

/** One screen of the close, in the order they are shown. */
export type DayCloseStep =
  | { kind: "day" }
  | { kind: "storage"; storage: DayCloseStorage }
  | { kind: "payments" }
  | { kind: "summary" }

/** A new close: the proposed date, the preselected register, nothing counted. */
export function initialValues(form: DayCloseForm): DayCloseValues {
  return {
    date: form.proposedDate,
    registerId: form.preselectedRegisterId ?? (form.registers.length === 1 ? form.registers[0]!.id : null),
    pieces: {},
    amounts: {},
    invalidAmounts: [],
    invalidPieces: [],
  }
}

/**
 * The dates the person may close (ADR 0033 §5): in `employee_picks` mode today and yesterday, in the
 * organization's time zone; otherwise only the date the day-end time gives.
 */
export function dateChoices(form: DayCloseForm): BusinessDate[] {
  if (form.mode !== "employee_picks") return [form.proposedDate]
  return [form.today, addDays(form.today, -1)]
}

/** Whether the day step asks anything: a date to pick, or a register to choose among several. */
export function asksForDay(form: DayCloseForm): boolean {
  return dateChoices(form).length > 1 || form.registers.length > 1
}

/**
 * The steps of the close: the day (when there is something to choose), deposits first, then the
 * counts, the non-cash methods (when any is active) and the summary. Deposits come first so the cash
 * put into the safe is out of the cash box before it is counted.
 */
export function stepsOf(form: DayCloseForm): DayCloseStep[] {
  const byMode = (mode: DayCloseStorage["mode"]) =>
    form.storages.filter((storage) => storage.mode === mode).map((storage) => ({ kind: "storage", storage }) as const)
  return [
    ...(asksForDay(form) ? [{ kind: "day" } as const] : []),
    ...byMode("deposit"),
    ...byMode("counted"),
    ...(form.paymentMethods.length > 0 ? [{ kind: "payments" } as const] : []),
    { kind: "summary" },
  ]
}

/** The value of the pieces, in minor units: Σ pieces × face value, over the denominations offered. */
export function piecesTotal(denominations: readonly DayCloseDenomination[], pieces: Pieces | undefined): MinorUnits {
  if (!pieces) return 0
  return denominations.reduce((total, denomination) => total + denomination.value * (pieces[denomination.id] ?? 0), 0)
}

/** The value of each storage's pieces, in the form's order of storages. */
export function storageTotals(form: DayCloseForm, values: DayCloseValues): { storage: DayCloseStorage; total: MinorUnits }[] {
  return form.storages.map((storage) => ({ storage, total: piecesTotal(form.denominations, values.pieces[storage.id]) }))
}

/** The sum of the non-cash amounts; an empty field is zero. */
export function nonCashTotal(form: DayCloseForm, values: DayCloseValues): MinorUnits {
  return form.paymentMethods.reduce((total, method) => total + (values.amounts[method.id] ?? 0), 0)
}

/**
 * The number of pieces typed into a field: a whole number from 0 to `MAX_PIECES`, else null. An
 * empty field is zero, so a denomination nobody has is simply left blank.
 */
export function parsePieces(text: string): number | null {
  const trimmed = text.trim()
  if (trimmed === "") return 0
  if (!/^\d+$/.test(trimmed)) return null
  const pieces = Number(trimmed)
  return pieces <= MAX_PIECES ? pieces : null
}

/** One more or one fewer piece, kept from 0 to `MAX_PIECES`. */
export function stepPieces(pieces: number, by: 1 | -1): number {
  return Math.min(MAX_PIECES, Math.max(0, pieces + by))
}

/**
 * Sets a storage's pieces of one denomination; null when the field holds text that is not a number
 * of pieces, which counts as zero and holds the step until it is fixed.
 */
export function withPieces(values: DayCloseValues, storageId: string, denominationId: string, pieces: number | null): DayCloseValues {
  const key = `${storageId}:${denominationId}`
  const others = values.invalidPieces.filter((invalid) => invalid !== key)
  return {
    ...values,
    pieces: { ...values.pieces, [storageId]: { ...values.pieces[storageId], [denominationId]: pieces ?? 0 } },
    invalidPieces: pieces === null ? [...others, key] : others,
  }
}

/** Sets a non-cash amount, remembering whether the field holds an amount at all. */
export function withAmount(values: DayCloseValues, methodId: string, amount: MinorUnits | null, invalid: boolean): DayCloseValues {
  const others = values.invalidAmounts.filter((id) => id !== methodId)
  return {
    ...values,
    amounts: { ...values.amounts, [methodId]: amount },
    invalidAmounts: invalid ? [...others, methodId] : others,
  }
}

/**
 * Whether the person may go on from the step: a register chosen, every number of pieces and every
 * amount readable and within the limits.
 */
export function canLeaveStep(step: DayCloseStep, form: DayCloseForm, values: DayCloseValues): boolean {
  switch (step.kind) {
    case "day":
      return values.registerId !== null && form.registers.some((register) => register.id === values.registerId)
    case "storage":
      return !values.invalidPieces.some((key) => key.startsWith(`${step.storage.id}:`))
    case "payments":
      return form.paymentMethods.every(
        (method) => !values.invalidAmounts.includes(method.id) && (values.amounts[method.id] ?? 0) <= MAX_AMOUNT,
      )
    default:
      return true
  }
}

/**
 * The body of `POST /day-closes`. Every active storage once: a counted storage with every
 * denomination (zeros included), a deposit storage with only what was put in. Every non-cash method
 * once, an empty field as zero. The date is always sent, so the close gets the date the person saw
 * even if the day-end time passes while they count (the API then refuses it rather than close
 * another date).
 */
export function recordDayCloseBody(form: DayCloseForm, values: DayCloseValues): RecordDayCloseInput {
  if (values.registerId === null) throw new Error("A close needs a register.")
  return {
    registerId: values.registerId,
    date: values.date,
    storages: form.storages.map((storage) => {
      const pieces = values.pieces[storage.id] ?? {}
      const lines = form.denominations.map((denomination) => ({
        denominationId: denomination.id,
        pieces: pieces[denomination.id] ?? 0,
      }))
      return { storageId: storage.id, lines: storage.mode === "counted" ? lines : lines.filter((line) => line.pieces > 0) }
    }),
    payments: form.paymentMethods.map((method) => ({ paymentMethodId: method.id, amount: values.amounts[method.id] ?? 0 })),
  }
}
