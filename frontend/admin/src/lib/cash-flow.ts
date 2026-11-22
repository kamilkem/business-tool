import type { components } from "@business-tool/api-client"
import { MAX_PIECES, piecesTotal } from "@business-tool/ui/lib/cash-count"
import {
  type BusinessDate,
  type BusinessPeriod,
  addDays,
  businessDateOf,
  compareBusinessDates,
  instantsAt,
  isBusinessDate,
  isWallTime,
  localDateTimeOf,
  periodError,
  toZonedIsoString,
} from "@business-tool/ui/lib/date-time"
import type { MinorUnits } from "@business-tool/ui/lib/money"
import type { ShiftMoment } from "@business-tool/ui/lib/shift-form"

import type { DayClose, DayCloseStorageItem } from "./day-closes"

/*
 * The admin's Cash flow page (ADR 0033 §4): what each storage holds now, the ledger's history
 * (movements and closes) for a period, and the forms that add, take out, transfer and audit cash.
 */

export type CashBalance = components["schemas"]["CashBalance"]
export type CashBalanceLine = components["schemas"]["CashBalanceLine"]
export type CashMovement = components["schemas"]["CashMovement"]
export type MovementKind = CashMovement["kind"]
type Denomination = components["schemas"]["Denomination"]
type AdjustmentBody = components["schemas"]["CashMovement.RecordCashAdjustmentInput"]
type TransferBody = components["schemas"]["CashMovement.RecordCashTransferInput"]
type CountBody = components["schemas"]["CashMovement.RecordCashCountInput"]

/** The longest period the history may show (the API's `ListCashMovementsQuery::MAX_DAYS`). */
export const CASH_MOVEMENTS_MAX_DAYS = 92

/** The longest note of a movement (the API's `CashMovement::NOTE_MAX_LENGTH`). */
export const MOVEMENT_NOTE_MAX_LENGTH = 255

// --- The URL -----------------------------------------------------------------------------------

export interface CashFlowSearch {
  from: BusinessDate | undefined
  to: BusinessDate | undefined
  storageId: string | undefined
}

/** The history's period and storage from the URL; an unreadable or too long period falls back to this month. */
export function validateCashFlowSearch(search: Record<string, unknown>): CashFlowSearch {
  const { from, to, storageId } = search
  const period =
    typeof from === "string" &&
    typeof to === "string" &&
    isBusinessDate(from) &&
    isBusinessDate(to) &&
    periodError({ from, to }, CASH_MOVEMENTS_MAX_DAYS) === null
      ? { from, to }
      : { from: undefined, to: undefined }
  return { ...period, storageId: typeof storageId === "string" && storageId !== "" ? storageId : undefined }
}

// --- Balances ----------------------------------------------------------------------------------

/** The lines a storage holds pieces of (a counted storage's estimate may be below zero), by value. */
export function heldLines(balance: CashBalance): CashBalanceLine[] {
  return balance.lines.filter((line) => line.pieces !== 0)
}

/** Pieces of deactivated denominations still in the storage: a manager takes them out with an adjustment (ADR 0033 §2). */
export function retiredLines(balance: CashBalance): CashBalanceLine[] {
  return balance.lines.filter((line) => !line.active && line.pieces > 0)
}

/** The cash in all storages, in the organization's currency. Counted storages' parts are estimates. */
export function cashOnHand(balances: readonly CashBalance[]): MinorUnits {
  return balances.reduce((total, balance) => total + balance.total, 0)
}

// --- History -----------------------------------------------------------------------------------

export type HistoryEntry =
  | { type: "movement"; id: string; at: string; movement: CashMovement }
  /** `storages`: the close's storages, only the filtered one when the history shows one storage. */
  | { type: "close"; id: string; at: string; close: DayClose; storages: DayCloseStorageItem[] }

/**
 * The movements and closes of the period in ledger order, newest first (ADR 0033 §4: by moment,
 * then id). A close sits at `closedAt`. With a storage, only what touched it.
 */
export function cashFlowHistory(
  movements: readonly CashMovement[],
  closes: readonly DayClose[],
  storageId?: string,
): HistoryEntry[] {
  const entries: HistoryEntry[] = [
    ...movements
      .filter((movement) => !storageId || movement.storageId === storageId || movement.targetStorageId === storageId)
      .map((movement): HistoryEntry => ({ type: "movement", id: movement.id, at: movement.occurredAt, movement })),
    ...closes.flatMap((close): HistoryEntry[] => {
      const storages = storageId ? close.storages.filter((storage) => storage.storageId === storageId) : close.storages
      if (storageId && storages.length === 0) return []
      return [{ type: "close", id: close.id, at: close.closedAt, close, storages }]
    }),
  ]
  return entries.sort((a, b) => Date.parse(b.at) - Date.parse(a.at) || (a.id < b.id ? 1 : a.id > b.id ? -1 : 0))
}

/**
 * What a movement did, in minor units, signed as the storage saw it: an adjustment's change; a
 * transfer out of the storage negative and into it positive (without a storage, the amount moved);
 * a count's counted value.
 */
export function movementAmount(movement: CashMovement, storageId?: string): MinorUnits {
  if (movement.kind === "transfer" && storageId === movement.storageId) return -movement.total
  return movement.total
}

/** Whether the movement was entered later than it happened (more than a minute), so the history says when it was entered. */
export function enteredLater(movement: CashMovement): boolean {
  return Date.parse(movement.createdAt) - Date.parse(movement.occurredAt) > 60_000
}

// --- Deleting a movement -----------------------------------------------------------------------

/**
 * The dates to look for the close a deleted movement changes: from the day before the movement's
 * date (a close at 02:30 belongs to the previous day) for as long as the API lists closes, up to today.
 */
export function closesAfterPeriod(movement: CashMovement, timeZone: string, today: BusinessDate): BusinessPeriod {
  const from = addDays(businessDateOf(movement.occurredAt, timeZone), -1)
  const last = addDays(from, CASH_MOVEMENTS_MAX_DAYS - 1)
  return { from, to: compareBusinessDates(last, today) < 0 ? last : today }
}

/**
 * The closes whose expected opening changes when the movement is created or deleted (ADR 0033 §7):
 * on each storage it touches, the first close after it that counted that storage. A deposit
 * storage's deposits are not anchored by a count, so they change no close. Earliest first.
 */
export function affectedCloses(movement: CashMovement, closes: readonly DayClose[]): DayClose[] {
  const at = Date.parse(movement.occurredAt)
  const later = closes.filter((close) => Date.parse(close.closedAt) > at).sort((a, b) => Date.parse(a.closedAt) - Date.parse(b.closedAt))
  const touched = [movement.storageId, movement.targetStorageId].filter((id): id is string => id !== null)
  const found = new Map<string, DayClose>()
  for (const storageId of touched) {
    const close = later.find((candidate) =>
      candidate.storages.some((storage) => storage.storageId === storageId && storage.mode === "counted"),
    )
    if (close) found.set(close.id, close)
  }
  return [...found.values()].sort((a, b) => Date.parse(a.closedAt) - Date.parse(b.closedAt))
}

// --- The forms ---------------------------------------------------------------------------------

export type Direction = "add" | "remove"

/** A denomination a form offers, with the pieces the storage holds now (null when unknown). */
export interface FormDenomination {
  id: string
  value: MinorUnits
  kind: Denomination["kind"]
  active: boolean
  held: number | null
}

export interface MovementFormValues {
  kind: MovementKind
  /** The storage adjusted or counted, or the one a transfer takes cash out of. */
  storageId: string
  /** A transfer's other storage. */
  targetStorageId: string
  /** An adjustment adds or takes out; the pieces are typed without a sign. */
  direction: Direction
  /** Pieces typed per denomination; null for unreadable text. A denomination left out is zero. */
  pieces: Readonly<Record<string, number | null>>
  note: string
  /** Whether the cash moved earlier than now: then `moment` says when. */
  earlier: boolean
  moment: ShiftMoment
}

export function emptyMovementForm(kind: MovementKind, storageId: string, now: Date, timeZone: string): MovementFormValues {
  return {
    kind,
    storageId,
    targetStorageId: "",
    direction: "add",
    pieces: {},
    note: "",
    earlier: false,
    moment: { ...localDateTimeOf(now, timeZone), occurrence: "first" },
  }
}

/**
 * The denominations a form offers, by value:
 * - the active ones of the organization's currency;
 * - for taking cash out, also deactivated ones the storage still holds, the only way to empty them
 *   (a transfer and a count take active denominations only).
 */
export function formDenominations(
  values: Pick<MovementFormValues, "kind" | "direction">,
  denominations: readonly Denomination[],
  balance: CashBalance | undefined,
): FormDenomination[] {
  const held = new Map((balance?.lines ?? []).map((line) => [line.denominationId, line]))
  const offered: FormDenomination[] = denominations
    .filter((denomination) => denomination.active)
    .map((denomination) => ({
      id: denomination.id,
      value: denomination.value,
      kind: denomination.kind,
      active: true,
      held: balance ? (held.get(denomination.id)?.pieces ?? 0) : null,
    }))
  if (values.kind === "adjustment" && values.direction === "remove" && balance) {
    for (const line of retiredLines(balance)) {
      if (line.currency !== balance.currency) continue
      offered.push({ id: line.denominationId, value: line.value, kind: line.kind, active: false, held: line.pieces })
    }
  }
  return offered.sort((a, b) => a.value - b.value)
}

/** The pieces typed, unreadable ones as zero. */
function readablePieces(values: MovementFormValues): Record<string, number> {
  return Object.fromEntries(Object.entries(values.pieces).map(([id, pieces]) => [id, pieces ?? 0]))
}

/** The value of the pieces typed, signed for an adjustment that takes cash out: the form's live total. */
export function movementFormTotal(values: MovementFormValues, denominations: readonly FormDenomination[]): MinorUnits {
  const total = piecesTotal(denominations, readablePieces(values))
  return values.kind === "adjustment" && values.direction === "remove" ? -total : total
}

/**
 * What an audit count would show against the storage's balance now: counted value minus balance.
 * Null for another kind, without a balance, or for a count entered for an earlier moment (its
 * balance is the one just before that moment, which the API computes).
 */
export function countDiscrepancy(
  values: MovementFormValues,
  denominations: readonly FormDenomination[],
  balance: CashBalance | undefined,
): MinorUnits | null {
  if (values.kind !== "count" || !balance || values.earlier) return null
  return movementFormTotal(values, denominations) - balance.total
}

/** The storage's balance after the movement, when it happens now: for the form to show what is left. */
export function balanceAfter(
  values: MovementFormValues,
  denominations: readonly FormDenomination[],
  balance: CashBalance | undefined,
): MinorUnits | null {
  if (!balance || values.earlier) return null
  const total = movementFormTotal(values, denominations)
  if (values.kind === "count") return total
  if (values.kind === "transfer") return balance.total - total
  return balance.total + total
}

export type MovementProblem =
  | "storage"
  | "target"
  | "sameStorage"
  | "pieces"
  | "nothing"
  | "note"
  | "momentInvalid"
  | "momentSkipped"
  | "momentInFuture"

export type MovementRequest =
  | { kind: "adjustment"; body: AdjustmentBody }
  | { kind: "transfer"; body: TransferBody }
  | { kind: "count"; body: CountBody }

export type MovementFormResult = { ok: true; request: MovementRequest } | { ok: false; problems: MovementProblem[] }

/** The instant the "when it happened" fields name, or why they name none. */
function momentOf(moment: ShiftMoment, timeZone: string): Date | "momentInvalid" | "momentSkipped" {
  if (!isBusinessDate(moment.date) || !isWallTime(moment.time)) return "momentInvalid"
  const candidates = instantsAt({ date: moment.date, time: moment.time }, timeZone)
  const instant = moment.occurrence === "second" ? (candidates[1] ?? candidates[0]) : candidates[0]
  return instant ?? "momentSkipped"
}

/**
 * The request a form sends, or what to fix first. The API checks again (the storage's balance,
 * deactivated storages and denominations, the moment against its own clock).
 * - An adjustment sends the non-zero lines, signed; a transfer the non-zero ones; a count every
 *   denomination offered, zeros included, so what was counted empty is recorded.
 * - `occurredAt` only for an earlier moment, with the organization's offset; else the API's now.
 */
export function movementRequest(
  values: MovementFormValues,
  denominations: readonly FormDenomination[],
  timeZone: string,
  now: Date = new Date(),
): MovementFormResult {
  const problems: MovementProblem[] = []
  if (values.storageId === "") problems.push("storage")
  if (values.kind === "transfer") {
    if (values.targetStorageId === "") problems.push("target")
    else if (values.targetStorageId === values.storageId) problems.push("sameStorage")
  }
  // Unreadable text (null) stays null, a field never typed in is zero.
  const shown = denominations.map((denomination) => {
    const pieces = values.pieces[denomination.id]
    return { id: denomination.id, pieces: pieces === undefined ? 0 : pieces }
  })
  if (shown.some((line) => line.pieces === null || line.pieces < 0 || line.pieces > MAX_PIECES)) problems.push("pieces")
  else if (values.kind !== "count" && shown.every((line) => line.pieces === 0)) problems.push("nothing")
  const note = values.note.trim()
  if (note.length > MOVEMENT_NOTE_MAX_LENGTH) problems.push("note")

  let occurredAt: string | undefined
  if (values.earlier) {
    const instant = momentOf(values.moment, timeZone)
    if (typeof instant === "string") problems.push(instant)
    else if (instant.getTime() > now.getTime()) problems.push("momentInFuture")
    else occurredAt = toZonedIsoString(instant, timeZone)
  }
  if (problems.length > 0) return { ok: false, problems }

  const lines = shown as { id: string; pieces: number }[]
  const nonZero = lines.filter((line) => line.pieces !== 0)
  const common = { note: note === "" ? null : note, ...(occurredAt ? { occurredAt } : {}) }
  switch (values.kind) {
    case "adjustment": {
      const sign = values.direction === "remove" ? -1 : 1
      return {
        ok: true,
        request: {
          kind: "adjustment",
          body: {
            storageId: values.storageId,
            lines: nonZero.map((line) => ({ denominationId: line.id, pieces: sign * line.pieces })),
            ...common,
          },
        },
      }
    }
    case "transfer":
      return {
        ok: true,
        request: {
          kind: "transfer",
          body: {
            fromStorageId: values.storageId,
            toStorageId: values.targetStorageId,
            lines: nonZero.map((line) => ({ denominationId: line.id, pieces: line.pieces })),
            ...common,
          },
        },
      }
    case "count":
      return {
        ok: true,
        request: {
          kind: "count",
          body: { storageId: values.storageId, lines: lines.map((line) => ({ denominationId: line.id, pieces: line.pieces })), ...common },
        },
      }
  }
}

/**
 * The storages a form offers:
 * - an adjustment: every storage in the balances (deactivated ones are listed while they hold cash,
 *   and can only be emptied);
 * - a transfer: any of them as the source, an active other one as the target;
 * - a count: active deposit storages only (a counted storage is counted at the close).
 */
export function storageChoices(
  kind: MovementKind,
  balances: readonly CashBalance[],
): { sources: CashBalance[]; targets: CashBalance[] } {
  if (kind === "count") return { sources: balances.filter((b) => b.active && b.mode === "deposit"), targets: [] }
  return { sources: [...balances], targets: kind === "transfer" ? balances.filter((b) => b.active) : [] }
}
