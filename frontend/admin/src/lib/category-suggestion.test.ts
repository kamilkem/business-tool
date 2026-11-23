import { describe, expect, it } from "vitest"

import { formCategory, needsConfirmation, suggestionSeller } from "./category-suggestion"
import type { ExpenseCategory } from "./expense-categories"

function category(name: string): ExpenseCategory {
  return { id: `category-${name}`, name, archived: false, createdAt: "2026-11-01T10:00:00+00:00", archivedAt: null }
}

const food = category("Food")
const drinks = category("Drinks")
const active = [food, drinks]

describe("suggestionSeller", () => {
  it("test_it_asks_by_the_tax_id_when_typed_else_by_the_name", () => {
    expect(suggestionSeller({ sellerTaxId: " 526-025 ", sellerName: "Hurtownia" })).toEqual({ sellerTaxId: "526-025" })
    expect(suggestionSeller({ sellerTaxId: "  ", sellerName: " Targ Rybny " })).toEqual({ sellerName: "Targ Rybny" })
    expect(suggestionSeller({ sellerTaxId: "", sellerName: "   " })).toBeNull()
  })
})

describe("formCategory", () => {
  it("test_it_pre_fills_an_active_suggested_category_until_one_is_chosen", () => {
    const suggestion = { categoryId: food.id, categoryName: "Food" }

    expect(formCategory({ chosen: false, categoryId: null, suggestion, activeCategories: active })).toEqual({
      categoryId: food.id,
      suggested: true,
    })
    expect(formCategory({ chosen: true, categoryId: drinks.id, suggestion, activeCategories: active })).toEqual({
      categoryId: drinks.id,
      suggested: false,
    })
    expect(formCategory({ chosen: true, categoryId: null, suggestion, activeCategories: active })).toEqual({
      categoryId: null,
      suggested: false,
    })
  })

  it("test_it_keeps_the_forms_category_without_a_usable_suggestion", () => {
    for (const suggestion of [undefined, null, { categoryId: null, categoryName: null }, { categoryId: "category-Gone", categoryName: "Gone" }]) {
      expect(formCategory({ chosen: false, categoryId: null, suggestion, activeCategories: active })).toEqual({
        categoryId: null,
        suggested: false,
      })
    }
  })
})

describe("needsConfirmation", () => {
  it("test_it_asks_to_confirm_only_a_suggested_category", () => {
    expect(needsConfirmation({ categoryId: food.id, categorySuggested: true })).toBe(true)
    expect(needsConfirmation({ categoryId: food.id, categorySuggested: false })).toBe(false)
    expect(needsConfirmation({ categoryId: null, categorySuggested: true })).toBe(false)
  })
})
