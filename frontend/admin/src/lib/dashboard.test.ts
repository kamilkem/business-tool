import { describe, expect, it } from "vitest";

import {
  type BusinessSummary,
  type BusinessSummaryCurrency,
  type BusinessSummaryMonth,
  dashboardMonth,
  monthSearch,
  otherCurrencies,
  payrollFigure,
  primaryFigures,
  selectedMonth,
  trendBars,
  trendRange,
  validateDashboardSearch,
} from "./dashboard";

const NOVEMBER = { from: "2026-11-01", to: "2026-11-30" };

function figures(
  overrides: Partial<BusinessSummaryCurrency> = {},
): BusinessSummaryCurrency {
  return {
    currency: "PLN",
    incomeGross: 0,
    incomeVat: 0,
    incomeNet: 0,
    revenueEntryCount: 0,
    expensesGross: 0,
    expensesVat: 0,
    expensesNet: 0,
    invoiceCount: 0,
    payrollCost: 0,
    payrollDraftTotal: null,
    result: 0,
    ...overrides,
  };
}

function month(
  key: string,
  overrides: Partial<BusinessSummaryMonth> = {},
): BusinessSummaryMonth {
  return {
    month: key,
    firstDate: `${key}-01`,
    lastDate: `${key}-28`,
    complete: true,
    payrollStatus: "none",
    payrollRunId: null,
    currencies: [figures()],
    ...overrides,
  };
}

function summary(months: BusinessSummaryMonth[]): BusinessSummary {
  return {
    from: months[0]?.month ?? "",
    to: months.at(-1)?.month ?? "",
    currency: "PLN",
    months,
  };
}

describe("the month in the URL", () => {
  it("test_it_shows_this_month_without_a_month_in_the_url", () => {
    expect(dashboardMonth(undefined, NOVEMBER)).toEqual(NOVEMBER);
  });

  it("test_it_shows_an_earlier_month_from_the_url", () => {
    expect(dashboardMonth("2026-02", NOVEMBER)).toEqual({
      from: "2026-02-01",
      to: "2026-02-28",
    });
  });

  it("test_it_falls_back_to_this_month_for_a_month_in_the_future", () => {
    expect(dashboardMonth("2026-12", NOVEMBER)).toEqual(NOVEMBER);
  });

  it("test_it_drops_a_month_that_is_not_yyyy_mm", () => {
    expect(validateDashboardSearch({ month: "2026-13" })).toEqual({
      month: undefined,
    });
    expect(validateDashboardSearch({ month: "2026-10" })).toEqual({
      month: "2026-10",
    });
  });
});

describe("trendRange", () => {
  it("test_it_asks_for_six_months_ending_with_the_selected_one", () => {
    expect(trendRange(NOVEMBER)).toEqual({ from: "2026-06", to: "2026-11" });
  });

  it("test_it_crosses_the_year", () => {
    expect(trendRange({ from: "2026-02-01", to: "2026-02-28" })).toEqual({
      from: "2025-09",
      to: "2026-02",
    });
  });
});

describe("the selected month", () => {
  it("test_it_is_the_last_month_of_the_summary", () => {
    expect(
      selectedMonth(summary([month("2026-10"), month("2026-11")])).month,
    ).toBe("2026-11");
  });

  it("test_it_shows_the_organization_currency_and_names_the_others", () => {
    const november = month("2026-11", {
      currencies: [
        figures({ incomeNet: 100 }),
        figures({ currency: "EUR" }),
        figures({ currency: "USD" }),
      ],
    });

    expect(primaryFigures(november).incomeNet).toBe(100);
    expect(otherCurrencies(november)).toEqual(["EUR", "USD"]);
    expect(otherCurrencies(month("2026-10"))).toEqual([]);
  });

  it("test_it_links_to_the_month_s_business_dates", () => {
    expect(monthSearch(month("2026-02"))).toEqual({
      from: "2026-02-01",
      to: "2026-02-28",
    });
  });
});

describe("payrollFigure", () => {
  it("test_it_shows_the_approved_cost", () => {
    expect(payrollFigure("approved", figures({ payrollCost: 500_00 }))).toEqual(
      { kind: "approved", amount: 500_00 },
    );
  });

  it("test_it_shows_a_draft_total_as_an_estimate_only", () => {
    expect(
      payrollFigure(
        "draft",
        figures({ payrollCost: 0, payrollDraftTotal: 420_00 }),
      ),
    ).toEqual({ kind: "draft", estimate: 420_00 });
  });

  it("test_it_says_when_there_is_no_run", () => {
    expect(payrollFigure("none", figures())).toEqual({ kind: "none" });
  });
});

describe("trendBars", () => {
  it("test_it_scales_income_and_costs_against_the_largest_amount", () => {
    const bars = trendBars(
      summary([
        month("2026-10", {
          payrollStatus: "approved",
          currencies: [
            figures({
              incomeNet: 1000,
              expensesNet: 300,
              payrollCost: 200,
              result: 500,
            }),
          ],
        }),
        month("2026-11", {
          complete: false,
          currencies: [
            figures({ incomeNet: 2000, expensesNet: 500, result: 1500 }),
          ],
        }),
      ]),
    );

    expect(
      bars.map((bar) => [bar.month, bar.income, bar.costs, bar.result]),
    ).toEqual([
      ["2026-10", 1000, 500, 500],
      ["2026-11", 2000, 500, 1500],
    ]);
    expect(bars.map((bar) => [bar.incomeHeight, bar.costsHeight])).toEqual([
      [50, 25],
      [100, 25],
    ]);
    expect(bars.map((bar) => [bar.complete, bar.payrollPending])).toEqual([
      [true, false],
      [false, true],
    ]);
  });

  it("test_it_leaves_out_a_draft_estimate_from_the_costs", () => {
    const [bar] = trendBars(
      summary([
        month("2026-11", {
          payrollStatus: "draft",
          currencies: [figures({ expensesNet: 300, payrollDraftTotal: 900 })],
        }),
      ]),
    );

    expect(bar?.costs).toBe(300);
    expect(bar?.payrollPending).toBe(true);
  });

  it("test_it_draws_no_bars_for_empty_or_negative_amounts", () => {
    const bars = trendBars(
      summary([
        month("2026-10"),
        month("2026-11", {
          currencies: [figures({ expensesNet: -50, result: 50 })],
        }),
      ]),
    );

    expect(bars.map((bar) => [bar.incomeHeight, bar.costsHeight])).toEqual([
      [0, 0],
      [0, 0],
    ]);
  });

  it("test_it_uses_the_organization_currency_only", () => {
    const [bar] = trendBars(
      summary([
        month("2026-11", {
          currencies: [
            figures({ incomeNet: 100 }),
            figures({ currency: "EUR", incomeNet: 99_999 }),
          ],
        }),
      ]),
    );

    expect(bar?.income).toBe(100);
    expect(bar?.incomeHeight).toBe(100);
  });
});
