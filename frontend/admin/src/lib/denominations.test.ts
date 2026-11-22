import { ApiError } from "@business-tool/api-client"
import { afterEach, beforeEach, describe, expect, it } from "vitest"

import { i18n } from "../i18n"
import {
  type Denomination,
  denominationFormErrors,
  denominationsByKind,
  splitDenominations,
  withDenomination,
  withoutDenomination,
} from "./denominations"

function denomination(value: number, kind: Denomination["kind"] = value < 1000 ? "coin" : "note", active = true): Denomination {
  return {
    id: `denomination-${value}`,
    currency: "PLN",
    value,
    kind,
    active,
    used: false,
    createdAt: "2026-11-01T10:00:00+00:00",
    deactivatedAt: active ? null : "2026-11-10T10:00:00+00:00",
  }
}

const grosz = denomination(1)
const fiveZloty = denomination(500)
const tenZloty = denomination(1000)
const oldNote = denomination(50000, "note", false)

describe("splitDenominations", () => {
  it("test_it_splits_active_and_deactivated_denominations_keeping_their_order", () => {
    expect(splitDenominations([grosz, tenZloty, oldNote])).toEqual({ active: [grosz, tenZloty], deactivated: [oldNote] })
  })
})

describe("denominationsByKind", () => {
  it("test_it_groups_coins_before_notes_keeping_the_order_of_each", () => {
    expect(denominationsByKind([grosz, fiveZloty, tenZloty])).toEqual([
      { kind: "coin", denominations: [grosz, fiveZloty] },
      { kind: "note", denominations: [tenZloty] },
    ])
  })

  it("test_it_leaves_out_a_kind_without_denominations", () => {
    expect(denominationsByKind([tenZloty])).toEqual([{ kind: "note", denominations: [tenZloty] }])
    expect(denominationsByKind([])).toEqual([])
  })
})

describe("withDenomination", () => {
  it("test_it_puts_an_added_denomination_in_value_order_before_the_deactivated_ones", () => {
    const twoZloty = denomination(200)

    expect(withDenomination([grosz, fiveZloty, tenZloty, oldNote], twoZloty)).toEqual([grosz, twoZloty, fiveZloty, tenZloty, oldNote])
  })

  it("test_it_moves_a_deactivated_denomination_among_the_deactivated_ones_and_back", () => {
    const deactivated = { ...fiveZloty, active: false, deactivatedAt: "2026-11-12T10:00:00+00:00" }
    const reactivated = { ...oldNote, active: true, deactivatedAt: null }

    expect(withDenomination([grosz, fiveZloty, tenZloty, oldNote], deactivated)).toEqual([grosz, tenZloty, deactivated, oldNote])
    expect(withDenomination([grosz, tenZloty, oldNote], reactivated)).toEqual([grosz, tenZloty, reactivated])
  })

  it("test_it_does_not_change_the_list_it_was_given", () => {
    const list = [tenZloty, grosz]
    withDenomination(list, fiveZloty)

    expect(list).toEqual([tenZloty, grosz])
  })
})

describe("withoutDenomination", () => {
  it("test_it_drops_a_deleted_denomination", () => {
    expect(withoutDenomination([grosz, fiveZloty], grosz.id)).toEqual([fiveZloty])
  })
})

describe("denominationFormErrors", () => {
  const denominations = [grosz, fiveZloty, tenZloty, oldNote]
  const taken = new ApiError(409, { detail: "Taken", code: "cash_flow.denomination_value_taken", parameters: {} })

  it("test_it_shows_a_value_already_on_the_list_on_the_value_field", () => {
    expect(denominationFormErrors(taken, 500, "5,00 zł", denominations)).toEqual({ value: "5,00 zł is already on the list." })
  })

  it("test_it_points_to_the_deactivated_denomination_to_reactivate", () => {
    expect(denominationFormErrors(taken, 50000, "500,00 zł", denominations)).toEqual({
      value: "500,00 zł is already on the list, deactivated. Reactivate it instead.",
    })
  })

  it("test_it_shows_the_domain_refusal_of_a_value_on_the_value_field", () => {
    const invalid = new ApiError(422, { detail: "Invalid", code: "cash_flow.invalid_denomination_value", parameters: {} })

    expect(denominationFormErrors(invalid, 0, "0,00 zł", denominations)).toEqual({
      value: "A denomination's value must be greater than zero.",
    })
  })

  it("test_it_shows_violations_on_their_fields", () => {
    const invalid = new ApiError(422, {
      detail: "Invalid",
      violations: [{ propertyPath: "kind", message: "The value you selected is not a valid choice." }],
    })

    expect(denominationFormErrors(invalid, 100, "1,00 zł", denominations)).toEqual({
      kind: "The value you selected is not a valid choice.",
    })
  })

  it("test_it_shows_any_other_failure_under_the_form", () => {
    expect(denominationFormErrors(new ApiError(500, {}), 100, "1,00 zł", denominations)).toEqual({
      form: "Something went wrong on our side. Try again in a moment.",
    })
  })

  describe("in Polish", () => {
    beforeEach(async () => {
      await i18n.changeLanguage("pl")
    })
    afterEach(async () => {
      await i18n.changeLanguage("en")
    })

    it("test_it_explains_a_value_already_on_the_list_in_polish", () => {
      expect(denominationFormErrors(taken, 500, "5,00 zł", denominations)).toEqual({ value: "Nominał 5,00 zł już jest na liście." })
    })
  })
})
