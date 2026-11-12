import { useState } from "react"
import { useQuery } from "@tanstack/react-query"
import { Link, createFileRoute } from "@tanstack/react-router"
import {
  ArrowLeftIcon,
  ChevronLeftIcon,
  ChevronRightIcon,
  CircleAlertIcon,
  InboxIcon,
  LoaderCircleIcon,
  PencilIcon,
  PlusIcon,
  UserPenIcon,
} from "lucide-react"

import { Alert, AlertDescription } from "@business-tool/ui/components/alert"
import { Button } from "@business-tool/ui/components/button"
import { Skeleton } from "@business-tool/ui/components/skeleton"
import { useDateTimeFormat } from "@business-tool/ui/hooks/use-organization-time-zone"
import {
  type BusinessPeriod,
  browserTimeZone,
  formatDuration,
  minutesBetween,
  shiftPeriod,
} from "@business-tool/ui/lib/date-time"
import { type Shift, grandTotal, isOpen, totalsPerEmployee } from "@business-tool/ui/lib/shifts"
import { requestErrorMessage } from "@business-tool/ui/lib/request-error"

import {
  type MyShiftsSearch,
  monthKey,
  monthPeriodOf,
  shiftChange,
  shiftCount,
  shiftHours,
  validateMyShiftsSearch,
} from "@/lib/my-shifts"
import { type ShiftChangeRequestTarget, ShiftChangeRequestSheet } from "@/components/shift-change-request-sheet"
import { myShiftsQuery } from "@/lib/queries"
import { useNow } from "@/lib/shifts"

export const Route = createFileRoute("/_app/shifts")({
  validateSearch: (search: Record<string, unknown>): MyShiftsSearch => validateMyShiftsSearch(search),
  component: MyShiftsScreen,
})

/** "Wed 11 Nov" for a shift's business date (the locale decides the order). */
const SHIFT_DAY: Intl.DateTimeFormatOptions = { weekday: "short", day: "numeric", month: "short" }

function MyShiftsScreen() {
  const { me, membership } = Route.useRouteContext()
  const { month } = Route.useSearch()
  const navigate = Route.useNavigate()
  const format = useDateTimeFormat()
  const [requestTarget, setRequestTarget] = useState<ShiftChangeRequestTarget | null>(null)

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
        <h1 className="text-lg font-semibold">My shifts</h1>
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

      <MonthShifts
        organizationId={membership.organization.id}
        userId={me.id}
        period={period}
        onRequestNewTimes={(shift) => setRequestTarget({ kind: "new_times", shift })}
      />

      {/* People never change their shift times themselves: they ask an owner or manager (ADR 0016 §5). */}
      <div className="flex flex-col gap-2">
        <Button variant="outline" className="h-11" onClick={() => setRequestTarget({ kind: "missing" })}>
          <PlusIcon />
          Add a missing shift
        </Button>
        <Button asChild variant="ghost" className="h-11">
          <Link to="/requests">
            <InboxIcon />
            My requests
          </Link>
        </Button>
      </div>

      <ShiftChangeRequestSheet
        organizationId={membership.organization.id}
        userId={me.id}
        target={requestTarget}
        onClose={() => setRequestTarget(null)}
        onSent={() => {
          setRequestTarget(null)
          void navigate({ to: "/requests" })
        }}
      />

      {format.timeZone !== browserTimeZone() && (
        <p className="text-center text-xs text-muted-foreground">Times in {format.timeZone.replaceAll("_", " ")}</p>
      )}
    </main>
  )
}

function MonthShifts({
  organizationId,
  userId,
  period,
  onRequestNewTimes,
}: {
  organizationId: string
  userId: string
  period: BusinessPeriod
  onRequestNewTimes: (shift: Shift) => void
}) {
  const shifts = useQuery(myShiftsQuery(organizationId, userId, period))
  const format = useDateTimeFormat()

  if (shifts.isPending) {
    return (
      <div className="flex flex-col gap-3" aria-busy>
        <Skeleton className="h-28 w-full rounded-xl" />
        <Skeleton className="h-20 w-full rounded-xl" />
        <Skeleton className="h-20 w-full rounded-xl" />
      </div>
    )
  }

  if (shifts.isError) {
    return (
      <div className="flex flex-col gap-3">
        <Alert variant="destructive">
          <CircleAlertIcon />
          <AlertDescription>{requestErrorMessage(shifts.error)}</AlertDescription>
        </Alert>
        <Button variant="outline" className="h-11" onClick={() => shifts.refetch()} disabled={shifts.isFetching}>
          {shifts.isFetching && <LoaderCircleIcon className="animate-spin" />}
          Try again
        </Button>
      </div>
    )
  }

  // The API answers with this person's shifts only (userId), so the grand total is theirs.
  const total = grandTotal(totalsPerEmployee(shifts.data))
  const closed = total.shifts - total.openShifts

  return (
    <>
      <section className="rounded-xl border bg-card p-4" aria-label="Total">
        <div className="text-sm text-muted-foreground">Worked in {format.period(period)}</div>
        <div className="text-4xl font-semibold tabular-nums tracking-tight">{formatDuration(total.workedMinutes)}</div>
        <div className="text-sm text-muted-foreground">{closed === 0 ? "No finished shifts" : `in ${shiftCount(closed)}`}</div>
        {total.openShifts > 0 && (
          <p className="mt-2 text-sm">The shift you're on is added when you clock out.</p>
        )}
      </section>

      {shifts.data.length === 0 ? (
        <p className="py-6 text-center text-muted-foreground">No shifts in {format.period(period)}.</p>
      ) : (
        <ul className="flex flex-col gap-2">
          {shifts.data.map((shift) => (
            <ShiftItem key={shift.id} shift={shift} userId={userId} onRequestNewTimes={() => onRequestNewTimes(shift)} />
          ))}
        </ul>
      )}
    </>
  )
}

function ShiftItem({ shift, userId, onRequestNewTimes }: { shift: Shift; userId: string; onRequestNewTimes: () => void }) {
  const format = useDateTimeFormat()
  const change = shiftChange(format, shift, userId)
  const day = format.businessDate(shift.date, SHIFT_DAY)

  return (
    <li className="flex items-start gap-1 rounded-xl border bg-card py-3 pr-1 pl-4">
      <div className="min-w-0 flex-1">
        <div className="flex items-baseline justify-between gap-3">
          <span className="font-medium">{day}</span>
          {isOpen(shift) ? (
            <span className="flex items-center gap-1.5 text-sm font-medium">
              <span className="size-2 rounded-full bg-green-600" aria-hidden />
              On shift
            </span>
          ) : (
            <span className="font-medium tabular-nums">{formatDuration(shift.durationMinutes ?? 0)}</span>
          )}
        </div>
        <div className="text-sm tabular-nums text-muted-foreground">
          {shiftHours(format, shift)}
          {isOpen(shift) && <SoFar startedAt={shift.startedAt} />}
        </div>
        {change && (
          <div className="mt-1 flex items-center gap-1.5 text-sm text-amber-700 dark:text-amber-400">
            <UserPenIcon className="size-4 shrink-0" aria-hidden />
            {change}
          </div>
        )}
        {shift.note && <p className="mt-1 text-sm break-words text-muted-foreground">{shift.note}</p>}
      </div>
      <Button
        variant="ghost"
        size="icon"
        className="-my-2 size-11 shrink-0 text-muted-foreground"
        onClick={onRequestNewTimes}
        aria-label={`Ask for new times for ${day}`}
      >
        <PencilIcon />
      </Button>
    </li>
  )
}

/** " · 2 h 5 min so far" for the open shift; only this re-renders every second. */
function SoFar({ startedAt }: { startedAt: string }) {
  const now = useNow()
  return <> · {formatDuration(minutesBetween(startedAt, now))} so far</>
}
