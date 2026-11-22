import { useQuery } from "@tanstack/react-query"
import { Link, createFileRoute } from "@tanstack/react-router"
import { CalendarDaysIcon, CircleAlertIcon, CircleCheckIcon, LoaderCircleIcon, LockKeyholeIcon, WalletIcon, WifiOffIcon } from "lucide-react"
import { useTranslation } from "react-i18next"

import { Alert, AlertDescription } from "@business-tool/ui/components/alert"
import { Button } from "@business-tool/ui/components/button"
import { Skeleton } from "@business-tool/ui/components/skeleton"
import { useDateTimeFormat } from "@business-tool/ui/hooks/use-organization-time-zone"
import { browserTimeZone, type DateTimeFormat } from "@business-tool/ui/lib/date-time"

import { currentShiftQuery, dayCloseFormQuery, type Shift } from "@/lib/queries"
import { elapsedMs, formatClockTime, formatElapsed, formatWorked } from "@/lib/format"
import { clockErrorMessage, useClockMutation, useNow, useOnline } from "@/lib/shifts"

export const Route = createFileRoute("/_app/")({
  component: ClockScreen,
})

function ClockScreen() {
  const { membership } = Route.useRouteContext()
  // The layout shows "no organization" instead of this screen.
  if (!membership) return null
  return <ClockPanel organizationId={membership.organization.id} />
}

function ClockPanel({ organizationId }: { organizationId: string }) {
  const { t } = useTranslation("timeclock")
  const now = useNow()
  const online = useOnline()
  const current = useQuery(currentShiftQuery(organizationId))
  const clock = useClockMutation(organizationId)
  const format = useDateTimeFormat()
  // The close is offered only while there is something to count (#187).
  const canCloseDay = useQuery(dayCloseFormQuery(organizationId)).data?.canClose === true

  const shift = current.data
  const action = shift ? "out" : "in"
  const lastAction = clock.variables
  const endedShift = clock.isSuccess && lastAction === "out" ? clock.data : null

  return (
    <>
      <main className="flex flex-1 flex-col items-center justify-center gap-2 px-4 text-center">
        {current.isPending ? (
          <Skeleton className="h-24 w-56" />
        ) : shift ? (
          <RunningShift shift={shift} now={now} format={format} />
        ) : (
          <>
            <div className="text-sm font-medium text-muted-foreground">{t("clock.notClockedIn")}</div>
            <div className="text-6xl font-semibold tabular-nums tracking-tight">
              {format.time(now)}
            </div>
            <div className="text-muted-foreground">{format.date(now, TODAY)}</div>
          </>
        )}
        {format.timeZone !== browserTimeZone() && (
          <p className="text-xs text-muted-foreground">{t("common.timesIn", { timeZone: format.timeZone.replaceAll("_", " ") })}</p>
        )}
      </main>

      <footer className="mx-auto flex w-full max-w-sm flex-col gap-3 px-4 pt-2 pb-4" aria-live="polite">
        {!online && (
          <Alert>
            <WifiOffIcon />
            <AlertDescription>{t("clock.offline")}</AlertDescription>
          </Alert>
        )}
        {current.isError && (
          <Alert variant="destructive">
            <CircleAlertIcon />
            <AlertDescription>{clockErrorMessage(current.error, "in")}</AlertDescription>
          </Alert>
        )}
        {clock.isError && lastAction && (
          <Alert variant="destructive">
            <CircleAlertIcon />
            <AlertDescription>{clockErrorMessage(clock.error, lastAction)}</AlertDescription>
          </Alert>
        )}
        {endedShift && endedShift.endedAt && (
          <Alert>
            <CircleCheckIcon />
            <AlertDescription>
              {t("clock.clockedOut", {
                time: formatClockTime(format, endedShift.endedAt, now),
                duration: formatWorked(elapsedMs(endedShift.startedAt, endedShift.endedAt)),
              })}
            </AlertDescription>
          </Alert>
        )}
        {endedShift && canCloseDay && (
          <Button asChild size="lg" variant="outline" className="h-12">
            <Link to="/close-day">
              <LockKeyholeIcon />
              {t("clock.closeDay")}
            </Link>
          </Button>
        )}

        {current.isError ? (
          <Button size="lg" variant="outline" className="h-16 text-lg" onClick={() => current.refetch()} disabled={current.isFetching}>
            {current.isFetching ? <LoaderCircleIcon className="animate-spin" /> : null}
            {t("common.tryAgain")}
          </Button>
        ) : (
          <Button
            size="lg"
            variant={action === "out" ? "destructive" : "default"}
            className="h-16 text-lg"
            disabled={current.isPending || clock.isPending || !online}
            onClick={() => clock.submit(action)}
          >
            {clock.isPending && <LoaderCircleIcon className="animate-spin" />}
            {clock.isPending
              ? t(lastAction === "out" ? "clock.clockingOut" : "clock.clockingIn")
              : t(action === "out" ? "clock.clockOut" : "clock.clockIn")}
          </Button>
        )}
        <div className="grid grid-cols-2 gap-2">
          <Button asChild variant="ghost" className="h-11">
            <Link to="/shifts">
              <CalendarDaysIcon />
              {t("clock.myShifts")}
            </Link>
          </Button>
          <Button asChild variant="ghost" className="h-11">
            <Link to="/pay">
              <WalletIcon />
              {t("clock.myPay")}
            </Link>
          </Button>
          {canCloseDay && !endedShift && (
            <Button asChild variant="ghost" className="col-span-2 h-11">
              <Link to="/close-day">
                <LockKeyholeIcon />
                {t("clock.closeDay")}
              </Link>
            </Button>
          )}
        </div>
      </footer>
    </>
  )
}

/** "Wednesday, 11 November" (the locale decides the order). */
const TODAY: Intl.DateTimeFormatOptions = { weekday: "long", day: "numeric", month: "long" }

function RunningShift({ shift, now, format }: { shift: Shift; now: Date; format: DateTimeFormat }) {
  const { t } = useTranslation("timeclock")
  return (
    <>
      <div className="flex items-center gap-2 text-sm font-medium">
        <span className="size-2 rounded-full bg-green-600" aria-hidden />
        {t("clock.onShiftSince", { time: formatClockTime(format, shift.startedAt, now) })}
      </div>
      <div className="text-6xl font-semibold tabular-nums tracking-tight" aria-label={t("clock.timeOnShift")}>
        {formatElapsed(elapsedMs(shift.startedAt, now))}
      </div>
      <div className="text-muted-foreground">{format.date(now, TODAY)}</div>
    </>
  )
}
