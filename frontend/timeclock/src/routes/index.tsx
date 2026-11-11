import { signInAgainUrl } from "@business-tool/api-client"
import { useQuery } from "@tanstack/react-query"
import { createFileRoute, redirect, useRouter } from "@tanstack/react-router"
import { CircleAlertIcon, CircleCheckIcon, LoaderCircleIcon, LogOutIcon, WifiOffIcon } from "lucide-react"

import { Alert, AlertDescription } from "@business-tool/ui/components/alert"
import { Button } from "@business-tool/ui/components/button"
import { Skeleton } from "@business-tool/ui/components/skeleton"
import { OrganizationTimeZoneContext, useDateTimeFormat } from "@business-tool/ui/hooks/use-organization-time-zone"
import { browserTimeZone, type DateTimeFormat } from "@business-tool/ui/lib/date-time"

import { currentShiftQuery, meQuery, type Shift } from "@/lib/queries"
import { elapsedMs, formatClockTime, formatElapsed, formatWorked } from "@/lib/format"
import { clockErrorMessage, useClockMutation, useNow, useOnline } from "@/lib/shifts"

export const Route = createFileRoute("/")({
  beforeLoad: async ({ context, location }) => {
    const { session, queryClient } = context
    if (!session.get().token) {
      throw redirect({ to: "/login", search: { redirect: location.href } })
    }

    let me
    try {
      me = await queryClient.ensureQueryData(meQuery)
    } catch (error) {
      // The token expired: the API client signed out and cleared the cache,
      // which cancels this query, so the error may be a 401 or a cancellation.
      // Sign in again, then come back here (ADR 0016).
      if (!session.get().token) {
        throw redirect({ href: signInAgainUrl({ pathname: location.pathname, search: location.searchStr }) })
      }
      throw error
    }
    const memberships = me.memberships
    const selected = session.get().organizationId
    const membership = memberships.find((m) => m.organization.id === selected) ?? memberships[0] ?? null
    if (membership && membership.organization.id !== selected) {
      session.selectOrganization(membership.organization.id)
    }

    return { me, membership }
  },
  component: ClockScreen,
})

function ClockScreen() {
  const { me, membership, session, queryClient } = Route.useRouteContext()
  const router = useRouter()

  async function signOut() {
    session.signOut()
    queryClient.clear()
    await router.navigate({ to: "/login" })
  }

  return (
    <div className="flex min-h-svh flex-col bg-muted pt-[env(safe-area-inset-top)] pb-[env(safe-area-inset-bottom)] pl-[env(safe-area-inset-left)] pr-[env(safe-area-inset-right)]">
      <header className="flex items-center justify-between gap-2 py-2 pl-4 pr-2">
        <div className="min-w-0 text-sm">
          <div className="truncate font-medium">{membership?.organization.name ?? "No organization"}</div>
          <div className="truncate text-muted-foreground">
            {me.firstName} {me.lastName}
          </div>
        </div>
        <Button variant="ghost" size="icon" className="size-11" onClick={signOut} aria-label="Sign out">
          <LogOutIcon />
        </Button>
      </header>

      {membership ? (
        <OrganizationTimeZoneContext value={membership.organization.timezone}>
          <ClockPanel organizationId={membership.organization.id} />
        </OrganizationTimeZoneContext>
      ) : (
        <main className="flex flex-1 flex-col items-center justify-center px-4 text-center">
          <p className="max-w-xs text-muted-foreground">
            You aren't a member of any organization, so there is nothing to clock in to. Ask your manager to add you.
          </p>
        </main>
      )}
    </div>
  )
}

function ClockPanel({ organizationId }: { organizationId: string }) {
  const now = useNow()
  const online = useOnline()
  const current = useQuery(currentShiftQuery(organizationId))
  const clock = useClockMutation(organizationId)
  const format = useDateTimeFormat()

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
            <div className="text-sm font-medium text-muted-foreground">Not clocked in</div>
            <div className="text-6xl font-semibold tabular-nums tracking-tight">
              {format.time(now)}
            </div>
            <div className="text-muted-foreground">{format.date(now, TODAY)}</div>
          </>
        )}
        {format.timeZone !== browserTimeZone() && (
          <p className="text-xs text-muted-foreground">Times in {format.timeZone.replaceAll("_", " ")}</p>
        )}
      </main>

      <footer className="mx-auto flex w-full max-w-sm flex-col gap-3 px-4 pt-2 pb-4" aria-live="polite">
        {!online && (
          <Alert>
            <WifiOffIcon />
            <AlertDescription>You're offline. Clocking in and out needs a connection.</AlertDescription>
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
              Clocked out at {formatClockTime(format, endedShift.endedAt, now)}. You worked{" "}
              {formatWorked(elapsedMs(endedShift.startedAt, endedShift.endedAt))}.
            </AlertDescription>
          </Alert>
        )}

        {current.isError ? (
          <Button size="lg" variant="outline" className="h-16 text-lg" onClick={() => current.refetch()} disabled={current.isFetching}>
            {current.isFetching ? <LoaderCircleIcon className="animate-spin" /> : null}
            Try again
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
              ? lastAction === "out"
                ? "Clocking out…"
                : "Clocking in…"
              : action === "out"
                ? "Clock out"
                : "Clock in"}
          </Button>
        )}
      </footer>
    </>
  )
}

/** "Wednesday, 11 November" (the locale decides the order). */
const TODAY: Intl.DateTimeFormatOptions = { weekday: "long", day: "numeric", month: "long" }

function RunningShift({ shift, now, format }: { shift: Shift; now: Date; format: DateTimeFormat }) {
  return (
    <>
      <div className="flex items-center gap-2 text-sm font-medium">
        <span className="size-2 rounded-full bg-green-600" aria-hidden />
        On shift since {formatClockTime(format, shift.startedAt, now)}
      </div>
      <div className="text-6xl font-semibold tabular-nums tracking-tight" aria-label="Time on shift">
        {formatElapsed(elapsedMs(shift.startedAt, now))}
      </div>
      <div className="text-muted-foreground">{format.date(now, TODAY)}</div>
    </>
  )
}
