import { useSuspenseQuery } from "@tanstack/react-query"
import { Link, createFileRoute, useNavigate } from "@tanstack/react-router"
import { PlusIcon, TriangleAlertIcon } from "lucide-react"
import { useTranslation } from "react-i18next"

import { Badge } from "@business-tool/ui/components/badge"
import { Button } from "@business-tool/ui/components/button"
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@business-tool/ui/components/card"
import { PeriodPicker } from "@business-tool/ui/components/period-picker"
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@business-tool/ui/components/table"
import { useMoneyFormat } from "@business-tool/ui/hooks/use-organization-currency"
import { useDateTimeFormat } from "@business-tool/ui/hooks/use-organization-time-zone"
import { type BusinessPeriod, isWholeMonth, thisMonth } from "@business-tool/ui/lib/date-time"

import { CashFlowNav } from "@/components/cash-flow-nav"
import { requireCapability } from "@/lib/access"
import { DAY_CLOSES_MAX_DAYS, type DayClosesSearch, dayClosesTable, validateDayClosesSearch } from "@/lib/day-closes"
import { dayClosesQuery } from "@/lib/queries"

/*
 * End-of-day closes (ADR 0033 §6, §7): the closes of a period, with who closed, the register, the
 * totals per storage, the non-cash amounts and the cash income, a shortage flagged for review.
 */
export const Route = createFileRoute("/_app/cash-flow/closes/")({
  validateSearch: (search: Record<string, unknown>): DayClosesSearch => validateDayClosesSearch(search),
  beforeLoad: requireCapability("cashFlow"),
  loaderDeps: ({ search }) => ({ from: search.from, to: search.to }),
  loader: async ({ context, deps }) => {
    const { organization } = context.membership
    await context.queryClient.ensureQueryData(dayClosesQuery(organization.id, periodOf(deps, thisMonth(organization.timezone))))
  },
  component: DayClosesPage,
})

function periodOf(search: DayClosesSearch, fallback: BusinessPeriod): BusinessPeriod {
  return search.from && search.to ? { from: search.from, to: search.to } : fallback
}

function DayClosesPage() {
  const { membership } = Route.useRouteContext()
  const format = useDateTimeFormat()
  const money = useMoneyFormat()
  const search = Route.useSearch()
  const navigate = useNavigate({ from: Route.fullPath })
  const { t } = useTranslation("admin")
  const period = periodOf(search, format.thisMonth())
  const { data: closes } = useSuspenseQuery(dayClosesQuery(membership.organization.id, period))
  const table = dayClosesTable(closes)

  function setPeriod(next: BusinessPeriod) {
    // This month is the default, so it stays out of the URL.
    const current = format.thisMonth()
    const isCurrent = next.from === current.from && next.to === current.to
    void navigate({ search: { from: isCurrent ? undefined : next.from, to: isCurrent ? undefined : next.to } })
  }

  return (
    <div className="flex flex-col gap-6">
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div className="flex min-w-0 flex-col gap-3">
          <CashFlowNav />
          <div>
            <h1 className="text-2xl font-semibold tracking-tight">{t("dayCloses.title")}</h1>
            <p className="text-sm text-muted-foreground">{t("dayCloses.description")}</p>
          </div>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <PeriodPicker value={period} onChange={setPeriod} maxDays={DAY_CLOSES_MAX_DAYS} />
          <Button asChild>
            <Link to="/cash-flow/closes/new">
              <PlusIcon />
              {t("dayCloses.record")}
            </Link>
          </Button>
        </div>
      </div>

      <Card className="gap-0 pb-0">
        <CardHeader className="pb-4">
          <CardTitle>
            {t("dayCloses.list.count", {
              count: closes.length,
              context: isWholeMonth(period) ? "month" : undefined,
              period: format.period(period),
            })}
          </CardTitle>
          <CardDescription>
            {closes.length === 0
              ? t("dayCloses.list.none")
              : [
                  t("dayCloses.list.bookedCash", { amount: money(table.bookedCash) }),
                  table.shortages > 0 ? t("dayCloses.list.shortages", { count: table.shortages }) : null,
                ]
                  .filter(Boolean)
                  .join(" · ")}
          </CardDescription>
        </CardHeader>
        <CardContent className="px-0">
          {closes.length > 0 && (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead className="pl-6">{t("dayCloses.list.date")}</TableHead>
                  <TableHead>{t("dayCloses.list.register")}</TableHead>
                  <TableHead className="text-right">{t("dayCloses.cashIncome")}</TableHead>
                  {table.storages.map((storage) => (
                    <TableHead key={storage.id} className="text-right">
                      {storage.name}
                      <span className="block text-xs font-normal text-muted-foreground">{t(`dayCloses.mode.${storage.mode}`)}</span>
                    </TableHead>
                  ))}
                  {table.payments.map((method) => (
                    <TableHead key={method.id} className="text-right">
                      {method.name ?? t("dayCloses.unknownMethod")}
                    </TableHead>
                  ))}
                  <TableHead className="pr-6">{t("dayCloses.list.closedBy")}</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {table.rows.map(({ close, storageTotals, payments }) => (
                  <TableRow key={close.id}>
                    <TableCell className="pl-6">
                      <Link
                        to="/cash-flow/closes/$closeId"
                        params={{ closeId: close.id }}
                        className="font-medium tabular-nums underline-offset-4 hover:underline"
                      >
                        {format.businessDate(close.date, { weekday: "short", day: "numeric", month: "short" })}
                      </Link>
                    </TableCell>
                    <TableCell>{close.registerName ?? t("dayCloses.unknownRegister")}</TableCell>
                    <TableCell className="text-right tabular-nums">
                      {close.shortage ? (
                        <span className="inline-flex items-center gap-1.5 text-amber-700 dark:text-amber-400">
                          <TriangleAlertIcon className="size-4" aria-hidden />
                          <span className="sr-only">{t("dayCloses.shortage")}: </span>
                          {money(close.cashIncome)}
                        </span>
                      ) : (
                        money(close.cashIncome)
                      )}
                    </TableCell>
                    {storageTotals.map((total, index) => (
                      <TableCell key={table.storages[index]?.id ?? index} className="text-right tabular-nums">
                        {total === null ? "—" : money(total)}
                      </TableCell>
                    ))}
                    {payments.map((amount, index) => (
                      <TableCell key={table.payments[index]?.id ?? index} className="text-right tabular-nums">
                        {amount === null ? "—" : money(amount)}
                      </TableCell>
                    ))}
                    <TableCell className="pr-6">
                      <div className="flex flex-col">
                        <span>
                          {close.closedByFirstName} {close.closedByLastName}
                        </span>
                        <span className="text-xs text-muted-foreground">
                          {format.dateTime(close.closedAt)}
                          {close.updatedAt && (
                            <Badge variant="secondary" className="ml-1.5 align-middle">
                              {t("dayCloses.list.corrected")}
                            </Badge>
                          )}
                        </span>
                      </div>
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          )}
        </CardContent>
      </Card>
    </div>
  )
}
