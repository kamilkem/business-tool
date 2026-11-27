import { describe, expect, it } from "vitest"

import {
  type ChartPoint,
  type PricePoint,
  type Product,
  type ProductFormValues,
  buildPriceChart,
  dateOfDay,
  dateTicks,
  dayDomain,
  dayNumber,
  defaultPeriod,
  formValuesOf,
  itemsOutsidePrices,
  matchProducts,
  normalizeDecimal,
  notPricedReason,
  priceDomain,
  priceTicks,
  priceStats,
  productChanges,
  sellerSeries,
  validateProductSearch,
} from "./product-prices"

function point(id: string, date: string, seller: string, net: number | null, overrides: Partial<PricePoint> = {}): PricePoint {
  return {
    itemId: id,
    date,
    sellerTaxId: `tax-${seller}`,
    sellerName: seller,
    invoiceId: `invoice-${id}`,
    invoiceNumber: `FV/${id}`,
    itemName: "JACK DANIELS 0,7L",
    quantity: "6",
    packageSize: "0.7",
    bundleCount: null,
    net: net === null ? null : { price: net, change: null, changePercent: null },
    gross: net === null ? null : { price: Math.round(net * 1.23), change: null, changePercent: null },
    ...overrides,
  }
}

function product(overrides: Partial<Product> = {}): Product {
  return {
    id: "p1",
    name: "Jack Daniel's",
    categoryId: "whisky",
    measure: "volume",
    referenceQuantity: "1",
    excluded: false,
    createdAt: "2026-01-01T00:00:00+00:00",
    ...overrides,
  }
}

describe("defaultPeriod", () => {
  it("test_it_starts_eleven_months_before_this_month_and_ends_with_it", () => {
    expect(defaultPeriod({ from: "2026-11-01", to: "2026-11-30" })).toEqual({ from: "2025-12-01", to: "2026-11-30" })
  })

  it("test_it_crosses_the_year_when_this_month_is_early_in_the_year", () => {
    expect(defaultPeriod({ from: "2026-03-01", to: "2026-03-31" })).toEqual({ from: "2025-04-01", to: "2026-03-31" })
    expect(defaultPeriod({ from: "2026-01-01", to: "2026-01-31" })).toEqual({ from: "2025-02-01", to: "2026-01-31" })
  })
})

describe("validateProductSearch", () => {
  it("test_it_keeps_a_valid_period", () => {
    expect(validateProductSearch({ from: "2026-01-01", to: "2026-06-30" })).toEqual({ from: "2026-01-01", to: "2026-06-30" })
  })

  it("test_it_drops_a_period_the_api_would_refuse", () => {
    const dropped = { from: undefined, to: undefined }
    expect(validateProductSearch({ from: "2026-02-30", to: "2026-06-30" })).toEqual(dropped)
    expect(validateProductSearch({ from: "2026-07-01", to: "2026-06-30" })).toEqual(dropped)
    expect(validateProductSearch({ from: "2026-01-01" })).toEqual(dropped)
  })

  it("test_it_has_no_longest_period", () => {
    expect(validateProductSearch({ from: "2020-01-01", to: "2026-12-31" })).toEqual({ from: "2020-01-01", to: "2026-12-31" })
  })
})

describe("day numbers", () => {
  it("test_it_counts_days_since_1970_and_back_without_a_time_zone", () => {
    expect(dayNumber("1970-01-01")).toBe(0)
    expect(dayNumber("1970-01-31")).toBe(30)
    expect(dateOfDay(dayNumber("2026-03-29"))).toBe("2026-03-29")
    // The day clocks change in Europe/Warsaw is still one day after the previous one.
    expect(dayNumber("2026-03-30") - dayNumber("2026-03-29")).toBe(1)
  })
})

describe("sellerSeries", () => {
  it("test_it_gives_the_three_most_frequent_sellers_a_slot_each_and_groups_the_rest", () => {
    const points = [
      point("1", "2026-01-05", "Alpha", 100),
      point("2", "2026-01-06", "Beta", 100),
      point("3", "2026-01-07", "Beta", 100),
      point("4", "2026-01-08", "Gamma", 100),
      point("5", "2026-01-09", "Gamma", 100),
      point("6", "2026-01-10", "Gamma", 100),
      point("7", "2026-01-11", "Delta", 100),
      point("8", "2026-01-12", "Epsilon", 100),
    ]
    const { series } = sellerSeries(points)

    expect(series.map((s) => [s.name, s.slot, s.count])).toEqual([
      ["Gamma", 1, 3],
      ["Beta", 2, 2],
      ["Alpha", 3, 1],
      [null, "other", 2],
    ])
    expect(series.at(-1)?.sellers).toBe(2)
  })

  it("test_it_breaks_ties_by_name_so_colours_do_not_depend_on_order", () => {
    const a = sellerSeries([point("1", "2026-01-05", "Zeta", 1), point("2", "2026-01-06", "Alpha", 1)])
    const b = sellerSeries([point("2", "2026-01-06", "Alpha", 1), point("1", "2026-01-05", "Zeta", 1)])
    expect(a.series.map((s) => s.name)).toEqual(["Alpha", "Zeta"])
    expect(b.series.map((s) => s.name)).toEqual(["Alpha", "Zeta"])
  })

  it("test_it_tells_sellers_by_tax_id_and_names_them_after_their_latest_invoice", () => {
    const { series } = sellerSeries([
      point("1", "2026-01-05", "Hurtownia", 1, { sellerTaxId: "123" }),
      point("2", "2026-02-05", "Hurtownia Sp. z o.o.", 1, { sellerTaxId: "123" }),
    ])
    expect(series).toHaveLength(1)
    expect(series[0]).toMatchObject({ name: "Hurtownia Sp. z o.o.", count: 2 })
  })

  it("test_it_tells_sellers_without_a_tax_id_apart_by_name", () => {
    const { series } = sellerSeries([
      point("1", "2026-01-05", "Targ", 1, { sellerTaxId: null }),
      point("2", "2026-01-06", "Sklep", 1, { sellerTaxId: null }),
    ])
    expect(series.map((s) => s.name)).toEqual(["Sklep", "Targ"])
  })
})

describe("buildPriceChart", () => {
  it("test_it_picks_the_basis_and_keeps_the_apis_change", () => {
    const points = [
      point("1", "2026-01-05", "Alpha", 1000),
      point("2", "2026-02-05", "Alpha", 1100, {
        net: { price: 1100, change: 100, changePercent: "10" },
        gross: { price: 1353, change: 123, changePercent: "10" },
      }),
    ]

    const net = buildPriceChart(points, "net", "en-GB")
    expect(net.points.map((p) => p.price)).toEqual([1000, 1100])
    expect(net.points[0]?.change).toBeNull()
    expect(net.points[1]?.change).toEqual({ direction: "up", amount: 100, percent: "+10%" })

    const gross = buildPriceChart(points, "gross", "en-GB")
    expect(gross.points.map((p) => p.price)).toEqual([1230, 1353])
    expect(gross.points[1]?.change?.amount).toBe(123)
  })

  it("test_it_leaves_out_points_without_a_price_on_the_basis_and_counts_them", () => {
    const chart = buildPriceChart(
      [point("1", "2026-01-05", "Alpha", 1000), point("2", "2026-01-06", "Alpha", null)],
      "net",
    )
    expect(chart.points.map((p) => p.itemId)).toEqual(["1"])
    expect(chart.withoutPrice).toBe(1)
  })

  it("test_it_does_not_repaint_a_seller_when_the_basis_changes", () => {
    const grossOnly = { gross: { price: 123, change: null, changePercent: null } }
    const points = [
      point("1", "2026-01-05", "Alpha", null, grossOnly),
      point("2", "2026-01-06", "Alpha", null, grossOnly),
      point("3", "2026-01-07", "Beta", 100),
    ]
    // On net Alpha has no points on the chart, yet it is still the first seller by count.
    expect(buildPriceChart(points, "net").points[0]?.slot).toBe(2)
    expect(buildPriceChart(points, "gross").points.map((p) => p.slot)).toEqual([1, 1, 2])
  })

  it("test_it_puts_points_on_a_day_axis", () => {
    const chart = buildPriceChart([point("1", "2026-01-05", "Alpha", 1), point("2", "2026-01-15", "Alpha", 1)], "net")
    expect(chart.points[1]!.x - chart.points[0]!.x).toBe(10)
    expect(dateOfDay(chart.points[0]!.x)).toBe("2026-01-05")
  })
})

function chartPoint(x: number, price: number): ChartPoint {
  return { itemId: String(x), x, date: dateOfDay(x), price, change: null, sellerName: "A", slot: 1 }
}

describe("priceStats", () => {
  it("test_it_finds_the_latest_lowest_and_highest_price", () => {
    const stats = priceStats([chartPoint(1, 500), chartPoint(2, 300), chartPoint(3, 800), chartPoint(4, 600)])
    expect([stats?.latest.price, stats?.lowest.price, stats?.highest.price]).toEqual([600, 300, 800])
  })

  it("test_it_dates_a_tie_at_the_first_lowest_and_the_last_highest", () => {
    const stats = priceStats([chartPoint(1, 300), chartPoint(2, 800), chartPoint(3, 300), chartPoint(4, 800)])
    expect(stats?.lowest.x).toBe(1)
    expect(stats?.highest.x).toBe(4)
  })

  it("test_it_has_none_without_points", () => {
    expect(priceStats([])).toBeNull()
  })
})

describe("chart domains", () => {
  it("test_it_pads_the_days_so_a_lone_point_is_not_on_the_frame", () => {
    expect(dayDomain([chartPoint(100, 1)])).toEqual([97, 103])
    expect(dayDomain([chartPoint(100, 1), chartPoint(400, 1)])).toEqual([91, 409])
  })

  it("test_it_pads_the_prices_and_never_goes_below_zero", () => {
    const [low, high] = priceDomain([chartPoint(1, 100), chartPoint(2, 120)])
    expect(low).toBeLessThan(100)
    expect(high).toBeGreaterThan(120)
    expect(priceDomain([chartPoint(1, 1), chartPoint(2, 500)])[0]).toBe(0)
  })

  it("test_it_gives_a_lone_price_room_on_both_sides", () => {
    const [low, high] = priceDomain([chartPoint(1, 1000)])
    expect(low).toBeLessThan(1000)
    expect(high).toBeGreaterThan(1000)
  })
})

describe("priceTicks", () => {
  it("test_it_rounds_the_ends_to_a_round_step_and_covers_the_domain", () => {
    expect(priceTicks([468, 674])).toEqual([450, 500, 550, 600, 650, 700])
    expect(priceTicks([4900, 5100])).toEqual([4900, 4950, 5000, 5050, 5100])
  })

  it("test_it_never_goes_below_zero_and_handles_a_tiny_range", () => {
    const ticks = priceTicks([0, 3])
    expect(ticks[0]).toBe(0)
    expect(ticks.at(-1)).toBeGreaterThanOrEqual(3)
    expect(priceTicks([0, 500000])).toEqual([0, 100000, 200000, 300000, 400000, 500000])
  })
})

describe("dateTicks", () => {
  it("test_it_labels_month_starts_for_a_long_span", () => {
    const { ticks, withDay } = dateTicks([dayNumber("2025-12-20"), dayNumber("2026-11-10")], 6)
    expect(withDay).toBe(false)
    expect(ticks.length).toBeLessThanOrEqual(6)
    expect(ticks.map(dateOfDay).every((date) => date.endsWith("-01"))).toBe(true)
    expect(dateOfDay(ticks[0]!)).toBe("2026-01-01")
  })

  it("test_it_labels_days_for_a_short_span", () => {
    const { ticks, withDay } = dateTicks([dayNumber("2026-11-01"), dayNumber("2026-11-20")], 6)
    expect(withDay).toBe(true)
    expect(ticks[0]).toBe(dayNumber("2026-11-01"))
    expect(ticks.length).toBeLessThanOrEqual(7)
  })
})

describe("notPricedReason", () => {
  const item = { invoiceKind: "regular", currency: "PLN", packageSize: "0.7", quantity: "6" }

  it("test_it_is_null_for_an_item_that_is_a_price_point", () => {
    expect(notPricedReason(item, product(), "PLN")).toBeNull()
  })

  it("test_it_names_the_first_rule_an_item_breaks", () => {
    expect(notPricedReason(item, product({ excluded: true }), "PLN")).toBe("excluded")
    expect(notPricedReason(item, product({ measure: "none", referenceQuantity: null }), "PLN")).toBe("noUnit")
    expect(notPricedReason({ ...item, invoiceKind: "advance_correction" }, product(), "PLN")).toBe("correction")
    expect(notPricedReason({ ...item, currency: "EUR" }, product(), "PLN")).toBe("otherCurrency")
    expect(notPricedReason({ ...item, packageSize: null }, product(), "PLN")).toBe("noPackageSize")
    expect(notPricedReason({ ...item, quantity: "0" }, product(), "PLN")).toBe("noQuantity")
    expect(notPricedReason({ ...item, quantity: null }, product(), "PLN")).toBe("noQuantity")
    // A correction in another currency is a correction first.
    expect(notPricedReason({ ...item, invoiceKind: "correction", currency: "EUR" }, product(), "PLN")).toBe("correction")
  })
})

describe("itemsOutsidePrices", () => {
  it("test_it_leaves_out_the_items_that_are_price_points", () => {
    const items = [
      { id: "a", invoiceKind: "regular", currency: "PLN", packageSize: "1", quantity: "1" },
      { id: "b", invoiceKind: "regular", currency: "PLN", packageSize: null, quantity: "1" },
    ]
    const rows = itemsOutsidePrices(items, new Set(["a"]), product(), "PLN")
    expect(rows.map((row) => [row.item.id, row.reason])).toEqual([["b", "noPackageSize"]])
  })
})

describe("productChanges", () => {
  const saved = product({ name: "Piwo", categoryId: "beer", measure: "volume", referenceQuantity: "0.5" })

  it("test_it_is_null_when_nothing_changed", () => {
    expect(productChanges(saved, formValuesOf(saved))).toBeNull()
  })

  it("test_it_sends_only_what_changed", () => {
    const values: ProductFormValues = { ...formValuesOf(saved), name: "  Piwo jasne ", categoryId: "" }
    expect(productChanges(saved, values)).toEqual({ name: "Piwo jasne", categoryId: null })
  })

  it("test_it_reads_a_comma_decimal_without_turning_it_into_a_number", () => {
    expect(productChanges(saved, { ...formValuesOf(saved), referenceQuantity: "0,33" })).toEqual({ referenceQuantity: "0.33" })
  })

  it("test_it_does_not_send_a_reference_that_only_differs_in_zeros", () => {
    expect(productChanges(saved, { ...formValuesOf(saved), referenceQuantity: "0.50" })).toBeNull()
    expect(productChanges(product({ referenceQuantity: "1" }), { ...formValuesOf(product()), referenceQuantity: "1.000" })).toBeNull()
  })

  it("test_it_sends_a_null_reference_with_a_measure_without_a_unit", () => {
    expect(productChanges(saved, { ...formValuesOf(saved), measure: "none", referenceQuantity: "0.5" })).toEqual({
      measure: "none",
      referenceQuantity: null,
    })
  })

  it("test_it_sends_the_reference_with_a_changed_measure_and_null_for_an_empty_one", () => {
    const none = product({ measure: "none", referenceQuantity: null })
    expect(productChanges(none, { ...formValuesOf(none), measure: "mass", referenceQuantity: "" })).toEqual({
      measure: "mass",
      referenceQuantity: null,
    })
  })

  it("test_it_sends_the_excluded_flag", () => {
    expect(productChanges(saved, { ...formValuesOf(saved), excluded: true })).toEqual({ excluded: true })
  })
})

describe("normalizeDecimal", () => {
  it("test_it_trims_and_swaps_a_comma_for_a_point", () => {
    expect(normalizeDecimal(" 0,5 ")).toBe("0.5")
    expect(normalizeDecimal("")).toBeNull()
    expect(normalizeDecimal("   ")).toBeNull()
  })
})

describe("matchProducts", () => {
  const products = [
    { id: "1", name: "Żubrówka" },
    { id: "2", name: "Żywiec" },
    { id: "3", name: "Jack Daniel's" },
  ]

  it("test_it_matches_ignoring_case_and_diacritics_by_name", () => {
    expect(matchProducts(products, "zubr", null).map((p) => p.id)).toEqual(["1"])
    expect(matchProducts(products, "ŻY", null).map((p) => p.id)).toEqual(["2"])
    expect(matchProducts(products, "", null).map((p) => p.id)).toEqual(["3", "1", "2"])
  })

  it("test_it_leaves_out_the_product_itself", () => {
    expect(matchProducts(products, "", "3").map((p) => p.id)).toEqual(["1", "2"])
  })
})
