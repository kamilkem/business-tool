import { useEffect, useState } from "react"
import { useSuspenseQuery } from "@tanstack/react-query"
import { createFileRoute, useNavigate } from "@tanstack/react-router"
import { ClockIcon, PencilIcon, PlusIcon } from "lucide-react"
import { useTranslation } from "react-i18next"

import { Badge } from "@business-tool/ui/components/badge"
import { Button } from "@business-tool/ui/components/button"
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@business-tool/ui/components/card"
import { PeriodPicker } from "@business-tool/ui/components/period-picker"
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@business-tool/ui/components/select"
import {
  Table,
  TableBody,
  TableCell,
  TableFooter,
  TableHead,
  TableHeader,
  TableRow,
} from "@business-tool/ui/components/table"
import { useDateTimeFormat } from "@business-tool/ui/hooks/use-organization-time-zone"
import {
  type BusinessPeriod,
  formatDuration,
  isWholeMonth,
  minutesBetween,
  thisMonth,
} from "@business-tool/ui/lib/date-time"
import {
  type EmployeeTotal,
  SHIFTS_MAX_DAYS,
  type Shift,
  grandTotal,
  isOpen,
  totalsPerEmployee,
} from "@business-tool/ui/lib/shifts"

import { ShiftDialog, type ShiftDialogTarget } from "@/components/shift-dialog"
import { requireCapability } from "@/lib/access"
import { type Member, fullName, isActive } from "@/lib/members"
import { membersQuery, shiftsQuery } from "@/lib/queries"
import { type ShiftsSearch, periodOf, validateShiftsSearch } from "@/lib/shifts"

const EVERYONE = "everyone"

export const Route = createFileRoute("/_app/time")({
  validateSearch: (search: Record<string, unknown>): ShiftsSearch => validateShiftsSearch(search),
  beforeLoad: requireCapability("time"),
  loaderDeps: ({ search }) => ({ from: search.from, to: search.to, userId: search.userId }),
  loader: async ({ context, deps }) => {
    const membership = context.membership
    const organizationId = membership.organization.id
    const members = await context.queryClient.ensureQueryData(membersQuery(organizationId, true))
    const period = periodOf(deps, thisMonth(membership.organization.timezone))
    await context.queryClient.ensureQueryData(shiftsQuery(organizationId, period, knownUserId(deps.userId, members)))
  },
  component: TimePage,
})

/** A person picked in the filter, unless the link names someone who is not a member (the API's 404). */
function knownUserId(userId: string | undefined, members: Member[]): string | undefined {
  return members.some((m) => m.userId === userId) ? userId : undefined
}

function TimePage() {
  const { membership } = Route.useRouteContext()

  return <Shifts organizationId={membership.organization.id} />
}

/** Who entered or last corrected a shift: a name, or null for the signed-in user. */
type NameOf = (userId: string) => string | null

function Shifts({ organizationId }: { organizationId: string }) {
  const { me } = Route.useRouteContext()
  const format = useDateTimeFormat()
  const search = Route.useSearch()
  const navigate = useNavigate({ from: Route.fullPath })
  const { data: members } = useSuspenseQuery(membersQuery(organizationId, true))
  const period = periodOf(search, format.thisMonth())
  const userId = knownUserId(search.userId, members)
  const { data: shifts } = useSuspenseQuery(shiftsQuery(organizationId, period, userId))
  const [dialog, setDialog] = useState<ShiftDialogTarget | null>(null)
  const { t } = useTranslation("admin")

  const totals = totalsPerEmployee(shifts)
  // In the order of the totals: last name, then first name.
  const people = [...members].sort(
    (a, b) => a.lastName.localeCompare(b.lastName) || a.firstName.localeCompare(b.firstName),
  )

  function setPeriod(next: BusinessPeriod) {
    // This month is the default, so it stays out of the URL.
    const current = format.thisMonth()
    const isCurrent = next.from === current.from && next.to === current.to
    void navigate({ search: (prev) => ({ ...prev, from: isCurrent ? undefined : next.from, to: isCurrent ? undefined : next.to }) })
  }

  function setUserId(next: string | undefined) {
    void navigate({ search: (prev) => ({ ...prev, userId: next }) })
  }

  const nameOf: NameOf = (id) => {
    if (id === me.id) return null
    const member = members.find((m) => m.userId === id)
    return member ? fullName(member) : t("time.formerMember")
  }

  return (
    <div className="mx-auto flex max-w-5xl flex-col gap-6">
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div>
          <h1 className="text-2xl font-semibold tracking-tight">{t("time.title")}</h1>
          <p className="text-sm text-muted-foreground">
            {t("time.description", { timeZone: format.timeZone.replaceAll("_", " ") })}
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <Select value={userId ?? EVERYONE} onValueChange={(value) => setUserId(value === EVERYONE ? undefined : value)}>
            <SelectTrigger aria-label={t("time.employee")} className="w-48">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value={EVERYONE}>{t("time.everyone")}</SelectItem>
              {people.map((member) => (
                <SelectItem key={member.userId} value={member.userId}>
                  {fullName(member)}
                  {!isActive(member) && <span className="text-muted-foreground"> {t("time.deactivated")}</span>}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
          <PeriodPicker value={period} onChange={setPeriod} maxDays={SHIFTS_MAX_DAYS} />
          <Button onClick={() => setDialog({ kind: "add", userId })}>
            <PlusIcon />
            {t("time.addShift")}
          </Button>
        </div>
      </div>

      <TotalsCard totals={totals} period={period} onSelect={userId ? undefined : setUserId} />
      <ShiftsCard shifts={shifts} period={period} nameOf={nameOf} onEdit={(shift) => setDialog({ kind: "edit", shift })} />
      <ShiftDialog
        organizationId={organizationId}
        actorId={me.id}
        members={members}
        target={dialog}
        onClose={() => setDialog(null)}
      />
    </div>
  )
}

function TotalsCard({
  totals,
  period,
  onSelect,
}: {
  totals: EmployeeTotal[]
  period: BusinessPeriod
  /** Narrows the page to one person; absent when it already is. */
  onSelect?: (userId: string) => void
}) {
  const format = useDateTimeFormat()
  const { t } = useTranslation("admin")
  const sum = grandTotal(totals)
  const inPeriod = { context: isWholeMonth(period) ? "month" : undefined, period: format.period(period) }

  return (
    <Card className="gap-0 pb-0">
      <CardHeader className="pb-4">
        <CardTitle>{t("time.totals.title")}</CardTitle>
        <CardDescription>{t("time.totals.description", inPeriod)}</CardDescription>
      </CardHeader>
      <CardContent className="px-0">
        {totals.length === 0 ? (
          <p className="border-t px-6 py-6 text-sm text-muted-foreground">{t("time.totals.none")}</p>
        ) : (
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead className="pl-6">{t("time.employee")}</TableHead>
                <TableHead className="text-right">{t("time.totals.shifts")}</TableHead>
                <TableHead className="pr-6 text-right">{t("time.totals.worked")}</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {totals.map((total) => (
                <TableRow key={total.userId}>
                  <TableCell className="pl-6 font-medium">
                    {onSelect ? (
                      <Button variant="link" className="h-auto p-0" onClick={() => onSelect(total.userId)}>
                        {total.firstName} {total.lastName}
                      </Button>
                    ) : (
                      `${total.firstName} ${total.lastName}`
                    )}
                  </TableCell>
                  <TableCell className="text-right tabular-nums">
                    <ShiftCount shifts={total.shifts} open={total.openShifts} />
                  </TableCell>
                  <TableCell className="pr-6 text-right tabular-nums">{formatDuration(total.workedMinutes)}</TableCell>
                </TableRow>
              ))}
            </TableBody>
            {totals.length > 1 && (
              <TableFooter>
                <TableRow>
                  <TableCell className="pl-6">{t("time.everyone")}</TableCell>
                  <TableCell className="text-right tabular-nums">
                    <ShiftCount shifts={sum.shifts} open={sum.openShifts} />
                  </TableCell>
                  <TableCell className="pr-6 text-right tabular-nums">{formatDuration(sum.workedMinutes)}</TableCell>
                </TableRow>
              </TableFooter>
            )}
          </Table>
        )}
      </CardContent>
    </Card>
  )
}

function ShiftCount({ shifts, open }: { shifts: number; open: number }) {
  const { t } = useTranslation("admin")
  return (
    <>
      {shifts}
      {open > 0 && <span className="text-muted-foreground"> {t("time.totals.open", { count: open })}</span>}
    </>
  )
}

/** The current moment, updated every minute: enough for an open shift's "so far". */
function useMinuteClock(): Date {
  const [now, setNow] = useState(() => new Date())
  useEffect(() => {
    const timer = setInterval(() => setNow(new Date()), 60_000)
    return () => clearInterval(timer)
  }, [])
  return now
}

function ShiftsCard({
  shifts,
  period,
  nameOf,
  onEdit,
}: {
  shifts: Shift[]
  period: BusinessPeriod
  nameOf: NameOf
  onEdit: (shift: Shift) => void
}) {
  const format = useDateTimeFormat()
  const { t } = useTranslation("admin")
  const now = useMinuteClock()

  return (
    <Card className="gap-0 pb-0">
      <CardHeader className="pb-4">
        <CardTitle>{t("time.shifts.title")}</CardTitle>
        <CardDescription>{t("time.shifts.description")}</CardDescription>
      </CardHeader>
      <CardContent className="px-0">
        {shifts.length === 0 ? (
          <p className="border-t px-6 py-6 text-sm text-muted-foreground">
            {t("time.shifts.none", { context: isWholeMonth(period) ? "month" : undefined, period: format.period(period) })}
          </p>
        ) : (
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead className="pl-6">{t("time.shifts.date")}</TableHead>
                <TableHead>{t("time.employee")}</TableHead>
                <TableHead>{t("time.shifts.clockIn")}</TableHead>
                <TableHead>{t("time.shifts.clockOut")}</TableHead>
                <TableHead className="text-right">{t("time.shifts.duration")}</TableHead>
                <TableHead>{t("time.shifts.note")}</TableHead>
                <TableHead className="pr-6">
                  <span className="sr-only">{t("common.actions")}</span>
                </TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {shifts.map((shift) => (
                <ShiftRow key={shift.id} shift={shift} now={now} nameOf={nameOf} onEdit={() => onEdit(shift)} />
              ))}
            </TableBody>
          </Table>
        )}
      </CardContent>
    </Card>
  )
}

function ShiftRow({ shift, now, nameOf, onEdit }: { shift: Shift; now: Date; nameOf: NameOf; onEdit: () => void }) {
  const format = useDateTimeFormat()
  const { t } = useTranslation("admin")
  const open = isOpen(shift)

  return (
    <TableRow
      data-state={open ? "open" : undefined}
      className={open ? "bg-amber-50 hover:bg-amber-100/70 dark:bg-amber-950/40 dark:hover:bg-amber-950/60" : undefined}
    >
      <TableCell className="pl-6 tabular-nums">
        {format.businessDate(shift.date, { weekday: "short", day: "numeric", month: "short" })}
      </TableCell>
      <TableCell>
        <div className="font-medium">
          {shift.firstName} {shift.lastName}
        </div>
        <ChangedBy shift={shift} nameOf={nameOf} />
      </TableCell>
      <TableCell className="tabular-nums">{format.time(shift.startedAt)}</TableCell>
      <TableCell className="tabular-nums">
        {shift.endedAt === null ? (
          <Badge className="bg-amber-500 text-white dark:bg-amber-600">{t("time.shifts.open")}</Badge>
        ) : (
          <EndTime date={shift.date} endedAt={shift.endedAt} />
        )}
      </TableCell>
      <TableCell className="text-right tabular-nums">
        {shift.durationMinutes === null ? (
          <span className="text-muted-foreground">{t("time.shifts.soFar", { duration: formatDuration(minutesBetween(shift.startedAt, now)) })}</span>
        ) : (
          formatDuration(shift.durationMinutes)
        )}
      </TableCell>
      <TableCell className="max-w-56 truncate text-muted-foreground" title={shift.note ?? undefined}>
        {shift.note}
      </TableCell>
      <TableCell className="pr-6 text-right">
        {open ? (
          <Button variant="outline" size="sm" onClick={onEdit}>
            <ClockIcon />
            {t("time.shifts.endShift")}
          </Button>
        ) : (
          <Button
            variant="ghost"
            size="icon-sm"
            aria-label={t("time.shifts.edit", { name: `${shift.firstName} ${shift.lastName}`, date: format.businessDate(shift.date) })}
            onClick={onEdit}
          >
            <PencilIcon />
          </Button>
        )}
      </TableCell>
    </TableRow>
  )
}

/** "Entered by Anna Nowak, 11 Nov 2026, 14:02" or "Corrected by you, …"; nothing for a shift as clocked. */
function ChangedBy({ shift, nameOf }: { shift: Shift; nameOf: NameOf }) {
  const format = useDateTimeFormat()
  const { t } = useTranslation("admin")
  if (shift.correctedBy === null || shift.correctedAt === null) return null
  const what = shift.source === "manager" ? "entered" : "corrected"
  const name = nameOf(shift.correctedBy)
  const when = format.dateTime(shift.correctedAt)

  return (
    <div className="text-xs text-muted-foreground">
      {name === null ? t(`time.shifts.${what}ByYou`, { when }) : t(`time.shifts.${what}By`, { name, when })}
    </div>
  )
}

/** The clock-out time, with its date when the shift ended on a later day (a night shift). */
function EndTime({ date, endedAt }: { date: string; endedAt: string }) {
  const format = useDateTimeFormat()
  const endDate = format.dateOf(endedAt)

  return (
    <>
      {format.time(endedAt)}
      {endDate !== date && (
        <span className="text-muted-foreground"> ({format.businessDate(endDate, { day: "numeric", month: "short" })})</span>
      )}
    </>
  )
}
