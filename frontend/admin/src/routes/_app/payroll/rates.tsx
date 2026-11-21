import { Fragment, useState } from "react"
import { unwrap } from "@business-tool/api-client"
import { useMutation, useQueryClient, useSuspenseQuery } from "@tanstack/react-query"
import { createFileRoute } from "@tanstack/react-router"
import { ChevronDownIcon, LockIcon, MoreHorizontalIcon, PlusIcon, TriangleAlertIcon } from "lucide-react"
import { toast } from "sonner"

import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from "@business-tool/ui/components/alert-dialog"
import { Badge } from "@business-tool/ui/components/badge"
import { Button } from "@business-tool/ui/components/button"
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@business-tool/ui/components/card"
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from "@business-tool/ui/components/dropdown-menu"
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@business-tool/ui/components/table"
import { useLocale } from "@business-tool/ui/hooks/use-locale"
import { useDateTimeFormat } from "@business-tool/ui/hooks/use-organization-time-zone"
import { type BusinessPeriod } from "@business-tool/ui/lib/date-time"
import { formatMoney } from "@business-tool/ui/lib/money"
import { requestErrorMessage } from "@business-tool/ui/lib/request-error"

import { PayRateDialog, type PayRateTarget } from "@/components/pay-rate-dialog"
import { PayrollNav } from "@/components/payroll-nav"
import { requireCapability } from "@/lib/access"
import { api } from "@/lib/api"
import { fullName, memberNames, roleLabel } from "@/lib/members"
import {
  type DayRate,
  type HourlyRate,
  type MemberRates,
  hourlyRateOn,
  isDayRateLocked,
  isHourlyRateLocked,
  lockedPeriods,
  ratesByMember,
} from "@/lib/payroll"
import { dayRatesQuery, hourlyRatesQuery, invalidatePayRates, invalidatePayrollRuns, membersQuery, payrollRunsQuery } from "@/lib/queries"

/*
 * Payroll rates (ADR 0027 §3): each member's usual hourly rate from a date, with its history, and
 * day rates that replace it for one date. Owners and managers manage everyone's, their own
 * included. Rates on a date of an approved month are read-only (the API answers 409).
 */
export const Route = createFileRoute("/_app/payroll/rates")({
  beforeLoad: requireCapability("payroll"),
  loader: async ({ context }) => {
    const { organization } = context.membership
    const { queryClient } = context
    await Promise.all([
      queryClient.ensureQueryData(membersQuery(organization.id, true)),
      queryClient.ensureQueryData(hourlyRatesQuery(organization.id)),
      queryClient.ensureQueryData(dayRatesQuery(organization.id)),
      queryClient.ensureQueryData(payrollRunsQuery(organization.id)),
    ])
  },
  component: RatesPage,
})

type Removal = { kind: "hourly"; rate: HourlyRate; name: string } | { kind: "day"; rate: DayRate; name: string }

function RatesPage() {
  const { membership } = Route.useRouteContext()
  const organizationId = membership.organization.id
  const queryClient = useQueryClient()
  const format = useDateTimeFormat()
  const locale = useLocale()
  const today = format.today()

  const { data: members } = useSuspenseQuery(membersQuery(organizationId, true))
  const { data: hourly } = useSuspenseQuery(hourlyRatesQuery(organizationId))
  const { data: day } = useSuspenseQuery(dayRatesQuery(organizationId))
  const { data: runs } = useSuspenseQuery(payrollRunsQuery(organizationId))
  const locked = lockedPeriods(runs)
  const entries = ratesByMember(members, hourly, day)

  const [dialog, setDialog] = useState<PayRateTarget | null>(null)
  const [removal, setRemoval] = useState<Removal | null>(null)
  const [expanded, setExpanded] = useState<ReadonlySet<string>>(() => new Set())

  const names = memberNames(members)

  const remove = useMutation({
    mutationFn: (target: Removal) =>
      target.kind === "hourly"
        ? unwrap(api.DELETE("/api/hourly-rates/{id}", { params: { path: { id: target.rate.id } } }))
        : unwrap(api.DELETE("/api/day-rates/{id}", { params: { path: { id: target.rate.id } } })),
    onSuccess: async (_, target) => {
      setRemoval(null)
      toast.success(`${target.kind === "hourly" ? "Hourly rate" : "Day rate"} deleted`, { description: target.name })
      await Promise.all([invalidatePayRates(queryClient, organizationId), invalidatePayrollRuns(queryClient, organizationId)])
    },
    onError: (error) => {
      setRemoval(null)
      toast.error("Could not delete the rate", { description: requestErrorMessage(error, { names }) })
    },
  })

  function toggle(userId: string) {
    setExpanded((current) => {
      const next = new Set(current)
      if (next.has(userId)) next.delete(userId)
      else next.add(userId)
      return next
    })
  }

  const withoutRate = entries.filter((entry) => entry.member.deactivatedAt === null && hourlyRateOn(entry.hourly, today) === null)

  return (
    <div className="mx-auto flex max-w-5xl flex-col gap-6">
      <div className="flex flex-col gap-3">
        <PayrollNav />
        <div>
          <h1 className="text-2xl font-semibold tracking-tight">Rates</h1>
          <p className="text-sm text-muted-foreground">
            Gross pay per hour. A raise is a new rate from a date; earlier shifts keep the earlier rate. A day rate replaces
            it for the shifts that start on one date.
          </p>
        </div>
      </div>

      <Card className="gap-0 pb-0">
        <CardHeader className="pb-4">
          <CardTitle>People</CardTitle>
          <CardDescription>
            {withoutRate.length === 0
              ? "Everyone has a rate today. Click a person to see their history and day rates."
              : `${withoutRate.length} ${withoutRate.length === 1 ? "person has" : "people have"} no rate today: their shifts can't be paid until one is set.`}
          </CardDescription>
        </CardHeader>
        <CardContent className="px-0">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead className="pl-6">Person</TableHead>
                <TableHead>Role</TableHead>
                <TableHead className="text-right">Rate today</TableHead>
                <TableHead>Since</TableHead>
                <TableHead className="pr-6 text-right">
                  <span className="sr-only">Actions</span>
                </TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {entries.map((entry) => {
                const { member } = entry
                const current = hourlyRateOn(entry.hourly, today)
                const upcoming = entry.hourly.find((rate) => rate.effectiveFrom > today) ?? null
                const open = expanded.has(member.userId)
                const name = fullName(member)
                return (
                  <Fragment key={member.userId}>
                    <TableRow className={open ? "border-b-0" : undefined}>
                      <TableCell className="pl-6">
                        <button
                          type="button"
                          className="flex items-center gap-1.5 text-left font-medium hover:underline"
                          aria-expanded={open}
                          onClick={() => toggle(member.userId)}
                        >
                          <ChevronDownIcon className={`size-4 shrink-0 transition-transform ${open ? "" : "-rotate-90"}`} />
                          {name}
                          {member.deactivatedAt !== null && <span className="font-normal text-muted-foreground">(deactivated)</span>}
                        </button>
                      </TableCell>
                      <TableCell>{roleLabel(member.role)}</TableCell>
                      <TableCell className="text-right tabular-nums">
                        {current ? (
                          formatMoney(current.amount, current.currency, locale)
                        ) : (
                          <Badge variant="destructive">
                            <TriangleAlertIcon />
                            No rate
                          </Badge>
                        )}
                      </TableCell>
                      <TableCell className="text-muted-foreground tabular-nums">
                        {current ? format.businessDate(current.effectiveFrom) : "—"}
                        {upcoming && (
                          <span className="block text-xs">
                            {formatMoney(upcoming.amount, upcoming.currency, locale)} from {format.businessDate(upcoming.effectiveFrom)}
                          </span>
                        )}
                      </TableCell>
                      <TableCell className="pr-6 text-right">
                        <DropdownMenu>
                          <DropdownMenuTrigger asChild>
                            <Button variant="ghost" size="icon" aria-label={`Rates of ${name}`}>
                              <MoreHorizontalIcon />
                            </Button>
                          </DropdownMenuTrigger>
                          <DropdownMenuContent align="end">
                            <DropdownMenuItem onSelect={() => setDialog({ kind: "hourly", member })}>
                              {current ? "New rate from a date" : "Set hourly rate"}
                            </DropdownMenuItem>
                            <DropdownMenuItem onSelect={() => setDialog({ kind: "day", member })}>Add day rate</DropdownMenuItem>
                          </DropdownMenuContent>
                        </DropdownMenu>
                      </TableCell>
                    </TableRow>
                    {open && (
                      <TableRow className="hover:bg-transparent">
                        <TableCell colSpan={5} className="bg-muted/30 px-6 pt-0 pb-4">
                          <History
                            entry={entry}
                            locked={locked}
                            onAdd={(kind) => setDialog({ kind, member })}
                            onEdit={(target) => setDialog(target)}
                            onRemove={(target) => setRemoval(target)}
                          />
                        </TableCell>
                      </TableRow>
                    )}
                  </Fragment>
                )
              })}
            </TableBody>
          </Table>
        </CardContent>
      </Card>

      <PayRateDialog organizationId={organizationId} target={dialog} onClose={() => setDialog(null)} />

      <AlertDialog open={removal !== null} onOpenChange={(open) => !open && setRemoval(null)}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Delete this {removal?.kind === "day" ? "day rate" : "hourly rate"}?</AlertDialogTitle>
            <AlertDialogDescription>
              {removal?.kind === "day"
                ? `${removal.name}'s shifts on ${format.businessDate(removal.rate.date)} go back to the hourly rate.`
                : removal
                  ? `${removal.name}'s shifts from ${format.businessDate(removal.rate.effectiveFrom)} go back to the previous rate, or to none.`
                  : null}
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Cancel</AlertDialogCancel>
            <AlertDialogAction
              className="bg-destructive text-white hover:bg-destructive/90"
              disabled={remove.isPending}
              onClick={(event) => {
                event.preventDefault()
                if (removal) remove.mutate(removal)
              }}
            >
              Delete
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  )
}

function History({
  entry,
  locked,
  onAdd,
  onEdit,
  onRemove,
}: {
  entry: MemberRates
  locked: BusinessPeriod[]
  onAdd: (kind: "hourly" | "day") => void
  onEdit: (target: PayRateTarget) => void
  onRemove: (target: Removal) => void
}) {
  const format = useDateTimeFormat()
  const locale = useLocale()
  const { member } = entry
  const name = fullName(member)

  return (
    <div className="grid gap-4 pt-2 md:grid-cols-2">
      <section className="flex flex-col gap-2">
        <div className="flex items-center justify-between">
          <h3 className="text-sm font-medium">Hourly rates</h3>
          <Button size="sm" variant="outline" onClick={() => onAdd("hourly")}>
            <PlusIcon />
            New rate
          </Button>
        </div>
        {entry.hourly.length === 0 ? (
          <p className="text-sm text-muted-foreground">No hourly rate yet.</p>
        ) : (
          <ul className="divide-y rounded-md border bg-background">
            {entry.hourly.map((rate) => (
              <RateItem
                key={rate.id}
                when={
                  rate.effectiveTo
                    ? `${format.businessDate(rate.effectiveFrom)} – ${format.businessDate(rate.effectiveTo)}`
                    : `From ${format.businessDate(rate.effectiveFrom)}`
                }
                amount={formatMoney(rate.amount, rate.currency, locale)}
                locked={isHourlyRateLocked(rate, locked)}
                label={`hourly rate from ${format.businessDate(rate.effectiveFrom)}`}
                onEdit={() => onEdit({ kind: "hourly", member, rate })}
                onRemove={() => onRemove({ kind: "hourly", rate, name })}
              />
            ))}
          </ul>
        )}
      </section>
      <section className="flex flex-col gap-2">
        <div className="flex items-center justify-between">
          <h3 className="text-sm font-medium">Day rates</h3>
          <Button size="sm" variant="outline" onClick={() => onAdd("day")}>
            <PlusIcon />
            Day rate
          </Button>
        </div>
        {entry.day.length === 0 ? (
          <p className="text-sm text-muted-foreground">No day rate.</p>
        ) : (
          <ul className="divide-y rounded-md border bg-background">
            {entry.day.map((rate) => (
              <RateItem
                key={rate.id}
                when={format.businessDate(rate.date, { weekday: "short", day: "numeric", month: "short", year: "numeric" })}
                amount={formatMoney(rate.amount, rate.currency, locale)}
                locked={isDayRateLocked(rate, locked)}
                label={`day rate on ${format.businessDate(rate.date)}`}
                onEdit={() => onEdit({ kind: "day", member, rate })}
                onRemove={() => onRemove({ kind: "day", rate, name })}
              />
            ))}
          </ul>
        )}
      </section>
    </div>
  )
}

function RateItem({
  when,
  amount,
  locked,
  label,
  onEdit,
  onRemove,
}: {
  when: string
  amount: string
  locked: boolean
  label: string
  onEdit: () => void
  onRemove: () => void
}) {
  return (
    <li className="flex items-center gap-2 px-4 py-2 text-sm">
      <span className="flex-1 tabular-nums">{when}</span>
      <span className="tabular-nums">{amount}</span>
      {locked ? (
        <span className="flex size-8 items-center justify-center text-muted-foreground" title="In an approved payroll month: reopen the run to change it">
          <LockIcon className="size-4" aria-label="Locked by an approved payroll month" />
        </span>
      ) : (
        <DropdownMenu>
          <DropdownMenuTrigger asChild>
            <Button variant="ghost" size="icon" className="size-8" aria-label={`Change ${label}`}>
              <MoreHorizontalIcon />
            </Button>
          </DropdownMenuTrigger>
          <DropdownMenuContent align="end">
            <DropdownMenuItem onSelect={onEdit}>Change</DropdownMenuItem>
            <DropdownMenuItem variant="destructive" onSelect={onRemove}>
              Delete
            </DropdownMenuItem>
          </DropdownMenuContent>
        </DropdownMenu>
      )}
    </li>
  )
}
