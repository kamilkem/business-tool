import { describe, expect, it } from "vitest"

import { MAX_PIECES, parsePieces, piecesTotal, stepPieces } from "./cash-count"

const COIN_50GR = { id: "d50gr", value: 50 }
const COIN_5 = { id: "d5", value: 500 }
const NOTE_100 = { id: "d100", value: 10_000 }
const DENOMINATIONS = [COIN_50GR, COIN_5, NOTE_100]

describe("piecesTotal", () => {
  it("test_it_adds_up_pieces_times_face_values_in_minor_units", () => {
    expect(piecesTotal(DENOMINATIONS, { d50gr: 3, d5: 2, d100: 4 })).toBe(3 * 50 + 2 * 500 + 4 * 10_000)
  })

  it("test_it_is_zero_with_nothing_entered", () => {
    expect(piecesTotal(DENOMINATIONS, undefined)).toBe(0)
    expect(piecesTotal(DENOMINATIONS, {})).toBe(0)
  })

  it("test_it_ignores_pieces_of_a_denomination_no_longer_offered", () => {
    expect(piecesTotal([COIN_5], { d5: 1, gone: 99 })).toBe(500)
  })
})

describe("parsePieces and stepPieces", () => {
  it("test_it_reads_whole_numbers_and_an_empty_field_as_zero", () => {
    expect(parsePieces("12")).toBe(12)
    expect(parsePieces(" ")).toBe(0)
    expect(parsePieces("1.5")).toBeNull()
    expect(parsePieces("-1")).toBeNull()
    expect(parsePieces(String(MAX_PIECES + 1))).toBeNull()
  })

  it("test_it_steps_within_zero_and_the_maximum", () => {
    expect(stepPieces(0, -1)).toBe(0)
    expect(stepPieces(2, 1)).toBe(3)
    expect(stepPieces(MAX_PIECES, 1)).toBe(MAX_PIECES)
  })
})
