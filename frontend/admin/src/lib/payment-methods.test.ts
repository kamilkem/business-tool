import { ApiError } from "@business-tool/api-client"
import { afterEach, beforeEach, describe, expect, it } from "vitest"

import { i18n } from "../i18n"
import {
  type PaymentMethod,
  entryFormMethods,
  moveItem,
  otherActiveCashMethod,
  paymentMethodFormErrors,
  reorderedPaymentMethods,
  splitPaymentMethods,
  withPaymentMethod,
  withoutPaymentMethod,
} from "./payment-methods"

function method(name: string, { active = true, used = false, cash = false } = {}): PaymentMethod {
  return {
    id: `m-${name}`,
    name,
    active,
    cash,
    used,
    createdAt: "2026-10-01T10:00:00+00:00",
    deactivatedAt: active ? null : "2026-11-05T10:00:00+00:00",
  }
}

const cash = method("Cash", { used: true, cash: true })
const card = method("Card")
const blik = method("BLIK")
const voucher = method("Voucher", { active: false, used: true })
const old = method("Old", { active: false })
/** As the API lists them: active ones in the organization's order, then deactivated ones. */
const LIST = [cash, card, blik, voucher, old]

describe("the list", () => {
  it("test_it_splits_active_and_deactivated_methods_keeping_their_order", () => {
    expect(splitPaymentMethods(LIST)).toEqual({ active: [cash, card, blik], deactivated: [voucher, old] })
  })

  it("test_it_puts_an_added_or_reactivated_method_last_among_active_ones_and_a_deactivated_one_last", () => {
    const meal = method("Meal vouchers")
    expect(withPaymentMethod(LIST, meal).map((m) => m.name)).toEqual(["Cash", "Card", "BLIK", "Meal vouchers", "Voucher", "Old"])
    expect(withPaymentMethod(LIST, { ...voucher, active: true, deactivatedAt: null }).map((m) => m.name)).toEqual(["Cash", "Card", "BLIK", "Voucher", "Old"])
    expect(withPaymentMethod(LIST, { ...card, active: false }).map((m) => m.name)).toEqual(["Cash", "BLIK", "Voucher", "Old", "Card"])
  })

  it("test_it_renames_a_method_in_place_and_removes_a_deleted_one", () => {
    expect(withPaymentMethod(LIST, { ...card, name: "Card (terminal)" }).map((m) => m.name)).toEqual(["Cash", "Card (terminal)", "BLIK", "Voucher", "Old"])
    expect(withoutPaymentMethod(LIST, blik.id)).toEqual([cash, card, voucher, old])
  })

  it("test_it_moves_an_item_and_reorders_the_active_methods_with_deactivated_ones_last", () => {
    expect(moveItem(["a", "b", "c", "d"], 3, 0)).toEqual(["d", "a", "b", "c"])
    expect(moveItem(["a", "b", "c", "d"], 0, 2)).toEqual(["b", "c", "a", "d"])
    expect(moveItem(["a"], 4, 0)).toEqual(["a"])
    expect(reorderedPaymentMethods(LIST, [blik.id, cash.id, card.id])).toEqual([blik, cash, card, voucher, old])
    // A method the order left out (added meanwhile) stays after the ordered ones.
    expect(reorderedPaymentMethods(LIST, [card.id, cash.id])).toEqual([card, cash, blik, voucher, old])
  })
})

describe("entryFormMethods", () => {
  it("test_it_offers_the_active_methods_in_order_for_a_new_entry", () => {
    expect(entryFormMethods(LIST)).toEqual([
      { id: "m-Cash", name: "Cash", active: true },
      { id: "m-Card", name: "Card", active: true },
      { id: "m-BLIK", name: "BLIK", active: true },
    ])
  })

  it("test_it_keeps_the_deactivated_methods_an_entry_has_after_the_active_ones", () => {
    const rows = entryFormMethods(LIST, [
      { paymentMethodId: voucher.id, paymentMethodName: "Voucher" },
      { paymentMethodId: cash.id, paymentMethodName: "Cash" },
      { paymentMethodId: "m-deleted-meanwhile", paymentMethodName: "Gift card" },
    ])

    expect(rows.map((row) => [row.name, row.active])).toEqual([
      ["Cash", true],
      ["Card", true],
      ["BLIK", true],
      ["Voucher", false],
      ["Gift card", false],
    ])
  })
})

describe("otherActiveCashMethod", () => {
  it("test_it_finds_the_active_cash_method_other_than_the_one_edited", () => {
    expect(otherActiveCashMethod(LIST)).toBe(cash)
    expect(otherActiveCashMethod(LIST, card)).toBe(cash)
    expect(otherActiveCashMethod(LIST, cash)).toBeUndefined()
  })

  it("test_it_ignores_a_deactivated_cash_method", () => {
    const oldCash = method("Old cash", { active: false, cash: true })

    expect(otherActiveCashMethod([card, oldCash])).toBeUndefined()
  })
})

describe("paymentMethodFormErrors", () => {
  it("test_it_puts_another_cash_method_on_the_checkbox_and_names_it", () => {
    const conflict = new ApiError(409, { detail: "cash", code: "income.cash_payment_method_taken", parameters: { paymentMethodId: card.id, name: "Card" } })

    expect(paymentMethodFormErrors(conflict, "Card", LIST)).toEqual({ cash: "“Cash” is already marked as cash. Remove its mark first." })
    // The list no longer shows the cash method (it changed meanwhile): the code's own message.
    expect(paymentMethodFormErrors(conflict, "Card", [card])).toEqual({
      cash: "Another active payment method is already marked as cash. Remove its mark first.",
    })
  })

  it("test_it_puts_a_cash_violation_on_the_checkbox", () => {
    const invalid = new ApiError(422, { violations: [{ propertyPath: "cash", message: "Not a boolean." }] })

    expect(paymentMethodFormErrors(invalid, "x", LIST)).toEqual({ cash: "Not a boolean." })
  })

  it("test_it_puts_a_duplicate_name_on_the_field_and_says_when_a_deactivated_method_has_it", () => {
    const conflict = new ApiError(409, { detail: "taken", code: "income.payment_method_name_taken", parameters: { name: "Voucher" } })

    expect(paymentMethodFormErrors(conflict, " Card ", LIST)).toEqual({ name: "A payment method named “Card” already exists." })
    expect(paymentMethodFormErrors(conflict, "Voucher", LIST).name).toContain("deactivated payment method")
  })

  it("test_it_puts_violations_on_the_name_and_anything_else_under_the_form", () => {
    const invalid = new ApiError(422, { violations: [{ propertyPath: "name", message: "Too long." }] })
    const odd = new ApiError(422, { violations: [{ propertyPath: "other", message: "Odd." }] })

    expect(paymentMethodFormErrors(invalid, "x", LIST)).toEqual({ name: "Too long." })
    expect(paymentMethodFormErrors(odd, "x", LIST)).toEqual({ form: "Odd." })
  })

  describe("in Polish", () => {
    beforeEach(async () => {
      await i18n.changeLanguage("pl")
    })
    afterEach(async () => {
      await i18n.changeLanguage("en")
    })

    it("test_it_explains_a_duplicate_name_in_polish", () => {
      const conflict = new ApiError(409, { detail: "taken" })

      expect(paymentMethodFormErrors(conflict, "Card", LIST)).toEqual({ name: "Metoda płatności o nazwie „Card” już istnieje." })
    })

    it("test_it_explains_another_cash_method_in_polish", () => {
      const conflict = new ApiError(409, { detail: "cash", code: "income.cash_payment_method_taken", parameters: { paymentMethodId: card.id, name: "Card" } })

      expect(paymentMethodFormErrors(conflict, "Card", LIST)).toEqual({ cash: "Metoda „Cash” jest już oznaczona jako gotówka. Najpierw usuń jej oznaczenie." })
      expect(paymentMethodFormErrors(conflict, "Card", [card])).toEqual({
        cash: "Inna aktywna metoda płatności jest już oznaczona jako gotówka. Najpierw usuń jej oznaczenie.",
      })
    })
  })
})
