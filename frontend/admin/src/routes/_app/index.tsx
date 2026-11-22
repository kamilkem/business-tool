import type { ReactNode } from "react";
import { useSuspenseQuery } from "@tanstack/react-query";
import { Link, createFileRoute, useNavigate } from "@tanstack/react-router";
import {
  ArrowRightIcon,
  ChevronLeftIcon,
  ChevronRightIcon,
  HourglassIcon,
  InfoIcon,
  ReceiptIcon,
  ScaleIcon,
  TrendingUpIcon,
  WalletIcon,
  type LucideIcon,
} from "lucide-react";
import { useTranslation } from "react-i18next";

import {
  Alert,
  AlertDescription,
  AlertTitle,
} from "@business-tool/ui/components/alert";
import { Badge } from "@business-tool/ui/components/badge";
import { Button } from "@business-tool/ui/components/button";
import {
  Card,
  CardAction,
  CardContent,
  CardDescription,
  CardFooter,
  CardHeader,
  CardTitle,
} from "@business-tool/ui/components/card";
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from "@business-tool/ui/components/tooltip";
import { useMoneyFormat } from "@business-tool/ui/hooks/use-organization-currency";
import { useDateTimeFormat } from "@business-tool/ui/hooks/use-organization-time-zone";
import {
  type BusinessPeriod,
  compareBusinessDates,
  shiftPeriod,
  thisMonth as monthNow,
  today,
} from "@business-tool/ui/lib/date-time";
import { cn } from "@business-tool/ui/lib/utils";

import { CashOnHandCard } from "@/components/cash-on-hand-card";
import { UseTimeclock } from "@/components/use-timeclock";
import { can } from "@/lib/access";
import {
  type BusinessSummary,
  type BusinessSummaryMonth,
  type DashboardSearch,
  type TrendBar,
  dashboardMonth,
  monthSearch,
  otherCurrencies,
  payrollFigure,
  primaryFigures,
  selectedMonth,
  trendBars,
  trendRange,
  validateDashboardSearch,
} from "@/lib/dashboard";
import { monthKey } from "@/lib/payroll";
import { lastClosePeriod } from "@/lib/cash-on-hand";
import {
  businessSummaryQuery,
  cashBalancesQuery,
  dayClosesQuery,
} from "@/lib/queries";

/*
 * The start page. Owners and managers get the dashboard (#68): the selected month's income,
 * expenses, payroll and result, and a trend of the months up to it, from one business summary
 * request (ADR 0029), and the cash on hand now from the Cash flow balances (#191). Employees get
 * the way to the timeclock (ADR 0021). The start page is where `requireCapability()` redirects
 * to, so it is gated with `can()` rather than redirecting itself.
 */
export const Route = createFileRoute("/_app/")({
  validateSearch: (search: Record<string, unknown>): DashboardSearch =>
    validateDashboardSearch(search),
  loaderDeps: ({ search }) => ({ month: search.month }),
  loader: async ({ context, deps }) => {
    const { membership, queryClient } = context;
    if (!membership || !can(membership.role, "dashboard")) return;
    const { organization } = membership;
    const period = dashboardMonth(deps.month, monthNow(organization.timezone));
    const cashOnHand = can(membership.role, "cashFlow")
      ? [
          queryClient.ensureQueryData(cashBalancesQuery(organization.id)),
          queryClient.ensureQueryData(
            dayClosesQuery(
              organization.id,
              lastClosePeriod(today(organization.timezone)),
            ),
          ),
        ]
      : [];
    await Promise.all([
      queryClient.ensureQueryData(
        businessSummaryQuery(organization.id, trendRange(period)),
      ),
      ...cashOnHand,
    ]);
  },
  component: StartPage,
});

/** The dashboard, or for a role that may not use the admin, the way to the timeclock. */
function StartPage() {
  const { membership } = Route.useRouteContext();
  return membership && can(membership.role, "dashboard") ? (
    <Dashboard organizationId={membership.organization.id} />
  ) : (
    <UseTimeclock />
  );
}

function Dashboard({ organizationId }: { organizationId: string }) {
  const { me, membership } = Route.useRouteContext();
  const { t } = useTranslation("admin");
  const format = useDateTimeFormat();
  const search = Route.useSearch();
  const navigate = useNavigate({ from: Route.fullPath });
  const thisMonth = format.thisMonth();
  const period = dashboardMonth(search.month, thisMonth);
  const isThisMonth = compareBusinessDates(period.from, thisMonth.from) === 0;
  const { data: summary } = useSuspenseQuery(
    businessSummaryQuery(organizationId, trendRange(period)),
  );
  const month = selectedMonth(summary);

  function showMonth(next: BusinessPeriod) {
    const key = monthKey(next);
    void navigate({
      search: { month: key === monthKey(thisMonth) ? undefined : key },
      replace: true,
    });
  }

  return (
    <div className="flex flex-col gap-6">
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div>
          <h1 className="text-2xl font-semibold tracking-tight">
            {t("dashboard.hello", { name: me.firstName || me.email })}
          </h1>
          <p className="text-sm text-muted-foreground">
            {t("dashboard.description")}
          </p>
        </div>
        <div
          className="flex items-center gap-1"
          role="group"
          aria-label={t("dashboard.month")}
        >
          <Button
            variant="outline"
            size="icon"
            onClick={() => showMonth(shiftPeriod(period, -1))}
            aria-label={t("period.previousMonth", { ns: "ui" })}
          >
            <ChevronLeftIcon />
          </Button>
          <div
            className="min-w-36 text-center text-sm font-medium tabular-nums"
            aria-live="polite"
          >
            {format.period(period)}
          </div>
          <Button
            variant="outline"
            size="icon"
            onClick={() => showMonth(shiftPeriod(period, 1))}
            disabled={isThisMonth}
            aria-label={t("period.nextMonth", { ns: "ui" })}
          >
            <ChevronRightIcon />
          </Button>
        </div>
      </div>

      <MonthNotes month={month} />
      <MonthCards month={month} />
      <div className="grid gap-4 xl:grid-cols-3">
        <Trend
          summary={summary}
          selected={month.month}
          className="xl:col-span-2"
        />
        {can(membership?.role, "cashFlow") && (
          <CashOnHandCard organizationId={organizationId} />
        )}
      </div>
    </div>
  );
}

/** That the month is still running, and that amounts in other currencies are left out. */
function MonthNotes({ month }: { month: BusinessSummaryMonth }) {
  const { t } = useTranslation("admin");
  const format = useDateTimeFormat();
  const others = otherCurrencies(month);
  if (month.complete && others.length === 0) return null;

  return (
    <div className="flex flex-col gap-3">
      {!month.complete && (
        <Alert>
          <HourglassIcon />
          <AlertTitle>{t("dashboard.partial.title")}</AlertTitle>
          <AlertDescription>
            {t("dashboard.partial.description", {
              end: format.businessDate(month.lastDate),
            })}
          </AlertDescription>
        </Alert>
      )}
      {others.length > 0 && (
        <Alert>
          <InfoIcon />
          <AlertTitle>
            {t("dashboard.otherCurrencies.title", {
              currencies: others.join(", "),
            })}
          </AlertTitle>
          <AlertDescription>
            {t("dashboard.otherCurrencies.description")}
          </AlertDescription>
        </Alert>
      )}
    </div>
  );
}

function MonthCards({ month }: { month: BusinessSummaryMonth }) {
  const { t } = useTranslation("admin");
  const money = useMoneyFormat();
  const figures = primaryFigures(month);
  const payroll = payrollFigure(month.payrollStatus, figures);
  const period = monthSearch(month);

  return (
    <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
      <FigureCard
        icon={TrendingUpIcon}
        title={t("dashboard.income.title")}
        amount={money(figures.incomeNet)}
        details={[
          t("dashboard.gross", { amount: money(figures.incomeGross) }),
          t("dashboard.income.entries", { count: figures.revenueEntryCount }),
        ]}
        link={
          <Link to="/income" search={period}>
            {t("dashboard.income.open")}
            <ArrowRightIcon />
          </Link>
        }
      />
      <FigureCard
        icon={ReceiptIcon}
        title={t("dashboard.expenses.title")}
        amount={money(figures.expensesNet)}
        details={[
          t("dashboard.gross", { amount: money(figures.expensesGross) }),
          t("dashboard.expenses.invoices", { count: figures.invoiceCount }),
        ]}
        link={
          <Link to="/expenses" search={period}>
            {t("dashboard.expenses.open")}
            <ArrowRightIcon />
          </Link>
        }
      />
      <FigureCard
        icon={WalletIcon}
        title={t("dashboard.payroll.title")}
        amount={
          payroll.kind === "approved"
            ? money(payroll.amount)
            : payroll.kind === "draft"
              ? t("dashboard.payroll.estimate", {
                  amount: money(payroll.estimate),
                })
              : "—"
        }
        tone={payroll.kind === "approved" ? "default" : "muted"}
        badge={
          payroll.kind === "approved" ? (
            <Badge variant="outline">{t("dashboard.payroll.approved")}</Badge>
          ) : payroll.kind === "draft" ? (
            <Badge variant="secondary">{t("dashboard.payroll.draft")}</Badge>
          ) : (
            <Badge variant="outline">{t("dashboard.payroll.none")}</Badge>
          )
        }
        details={[
          payroll.kind === "approved"
            ? t("dashboard.payroll.approvedDetail")
            : payroll.kind === "draft"
              ? t("dashboard.payroll.draftDetail")
              : t("dashboard.payroll.noneDetail"),
        ]}
        link={
          <Link to="/payroll" search={{ month: month.month }}>
            {t("dashboard.payroll.open")}
            <ArrowRightIcon />
          </Link>
        }
      />
      <FigureCard
        icon={ScaleIcon}
        title={t("dashboard.result.title")}
        amount={money(figures.result)}
        tone={figures.result < 0 ? "negative" : "default"}
        details={[
          month.payrollStatus === "approved"
            ? t("dashboard.result.detail")
            : t("dashboard.result.detailWithoutPayroll"),
        ]}
      />
    </div>
  );
}

function FigureCard({
  icon: Icon,
  title,
  amount,
  badge,
  details,
  link,
  tone = "default",
}: {
  icon: LucideIcon;
  title: string;
  amount: string;
  badge?: ReactNode;
  details: string[];
  link?: ReactNode;
  /** Negative: a loss. Muted: not a figure that counts (a draft's estimate, or none). */
  tone?: "default" | "negative" | "muted";
}) {
  return (
    <Card className="gap-3">
      <CardHeader>
        <CardDescription className="flex items-center gap-2">
          <Icon className="size-4" />
          {title}
        </CardDescription>
        {badge && <CardAction>{badge}</CardAction>}
        <CardTitle
          className={cn(
            "text-2xl break-words tabular-nums",
            tone === "negative" && "text-destructive",
            tone === "muted" && "text-muted-foreground",
          )}
        >
          {amount}
        </CardTitle>
      </CardHeader>
      <CardContent className="flex flex-1 flex-col gap-0.5 text-sm text-muted-foreground">
        {details.map((detail) => (
          <p key={detail}>{detail}</p>
        ))}
      </CardContent>
      {link && (
        <CardFooter>
          <Button variant="link" size="sm" className="h-auto px-0" asChild>
            {link}
          </Button>
        </CardFooter>
      )}
    </Card>
  );
}

const INCOME_BAR = "bg-blue-600 dark:bg-blue-500";
const COSTS_BAR = "bg-orange-600";

/** Net income against costs (net expenses and approved payroll) per month, and the result under each. */
function Trend({
  summary,
  selected,
  className,
}: {
  summary: BusinessSummary;
  selected: string;
  className?: string;
}) {
  const { t } = useTranslation("admin");
  const bars = trendBars(summary);

  return (
    <Card className={className}>
      <CardHeader>
        <CardTitle>{t("dashboard.trend.title")}</CardTitle>
        <CardDescription>
          {t("dashboard.trend.description", { count: bars.length })}
        </CardDescription>
        <CardAction className="flex flex-col items-end gap-1 text-xs text-muted-foreground sm:flex-row sm:gap-4">
          <LegendItem
            className={INCOME_BAR}
            label={t("dashboard.trend.income")}
          />
          <LegendItem
            className={COSTS_BAR}
            label={t("dashboard.trend.costs")}
          />
        </CardAction>
      </CardHeader>
      <CardContent>
        <ol
          className="grid gap-1 sm:gap-3"
          style={{
            gridTemplateColumns: `repeat(${bars.length}, minmax(0, 1fr))`,
          }}
        >
          {bars.map((bar) => (
            <TrendColumn
              key={bar.month}
              bar={bar}
              selected={bar.month === selected}
            />
          ))}
        </ol>
        <p className="mt-4 text-xs text-muted-foreground">
          {t("dashboard.trend.footnote")}
          {bars.some((bar) => !bar.complete) &&
            ` ${t("dashboard.trend.partialFootnote")}`}
        </p>
      </CardContent>
    </Card>
  );
}

function LegendItem({
  className,
  label,
}: {
  className: string;
  label: string;
}) {
  return (
    <span className="flex items-center gap-1.5">
      <span className={cn("size-2.5 rounded-sm", className)} aria-hidden />
      {label}
    </span>
  );
}

function TrendColumn({ bar, selected }: { bar: TrendBar; selected: boolean }) {
  const { t } = useTranslation("admin");
  const format = useDateTimeFormat();
  const money = useMoneyFormat();
  const short = format.businessDate(bar.firstDate, { month: "short" });
  const monthName = format.businessDate(bar.firstDate, {
    month: "long",
    year: "numeric",
  });

  return (
    <li>
      <Tooltip>
        <TooltipTrigger asChild>
          <Link
            from={Route.fullPath}
            search={{ month: bar.month }}
            replace
            aria-current={selected ? "date" : undefined}
            aria-label={t("dashboard.trend.monthLabel", {
              month: monthName,
              income: money(bar.income),
              costs: money(bar.costs),
              result: money(bar.result),
            })}
            className={cn(
              "flex flex-col items-stretch gap-1 rounded-md p-1 outline-none hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring",
              selected && "bg-muted",
            )}
          >
            <div
              className="flex h-40 items-end justify-center gap-0.5"
              aria-hidden
            >
              <div
                className={cn("w-full max-w-5 rounded-t-sm", INCOME_BAR)}
                style={{ height: `${bar.incomeHeight}%` }}
              />
              <div
                className={cn("w-full max-w-5 rounded-t-sm", COSTS_BAR)}
                style={{ height: `${bar.costsHeight}%` }}
              />
            </div>
            <div className="border-t pt-1 text-center text-xs" aria-hidden>
              <div
                className={cn(
                  "text-muted-foreground",
                  selected && "font-medium text-foreground",
                )}
              >
                {short}
                {!bar.complete && "*"}
              </div>
              <div
                className={cn(
                  "hidden truncate tabular-nums sm:block",
                  bar.result < 0 && "text-destructive",
                )}
              >
                {money(bar.result)}
              </div>
            </div>
          </Link>
        </TooltipTrigger>
        <TooltipContent>
          <div className="flex flex-col gap-0.5">
            <div className="font-medium">
              {monthName}
              {!bar.complete && ` · ${t("dashboard.trend.partial")}`}
            </div>
            <div>
              {t("dashboard.trend.tooltipIncome", {
                amount: money(bar.income),
              })}
            </div>
            <div>
              {t("dashboard.trend.tooltipCosts", { amount: money(bar.costs) })}
            </div>
            <div>
              {t("dashboard.trend.tooltipResult", {
                amount: money(bar.result),
              })}
            </div>
            {bar.payrollPending && (
              <div>{t("dashboard.trend.payrollPending")}</div>
            )}
          </div>
        </TooltipContent>
      </Tooltip>
    </li>
  );
}
