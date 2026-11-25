import { ApiError } from "@business-tool/api-client"
import { describe, expect, it } from "vitest"

import {
  buildTree,
  categoryFormErrors,
  descendantIds,
  parentChoices,
  type ProductCategory,
  withCategory,
  withoutCategory,
} from "./product-categories"

function category(id: string, name: string, parentId: string | null = null): ProductCategory {
  return { id, name, parentId, createdAt: "2026-11-01T10:00:00+00:00" }
}

const alcohol = category("alcohol", "Alcohol")
const food = category("food", "Food")
const whisky = category("whisky", "Whisky", "alcohol")
const beer = category("beer", "Beer", "alcohol")
const bourbon = category("bourbon", "Bourbon", "whisky")
const categories = [whisky, food, bourbon, alcohol, beer]

describe("buildTree", () => {
  it("test_it_nests_categories_under_their_parents_at_any_depth_each_level_by_name", () => {
    const tree = buildTree(categories)

    expect(tree.map((node) => node.category.name)).toEqual(["Alcohol", "Food"])
    expect(tree[0]?.children.map((node) => node.category.name)).toEqual(["Beer", "Whisky"])
    expect(tree[0]?.children[1]?.children.map((node) => node.category.name)).toEqual(["Bourbon"])
    expect(tree[1]?.children).toEqual([])
  })

  it("test_it_shows_a_category_whose_parent_is_missing_at_the_top_level", () => {
    const orphan = category("orphan", "Orphan", "deleted")

    expect(buildTree([food, orphan]).map((node) => node.category.name)).toEqual(["Food", "Orphan"])
  })

  it("test_it_returns_an_empty_tree_for_no_categories", () => {
    expect(buildTree([])).toEqual([])
  })
})

describe("descendantIds", () => {
  it("test_it_collects_children_and_deeper_descendants_but_not_the_category_itself", () => {
    expect(descendantIds(categories, "alcohol")).toEqual(new Set(["whisky", "beer", "bourbon"]))
    expect(descendantIds(categories, "whisky")).toEqual(new Set(["bourbon"]))
  })

  it("test_it_returns_nothing_for_a_leaf", () => {
    expect(descendantIds(categories, "food").size).toBe(0)
  })
})

describe("parentChoices", () => {
  it("test_it_offers_every_category_in_tree_order_when_adding", () => {
    expect(parentChoices(categories, null).map((choice) => [choice.path, choice.depth])).toEqual([
      ["Alcohol", 0],
      ["Alcohol › Beer", 1],
      ["Alcohol › Whisky", 1],
      ["Alcohol › Whisky › Bourbon", 2],
      ["Food", 0],
    ])
  })

  it("test_it_offers_no_descendants_and_not_the_category_itself_when_moving", () => {
    expect(parentChoices(categories, "alcohol").map((choice) => choice.category.id)).toEqual(["food"])
    expect(parentChoices(categories, "whisky").map((choice) => choice.category.id)).toEqual(["alcohol", "beer", "food"])
  })
})

describe("withCategory and withoutCategory", () => {
  it("test_it_adds_a_new_category_and_replaces_a_changed_one", () => {
    const rum = category("rum", "Rum", "alcohol")
    const moved = { ...beer, parentId: null }

    expect(withCategory(categories, rum)).toHaveLength(6)
    const changed = withCategory(categories, moved)
    expect(changed).toHaveLength(5)
    expect(changed.find((c) => c.id === "beer")?.parentId).toBeNull()
  })

  it("test_it_drops_a_deleted_category", () => {
    expect(withoutCategory(categories, "food").map((c) => c.id)).not.toContain("food")
  })
})

function problem(status: number, body: Record<string, unknown>): ApiError {
  return new ApiError(status, body)
}

describe("categoryFormErrors", () => {
  it("test_it_puts_a_taken_name_on_the_name_field", () => {
    const error = problem(409, { code: "purchasing.product_category_name_taken", parameters: { name: "Beer" }, detail: "x" })

    const result = categoryFormErrors(error)

    expect(result.name).toBeTruthy()
    expect(result.form).toBeUndefined()
  })

  it("test_it_puts_other_domain_errors_under_the_form", () => {
    const result = categoryFormErrors(problem(409, { code: "purchasing.product_category_cycle", parameters: {}, detail: "x" }))

    expect(result.name).toBeUndefined()
    expect(result.form).toBeTruthy()
  })

  it("test_it_puts_a_violation_of_the_name_on_the_name_field", () => {
    const result = categoryFormErrors(
      problem(422, { violations: [{ propertyPath: "name", message: "This value should not be blank." }] }),
    )

    expect(result).toEqual({ name: "This value should not be blank." })
  })
})
