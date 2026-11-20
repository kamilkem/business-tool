import { useQuery } from "@tanstack/react-query"
import { Link, createFileRoute } from "@tanstack/react-router"
import {
  ArrowLeftIcon,
  CalendarDaysIcon,
  ChevronLeftIcon,
  ChevronRightIcon,
  CircleAlertIcon,
  LoaderCircleIcon,
  TriangleAlertIcon,
} from "lucide-react"

import { Alert, AlertDescription } from "@business-tool/ui/components/alert"
import { Badge } from "@business-tool/ui/components/badge"
import { Button } from "@business-tool/ui/components/button"
import { Skeleton } from "@business-tool/ui/components/skeleton"
import { useLocale } from "@business-tool/ui/hooks/use-locale"
import { useDateTimeFormat } from "@business-tool/ui/hooks/use-organization-time-zone"
import { type BusinessPeriod, formatDuration, shiftPeriod } from "@business-tool/ui/lib/date-time"
import { formatMoney } from "@business-tool/ui/lib/money"
import { requestErrorMessage } from "@business-tool/ui/lib/request-error"

import { hoursDetail, minutesOf, noRateNotice, payExplanation, payHeading } from "@/lib/my-pay"
import { type MyShiftsSearch, monthKey, monthPeriodOf, validateMyShiftsSearch } from "@/lib/my-shifts"
import { type MyPay, myPayQuery } from "@/lib/queries"

export const Route = createFileRoute("/_app/pay")({
  validateSearch: (search: Record<string, unknown>): MyShiftsSearch => validateMyShiftsSearch(search),
  component: MyPayScreen,
})

function MyPayScreen() {
  const { me, membership } = Route.useRouteContext()
  const { month } = Route.useSearch()
  const navigate = Route.useNavigate()
  const format = useDateTimeFormat()

  // The layout shows "no organization" instead of this screen.
  if (!membership) return null

  const thisMonth = format.thisMonth()
  const period = monthPeriodOf(month, thisMonth)
  const isThisMonth = period.from === thisMonth.from

  function showMonth(next: BusinessPeriod) {
    // This month is the default and stays out of the URL.
    void navigate({ search: { month: next.from === thisMonth.from ? undefined : monthKey(next) } })
  }

  return (
    <main className="mx-auto flex w-full max-w-sm flex-1 flex-col gap-3 px-4 pb-4">
      <div className="flex items-center gap-1">
        <Button asChild variant="ghost" size="icon" className="-ml-2 size-11">
          <Link to="/" aria-label="Back to the clock">
            <ArrowLeftIcon />
          </Link>
        </Button>
        <h1 className="text-lg font-semibold">My pay</h1>
      </div>

      <div className="flex items-center gap-2" role="group" aria-label="Month">
        <Button variant="outline" size="icon" className="size-11" onClick={() => showMonth(shiftPeriod(period, -1))} aria-label="Previous month">
          <ChevronLeftIcon />
        </Button>
        <div className="flex-1 text-center font-medium" aria-live="polite">
          {format.period(period)}
        </div>
        <Button
          variant="outline"
          size="icon"
          className="size-11"
          onClick={() => showMonth(shiftPeriod(period, 1))}
          disabled={isThisMonth}
          aria-label="Next month"
        >
          <ChevronRightIcon />
        </Button>
      </div>

      <MonthPay organizationId={membership.organization.id} userId={me.id} period={period} />

      <Button asChild variant="ghost" className="h-11">
        <Link to="/shifts" search={{ month: isThisMonth ? undefined : monthKey(period) }}>
          <CalendarDaysIcon />
          My shifts in {format.period(period)}
        </Link>
      </Button>
    </main>
  )
}

function MonthPay({ organizationId, userId, period }: { organizationId: string; userId: string; period: BusinessPeriod }) {
  const pay = useQuery(myPayQuery(organizationId, userId, monthKey(period)))

  if (pay.isPending) {
    return (
      <div className="flex flex-col gap-3" aria-busy>
        <Skeleton className="h-36 w-full rounded-xl" />
        <Skeleton className="h-24 w-full rounded-xl" />
      </div>
    )
  }

  if (pay.isError) {
    return (
      <div className="flex flex-col gap-3">
        <Alert variant="destructive">
          <CircleAlertIcon />
          <AlertDescription>{requestErrorMessage(pay.error)}</AlertDescription>
        </Alert>
        <Button variant="outline" className="h-11" onClick={() => pay.refetch()} disabled={pay.isFetching}>
          {pay.isFetching && <LoaderCircleIcon className="animate-spin" />}
          Try again
        </Button>
      </div>
    )
  }

  return <PayDetails pay={pay.data} />
}

function PayDetails({ pay }: { pay: MyPay }) {
  const format = useDateTimeFormat()
  const locale = useLocale()
  // The approved run's currency, or the organization's for an estimate: the API says which.
  const money = (minor: number) => formatMoney(minor, pay.currency, locale)
  const notice = noRateNotice(pay)
  const approved = pay.status === "approved"

  return (
    <>
      <section className="rounded-xl border bg-card p-4" aria-label={payHeading(pay)}>
        <div className="flex items-center justify-between gap-2">
          <span className="text-sm text-muted-foreground">{payHeading(pay)}</span>
          <Badge variant={approved ? "default" : "outline"}>{approved ? "Approved" : "Estimate"}</Badge>
        </div>
        <div className="text-4xl font-semibold tabular-nums tracking-tight">
          {approved ? "" : "≈ "}
          {money(pay.total)}
        </div>
        <p className="mt-1 text-sm text-muted-foreground">{payExplanation(pay)}</p>
        {approved && pay.approvedAt && (
          <p className="mt-1 text-sm text-muted-foreground">Approved on {format.date(pay.approvedAt)}</p>
        )}
      </section>

      {notice && (
        <Alert className="border-amber-300 bg-amber-50 text-amber-900 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-200">
          <TriangleAlertIcon />
          <AlertDescription className="text-inherit">{notice}</AlertDescription>
        </Alert>
      )}

      <section className="rounded-xl border bg-card p-4" aria-label="Hours">
        <div className="text-sm text-muted-foreground">Worked</div>
        <div className="text-2xl font-semibold tabular-nums">{formatDuration(minutesOf(pay.seconds))}</div>
        <div className="text-sm text-muted-foreground">{hoursDetail(pay)}</div>
        {pay.openShifts > 0 && <p className="mt-2 text-sm">The shift you're on is added when you clock out.</p>}
      </section>

      {approved && (
        <section className="rounded-xl border bg-card p-4" aria-label="Breakdown">
          <dl className="flex flex-col gap-2 text-sm">
            <div className="flex justify-between gap-3">
              <dt>Pay for hours</dt>
              <dd className="tabular-nums">{money(pay.payForHours)}</dd>
            </div>
            {pay.adjustments.map((adjustment) => (
              <div key={adjustment.id} className="flex justify-between gap-3">
                <dt className="min-w-0 break-words">{adjustment.label}</dt>
                <dd className="shrink-0 tabular-nums">{money(adjustment.amount)}</dd>
              </div>
            ))}
            <div className="flex justify-between gap-3 border-t pt-2 font-medium">
              <dt>Total</dt>
              <dd className="tabular-nums">{money(pay.total)}</dd>
            </div>
          </dl>
        </section>
      )}
    </>
  )
}
