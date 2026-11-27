import { describe, expect, it } from "vitest"

import { BADGE_MAX, badgeText, isOverviewPath } from "./analysis-nav"

describe("badgeText", () => {
  it("test_it_shows_no_badge_while_the_count_is_unknown_or_zero", () => {
    expect(badgeText(undefined)).toBeNull()
    expect(badgeText(0)).toBeNull()
  })

  it("test_it_writes_the_count_out_up_to_the_limit", () => {
    expect(badgeText(1)).toBe("1")
    expect(badgeText(BADGE_MAX)).toBe("99")
  })

  it("test_it_caps_a_larger_count", () => {
    expect(badgeText(BADGE_MAX + 1)).toBe("99+")
    expect(badgeText(2500)).toBe("99+")
  })
})

describe("isOverviewPath", () => {
  it("test_it_marks_the_overview_and_a_products_page_as_the_overview_tab", () => {
    expect(isOverviewPath("/analysis")).toBe(true)
    expect(isOverviewPath("/analysis/")).toBe(true)
    expect(isOverviewPath("/analysis/products/0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b")).toBe(true)
  })

  it("test_it_leaves_the_other_tabs_and_other_sections_out", () => {
    expect(isOverviewPath("/analysis/review")).toBe(false)
    expect(isOverviewPath("/analysis/proposals")).toBe(false)
    expect(isOverviewPath("/expenses")).toBe(false)
    expect(isOverviewPath("/analysis-old")).toBe(false)
  })
})
