import { afterEach, describe, expect, it } from "vitest"

import { i18n } from "../i18n"

import { hoursDetail, minutesOf, noRateNotice, payExplanation, payHeading } from "./my-pay"
import type { MyPay } from "./queries"

afterEach(async () => {
  await i18n.changeLanguage("en")
})

function pay(overrides: Partial<MyPay>): MyPay {
  return {
    month: "2026-11",
    firstDate: "2026-11-01",
    lastDate: "2026-11-30",
    status: "estimate",
    currency: "PLN",
    shifts: 3,
    seconds: 27_000,
    paidSeconds: 27_000,
    shiftsWithoutRate: 0,
    openShifts: 0,
    payForHours: 22_500,
    adjustments: [],
    adjustmentsTotal: 0,
    total: 22_500,
    approvedAt: null,
    ...overrides,
  }
}

describe("minutesOf", () => {
  it("test_it_counts_whole_minutes_only", () => {
    expect(minutesOf(8_999)).toBe(149)
    expect(minutesOf(9_000)).toBe(150)
    expect(minutesOf(59)).toBe(0)
  })
})

describe("payHeading and payExplanation", () => {
  it("test_it_labels_an_estimate_as_such", () => {
    expect(payHeading(pay({}))).toBe("Estimated pay")
    expect(payExplanation(pay({}))).toMatch(/^Gross, from your finished shifts so far/)
  })

  it("test_it_labels_an_approved_paycheck", () => {
    const approved = pay({ status: "approved", approvedAt: "2026-11-20T10:00:00+00:00" })
    expect(payHeading(approved)).toBe("Approved pay")
    expect(payExplanation(approved)).toBe("Gross, as approved for payroll.")
  })
})

describe("noRateNotice", () => {
  it("test_it_says_nothing_when_every_shift_has_a_rate", () => {
    expect(noRateNotice(pay({}))).toBeNull()
  })

  it("test_it_says_no_rate_is_set_when_no_shift_has_one", () => {
    expect(noRateNotice(pay({ shiftsWithoutRate: 3, payForHours: 0, total: 0 }))).toBe(
      "No pay rate is set for you yet, so this shows no pay. Ask your manager to set your rate.",
    )
  })

  it("test_it_counts_the_shifts_left_out", () => {
    expect(noRateNotice(pay({ shiftsWithoutRate: 1 }))).toBe(
      "No pay rate is set for 1 shift, so it isn't counted. Ask your manager to set your rate.",
    )
    expect(noRateNotice(pay({ shiftsWithoutRate: 2 }))).toBe(
      "No pay rate is set for 2 shifts, so they aren't counted. Ask your manager to set your rate.",
    )
  })
})

describe("hoursDetail", () => {
  it("test_it_counts_the_finished_shifts", () => {
    expect(hoursDetail(pay({}))).toBe("in 3 shifts")
    expect(hoursDetail(pay({ shifts: 0, seconds: 0, paidSeconds: 0 }))).toBe("No finished shifts")
  })

  it("test_it_shows_the_paid_time_when_an_approved_run_rounded_it", () => {
    expect(hoursDetail(pay({ status: "approved", shifts: 1, seconds: 4_200, paidSeconds: 5_400 }))).toBe(
      "in 1 shift · paid 1 h 30 min (rounded)",
    )
  })
})

describe("in Polish", () => {
  it("test_it_counts_the_shifts_left_out_with_polish_plurals", async () => {
    await i18n.changeLanguage("pl")
    expect(noRateNotice(pay({ shiftsWithoutRate: 1 }))).toBe(
      "1 zmiana nie ma stawki, więc nie jest wliczona. Poproś przełożonego o ustawienie stawki.",
    )
    expect(noRateNotice(pay({ shiftsWithoutRate: 2 }))).toBe(
      "2 zmiany nie mają stawki, więc nie są wliczone. Poproś przełożonego o ustawienie stawki.",
    )
    expect(noRateNotice(pay({ shifts: 6, shiftsWithoutRate: 5 }))).toBe(
      "5 zmian nie ma stawki, więc nie są wliczone. Poproś przełożonego o ustawienie stawki.",
    )
  })

  it("test_it_shows_the_rounded_paid_time_in_polish", async () => {
    await i18n.changeLanguage("pl")
    expect(payHeading(pay({}))).toBe("Szacowana wypłata")
    expect(hoursDetail(pay({ status: "approved", shifts: 1, seconds: 4_200, paidSeconds: 5_400 }))).toBe(
      "z 1 zmiany · płatne 1 godz. 30 min (po zaokrągleniu)",
    )
  })
})
