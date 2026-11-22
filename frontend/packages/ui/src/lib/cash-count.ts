import type { MinorUnits } from "./money"

/*
 * Counting cash by denomination, for the end-of-day close in both apps (ADR 0033 §6): the time
 * clock's Close the day screen and the admin's review and correction of a close.
 */

/** The most pieces of one denomination a line may have (the API's `CashMovement::MAX_PIECES`). */
export const MAX_PIECES = 1_000_000

/** The most a non-cash amount may be, in minor units (the API's `RevenueEntry::MAX_AMOUNT`). */
export const MAX_AMOUNT = 2_147_483_647

/** Pieces by denomination id; a denomination left out is zero. */
export type Pieces = Readonly<Record<string, number>>

/** A denomination as far as counting goes: its id and face value in minor units. */
export interface CountedDenomination {
  id: string
  value: MinorUnits
}

/** The value of the pieces, in minor units: Σ pieces × face value, over the denominations given. */
export function piecesTotal(denominations: readonly CountedDenomination[], pieces: Pieces | undefined): MinorUnits {
  if (!pieces) return 0
  return denominations.reduce((total, denomination) => total + denomination.value * (pieces[denomination.id] ?? 0), 0)
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
