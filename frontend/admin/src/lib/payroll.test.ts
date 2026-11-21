import { ApiError } from "@business-tool/api-client"
import { afterEach, beforeEach, describe, expect, it } from "vitest"

import { i18n } from "../i18n"
import type { Member } from "./members"
import {
  type DayRate,
  type HourlyRate,
  type Paycheck,
  type PayrollRun,
  type PayrollRunSummary,
  adjustmentFormErrors,
  apiFormErrors,
  approvalBlockers,
  exportFileName,
  hourlyRateOn,
  isDayRateLocked,
  isHourlyRateLocked,
  linesAmount,
  lockedPeriods,
  paycheckRows,
  paymentFormErrors,
  payrollMonth,
  rateFormErrors,
  ratesByMember,
  roundingLabel,
  runOfMonth,
  runState,
  runStateLabel,
  validatePayrollSearch,
  withFieldError,
} from "./payroll"

const NOVEMBER = { from: "2026-11-01", to: "2026-11-30" }

function member(userId: string, firstName: string, deactivatedAt: string | null = null): Member {
  return {
    userId,
    email: `${firstName.toLowerCase()}@example.com`,
    firstName,
    lastName: "Doe",
    role: "employee",
    joinedAt: "2026-01-01T00:00:00+00:00",
    invitationPending: false,
    deactivatedAt,
  }
}

function paycheck(overrides: Partial<Paycheck> = {}): Paycheck {
  return {
    id: "paycheck-1",
    userId: "eve",
    paidSeconds: 3600,
    payForHours: 3200,
    adjustmentsTotal: 0,
    total: 3200,
    linesWithoutRate: 0,
    lines: [
      { shiftId: "s1", date: "2026-10-03", seconds: 3600, paidSeconds: 3600, rateKind: "hourly", rateId: "r1", rate: 3200, amount: 3200 },
    ],
    adjustments: [],
    paidOn: null,
    paymentMethod: null,
    paidMarkedBy: null,
    paidMarkedAt: null,
    ...overrides,
  }
}

function run(overrides: Partial<PayrollRun> = {}): PayrollRun {
  return {
    id: "run-1",
    month: "2026-10",
    firstDate: "2026-10-01",
    lastDate: "2026-10-31",
    status: "draft",
    currency: "PLN",
    roundingUnit: "none",
    roundingDirection: "nearest",
    openShifts: 0,
    pendingChangeRequests: 0,
    linesWithoutRate: 0,
    total: 3200,
    paidPaychecks: 0,
    paid: false,
    paychecks: [paycheck()],
    createdBy: "owner",
    createdAt: "2026-11-01T10:00:00+00:00",
    generatedBy: "owner",
    generatedAt: "2026-11-01T10:00:00+00:00",
    approvedBy: null,
    approvedAt: null,
    ...overrides,
  }
}

function summary(month: string, overrides: Partial<PayrollRunSummary> = {}): PayrollRunSummary {
  return {
    id: `run-${month}`,
    month,
    status: "approved",
    currency: "PLN",
    roundingUnit: "none",
    roundingDirection: "nearest",
    paychecks: 2,
    paidSeconds: 7200,
    linesWithoutRate: 0,
    total: 6400,
    paidPaychecks: 0,
    paid: false,
    generatedAt: "2026-11-01T10:00:00+00:00",
    approvedAt: "2026-11-02T10:00:00+00:00",
    ...overrides,
  }
}

function hourly(id: string, effectiveFrom: string, effectiveTo: string | null, userId = "eve"): HourlyRate {
  return { id, userId, effectiveFrom, effectiveTo, amount: 3200, currency: "PLN", createdAt: "2026-01-01T00:00:00+00:00", updatedAt: null }
}

function dayRate(id: string, date: string, userId = "eve"): DayRate {
  return { id, userId, date, amount: 4500, currency: "PLN", createdAt: "2026-01-01T00:00:00+00:00", updatedAt: null }
}

describe("the month in the URL", () => {
  it("test_it_keeps_a_valid_month_and_drops_anything_else", () => {
    expect(validatePayrollSearch({ month: "2026-10" })).toEqual({ month: "2026-10" })
    expect(validatePayrollSearch({ month: "2026-13" })).toEqual({ month: undefined })
    expect(validatePayrollSearch({ month: 202610 })).toEqual({ month: undefined })
    expect(validatePayrollSearch({})).toEqual({ month: undefined })
  })

  it("test_it_shows_last_month_by_default_and_for_a_month_after_this_one", () => {
    expect(payrollMonth(undefined, NOVEMBER)).toEqual({ from: "2026-10-01", to: "2026-10-31" })
    expect(payrollMonth("2026-12", NOVEMBER)).toEqual({ from: "2026-10-01", to: "2026-10-31" })
    expect(payrollMonth("2026-11", NOVEMBER)).toEqual(NOVEMBER)
    expect(payrollMonth("2026-02", NOVEMBER)).toEqual({ from: "2026-02-01", to: "2026-02-28" })
  })

  it("test_it_steps_back_over_a_year_boundary", () => {
    expect(payrollMonth(undefined, { from: "2027-01-01", to: "2027-01-31" })).toEqual({ from: "2026-12-01", to: "2026-12-31" })
  })
})

describe("runState", () => {
  it("test_it_tells_none_draft_approved_partly_paid_and_paid_apart", () => {
    expect(runState(null)).toBe("none")
    expect(runState({ status: "draft", paid: false, paidPaychecks: 0 })).toBe("draft")
    expect(runState({ status: "approved", paid: false, paidPaychecks: 0 })).toBe("approved")
    expect(runState({ status: "approved", paid: false, paidPaychecks: 1 })).toBe("partlyPaid")
    expect(runState({ status: "approved", paid: true, paidPaychecks: 2 })).toBe("paid")
  })

  it("test_it_finds_the_run_of_a_month", () => {
    const runs = [summary("2026-10"), summary("2026-09")]
    expect(runOfMonth(runs, "2026-09")?.id).toBe("run-2026-09")
    expect(runOfMonth(runs, "2026-08")).toBeNull()
  })
})

describe("roundingLabel", () => {
  it("test_it_describes_the_rounding_of_each_shift", () => {
    expect(roundingLabel("none", "up")).toBe("Exact time")
    expect(roundingLabel("15", "nearest")).toBe("Each shift to the nearest 15 minutes")
    expect(roundingLabel("60", "down")).toBe("Each shift rounded down to 1 hour")
  })
})

describe("approvalBlockers", () => {
  it("test_it_has_none_for_a_complete_draft_of_a_past_month", () => {
    expect(approvalBlockers(run(), "2026-11-20")).toEqual([])
  })

  it("test_it_names_every_reason_the_api_would_refuse", () => {
    const blockers = approvalBlockers(
      run({
        lastDate: "2026-11-30",
        openShifts: 1,
        pendingChangeRequests: 2,
        linesWithoutRate: 3,
        paychecks: [paycheck({ total: -100 })],
      }),
      "2026-11-20",
    )
    expect(blockers).toEqual([
      "The month isn't over yet: this draft is a preview.",
      "1 shift is still open. End it on the Time page, then regenerate.",
      "2 shift change requests are waiting for a decision. Decide them, then regenerate.",
      "3 shifts have no rate. Set the rate, then regenerate.",
      "1 paycheck has a negative total. Move part of the deduction to a later month.",
    ])
  })

  it("test_it_treats_the_last_day_of_the_month_as_not_over", () => {
    expect(approvalBlockers(run(), "2026-10-31")).toEqual(["The month isn't over yet: this draft is a preview."])
  })

  it("test_it_has_none_for_an_approved_run", () => {
    expect(approvalBlockers(run({ status: "approved", linesWithoutRate: 1 }), "2026-11-20")).toEqual([])
  })
})

describe("paycheckRows", () => {
  it("test_it_names_paychecks_and_sorts_them_by_name", () => {
    const rows = paycheckRows(
      run({ paychecks: [paycheck({ id: "p1", userId: "zoe" }), paycheck({ id: "p2", userId: "ann" }), paycheck({ id: "p3", userId: "gone" })] }),
      [member("zoe", "Zoe"), member("ann", "Ann", "2026-10-15T00:00:00+00:00")],
    )
    expect(rows.map((row) => [row.name, row.deactivated])).toEqual([
      ["Ann Doe", true],
      ["Former member", true],
      ["Zoe Doe", false],
    ])
  })

  it("test_it_sums_the_shift_amounts_without_a_rate_as_zero", () => {
    const check = paycheck({
      lines: [
        { shiftId: "a", date: "2026-10-01", seconds: 100, paidSeconds: 100, rateKind: "hourly", rateId: "r", rate: 3200, amount: 89 },
        { shiftId: "b", date: "2026-10-02", seconds: 100, paidSeconds: 100, rateKind: null, rateId: null, rate: null, amount: null },
        { shiftId: "c", date: "2026-10-03", seconds: 100, paidSeconds: 100, rateKind: "day", rateId: "d", rate: 3200, amount: 89 },
      ],
    })
    expect(linesAmount(check)).toBe(178)
  })
})

describe("rates", () => {
  const october = [{ from: "2026-10-01", to: "2026-10-31" }]

  it("test_it_locks_the_months_of_approved_runs_only", () => {
    expect(lockedPeriods([summary("2026-10"), summary("2026-11", { status: "draft" })])).toEqual(october)
  })

  it("test_it_locks_an_hourly_rate_in_effect_on_any_date_of_a_locked_month", () => {
    expect(isHourlyRateLocked(hourly("a", "2026-09-01", "2026-10-01"), october)).toBe(true)
    expect(isHourlyRateLocked(hourly("b", "2026-09-01", null), october)).toBe(true)
    expect(isHourlyRateLocked(hourly("c", "2026-11-01", null), october)).toBe(false)
    expect(isHourlyRateLocked(hourly("d", "2026-01-01", "2026-09-30"), october)).toBe(false)
  })

  it("test_it_locks_a_day_rate_on_a_date_of_a_locked_month", () => {
    expect(isDayRateLocked(dayRate("a", "2026-10-31"), october)).toBe(true)
    expect(isDayRateLocked(dayRate("b", "2026-11-01"), october)).toBe(false)
  })

  it("test_it_finds_the_hourly_rate_in_effect_on_a_date", () => {
    const rates = [hourly("new", "2026-11-01", null), hourly("old", "2026-01-01", "2026-10-31")]
    expect(hourlyRateOn(rates, "2026-10-31")?.id).toBe("old")
    expect(hourlyRateOn(rates, "2026-11-01")?.id).toBe("new")
    expect(hourlyRateOn(rates, "2025-12-31")).toBeNull()
  })

  it("test_it_lists_active_members_and_deactivated_ones_with_a_rate", () => {
    const members = [
      member("zoe", "Zoe"),
      member("gone", "Bob", "2026-10-01T00:00:00+00:00"),
      member("left", "Al", "2026-10-01T00:00:00+00:00"),
      member("ann", "Ann"),
    ]
    const entries = ratesByMember(members, [hourly("r", "2026-01-01", null, "gone")], [dayRate("d", "2026-10-31", "ann")])
    expect(entries.map((entry) => [entry.member.userId, entry.hourly.length, entry.day.length])).toEqual([
      ["ann", 0, 1],
      ["zoe", 0, 0],
      ["gone", 1, 0],
    ])
  })
})

describe("forms", () => {
  it("test_it_asks_for_a_date_and_a_rate_above_zero", () => {
    expect(rateFormErrors({ date: "", amount: null })).toEqual({
      date: "Choose a date.",
      amount: "Enter the gross amount per hour.",
    })
    expect(rateFormErrors({ date: "2026-10-01", amount: 0 })).toEqual({ amount: "The rate must be more than zero." })
    expect(rateFormErrors({ date: "2026-10-01", amount: 3050 })).toEqual({})
  })

  it("test_it_asks_for_a_person_a_label_and_a_non_zero_amount", () => {
    expect(Object.keys(adjustmentFormErrors({ userId: "", label: "  ", amount: null }))).toEqual(["userId", "label", "amount"])
    expect(adjustmentFormErrors({ userId: "eve", label: "Bonus", amount: 0 })).toEqual({ amount: "The amount can't be zero." })
    expect(adjustmentFormErrors({ userId: "eve", label: "x".repeat(101), amount: -5000 })).toEqual({
      label: "Up to 100 characters.",
    })
    expect(adjustmentFormErrors({ userId: "eve", label: "Advance", amount: -5000 })).toEqual({})
  })

  it("test_it_refuses_a_payment_date_after_today_and_no_method", () => {
    expect(paymentFormErrors({ paidOn: "2026-11-21", method: "" }, "2026-11-20")).toEqual({
      paidOn: "The day can't be after today.",
      method: "Choose how it was paid.",
    })
    expect(paymentFormErrors({ paidOn: "2026-11-20", method: "cash" }, "2026-11-20")).toEqual({})
  })

  it("test_it_puts_violations_on_fields_and_other_refusals_on_the_form", () => {
    const violation = new ApiError(422, {
      violations: [
        { propertyPath: "effectiveFrom", message: "This is not a date." },
        { propertyPath: "other", message: "Odd." },
      ],
    })
    expect(apiFormErrors(violation, { effectiveFrom: "date" })).toEqual({ date: "This is not a date.", form: "Odd." })
    const locked = new ApiError(409, { detail: "English detail.", code: "time_tracking.shift_period_locked", parameters: { date: "2026-10-31" } })
    expect(apiFormErrors(locked)).toEqual({ form: "The shifts of 31 Oct 2026 are locked: that period has been settled." })
  })

  it("test_it_replaces_or_removes_one_field_error", () => {
    expect(withFieldError({ amount: "Old", date: "D" }, "amount", null)).toEqual({ date: "D" })
    expect(withFieldError({ date: "D" }, "amount", "New")).toEqual({ date: "D", amount: "New" })
  })
})

describe("apiFormErrors with a code", () => {
  it("test_it_names_the_person_a_refusal_is_about", () => {
    const error = new ApiError(409, {
      detail: "English detail.",
      code: "payroll.pay_rate_date_taken.day_rate",
      parameters: { userId: "01a1fafb-ebac-7edd-8077-345173ce565c", date: "2026-11-03" },
    })
    expect(apiFormErrors(error, {}, { names: { "01a1fafb-ebac-7edd-8077-345173ce565c": "Max Doe" } })).toEqual({
      form: "Max Doe already has a day rate on 3 Nov 2026.",
    })
  })
})

describe("exportFileName", () => {
  it("test_it_names_the_file_as_the_api_does", () => {
    expect(exportFileName("2026-10", "paychecks")).toBe("payroll-2026-10-paychecks.csv")
    expect(exportFileName("2026-10", "shifts")).toBe("payroll-2026-10-shifts.csv")
  })
})

describe("in Polish", () => {
  beforeEach(async () => {
    await i18n.changeLanguage("pl")
  })
  afterEach(async () => {
    await i18n.changeLanguage("en")
  })

  it("test_it_counts_each_blocker_with_the_polish_plural_forms", () => {
    const blockers = (n: number) =>
      approvalBlockers(run({ openShifts: n, pendingChangeRequests: n, linesWithoutRate: n }), "2026-11-20")

    expect(blockers(1)).toEqual([
      "1 zmiana jest nadal otwarta. Zakończ ją na stronie Czas pracy i wygeneruj listę ponownie.",
      "1 prośba o zmianę czeka na decyzję. Rozpatrz ją i wygeneruj listę ponownie.",
      "1 zmiana nie ma stawki. Ustaw stawkę i wygeneruj listę ponownie.",
    ])
    expect(blockers(3)[0]).toBe("3 zmiany są nadal otwarte. Zakończ je na stronie Czas pracy i wygeneruj listę ponownie.")
    expect(blockers(5)[1]).toBe("5 próśb o zmianę czeka na decyzję. Rozpatrz je i wygeneruj listę ponownie.")
    expect(blockers(22)[2]).toBe("22 zmiany nie mają stawki. Ustaw stawkę i wygeneruj listę ponownie.")
  })

  it("test_it_declines_the_rounding_length_after_each_direction", () => {
    expect(roundingLabel("none", "up")).toBe("Dokładny czas")
    expect(roundingLabel("15", "nearest")).toBe("Każda zmiana zaokrąglona do najbliższych 15 minut")
    expect(roundingLabel("60", "nearest")).toBe("Każda zmiana zaokrąglona do najbliższej pełnej godziny")
    expect(roundingLabel("60", "down")).toBe("Każda zmiana zaokrąglona w dół do pełnej godziny")
  })

  it("test_it_names_the_run_states_and_a_former_member", () => {
    expect(runStateLabel("partlyPaid")).toBe("Częściowo wypłacona")
    expect(paycheckRows(run(), [])[0]?.name).toBe("Osoba spoza zespołu")
  })

  it("test_it_counts_the_label_limit_in_polish", () => {
    expect(adjustmentFormErrors({ userId: "eve", label: "x".repeat(101), amount: 100 }).label).toBe("Najwyżej 100 znaków.")
  })
})
