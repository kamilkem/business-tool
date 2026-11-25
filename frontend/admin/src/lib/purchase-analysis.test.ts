import { afterEach, beforeEach, describe, expect, it } from "vitest"

import { i18n } from "../i18n"
import {
  type AnalysisProduct,
  BASIS_STORAGE_KEY,
  type PurchaseAnalysis,
  UNCATEGORIZED_ID,
  branchIds,
  buildAnalysisTree,
  formatChangePercent,
  formatShare,
  hasPurchases,
  leftOutNotes,
  priceChangeOf,
  quantityLabel,
  readBasis,
  referenceLabel,
  saveBasis,
  shareTenths,
  validateAnalysisSearch,
} from "./purchase-analysis"

function product(id: string, categoryId: string | null, net: number, overrides: Partial<AnalysisProduct> = {}): AnalysisProduct {
  return {
    id,
    name: id,
    categoryId,
    measure: "volume",
    referenceQuantity: "1",
    spend: { net, gross: Math.round(net * 1.23) },
    quantity: "10",
    averageNetPrice: 1000,
    averageGrossPrice: 1230,
    lastPriceDate: "2026-11-03",
    lastNetPrice: { price: 1000, change: null, changePercent: null },
    lastGrossPrice: { price: 1230, change: null, changePercent: null },
    ...overrides,
  }
}

function category(id: string, parentId: string | null, net: number) {
  return { id, name: id, parentId, own: { net: 0, gross: 0 }, total: { net, gross: Math.round(net * 1.23) } }
}

function analysis(overrides: Partial<PurchaseAnalysis> = {}): PurchaseAnalysis {
  return {
    from: "2026-11-01",
    to: "2026-11-30",
    currency: "PLN",
    total: { net: 0, gross: 0 },
    categories: [],
    products: [],
    uncategorized: { net: 0, gross: 0, count: 0 },
    notAssigned: { net: 0, gross: 0, count: 0 },
    leftOut: { otherCurrency: 0, withoutNet: 0, withoutGross: 0, withoutPackageSize: 0 },
    ...overrides,
  }
}

describe("buildAnalysisTree", () => {
  it("test_it_nests_categories_to_any_depth_with_their_products", () => {
    const tree = buildAnalysisTree(
      analysis({
        categories: [category("alcohol", null, 9000), category("whisky", "alcohol", 6000), category("bourbon", "whisky", 4000)],
        products: [product("jack", "bourbon", 4000), product("scotch", "whisky", 2000), product("vodka", "alcohol", 3000)],
      }),
      "net",
    )

    expect(tree).toHaveLength(1)
    const [alcohol] = tree
    expect(alcohol).toMatchObject({ kind: "category", id: "alcohol", spend: 9000 })
    // Subcategories first, then the category's own products.
    const children = alcohol?.kind === "product" ? [] : (alcohol?.children ?? [])
    expect(children.map((node) => node.id)).toEqual(["whisky", "vodka"])
    const whisky = children[0]
    const whiskyChildren = whisky?.kind === "product" ? [] : (whisky?.children ?? [])
    expect(whiskyChildren.map((node) => node.id)).toEqual(["bourbon", "scotch"])
  })

  it("test_it_sorts_each_level_by_spend_largest_first_then_by_name", () => {
    const tree = buildAnalysisTree(
      analysis({
        categories: [category("a", null, 100), category("b", null, 300), category("c", null, 300)],
        products: [product("p1", "a", 100), product("p2", "b", 300), product("p3", "c", 300)],
      }),
      "net",
    )
    expect(tree.map((node) => node.id)).toEqual(["b", "c", "a"])
  })

  it("test_it_leaves_out_categories_with_no_product_in_the_period", () => {
    const tree = buildAnalysisTree(
      analysis({
        categories: [category("food", null, 0), category("empty", null, 0), category("meat", "food", 0)],
        products: [product("pork", "meat", 500)],
      }),
      "net",
    )
    expect(tree.map((node) => node.id)).toEqual(["food"])
  })

  it("test_it_keeps_a_category_whose_spend_is_zero_while_it_has_products", () => {
    const tree = buildAnalysisTree(
      analysis({ categories: [category("food", null, 0)], products: [product("pork", "food", 0)] }),
      "net",
    )
    expect(tree.map((node) => node.id)).toEqual(["food"])
  })

  it("test_it_puts_products_in_no_category_under_uncategorized_last", () => {
    const tree = buildAnalysisTree(
      analysis({
        categories: [category("food", null, 100)],
        products: [product("pork", "food", 100), product("rent", null, 5000, { measure: "none" })],
      }),
      "net",
    )
    expect(tree.map((node) => node.id)).toEqual(["food", UNCATEGORIZED_ID])
    expect(tree[1]).toMatchObject({ kind: "uncategorized", name: "Uncategorized", spend: 5000 })
  })

  it("test_it_shows_no_uncategorized_group_without_such_products", () => {
    expect(buildAnalysisTree(analysis(), "net")).toEqual([])
  })

  it("test_it_picks_the_spend_of_the_basis", () => {
    const data = analysis({
      categories: [category("food", null, 1000)],
      products: [product("pork", "food", 1000)],
    })
    expect(buildAnalysisTree(data, "net")[0]?.spend).toBe(1000)
    expect(buildAnalysisTree(data, "gross")[0]?.spend).toBe(1230)
  })

  it("test_it_keeps_a_product_whose_category_is_unknown_visible", () => {
    const tree = buildAnalysisTree(analysis({ products: [product("orphan", "gone", 700)] }), "net")
    expect(tree.map((node) => node.id)).toEqual([UNCATEGORIZED_ID])
  })

  it("test_it_lists_every_branch_id_for_expand_all", () => {
    const tree = buildAnalysisTree(
      analysis({
        categories: [category("alcohol", null, 9000), category("whisky", "alcohol", 6000)],
        products: [product("jack", "whisky", 6000), product("rent", null, 100)],
      }),
      "net",
    )
    expect(branchIds(tree)).toEqual(["alcohol", "whisky", UNCATEGORIZED_ID])
  })
})

describe("hasPurchases", () => {
  it("test_it_is_false_without_products_and_unassigned_items", () => {
    expect(hasPurchases(analysis())).toBe(false)
  })

  it("test_it_is_true_with_only_items_that_have_no_product_yet", () => {
    expect(hasPurchases(analysis({ notAssigned: { net: 100, gross: 123, count: 2 } }))).toBe(true)
  })
})

describe("shareTenths", () => {
  it("test_it_gives_tenths_of_a_percent", () => {
    expect(shareTenths(1, 3)).toBe(333)
    expect(shareTenths(2, 3)).toBe(667)
    expect(shareTenths(50, 100)).toBe(500)
    expect(shareTenths(100, 100)).toBe(1000)
  })

  it("test_it_rounds_half_away_from_zero", () => {
    expect(shareTenths(5, 2000)).toBe(3)
    expect(shareTenths(-5, 2000)).toBe(-3)
  })

  it("test_it_has_no_share_of_a_zero_or_negative_total", () => {
    expect(shareTenths(10, 0)).toBeNull()
    expect(shareTenths(10, -5)).toBeNull()
  })

  it("test_it_is_exact_beyond_the_float_precision_of_a_product", () => {
    // 2^53 minor units: part × 1000 does not fit in a double.
    expect(shareTenths(2 ** 53 - 1, 2 ** 53 - 1)).toBe(1000)
  })

  it("test_it_shows_a_negative_share_for_a_negative_part", () => {
    expect(shareTenths(-1000, 10000)).toBe(-100)
  })
})

describe("formatShare", () => {
  it("test_it_writes_the_share_in_the_locale", () => {
    expect(formatShare(123, "en-GB")).toBe("12.3%")
    expect(formatShare(123, "pl-PL")).toBe("12,3%")
    expect(formatShare(1000, "en-GB")).toBe("100%")
    expect(formatShare(3, "en-GB")).toBe("0.3%")
    expect(formatShare(0, "en-GB")).toBe("0%")
    expect(formatShare(-123, "en-GB")).toBe("-12.3%")
  })
})

describe("quantities", () => {
  it("test_it_words_a_quantity_in_reference_quantities", () => {
    const beer = product("beer", null, 1, { quantity: "12", referenceQuantity: "0.5" })
    expect(quantityLabel(beer, "en-GB")).toBe("12 × 0.5 L")
    expect(quantityLabel(beer, "pl-PL")).toBe("12 × 0,5 L")
    expect(referenceLabel(beer, "en-GB")).toBe("0.5 L")
  })

  it("test_it_leaves_out_the_multiplier_for_a_reference_of_one", () => {
    const coffee = product("coffee", null, 1, { measure: "mass", quantity: "4.5", referenceQuantity: "1" })
    expect(quantityLabel(coffee, "en-GB")).toBe("4.5 kg")
    expect(referenceLabel(coffee, "en-GB")).toBe("kg")
    expect(referenceLabel(product("x", null, 1, { measure: "count", referenceQuantity: "1.000" }), "en-GB")).toBe("pc")
  })

  it("test_it_has_no_quantity_for_a_product_without_a_unit_or_a_price_point", () => {
    const rent = product("rent", null, 1, { measure: "none", quantity: null, referenceQuantity: null })
    expect(quantityLabel(rent)).toBeNull()
    expect(referenceLabel(rent)).toBeNull()
    expect(quantityLabel(product("new", null, 1, { quantity: null }))).toBeNull()
  })

  it("test_it_names_the_unit_in_polish", async () => {
    await i18n.changeLanguage("pl")
    try {
      expect(quantityLabel(product("p", null, 1, { measure: "count", quantity: "6" }), "pl-PL")).toBe("6 szt.")
    } finally {
      await i18n.changeLanguage("en")
    }
  })
})

describe("price change", () => {
  it("test_it_reads_the_direction_of_a_change", () => {
    expect(priceChangeOf({ price: 1100, change: 100, changePercent: "10" }, "en-GB")).toEqual({
      direction: "up",
      amount: 100,
      percent: "+10%",
    })
    expect(priceChangeOf({ price: 900, change: -35, changePercent: "-3.45" }, "en-GB")).toEqual({
      direction: "down",
      amount: -35,
      percent: "−3.45%",
    })
    expect(priceChangeOf({ price: 1000, change: 0, changePercent: "0" }, "en-GB")).toEqual({
      direction: "flat",
      amount: 0,
      percent: "0%",
    })
  })

  it("test_it_has_no_change_for_a_first_purchase_or_a_missing_price", () => {
    expect(priceChangeOf({ price: 1000, change: null, changePercent: null })).toBeNull()
    expect(priceChangeOf(null)).toBeNull()
  })

  it("test_it_has_an_amount_but_no_percent_when_the_previous_price_was_zero", () => {
    expect(priceChangeOf({ price: 500, change: 500, changePercent: null }, "en-GB")).toEqual({
      direction: "up",
      amount: 500,
      percent: null,
    })
  })

  it("test_it_formats_the_percent_in_the_locale_without_a_float", () => {
    expect(formatChangePercent("-3.45", "pl-PL")).toBe("−3,45%")
    expect(formatChangePercent("12.5", "pl-PL")).toBe("+12,5%")
    expect(formatChangePercent(null)).toBeNull()
  })
})

describe("leftOutNotes", () => {
  const leftOut = { otherCurrency: 2, withoutNet: 3, withoutGross: 4, withoutPackageSize: 5 }

  it("test_it_lists_what_was_left_out_for_the_shown_basis_only", () => {
    expect(leftOutNotes(leftOut, "net")).toEqual([
      { key: "otherCurrency", count: 2 },
      { key: "withoutNet", count: 3 },
      { key: "withoutPackageSize", count: 5 },
    ])
    expect(leftOutNotes(leftOut, "gross")).toEqual([
      { key: "otherCurrency", count: 2 },
      { key: "withoutGross", count: 4 },
      { key: "withoutPackageSize", count: 5 },
    ])
  })

  it("test_it_lists_nothing_when_nothing_was_left_out", () => {
    expect(leftOutNotes({ otherCurrency: 0, withoutNet: 0, withoutGross: 7, withoutPackageSize: 0 }, "net")).toEqual([])
  })
})

describe("the remembered basis", () => {
  function storage(initial: Record<string, string> = {}) {
    const data = new Map(Object.entries(initial))
    return {
      getItem: (key: string) => data.get(key) ?? null,
      setItem: (key: string, value: string) => void data.set(key, value),
      data,
    }
  }

  it("test_it_defaults_to_net", () => {
    expect(readBasis(storage())).toBe("net")
    expect(readBasis(null)).toBe("net")
  })

  it("test_it_remembers_gross", () => {
    const store = storage()
    saveBasis("gross", store)
    expect(store.data.get(BASIS_STORAGE_KEY)).toBe("gross")
    expect(readBasis(store)).toBe("gross")
    saveBasis("net", store)
    expect(readBasis(store)).toBe("net")
  })

  it("test_it_ignores_a_stored_value_it_does_not_know", () => {
    expect(readBasis(storage({ [BASIS_STORAGE_KEY]: "vat" }))).toBe("net")
  })

  it("test_it_survives_a_storage_that_throws", () => {
    const broken = {
      getItem: () => {
        throw new Error("blocked")
      },
      setItem: () => {
        throw new Error("blocked")
      },
    }
    expect(readBasis(broken)).toBe("net")
    expect(() => saveBasis("gross", broken)).not.toThrow()
  })
})

describe("validateAnalysisSearch", () => {
  it("test_it_keeps_a_valid_period", () => {
    expect(validateAnalysisSearch({ from: "2026-10-01", to: "2026-10-31" })).toEqual({ from: "2026-10-01", to: "2026-10-31" })
  })

  it("test_it_drops_a_period_the_api_would_refuse", () => {
    const dropped = { from: undefined, to: undefined }
    expect(validateAnalysisSearch({ from: "2026-10-01" })).toEqual(dropped)
    expect(validateAnalysisSearch({ from: "2026-10-31", to: "2026-10-01" })).toEqual(dropped)
    expect(validateAnalysisSearch({ from: "2025-01-01", to: "2026-11-30" })).toEqual(dropped)
    expect(validateAnalysisSearch({ from: "yesterday", to: "today" })).toEqual(dropped)
  })
})

describe("in Polish", () => {
  beforeEach(async () => {
    await i18n.changeLanguage("pl")
  })
  afterEach(async () => {
    await i18n.changeLanguage("en")
  })

  it("test_it_names_the_uncategorized_group_in_polish", () => {
    const tree = buildAnalysisTree(analysis({ products: [product("rent", null, 100)] }), "net")
    expect(tree[0]).toMatchObject({ kind: "uncategorized", name: "Bez kategorii" })
  })
})
