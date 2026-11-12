import { useState } from "react"
import { useSuspenseQuery } from "@tanstack/react-query"
import { Link, createFileRoute, useNavigate } from "@tanstack/react-router"
import { PlusIcon, TriangleAlertIcon } from "lucide-react"

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
import { requireCapability } from "@/lib/access"
import {
  type IncomeGrid,
  type IncomeSearch,
  type MoneyTotal,
  PAYMENT_METHOD_LABELS,
  REVENUE_ENTRIES_MAX_DAYS,
  type RevenueEntry,
  type RevenueSummary,
  incomeGrid,
  validateIncomeSearch,
  vatDifference,
  vatRateLabel,
} from "@/lib/income"
import { fullName } from "@/lib/members"
import { membersQuery, registersQuery, revenueEntriesQuery, revenueSummaryQuery, vatRatesQuery } from "@/lib/queries"

/*
 * Income (ADR 0022): a month of daily reports, one per register and business date, with the
 * month's totals per payment method and VAT rate.
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
      queryClient.ensureQueryData(membersQuery(organization.id, true)),
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
  const { data: members } = useSuspenseQuery(membersQuery(organizationId, true))
  const [dialog, setDialog] = useState<RevenueEntryTarget | null>(null)

  const grid = incomeGrid(entries, registers, period, format.today())

  function setPeriod(next: BusinessPeriod) {
    // This month is the default, so it stays out of the URL.
    const current = format.thisMonth()
    const isCurrent = next.from === current.from && next.to === current.to
    void navigate({ search: { from: isCurrent ? undefined : next.from, to: isCurrent ? undefined : next.to } })
  }

  const nameOf = (userId: string) => {
    if (userId === me.id) return "you"
    const member = members.find((m) => m.userId === userId)
    return member ? fullName(member) : "a former member"
  }

  const noRegister = activeRegisters.length === 0

  return (
    <div className="mx-auto flex max-w-5xl flex-col gap-6">
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div className="flex flex-col gap-3">
          <IncomeNav />
          <div>
            <h1 className="text-2xl font-semibold tracking-tight">Income</h1>
            <p className="text-sm text-muted-foreground">
              Each register's daily report, by the day it covers. Click an amount to correct it.
            </p>
          </div>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <PeriodPicker value={period} onChange={setPeriod} maxDays={REVENUE_ENTRIES_MAX_DAYS} />
          <Button disabled={noRegister} onClick={() => setDialog({ kind: "add" })}>
            <PlusIcon />
            Add entry
          </Button>
        </div>
      </div>

      {noRegister && (
        <Alert>
          <TriangleAlertIcon />
          <AlertTitle>Add a register first</AlertTitle>
          <AlertDescription>
            Income is entered per register, one daily report each. Add the tills with their own fiscal printer, then
            come back to enter what they took.
          </AlertDescription>
          <AlertAction>
            <Button size="sm" asChild>
              <Link to="/income/registers">Add a register</Link>
            </Button>
          </AlertAction>
        </Alert>
      )}

      {/* No register, active or with entries in the period: the prompt above is all there is to say. */}
      {grid.columns.length > 0 && (
        <>
          <SummaryCards summary={summary} period={period} grid={grid} />
          <DaysCard grid={grid} period={period} onOpen={setDialog} />
        </>
      )}

      <RevenueEntryDialog
        organizationId={organizationId}
        activeRegisters={activeRegisters}
        vatRates={vatRates}
        nameOf={nameOf}
        target={dialog}
        onTargetChange={setDialog}
        onClose={() => setDialog(null)}
      />
    </div>
  )
}

function periodName(period: BusinessPeriod, formatted: string): string {
  return isWholeMonth(period) ? `in ${formatted}` : `for ${formatted}`
}

function DaysCard({
  grid,
  period,
  onOpen,
}: {
  grid: IncomeGrid
  period: BusinessPeriod
  onOpen: (target: RevenueEntryTarget) => void
}) {
  const format = useDateTimeFormat()
  const money = useMoneyTotalFormat()
  const missing = grid.rows.filter((row) => row.total.length === 0).length

  return (
    <Card className="gap-0 pb-0">
      <CardHeader className="pb-4">
        <CardTitle>Days</CardTitle>
        <CardDescription>
          {grid.rows.length === 0
            ? `No days to enter ${periodName(period, format.period(period))} yet.`
            : missing === 0
              ? "Every day so far has at least one entry."
              : `${missing} ${missing === 1 ? "day has" : "days have"} no entry yet. An empty cell adds that register's day.`}
        </CardDescription>
      </CardHeader>
      <CardContent className="px-0">
        {grid.rows.length > 0 && (
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead className="pl-6">Day</TableHead>
                {grid.columns.map((column) => (
                  <TableHead key={column.registerId} className="text-right">
                    {column.name}
                    {!column.active && <span className="font-normal text-muted-foreground"> (deactivated)</span>}
                  </TableHead>
                ))}
                <TableHead className="pr-6 text-right">Total</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {grid.rows.map((row) => (
                <TableRow key={row.date} className={row.total.length === 0 ? "text-muted-foreground" : undefined}>
                  <TableCell className="pl-6 tabular-nums">
                    {format.businessDate(row.date, { weekday: "short", day: "numeric", month: "short" })}
                  </TableCell>
                  {row.cells.map((entry, index) => {
                    const column = grid.columns[index]
                    if (!column) return null
                    return (
                      <TableCell key={column.registerId} className="py-1 text-right">
                        {entry ? (
                          <EntryCell entry={entry} onOpen={() => onOpen({ kind: "edit", entry })} />
                        ) : column.active ? (
                          <Button
                            variant="ghost"
                            size="sm"
                            className="text-muted-foreground/60 hover:text-foreground"
                            aria-label={`Add ${column.name}, ${format.businessDate(row.date)}`}
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
                <TableCell className="pl-6">Total</TableCell>
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
  const money = (minor: MinorUnits) => formatMoney(minor, entry.currency, locale)
  const difference = vatDifference(entry.paymentsTotal, entry.vatGrossTotal, entry.vatLines.length > 0)

  return (
    <Button
      variant="ghost"
      size="sm"
      className="tabular-nums"
      aria-label={`${entry.registerName}, ${format.businessDate(entry.businessDate)}: ${money(entry.paymentsTotal)}. Edit`}
      onClick={onOpen}
    >
      {difference !== null && (
        <span
          className="size-1.5 rounded-full bg-amber-500"
          title={`The VAT breakdown differs from the payments by ${money(Math.abs(difference))}`}
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
                  Payments{several && ` in ${item.currency}`}: <span className="tabular-nums">{money(item.total)}</span>
                </CardTitle>
                <CardDescription>
                  {item.entryCount} {item.entryCount === 1 ? "entry" : "entries"}{" "}
                  {periodName(period, format.period(period))}
                  {grid.rows.length > 0 && `, ${summary.daysWithEntries} of ${grid.rows.length} days entered`}.
                </CardDescription>
              </CardHeader>
              <CardContent className="px-0">
                {item.payments.length === 0 ? (
                  <p className="border-t px-6 py-6 text-sm text-muted-foreground">Nothing entered yet.</p>
                ) : (
                  <Table>
                    <TableHeader>
                      <TableRow>
                        <TableHead className="pl-6">Method</TableHead>
                        <TableHead className="pr-6 text-right">Amount</TableHead>
                      </TableRow>
                    </TableHeader>
                    <TableBody>
                      {item.payments.map((line) => (
                        <TableRow key={line.method}>
                          <TableCell className="pl-6">{PAYMENT_METHOD_LABELS[line.method]}</TableCell>
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
                <CardTitle>VAT</CardTitle>
                <CardDescription>
                  {item.vatLines.length === 0
                    ? "No entry has a VAT breakdown."
                    : `VAT ${money(item.vatTotal)} in a gross ${money(item.vatGrossTotal)}, from the daily reports.`}
                </CardDescription>
              </CardHeader>
              <CardContent className="px-0">
                {item.vatLines.length > 0 && (
                  <Table>
                    <TableHeader>
                      <TableRow>
                        <TableHead className="pl-6">Rate</TableHead>
                        <TableHead className="text-right">Gross</TableHead>
                        <TableHead className="text-right">VAT</TableHead>
                        <TableHead className="pr-6 text-right">Net</TableHead>
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
                    The VAT gross is {money(Math.abs(difference))} {difference > 0 ? "more" : "less"} than the payments.
                    Allowed: e.g. invoiced transfers aren't on the fiscal reports.
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
