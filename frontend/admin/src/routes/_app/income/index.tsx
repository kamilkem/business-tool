import { useState } from "react"
import { useSuspenseQueries, useSuspenseQuery } from "@tanstack/react-query"
import { Link, createFileRoute, useNavigate } from "@tanstack/react-router"
import { LockIcon, PlusIcon, TriangleAlertIcon } from "lucide-react"
import { useTranslation } from "react-i18next"

import { Alert, AlertAction, AlertDescription, AlertTitle } from "@business-tool/ui/components/alert"
import { Button } from "@business-tool/ui/components/button"
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@business-tool/ui/components/card"
import { PeriodPicker } from "@business-tool/ui/components/period-picker"
import {
  Table,
  TableBody,
  TableCell,
  TableFooter,
  TableHead,
  TableHeader,
  TableRow,
} from "@business-tool/ui/components/table"
import { useLocale } from "@business-tool/ui/hooks/use-locale"
import { useDateTimeFormat } from "@business-tool/ui/hooks/use-organization-time-zone"
import { type BusinessPeriod, isWholeMonth, thisMonth } from "@business-tool/ui/lib/date-time"
import { type MinorUnits, formatMoney } from "@business-tool/ui/lib/money"

import { IncomeNav } from "@/components/income-nav"
import { RevenueEntryDialog, type RevenueEntryTarget } from "@/components/revenue-entry-dialog"
import { RevenueChangeItem } from "@/components/revenue-entry-history"
import { RevenueMonthCard } from "@/components/revenue-month-card"
import { can, requireCapability } from "@/lib/access"
import {
  type IncomeGrid,
  type IncomeSearch,
  type MoneyTotal,
  REVENUE_ENTRIES_MAX_DAYS,
  type RevenueEntry,
  type RevenueSummary,
  incomeGrid,
  isInClosedMonth,
  monthsOf,
  validateIncomeSearch,
  vatDifference,
  vatRateLabel,
} from "@/lib/income"
import { fullName } from "@/lib/members"
import {
  membersQuery,
  paymentMethodsQuery,
  registersQuery,
  revenueEntriesQuery,
  revenueEntryChangesQuery,
  revenueMonthQuery,
  revenueSummaryQuery,
  vatRatesQuery,
} from "@/lib/queries"

/*
 * Income (ADR 0022): a month of daily reports, one per register and business date, with the
 * month's totals per payment method and VAT rate. An owner closes a month once it has ended, which
 * locks its entries; every write of an entry is kept in its history (ADR 0034).
 */
export const Route = createFileRoute("/_app/income/")({
  validateSearch: (search: Record<string, unknown>): IncomeSearch => validateIncomeSearch(search),
  beforeLoad: requireCapability("income"),
  loaderDeps: ({ search }) => ({ from: search.from, to: search.to }),
  loader: async ({ context, deps }) => {
    const { organization } = context.membership
    const period = periodOf(deps, thisMonth(organization.timezone))
    const { queryClient } = context
    await Promise.all([
      queryClient.ensureQueryData(revenueEntriesQuery(organization.id, period)),
      queryClient.ensureQueryData(revenueSummaryQuery(organization.id, period)),
      queryClient.ensureQueryData(registersQuery(organization.id, true)),
      queryClient.ensureQueryData(registersQuery(organization.id, false)),
      queryClient.ensureQueryData(vatRatesQuery(organization.id)),
      queryClient.ensureQueryData(paymentMethodsQuery(organization.id)),
      queryClient.ensureQueryData(membersQuery(organization.id, true)),
      queryClient.ensureQueryData(revenueEntryChangesQuery(organization.id, period)),
      ...monthsOf(period).map((month) => queryClient.ensureQueryData(revenueMonthQuery(organization.id, month))),
    ])
  },
  component: IncomePage,
})

function periodOf(search: IncomeSearch, fallback: BusinessPeriod): BusinessPeriod {
  return search.from && search.to ? { from: search.from, to: search.to } : fallback
}

/** Amounts of one or more currencies, never added together: "1 234,50 zł" or "— " when empty. */
function useMoneyTotalFormat(): (total: MoneyTotal) => string {
  const locale = useLocale()
  return (total) =>
    total.length === 0 ? "—" : total.map(({ amount, currency }) => formatMoney(amount, currency, locale)).join(" + ")
}

function IncomePage() {
  const { me, membership } = Route.useRouteContext()
  const organizationId = membership.organization.id
  const format = useDateTimeFormat()
  const search = Route.useSearch()
  const navigate = useNavigate({ from: Route.fullPath })
  const period = periodOf(search, format.thisMonth())

  const { data: entries } = useSuspenseQuery(revenueEntriesQuery(organizationId, period))
  const { data: summary } = useSuspenseQuery(revenueSummaryQuery(organizationId, period))
  const { data: registers } = useSuspenseQuery(registersQuery(organizationId, true))
  const { data: activeRegisters } = useSuspenseQuery(registersQuery(organizationId, false))
  const { data: vatRates } = useSuspenseQuery(vatRatesQuery(organizationId))
  const { data: paymentMethods } = useSuspenseQuery(paymentMethodsQuery(organizationId))
  const { data: members } = useSuspenseQuery(membersQuery(organizationId, true))
  const months = monthsOf(period)
  const revenueMonths = useSuspenseQueries({ queries: months.map((month) => revenueMonthQuery(organizationId, month)) })
  const closedMonths = new Set(revenueMonths.filter(({ data }) => data.closed).map(({ data }) => data.month))
  const [dialog, setDialog] = useState<RevenueEntryTarget | null>(null)
  const { t } = useTranslation("admin")

  const grid = incomeGrid(entries, registers, period, format.today())

  function setPeriod(next: BusinessPeriod) {
    // This month is the default, so it stays out of the URL.
    const current = format.thisMonth()
    const isCurrent = next.from === current.from && next.to === current.to
    void navigate({ search: { from: isCurrent ? undefined : next.from, to: isCurrent ? undefined : next.to } })
  }

  const nameOf = (userId: string) => {
    if (userId === me.id) return null
    const member = members.find((m) => m.userId === userId)
    return member ? fullName(member) : t("time.formerMember")
  }

  const noRegister = activeRegisters.length === 0

  return (
    <div className="flex flex-col gap-6">
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div className="flex flex-col gap-3">
          <IncomeNav />
          <div>
            <h1 className="text-2xl font-semibold tracking-tight">{t("income.title")}</h1>
            <p className="text-sm text-muted-foreground">{t("income.description")}</p>
          </div>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <PeriodPicker value={period} onChange={setPeriod} maxDays={REVENUE_ENTRIES_MAX_DAYS} />
          <Button disabled={noRegister} onClick={() => setDialog({ kind: "add" })}>
            <PlusIcon />
            {t("income.addEntry")}
          </Button>
        </div>
      </div>

      {noRegister && (
        <Alert>
          <TriangleAlertIcon />
          <AlertTitle>{t("income.noRegister.title")}</AlertTitle>
          <AlertDescription>{t("income.noRegister.description")}</AlertDescription>
          <AlertAction>
            <Button size="sm" asChild>
              <Link to="/income/registers">{t("income.noRegister.action")}</Link>
            </Button>
          </AlertAction>
        </Alert>
      )}

      {isWholeMonth(period) && months[0] && (
        <RevenueMonthCard
          organizationId={organizationId}
          month={months[0]}
          canClose={can(membership.role, "incomeClose")}
          nameOf={nameOf}
        />
      )}

      {/* No register, active or with entries in the period: the prompt above is all there is to say. */}
      {grid.columns.length > 0 && (
        <>
          <SummaryCards summary={summary} period={period} grid={grid} />
          <DaysCard grid={grid} period={period} closedMonths={closedMonths} onOpen={setDialog} />
        </>
      )}

      <ChangesCard organizationId={organizationId} period={period} nameOf={nameOf} />

      <RevenueEntryDialog
        organizationId={organizationId}
        activeRegisters={activeRegisters}
        paymentMethods={paymentMethods}
        vatRates={vatRates}
        nameOf={nameOf}
        closedMonths={closedMonths}
        target={dialog}
        onTargetChange={setDialog}
        onClose={() => setDialog(null)}
      />
    </div>
  )
}

function DaysCard({
  grid,
  period,
  closedMonths,
  onOpen,
}: {
  grid: IncomeGrid
  period: BusinessPeriod
  /** YYYY-MM: their days take no new entry. */
  closedMonths: ReadonlySet<string>
  onOpen: (target: RevenueEntryTarget) => void
}) {
  const format = useDateTimeFormat()
  const money = useMoneyTotalFormat()
  const { t } = useTranslation("admin")
  const missing = grid.rows.filter((row) => row.total.length === 0).length

  return (
    <Card className="gap-0 pb-0">
      <CardHeader className="pb-4">
        <CardTitle>{t("income.days.title")}</CardTitle>
        <CardDescription>
          {grid.rows.length === 0
            ? t("income.days.none", { context: isWholeMonth(period) ? "month" : undefined, period: format.period(period) })
            : missing === 0
              ? t("income.days.allEntered")
              : t("income.days.missing", { count: missing })}
        </CardDescription>
      </CardHeader>
      <CardContent className="px-0">
        {grid.rows.length > 0 && (
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead className="pl-6">{t("income.days.day")}</TableHead>
                {grid.columns.map((column) => (
                  <TableHead key={column.registerId} className="text-right">
                    {column.name}
                    {!column.active && <span className="font-normal text-muted-foreground"> {t("income.deactivated")}</span>}
                  </TableHead>
                ))}
                <TableHead className="pr-6 text-right">{t("income.total")}</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {grid.rows.map((row) => (
                <TableRow key={row.date} className={row.total.length === 0 ? "text-muted-foreground" : undefined}>
                  <TableCell className="pl-6 tabular-nums">
                    {format.businessDate(row.date, { weekday: "short", day: "numeric", month: "short" })}
                    {isInClosedMonth(row.date, closedMonths) && (
                      <LockIcon className="ml-1.5 inline size-3.5 text-muted-foreground" aria-label={t("income.days.locked")} />
                    )}
                  </TableCell>
                  {row.cells.map((entry, index) => {
                    const column = grid.columns[index]
                    if (!column) return null
                    return (
                      <TableCell key={column.registerId} className="py-1 text-right">
                        {entry ? (
                          <EntryCell entry={entry} onOpen={() => onOpen({ kind: "edit", entry })} />
                        ) : column.active && !isInClosedMonth(row.date, closedMonths) ? (
                          <Button
                            variant="ghost"
                            size="sm"
                            className="text-muted-foreground/60 hover:text-foreground"
                            aria-label={t("income.days.add", { register: column.name, date: format.businessDate(row.date) })}
                            onClick={() => onOpen({ kind: "add", registerId: column.registerId, businessDate: row.date })}
                          >
                            <PlusIcon />
                          </Button>
                        ) : null}
                      </TableCell>
                    )
                  })}
                  <TableCell className="pr-6 text-right font-medium tabular-nums">{money(row.total)}</TableCell>
                </TableRow>
              ))}
            </TableBody>
            <TableFooter>
              <TableRow>
                <TableCell className="pl-6">{t("income.total")}</TableCell>
                {grid.columnTotals.map((total, index) => (
                  <TableCell key={grid.columns[index]?.registerId ?? index} className="text-right tabular-nums">
                    {money(total)}
                  </TableCell>
                ))}
                <TableCell className="pr-6 text-right tabular-nums">{money(grid.total)}</TableCell>
              </TableRow>
            </TableFooter>
          </Table>
        )}
      </CardContent>
    </Card>
  )
}

function EntryCell({ entry, onOpen }: { entry: RevenueEntry; onOpen: () => void }) {
  const format = useDateTimeFormat()
  const locale = useLocale()
  const { t } = useTranslation("admin")
  const money = (minor: MinorUnits) => formatMoney(minor, entry.currency, locale)
  const difference = vatDifference(entry.paymentsTotal, entry.vatGrossTotal, entry.vatLines.length > 0)

  return (
    <Button
      variant="ghost"
      size="sm"
      className="tabular-nums"
      aria-label={t("income.days.edit", { register: entry.registerName, date: format.businessDate(entry.businessDate), amount: money(entry.paymentsTotal) })}
      onClick={onOpen}
    >
      {difference !== null && (
        <span
          className="size-1.5 rounded-full bg-amber-500"
          title={t("income.days.vatDiffers", { amount: money(Math.abs(difference)) })}
        />
      )}
      {money(entry.paymentsTotal)}
    </Button>
  )
}

function SummaryCards({
  summary,
  period,
  grid,
}: {
  summary: RevenueSummary
  period: BusinessPeriod
  grid: IncomeGrid
}) {
  const format = useDateTimeFormat()
  const locale = useLocale()
  const { t } = useTranslation("admin")
  const several = summary.currencies.length > 1
  // An empty period has one item with zeros, in the organization's currency.
  const currencies = summary.currencies.filter((item) => item.entryCount > 0 || !several)

  return (
    <>
      {currencies.map((item) => {
        const money = (minor: MinorUnits) => formatMoney(minor, item.currency, locale)
        const difference = vatDifference(item.total, item.vatGrossTotal, item.vatLines.length > 0)
        return (
          <div key={item.currency} className="grid gap-4 xl:grid-cols-2">
            <Card className="gap-0 pb-0">
              <CardHeader className="pb-4">
                <CardTitle>
                  {several ? t("income.summary.paymentsIn", { currency: item.currency }) : t("income.summary.payments")}:{" "}
                  <span className="tabular-nums">{money(item.total)}</span>
                </CardTitle>
                <CardDescription>
                  {t("income.summary.entries", {
                    count: item.entryCount,
                    context: isWholeMonth(period) ? "month" : undefined,
                    period: format.period(period),
                  })}
                  {grid.rows.length > 0 &&
                    ` ${t("income.summary.daysEntered", { entered: summary.daysWithEntries, count: grid.rows.length })}`}
                </CardDescription>
              </CardHeader>
              <CardContent className="px-0">
                {item.payments.length === 0 ? (
                  <p className="border-t px-6 py-6 text-sm text-muted-foreground">{t("income.summary.nothing")}</p>
                ) : (
                  <Table>
                    <TableHeader>
                      <TableRow>
                        <TableHead className="pl-6">{t("income.summary.method")}</TableHead>
                        <TableHead className="pr-6 text-right">{t("income.summary.amount")}</TableHead>
                      </TableRow>
                    </TableHeader>
                    <TableBody>
                      {item.payments.map((line) => (
                        <TableRow key={line.paymentMethodId}>
                          <TableCell className="pl-6">{line.paymentMethodName}</TableCell>
                          <TableCell className="pr-6 text-right tabular-nums">{money(line.amount)}</TableCell>
                        </TableRow>
                      ))}
                    </TableBody>
                  </Table>
                )}
              </CardContent>
            </Card>

            <Card className="gap-0 pb-0">
              <CardHeader className="pb-4">
                <CardTitle>{t("income.vat.title")}</CardTitle>
                <CardDescription>
                  {item.vatLines.length === 0
                    ? t("income.vat.none")
                    : t("income.vat.description", { vat: money(item.vatTotal), gross: money(item.vatGrossTotal) })}
                </CardDescription>
              </CardHeader>
              <CardContent className="px-0">
                {item.vatLines.length > 0 && (
                  <Table>
                    <TableHeader>
                      <TableRow>
                        <TableHead className="pl-6">{t("income.vat.rate")}</TableHead>
                        <TableHead className="text-right">{t("income.vat.gross")}</TableHead>
                        <TableHead className="text-right">{t("income.vat.vat")}</TableHead>
                        <TableHead className="pr-6 text-right">{t("income.vat.net")}</TableHead>
                      </TableRow>
                    </TableHeader>
                    <TableBody>
                      {item.vatLines.map((line) => (
                        <TableRow key={line.rate}>
                          <TableCell className="pl-6">{vatRateLabel(line.rate)}</TableCell>
                          <TableCell className="text-right tabular-nums">{money(line.gross)}</TableCell>
                          <TableCell className="text-right tabular-nums">{money(line.vat)}</TableCell>
                          <TableCell className="pr-6 text-right tabular-nums">{money(line.net)}</TableCell>
                        </TableRow>
                      ))}
                    </TableBody>
                  </Table>
                )}
                {difference !== null && (
                  <p className="flex items-start gap-2 border-t px-6 py-3 text-sm text-muted-foreground">
                    <TriangleAlertIcon className="mt-0.5 size-4 shrink-0 text-amber-500" />
                    {t(difference > 0 ? "income.vat.grossMore" : "income.vat.grossLess", { amount: money(Math.abs(difference)) })}
                  </p>
                )}
              </CardContent>
            </Card>
          </div>
        )
      })}
    </>
  )
}

/** How many changes the card shows before "Show all". */
const CHANGES_SHOWN = 10

/** The change history of the entries dated in the period, newest first, deleted ones included (ADR 0034 §4). */
function ChangesCard({
  organizationId,
  period,
  nameOf,
}: {
  organizationId: string
  period: BusinessPeriod
  nameOf: (userId: string) => string | null
}) {
  const { t } = useTranslation("admin")
  const format = useDateTimeFormat()
  const { data: changes } = useSuspenseQuery(revenueEntryChangesQuery(organizationId, period))
  const [all, setAll] = useState(false)
  const shown = all ? changes : changes.slice(0, CHANGES_SHOWN)

  return (
    <Card>
      <CardHeader>
        <CardTitle>{t("income.changes.title")}</CardTitle>
        <CardDescription>
          {changes.length === 0
            ? t("income.changes.none", { context: isWholeMonth(period) ? "month" : undefined, period: format.period(period) })
            : t("income.changes.description", { count: changes.length })}
        </CardDescription>
      </CardHeader>
      {changes.length > 0 && (
        <CardContent className="flex flex-col gap-3">
          <ol className="flex flex-col gap-3">
            {shown.map((change) => (
              <RevenueChangeItem key={change.id} change={change} nameOf={nameOf} showEntry />
            ))}
          </ol>
          {changes.length > CHANGES_SHOWN && (
            <Button variant="outline" className="self-start" onClick={() => setAll((value) => !value)}>
              {all ? t("income.changes.showFewer") : t("income.changes.showAll", { count: changes.length })}
            </Button>
          )}
        </CardContent>
      )}
    </Card>
  )
}
