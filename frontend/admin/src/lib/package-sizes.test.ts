import { describe, expect, it } from "vitest"

import {
  type PackageSizeGroup,
  answerAfterCreate,
  countItems,
  isAsking,
  packageSizeQuestion,
  sameSize,
  samePackage,
  targetsOf,
  groupId,
  unitKeyOf,
} from "./package-sizes"

function group(unitKey: string | null, packageSize: string | null, count = 1, confirmedCount = 0, bundleCount: number | null = null): PackageSizeGroup {
  return { unitKey, unit: unitKey, packageSize, bundleCount, count, confirmedCount }
}

describe("unitKeyOf", () => {
  it("test_it_reads_the_unit_the_way_the_api_groups_it", () => {
    expect(unitKeyOf("szt.")).toBe("szt")
    expect(unitKeyOf("SZT")).toBe("szt")
    expect(unitKeyOf(" Kart. ")).toBe("kart")
    expect(unitKeyOf("Łyk")).toBe("lyk")
    expect(unitKeyOf("Żółw")).toBe("zolw")
  })

  it("test_it_has_no_key_for_no_unit", () => {
    expect(unitKeyOf(null)).toBeNull()
    expect(unitKeyOf("")).toBeNull()
    expect(unitKeyOf(" . ")).toBeNull()
  })
})

describe("sameSize", () => {
  it("test_it_compares_sizes_as_numbers", () => {
    expect(sameSize("0.7", "0.70")).toBe(true)
    expect(sameSize("07", "7")).toBe(true)
    expect(sameSize("0.7", "0.07")).toBe(false)
    expect(sameSize(null, null)).toBe(true)
    expect(sameSize(null, "1")).toBe(false)
  })
})

describe("packageSizeQuestion", () => {
  const groups = [
    group("co", null, 3, 2),
    group("co", "4.2", 1, 1),
    group("kart", null, 2),
    group("szt", "0.5", 4, 3),
    group("szt", "0.7", 5, 5),
    group(null, null, 1),
  ]

  it("test_it_asks_about_the_items_with_no_size_under_other_units_when_a_size_is_set", () => {
    const question = packageSizeQuestion(groups, { size: "0.7", bundleCount: null, units: ["szt"], changed: false })

    expect(question.fill.map(groupId)).toEqual([groupId(group("co", null)), groupId(group("kart", null)), groupId(group(null, null))])
    expect(question.sameUnit).toEqual([])
    expect(question.otherUnits).toEqual([])
    expect(isAsking(question)).toBe(true)
  })

  it("test_it_leaves_the_unconfirmed_items_with_no_size_under_the_unit_just_set_to_matching", () => {
    const question = packageSizeQuestion([group("szt", null, 2), group("szt", "0.7")], { size: "0.7", bundleCount: null, units: ["szt"], changed: false })

    expect(question.fill).toEqual([])
    expect(isAsking(question)).toBe(false)
  })

  it("test_it_asks_about_the_confirmed_items_with_no_size_under_the_unit_just_set", () => {
    // Eight cases the owner confirmed with no size, and the one they just gave 12 × 0.85: matching cannot change a confirmed item.
    const confirmed = group("co", null, 8, 8)
    const question = packageSizeQuestion([confirmed, group("co", "0.85", 1, 1, 12)], { size: "0.85", bundleCount: 12, units: ["co"], changed: false })

    expect(question.fill).toEqual([confirmed])
    expect(isAsking(question)).toBe(true)
  })

  it("test_it_asks_about_other_sizes_too_when_an_existing_size_was_changed_listing_the_same_unit_apart_from_the_others", () => {
    const question = packageSizeQuestion(groups, { size: "0.7", bundleCount: null, units: ["szt"], changed: true })

    expect(question.fill.map((entry) => entry.unitKey)).toEqual(["co", "kart", null])
    // The 0.5 L bottles are the same unit; the case that has 4.2 is another.
    expect(question.sameUnit).toEqual([group("szt", "0.5", 4, 3)])
    expect(question.otherUnits).toEqual([group("co", "4.2", 1, 1)])
  })

  it("test_it_never_offers_the_size_the_items_have_already", () => {
    const question = packageSizeQuestion([group("szt", "0.70"), group("co", "0.7")], { size: "0.7", bundleCount: null, units: ["szt"], changed: true })

    expect(isAsking(question)).toBe(false)
  })

  it("test_it_does_not_ask_about_other_sizes_when_a_size_was_only_set", () => {
    const question = packageSizeQuestion([group("szt", "0.5"), group("co", "4.2")], { size: "0.7", bundleCount: null, units: ["szt"], changed: false })

    expect(isAsking(question)).toBe(false)
  })

  it("test_it_treats_items_with_no_unit_as_a_unit_of_their_own", () => {
    const question = packageSizeQuestion([group(null, null), group("szt", "0.7")], { size: "0.7", bundleCount: null, units: [null], changed: false })

    expect(question.fill).toEqual([])
    expect(packageSizeQuestion([group(null, null)], { size: "0.7", bundleCount: null, units: ["szt"], changed: false }).fill).toHaveLength(1)
  })

  it("test_it_asks_about_a_bundle_and_a_plain_package_of_the_same_size_apart", () => {
    const bundle = group("co", "0.7", 2, 1, 6)
    const question = packageSizeQuestion([group("szt", "0.7"), bundle, group("co", "0.7", 1)], { size: "0.7", bundleCount: null, units: ["szt"], changed: true })

    // 6 × 0.7 is not 0.7: the bundle is another package, the plain 0.7 on `CO` is the same one.
    expect(question.otherUnits).toEqual([bundle])
    expect(question.sameUnit).toEqual([])
    expect(packageSizeQuestion([bundle], { size: "0.7", bundleCount: 6, units: ["szt"], changed: true }).otherUnits).toEqual([])
  })

  it("test_it_counts_the_items_of_the_groups", () => {
    expect(countItems(groups)).toBe(16)
    expect(countItems([])).toBe(0)
  })
})

describe("samePackage", () => {
  it("test_it_compares_the_size_and_the_bundle_count", () => {
    expect(samePackage({ packageSize: "0.7", bundleCount: null }, { size: "0.70", bundleCount: null })).toBe(true)
    expect(samePackage({ packageSize: "0.7", bundleCount: 6 }, { size: "0.7", bundleCount: null })).toBe(false)
    expect(samePackage({ packageSize: null, bundleCount: null }, { size: "0.7", bundleCount: null })).toBe(false)
  })
})

describe("answerAfterCreate", () => {
  it("test_it_takes_the_size_most_items_have_and_asks_about_the_units_that_have_none", () => {
    const groups = [group("co", null, 2), group("szt", "0.5", 1), group("szt", "0.7", 4), group("op", "0.7", 1)]

    const answer = answerAfterCreate(groups)

    expect(answer).toEqual({ size: "0.7", bundleCount: null, units: ["szt", "szt", "op"], changed: false })
    expect(packageSizeQuestion(groups, answer!).fill).toEqual([group("co", null, 2)])
  })

  it("test_it_takes_the_bundle_count_along_with_the_size_most_items_have", () => {
    const groups = [group("co", "0.5", 3, 0, 20), group("szt", "0.5", 1)]

    expect(answerAfterCreate(groups)).toEqual({ size: "0.5", bundleCount: 20, units: ["co", "szt"], changed: false })
  })

  it("test_it_has_nothing_to_ask_when_no_item_has_a_size", () => {
    expect(answerAfterCreate([group("co", null), group("szt", null)])).toBeNull()
    expect(answerAfterCreate([])).toBeNull()
  })
})

describe("targetsOf", () => {
  it("test_it_sends_only_the_groups_the_owner_chose", () => {
    const co = group("co", null, 3)
    const kart = group("kart", null)

    expect(targetsOf([co, kart], new Set([groupId(kart)]))).toEqual([{ unitKey: "kart", packageSize: null, bundleCount: null }])
    expect(targetsOf([co, kart], new Set())).toEqual([])
  })
})
