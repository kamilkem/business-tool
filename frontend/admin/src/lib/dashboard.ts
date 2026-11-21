import type { components } from "@business-tool/api-client";
import {
  type BusinessPeriod,
  compareBusinessDates,
  monthOf,
  shiftPeriod,
} from "@business-tool/ui/lib/date-time";
import type { MinorUnits } from "@business-tool/ui/lib/money";

import { type Month, monthKey, validatePayrollSearch } from "./payroll";

/*
 * The dashboard (#68): the selected month's figures from the business summary (ADR 0029) and a trend
 * of the months up to it, both from one `GET /business-summary` request.
 */

export type BusinessSummary = components["schemas"]["BusinessSummary"];
export type BusinessSummaryMonth =
  components["schemas"]["BusinessSummaryMonth"];
export type BusinessSummaryCurrency =
  components["schemas"]["BusinessSummaryCurrency"];
export type PayrollStatus = BusinessSummaryMonth["payrollStatus"];

/** Months in the trend, the selected one last. The API allows up to 12 per request. */
export const TREND_MONTHS = 6;

export interface DashboardSearch {
  month?: Month;
}

/** The dashboard's `?month=2026-10`, the same shape as the Payroll page's. */
export function validateDashboardSearch(
  search: Record<string, unknown>,
): DashboardSearch {
  return validatePayrollSearch(search);
}

/**
 * The month to show: the one in the URL, or this month (a preview while it lasts). A month after
 * this one is refused by the API (422), so it falls back to this month as well.
 */
export function dashboardMonth(
  month: Month | undefined,
  thisMonth: BusinessPeriod,
): BusinessPeriod {
  if (month) {
    const period = monthOf(`${month}-01`);
    if (compareBusinessDates(period.from, thisMonth.from) <= 0) return period;
  }
  return thisMonth;
}

/** The `?from=&to=` (`YYYY-MM`) of the request: the trend's months, ending with the selected one. */
export function trendRange(
  selected: BusinessPeriod,
  months: number = TREND_MONTHS,
): { from: Month; to: Month } {
  let first = selected;
  for (let i = 1; i < months; i++) first = shiftPeriod(first, -1);
  return { from: monthKey(first), to: monthKey(selected) };
}

/** The figures of a month in the organization's currency: always the first entry (ADR 0029 §4). */
export function primaryFigures(
  month: BusinessSummaryMonth,
): BusinessSummaryCurrency {
  const [primary] = month.currencies;
  if (!primary)
    throw new Error(`The business summary of ${month.month} has no figures.`);
  return primary;
}

/** Currencies other than the organization's that the month has amounts in: shown as a note, never added. */
export function otherCurrencies(month: BusinessSummaryMonth): string[] {
  return month.currencies.slice(1).map((figures) => figures.currency);
}

/** The selected month's entry: the last one, since the request ends with it. */
export function selectedMonth(summary: BusinessSummary): BusinessSummaryMonth {
  const month = summary.months.at(-1);
  if (!month)
    throw new Error(
      `The business summary ${summary.from}–${summary.to} has no months.`,
    );
  return month;
}

/** What the payroll card says: the approved cost, a draft's estimate (not in the result), or no run yet. */
export type PayrollFigure =
  | { kind: "approved"; amount: MinorUnits }
  | { kind: "draft"; estimate: MinorUnits }
  | { kind: "none" };

export function payrollFigure(
  status: PayrollStatus,
  figures: BusinessSummaryCurrency,
): PayrollFigure {
  if (status === "approved")
    return { kind: "approved", amount: figures.payrollCost };
  if (status === "draft")
    return { kind: "draft", estimate: figures.payrollDraftTotal ?? 0 };
  return { kind: "none" };
}

/** One month of the trend chart, in the organization's currency. */
export interface TrendBar {
  month: Month;
  /** The month's first business date, to format its name. */
  firstDate: string;
  complete: boolean;
  income: MinorUnits;
  /** Net expenses plus the approved payroll cost: what the result subtracts. */
  costs: MinorUnits;
  result: MinorUnits;
  /** Bar heights, 0–100, against the trend's largest income or costs. Negative amounts draw no bar. */
  incomeHeight: number;
  costsHeight: number;
  /** The payroll in the costs is missing or only a draft. */
  payrollPending: boolean;
}

export function trendBars(summary: BusinessSummary): TrendBar[] {
  const rows = summary.months.map((month) => {
    const figures = primaryFigures(month);
    return {
      month,
      income: figures.incomeNet,
      costs: figures.expensesNet + figures.payrollCost,
      result: figures.result,
    };
  });
  const max = Math.max(0, ...rows.flatMap((row) => [row.income, row.costs]));
  const height = (amount: MinorUnits) =>
    max > 0 && amount > 0 ? (amount / max) * 100 : 0;

  return rows.map(({ month, income, costs, result }) => ({
    month: month.month,
    firstDate: month.firstDate,
    complete: month.complete,
    income,
    costs,
    result,
    incomeHeight: height(income),
    costsHeight: height(costs),
    payrollPending: month.payrollStatus !== "approved",
  }));
}

/** The Income and Expenses pages' `?from=&to=` for a month. */
export function monthSearch(month: BusinessSummaryMonth): {
  from: string;
  to: string;
} {
  return { from: month.firstDate, to: month.lastDate };
}
