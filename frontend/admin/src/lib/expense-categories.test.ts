import { ApiError } from "@business-tool/api-client"
import { describe, expect, it } from "vitest"

import { type ExpenseCategory, categoryFormErrors, splitCategories, withCategory } from "./expense-categories"

function category(name: string, archived = false, id = `category-${name}`): ExpenseCategory {
  return {
    id,
    name,
    archived,
    createdAt: "2026-11-01T10:00:00+00:00",
    archivedAt: archived ? "2026-11-10T10:00:00+00:00" : null,
  }
}

const food = category("Food")
const rent = category("Rent")
const marketing = category("Marketing", true)

describe("splitCategories", () => {
  it("test_it_splits_active_and_archived_categories_keeping_their_order", () => {
    expect(splitCategories([food, rent, marketing])).toEqual({ active: [food, rent], archived: [marketing] })
    expect(splitCategories([])).toEqual({ active: [], archived: [] })
  })
})

describe("withCategory", () => {
  it("test_it_puts_an_added_category_in_name_order_before_the_archived_ones", () => {
    const drinks = category("Drinks")

    expect(withCategory([food, rent, marketing], drinks)).toEqual([drinks, food, rent, marketing])
  })

  it("test_it_moves_a_renamed_category_to_its_new_place", () => {
    const renamed = { ...food, name: "Supplies" }

    expect(withCategory([food, rent, marketing], renamed)).toEqual([rent, renamed, marketing])
  })

  it("test_it_moves_an_archived_category_among_the_archived_ones", () => {
    const archived = { ...rent, archived: true, archivedAt: "2026-11-12T10:00:00+00:00" }

    expect(withCategory([food, rent, marketing], archived)).toEqual([food, marketing, archived])
  })

  it("test_it_moves_a_restored_category_back_among_the_active_ones", () => {
    const restored = { ...marketing, archived: false, archivedAt: null }

    expect(withCategory([food, rent, marketing], restored)).toEqual([food, restored, rent])
  })

  it("test_it_does_not_change_the_list_it_was_given", () => {
    const list = [food, rent]
    withCategory(list, category("Drinks"))

    expect(list).toEqual([food, rent])
  })
})

describe("categoryFormErrors", () => {
  const categories = [food, rent, marketing]

  it("test_it_shows_a_duplicate_name_on_the_name_field", () => {
    const conflict = new ApiError(409, { detail: 'Expense category "Food" already exists.' })

    expect(categoryFormErrors(conflict, "  Food ", categories)).toEqual({
      name: "A category named “Food” already exists.",
    })
  })

  it("test_it_says_when_the_name_belongs_to_an_archived_category", () => {
    const conflict = new ApiError(409, { detail: "Conflict" })

    expect(categoryFormErrors(conflict, "Marketing", categories).name).toContain("archived category")
  })

  it("test_it_shows_name_violations_on_the_name_field", () => {
    const invalid = new ApiError(422, {
      detail: "name: This value should not be blank.",
      violations: [{ propertyPath: "name", message: "This value should not be blank." }],
    })

    expect(categoryFormErrors(invalid, "", categories)).toEqual({ name: "This value should not be blank." })
  })

  it("test_it_shows_violations_of_other_fields_under_the_form", () => {
    const invalid = new ApiError(422, {
      detail: "id: Unexpected.",
      violations: [{ propertyPath: "id", message: "Unexpected." }],
    })

    expect(categoryFormErrors(invalid, "Food", categories)).toEqual({ form: "Unexpected." })
  })

  it("test_it_shows_a_422_without_violations_under_the_form", () => {
    expect(categoryFormErrors(new ApiError(422, { detail: "Invalid body." }), "Food", categories)).toEqual({
      form: "Invalid body.",
    })
  })

  it("test_it_shows_any_other_failure_under_the_form", () => {
    expect(categoryFormErrors(new ApiError(500, { detail: "Server error" }), "Food", categories)).toEqual({
      form: "Server error",
    })
    expect(categoryFormErrors(new TypeError("Failed to fetch"), "Food", categories)).toEqual({
      form: "Failed to fetch",
    })
  })
})
