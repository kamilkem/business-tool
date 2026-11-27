import { describe, expect, it } from "vitest"

import {
  MAX_BUNDLE_COUNT,
  MIN_BUNDLE_COUNT,
  bundleCountToSend,
  bundleInput,
  commonBundleCount,
  packageLabel,
  parseBundleCount,
} from "./package-size"

describe("packageLabel", () => {
  it("test_it_writes_a_bundle_as_its_count_times_the_size_of_one_package", () => {
    expect(packageLabel("0.5", 20, "L", "en-GB")).toBe("20 × 0.5 L")
    expect(packageLabel("0.33", 24, "L", "pl-PL")).toBe("24 × 0,33 L")
  })

  it("test_it_writes_a_single_package_as_its_size", () => {
    expect(packageLabel("0.7", null, "L", "en-GB")).toBe("0.7 L")
  })

  it("test_it_leaves_the_unit_out_when_the_product_is_not_known", () => {
    expect(packageLabel("0.5", 6, null, "en-GB")).toBe("6 × 0.5")
    expect(packageLabel("0.5", null, null, "en-GB")).toBe("0.5")
  })

  it("test_it_keeps_an_exact_size_with_many_digits", () => {
    expect(packageLabel("0.333333", 3, "L", "en-GB")).toBe("3 × 0.333333 L")
  })
})

describe("parseBundleCount", () => {
  it("test_it_accepts_whole_numbers_from_two_to_9999", () => {
    expect(parseBundleCount("2")).toBe(MIN_BUNDLE_COUNT)
    expect(parseBundleCount(" 20 ")).toBe(20)
    expect(parseBundleCount("9999")).toBe(MAX_BUNDLE_COUNT)
  })

  it.each(["", "1", "0", "10000", "-6", "2.5", "2,5", "six", "1e3", "+6"])("test_it_refuses_%j", (text) => {
    expect(parseBundleCount(text)).toBeNull()
  })
})

describe("bundleInput", () => {
  it("test_it_is_no_bundle_when_the_option_is_off_whatever_the_count_says", () => {
    expect(bundleInput(false, "20")).toEqual({ state: "none" })
    expect(bundleCountToSend(bundleInput(false, "20"))).toBeNull()
  })

  it("test_it_is_a_bundle_with_a_valid_count", () => {
    expect(bundleInput(true, "20")).toEqual({ state: "bundle", count: 20 })
    expect(bundleCountToSend(bundleInput(true, "20"))).toBe(20)
  })

  it("test_it_is_invalid_when_the_option_is_on_and_the_count_is_not_valid", () => {
    expect(bundleInput(true, "")).toEqual({ state: "invalid" })
    expect(bundleInput(true, "1")).toEqual({ state: "invalid" })
  })
})

describe("commonBundleCount", () => {
  it("test_it_is_the_count_all_the_items_share_with_the_same_package_size", () => {
    const item = { packageSize: "0.5", bundleCount: 20 }
    expect(commonBundleCount([item, { ...item }])).toBe(20)
  })

  it("test_it_is_none_when_the_counts_or_sizes_differ_or_there_is_no_bundle", () => {
    expect(commonBundleCount([])).toBeNull()
    expect(commonBundleCount([{ packageSize: "0.5", bundleCount: 20 }, { packageSize: "0.5", bundleCount: 24 }])).toBeNull()
    expect(commonBundleCount([{ packageSize: "0.5", bundleCount: 20 }, { packageSize: "0.33", bundleCount: 20 }])).toBeNull()
    expect(commonBundleCount([{ packageSize: "0.5", bundleCount: null }])).toBeNull()
    expect(commonBundleCount([{ packageSize: null, bundleCount: null }])).toBeNull()
  })
})
