import { ApiError } from "@business-tool/api-client"
import { describe, expect, it } from "vitest"

import {
  type QueueItem,
  assignErrors,
  commonPackageSize,
  groupByName,
  groupKey,
  measureGuess,
  packageSizeApplies,
  packageSizeToSend,
  pruneSelection,
  selectedItems,
  selectionState,
  suggestedLinks,
  toggleItems,
  validateReviewSearch,
} from "./review-queue"

function item(id: string, name: string, date: string, overrides: Partial<QueueItem> = {}): QueueItem {
  return {
    id,
    invoiceId: `invoice-${id}`,
    invoiceNumber: `FV/${id}`,
    invoiceIssueDate: date,
    invoiceKind: "regular",
    currency: "PLN",
    sellerTaxId: "5260001246",
    sellerName: "Hurtownia Alfa",
    position: 1,
    name,
    sellerCode: null,
    gtin: null,
    unit: "szt.",
    quantity: "6",
    unitNetPrice: "100.00",
    unitGrossPrice: null,
    discount: null,
    netAmount: 60000,
    grossAmount: 73800,
    vatAmount: 13800,
    vatRate: "23",
    beforeCorrection: false,
    productId: null,
    productName: null,
    status: "unassigned",
    packageSize: null,
    bundleCount: null,
    matchSource: null,
    matchCaveat: null,
    ...overrides,
  }
}

const suggested = (id: string, name: string, date: string, productId: string, productName: string) =>
  item(id, name, date, { status: "suggested", productId, productName })

describe("groupKey", () => {
  it("test_it_ignores_letter_case_and_runs_of_white_space", () => {
    expect(groupKey("  JACK  DANIELS 0,7L ")).toBe("jack daniels 0,7l")
    expect(groupKey("Jack Daniels 0,7L")).toBe(groupKey("JACK DANIELS   0,7L"))
  })

  it("test_it_keeps_names_that_differ_in_anything_else_apart", () => {
    expect(groupKey("JACK DANIELS 0,7L")).not.toBe(groupKey("JACK DANIELS 1L"))
    expect(groupKey("Żubrówka")).not.toBe(groupKey("Zubrowka"))
  })
})

describe("groupByName", () => {
  it("test_it_groups_items_printed_with_the_same_name_across_sellers_and_dates", () => {
    const groups = groupByName([
      item("a", "JACK DANIELS 0,7L", "2026-11-20"),
      item("b", "Cola 0,33", "2026-11-19"),
      item("c", "jack daniels  0,7l", "2026-11-10", { sellerName: "Beta Sp. z o.o." }),
      item("d", "JACK DANIELS 0,7L", "2026-10-01"),
    ])

    expect(groups.map((group) => group.items.map((i) => i.id))).toEqual([["a", "c", "d"], ["b"]])
    expect(groups[0]?.key).toBe("jack daniels 0,7l")
  })

  it("test_it_puts_the_group_with_the_newest_item_first_and_keeps_the_items_newest_first", () => {
    const groups = groupByName([
      item("a", "Cola", "2026-11-03"),
      item("b", "Wódka", "2026-11-20"),
      item("c", "Cola", "2026-11-02"),
      item("d", "Piwo", "2026-11-10"),
    ])

    expect(groups.map((group) => group.name)).toEqual(["Wódka", "Piwo", "Cola"])
    expect(groups[2]?.items.map((i) => i.id)).toEqual(["a", "c"])
  })

  it("test_it_orders_by_the_newest_item_even_when_the_list_is_not_sorted", () => {
    const groups = groupByName([item("a", "Cola", "2026-01-01"), item("b", "Wódka", "2026-02-01"), item("c", "Cola", "2026-03-01")])

    expect(groups.map((group) => group.name)).toEqual(["Cola", "Wódka"])
    expect(groups[0]?.newestDate).toBe("2026-03-01")
  })

  it("test_it_names_a_group_as_its_newest_item_printed_it", () => {
    const [group] = groupByName([item("a", " Jack  Daniels 0,7L ", "2026-11-20"), item("b", "JACK DANIELS 0,7L", "2026-10-20")])

    expect(group?.name).toBe("Jack  Daniels 0,7L")
  })

  it("test_it_counts_an_item_listed_twice_once", () => {
    const [group] = groupByName([item("a", "Cola", "2026-11-03"), item("a", "Cola", "2026-11-03"), item("b", "Cola", "2026-11-02")])

    expect(group?.items.map((i) => i.id)).toEqual(["a", "b"])
  })

  it("test_it_lists_the_sellers_with_the_most_items_first", () => {
    const [group] = groupByName([
      item("a", "Cola", "2026-11-05", { sellerName: "Zeta" }),
      item("b", "Cola", "2026-11-04", { sellerName: "Beta" }),
      item("c", "Cola", "2026-11-03", { sellerName: "Beta" }),
      item("d", "Cola", "2026-11-02", { sellerName: "Alfa" }),
    ])

    expect(group?.sellers).toEqual(["Beta", "Alfa", "Zeta"])
  })

  it("test_it_is_empty_without_items", () => {
    expect(groupByName([])).toEqual([])
  })
})

describe("suggestedLinks", () => {
  it("test_it_collects_the_suggested_items_per_product", () => {
    const links = suggestedLinks([
      suggested("a", "Cola", "2026-11-03", "p1", "Coca-Cola"),
      suggested("b", "Cola", "2026-11-02", "p1", "Coca-Cola"),
      suggested("c", "Cola", "2026-11-01", "p2", "Pepsi"),
      item("d", "Cola", "2026-10-01"),
    ])

    expect(links).toEqual([
      { productId: "p1", productName: "Coca-Cola", itemIds: ["a", "b"] },
      { productId: "p2", productName: "Pepsi", itemIds: ["c"] },
    ])
  })

  it("test_it_ignores_an_item_that_is_not_suggested_or_has_no_product", () => {
    expect(suggestedLinks([item("a", "Cola", "2026-11-03"), item("b", "Cola", "2026-11-02", { status: "suggested" })])).toEqual([])
  })

  it("test_it_shows_the_suggestions_on_the_group", () => {
    const [group] = groupByName([suggested("a", "Cola", "2026-11-03", "p1", "Coca-Cola"), item("b", "Cola", "2026-11-02")])

    expect(group?.suggestions).toEqual([{ productId: "p1", productName: "Coca-Cola", itemIds: ["a"] }])
  })
})

describe("selection", () => {
  const items = [item("a", "Cola", "2026-11-03"), item("b", "Cola", "2026-11-02"), item("c", "Wódka", "2026-11-01")]
  const groups = groupByName(items)
  const cola = groups[0]!.items

  it("test_it_says_whether_none_some_or_all_of_the_items_are_selected", () => {
    expect(selectionState(cola, new Set())).toBe("none")
    expect(selectionState(cola, new Set(["a"]))).toBe("some")
    expect(selectionState(cola, new Set(["a", "b", "c"]))).toBe("all")
    expect(selectionState([], new Set(["a"]))).toBe("none")
  })

  it("test_it_selects_a_group_that_is_not_fully_selected_and_unselects_one_that_is", () => {
    const some = toggleItems(new Set(["a", "c"]), cola)
    expect([...some].sort()).toEqual(["a", "b", "c"])

    const none = toggleItems(some, cola)
    expect([...none]).toEqual(["c"])
  })

  it("test_it_does_not_change_the_selection_it_was_given", () => {
    const before = new Set(["a"])
    toggleItems(before, cola)

    expect([...before]).toEqual(["a"])
  })

  it("test_it_returns_the_selected_items_in_the_order_of_the_groups", () => {
    expect(selectedItems(groups, new Set(["c", "b"])).map((i) => i.id)).toEqual(["b", "c"])
  })

  it("test_it_drops_an_item_that_left_the_queue_from_the_selection", () => {
    const pruned = pruneSelection(groups, new Set(["a", "gone"]))

    expect([...pruned]).toEqual(["a"])
  })

  it("test_it_keeps_the_same_selection_when_nothing_left", () => {
    const selection = new Set(["a"])

    expect(pruneSelection(groups, selection)).toBe(selection)
  })
})

describe("commonPackageSize", () => {
  it("test_it_is_the_size_all_the_items_share", () => {
    expect(commonPackageSize([item("a", "X", "2026-11-03", { packageSize: "0.7" }), item("b", "X", "2026-11-02", { packageSize: "0.7" })])).toBe("0.7")
  })

  it("test_it_is_null_when_they_differ_or_one_has_none", () => {
    expect(commonPackageSize([item("a", "X", "2026-11-03", { packageSize: "0.7" }), item("b", "X", "2026-11-02", { packageSize: "1" })])).toBeNull()
    expect(commonPackageSize([item("a", "X", "2026-11-03", { packageSize: "0.7" }), item("b", "X", "2026-11-02")])).toBeNull()
    expect(commonPackageSize([item("a", "X", "2026-11-03")])).toBeNull()
    expect(commonPackageSize([])).toBeNull()
  })

  it("test_it_is_null_when_the_same_size_is_a_bundle_for_one_item_and_not_for_another", () => {
    const bundle = item("a", "X", "2026-11-03", { packageSize: "0.5", bundleCount: 20 })
    expect(commonPackageSize([bundle, item("b", "X", "2026-11-02", { packageSize: "0.5", bundleCount: 20 })])).toBe("0.5")
    expect(commonPackageSize([bundle, item("b", "X", "2026-11-02", { packageSize: "0.5", bundleCount: 24 })])).toBeNull()
    expect(commonPackageSize([bundle, item("b", "X", "2026-11-02", { packageSize: "0.5" })])).toBeNull()
  })
})

describe("the package size to send", () => {
  it("test_it_sends_what_was_typed_with_a_decimal_point_as_text", () => {
    expect(packageSizeToSend(" 0,7 ", true)).toBe("0.7")
    expect(packageSizeToSend("0.700", true)).toBe("0.700")
  })

  it("test_it_sends_null_for_an_empty_field_so_each_item_keeps_its_own", () => {
    expect(packageSizeToSend("  ", true)).toBeNull()
  })

  it("test_it_sends_null_when_the_product_has_no_unit_or_is_excluded", () => {
    expect(packageSizeToSend("0.7", false)).toBeNull()
    expect(packageSizeApplies({ measure: "none", excluded: false })).toBe(false)
    expect(packageSizeApplies({ measure: "volume", excluded: true })).toBe(false)
    expect(packageSizeApplies({ measure: "volume", excluded: false })).toBe(true)
  })
})

describe("measureGuess", () => {
  it("test_it_reads_the_measure_from_the_printed_unit", () => {
    expect(measureGuess("l")).toBe("volume")
    expect(measureGuess("L")).toBe("volume")
    expect(measureGuess("kg")).toBe("mass")
    expect(measureGuess("szt.")).toBe("count")
    expect(measureGuess(null)).toBe("count")
    expect(measureGuess("op.")).toBe("count")
  })
})

describe("validateReviewSearch", () => {
  it("test_it_trims_the_search_and_drops_an_empty_one", () => {
    expect(validateReviewSearch({ q: "  jack " })).toEqual({ q: "jack" })
    expect(validateReviewSearch({ q: "   " })).toEqual({ q: undefined })
    expect(validateReviewSearch({ q: 5 })).toEqual({ q: undefined })
    expect(validateReviewSearch({})).toEqual({ q: undefined })
  })
})

describe("assignErrors", () => {
  it("test_it_puts_a_refused_package_size_on_its_field", () => {
    const error = new ApiError(422, { violations: [{ propertyPath: "packageSize", message: "This is not a decimal number." }] })

    expect(assignErrors(error)).toEqual({ packageSize: "This is not a decimal number." })
  })

  it("test_it_puts_the_violations_of_other_fields_under_the_form", () => {
    const error = new ApiError(422, {
      violations: [
        { propertyPath: "name", message: "Too long." },
        { propertyPath: "itemIds", message: "Too many." },
      ],
    })

    expect(assignErrors(error)).toEqual({ name: "Too long.", form: "Too many." })
  })
})
