import { describe, expect, it } from "vitest"

import {
  type PackageSizeGroup,
  answerAfterCreate,
  countItems,
  isAsking,
  packageSizeQuestion,
  sameSize,
  targetsOf,
  groupId,
  unitKeyOf,
} from "./package-sizes"

function group(unitKey: string | null, packageSize: string | null, count = 1, confirmedCount = 0): PackageSizeGroup {
  return { unitKey, unit: unitKey, packageSize, count, confirmedCount }
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
    const question = packageSizeQuestion(groups, { size: "0.7", units: ["szt"], changed: false })

    expect(question.fill.map(groupId)).toEqual([groupId(group("co", null)), groupId(group("kart", null)), groupId(group(null, null))])
    expect(question.sameUnit).toEqual([])
    expect(question.otherUnits).toEqual([])
    expect(isAsking(question)).toBe(true)
  })

  it("test_it_leaves_the_items_with_no_size_under_the_unit_just_set_alone", () => {
    const question = packageSizeQuestion([group("szt", null, 2), group("szt", "0.7")], { size: "0.7", units: ["szt"], changed: false })

    expect(question.fill).toEqual([])
    expect(isAsking(question)).toBe(false)
  })

  it("test_it_asks_about_other_sizes_too_when_an_existing_size_was_changed_listing_the_same_unit_apart_from_the_others", () => {
    const question = packageSizeQuestion(groups, { size: "0.7", units: ["szt"], changed: true })

    expect(question.fill.map((entry) => entry.unitKey)).toEqual(["co", "kart", null])
    // The 0.5 L bottles are the same unit; the case that has 4.2 is another.
    expect(question.sameUnit).toEqual([group("szt", "0.5", 4, 3)])
    expect(question.otherUnits).toEqual([group("co", "4.2", 1, 1)])
  })

  it("test_it_never_offers_the_size_the_items_have_already", () => {
    const question = packageSizeQuestion([group("szt", "0.70"), group("co", "0.7")], { size: "0.7", units: ["szt"], changed: true })

    expect(isAsking(question)).toBe(false)
  })

  it("test_it_does_not_ask_about_other_sizes_when_a_size_was_only_set", () => {
    const question = packageSizeQuestion([group("szt", "0.5"), group("co", "4.2")], { size: "0.7", units: ["szt"], changed: false })

    expect(isAsking(question)).toBe(false)
  })

  it("test_it_treats_items_with_no_unit_as_a_unit_of_their_own", () => {
    const question = packageSizeQuestion([group(null, null), group("szt", "0.7")], { size: "0.7", units: [null], changed: false })

    expect(question.fill).toEqual([])
    expect(packageSizeQuestion([group(null, null)], { size: "0.7", units: ["szt"], changed: false }).fill).toHaveLength(1)
  })

  it("test_it_counts_the_items_of_the_groups", () => {
    expect(countItems(groups)).toBe(16)
    expect(countItems([])).toBe(0)
  })
})

describe("answerAfterCreate", () => {
  it("test_it_takes_the_size_most_items_have_and_asks_about_the_units_that_have_none", () => {
    const groups = [group("co", null, 2), group("szt", "0.5", 1), group("szt", "0.7", 4), group("op", "0.7", 1)]

    const answer = answerAfterCreate(groups)

    expect(answer).toEqual({ size: "0.7", units: ["szt", "szt", "op"], changed: false })
    expect(packageSizeQuestion(groups, answer!).fill).toEqual([group("co", null, 2)])
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

    expect(targetsOf([co, kart], new Set([groupId(kart)]))).toEqual([{ unitKey: "kart", packageSize: null }])
    expect(targetsOf([co, kart], new Set())).toEqual([])
  })
})
