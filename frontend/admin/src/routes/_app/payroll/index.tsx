import { Fragment, useState } from "react"
import { unwrap } from "@business-tool/api-client"
import { useMutation, useQueryClient, useSuspenseQuery } from "@tanstack/react-query"
import { Link, createFileRoute, useNavigate } from "@tanstack/react-router"
import {
  CheckCircle2Icon,
  ChevronDownIcon,
  ChevronLeftIcon,
  ChevronRightIcon,
  CircleAlertIcon,
  DownloadIcon,
  LockIcon,
  PencilIcon,
  PlusIcon,
  RefreshCwIcon,
  Trash2Icon,
  TriangleAlertIcon,
  Undo2Icon,
} from "lucide-react"
import { toast } from "sonner"

import { Alert, AlertDescription, AlertTitle } from "@business-tool/ui/components/alert"
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
import { Card, CardAction, CardContent, CardDescription, CardHeader, CardTitle } from "@business-tool/ui/components/card"
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
import {
  type BusinessPeriod,
  compareBusinessDates,
  formatDuration,
  monthOf,
  shiftPeriod,
  thisMonth as monthNow,
} from "@business-tool/ui/lib/date-time"
import { type MinorUnits, formatMoney } from "@business-tool/ui/lib/money"
import { requestErrorMessage } from "@business-tool/ui/lib/request-error"

import { type AdjustmentTarget, PaycheckAdjustmentDialog } from "@/components/paycheck-adjustment-dialog"
import { PaycheckPaymentDialog } from "@/components/paycheck-payment-dialog"
import { PayrollGenerateDialog } from "@/components/payroll-generate-dialog"
import { PayrollNav } from "@/components/payroll-nav"
import { api } from "@/lib/api"
import { can, requireCapability } from "@/lib/access"
import type { Member } from "@/lib/members"
import {
  type ExportKind,
  PAYMENT_METHOD_LABELS,
  type PaycheckRow,
  type PayrollRun,
  type PayrollRunSummary,
  type PayrollSearch,
  RUN_STATE_LABELS,
  type RunState,
  approvalBlockers,
  exportFileName,
  linesAmount,
  minutesOf,
  monthKey,
  paycheckRows,
  payrollErrorMessage,
  payrollMonth,
  previousMonth,
  roundingLabel,
  runOfMonth,
  runState,
  validatePayrollSearch,
} from "@/lib/payroll"
import { invalidatePayrollRuns, membersQuery, payrollRunQuery, payrollRunsQuery } from "@/lib/queries"

/*
 * Payroll (ADR 0027): one run per calendar month, generated from the ended shifts and the rates as
 * a draft, reviewed and adjusted by owners and managers, approved by an owner (which locks the
 * month's shifts) and paid. Approve, reopen and mark paid are shown to owners only.
 */
export const Route = createFileRoute("/_app/payroll/")({
  validateSearch: (search: Record<string, unknown>): PayrollSearch => validatePayrollSearch(search),
  beforeLoad: requireCapability("payroll"),
  loaderDeps: ({ search }) => ({ month: search.month }),
  loader: async ({ context, deps }) => {
    const { organization } = context.membership
    const { queryClient } = context
    const [runs] = await Promise.all([
      queryClient.ensureQueryData(payrollRunsQuery(organization.id)),
      queryClient.ensureQueryData(membersQuery(organization.id, true)),
    ])
    const summary = runOfMonth(runs, monthKey(payrollMonth(deps.month, monthNow(organization.timezone))))
    if (summary) await queryClient.ensureQueryData(payrollRunQuery(organization.id, summary.id))
  },
  component: PayrollPage,
})

const STATE_BADGE: Record<RunState, "default" | "secondary" | "outline" | "destructive"> = {
  none: "outline",
  draft: "secondary",
  approved: "default",
  partlyPaid: "default",
  paid: "outline",
}

function StateBadge({ state, paid, of }: { state: RunState; paid?: number; of?: number }) {
  return (
    <Badge variant={STATE_BADGE[state]} className={state === "paid" ? "border-emerald-600 text-emerald-700 dark:text-emerald-400" : undefined}>
      {state === "paid" && <CheckCircle2Icon />}
      {state === "approved" || state === "partlyPaid" ? <LockIcon /> : null}
      {RUN_STATE_LABELS[state]}
      {state === "partlyPaid" && paid !== undefined && of !== undefined && ` ${paid}/${of}`}
    </Badge>
  )
}

function PayrollPage() {
  const { me, membership } = Route.useRouteContext()
  const organizationId = membership.organization.id
  const format = useDateTimeFormat()
  const search = Route.useSearch()
  const navigate = useNavigate({ from: Route.fullPath })
  const thisMonth = format.thisMonth()
  const period = payrollMonth(search.month, thisMonth)
  const isThisMonth = period.from === thisMonth.from

  const { data: runs } = useSuspenseQuery(payrollRunsQuery(organizationId))
  const { data: members } = useSuspenseQuery(membersQuery(organizationId, true))
  const summary = runOfMonth(runs, monthKey(period))

  function showMonth(next: BusinessPeriod) {
    // Last month is the default and stays out of the URL.
    const isDefault = next.from === previousMonth(thisMonth).from
    void navigate({ search: { month: isDefault ? undefined : monthKey(next) } })
  }

  const nameOf = (userId: string | null) => {
    if (userId === null) return "—"
    if (userId === me.id) return "you"
    const member = members.find((m) => m.userId === userId)
    return member ? `${member.firstName} ${member.lastName}`.trim() : "a former member"
  }

  return (
    <div className="mx-auto flex max-w-5xl flex-col gap-6">
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div className="flex flex-col gap-3">
          <PayrollNav />
          <div>
            <h1 className="text-2xl font-semibold tracking-tight">Payroll</h1>
            <p className="text-sm text-muted-foreground">
              Gross pay for a month from the ended shifts and each person's rate. Your accountant does the rest.
            </p>
          </div>
        </div>
        <div className="flex items-center gap-1" role="group" aria-label="Month">
          <Button variant="outline" size="icon" onClick={() => showMonth(shiftPeriod(period, -1))} aria-label="Previous month">
            <ChevronLeftIcon />
          </Button>
          <div className="min-w-36 text-center text-sm font-medium tabular-nums" aria-live="polite">
            {format.period(period)}
          </div>
          <Button
            variant="outline"
            size="icon"
            onClick={() => showMonth(shiftPeriod(period, 1))}
            disabled={isThisMonth}
            aria-label="Next month"
          >
            <ChevronRightIcon />
          </Button>
        </div>
      </div>

      {summary ? (
        <RunView
          key={summary.id}
          organizationId={organizationId}
          runId={summary.id}
          members={members}
          canApprove={can(membership.role, "payrollApprove")}
          nameOf={nameOf}
        />
      ) : (
        <NoRun organizationId={organizationId} period={period} isThisMonth={isThisMonth} />
      )}

      {runs.length > 0 && <RunsCard runs={runs} current={monthKey(period)} onOpen={showMonth} />}
    </div>
  )
}

function NoRun({ organizationId, period, isThisMonth }: { organizationId: string; period: BusinessPeriod; isThisMonth: boolean }) {
  const format = useDateTimeFormat()
  const [open, setOpen] = useState(false)

  return (
    <Card>
      <CardHeader>
        <CardTitle className="flex items-center gap-2">
          {format.period(period)} <StateBadge state="none" />
        </CardTitle>
        <CardDescription>
          {isThisMonth
            ? "This month isn't over. A draft now is a preview: it can be approved once the month has ended."
            : "Generate a draft from the month's ended shifts and the rates, review it, then an owner approves it."}
        </CardDescription>
        <CardAction>
          <Button onClick={() => setOpen(true)}>
            <PlusIcon />
            Generate draft
          </Button>
        </CardAction>
      </CardHeader>
      <PayrollGenerateDialog organizationId={organizationId} period={period} run={null} open={open} onClose={() => setOpen(false)} />
    </Card>
  )
}

type ConfirmKind = "approve" | "reopen" | "delete"

function RunView({
  organizationId,
  runId,
  members,
  canApprove,
  nameOf,
}: {
  organizationId: string
  runId: string
  members: Member[]
  canApprove: boolean
  nameOf: (userId: string | null) => string
}) {
  const queryClient = useQueryClient()
  const format = useDateTimeFormat()
  const locale = useLocale()
  const { data: run } = useSuspenseQuery(payrollRunQuery(organizationId, runId))
  const money = (minor: MinorUnits) => formatMoney(minor, run.currency, locale)
  const period = { from: run.firstDate, to: run.lastDate }
  const state = runState(run)
  const draft = run.status === "draft"
  const rows = paycheckRows(run, members)
  const blockers = approvalBlockers(run, format.today())

  const [generate, setGenerate] = useState(false)
  // The kind stays set while the dialog animates closed, so its wording doesn't change.
  const [confirm, setConfirm] = useState<{ kind: ConfirmKind; open: boolean }>({ kind: "approve", open: false })
  const shown = confirm.kind
  const ask = (kind: ConfirmKind) => setConfirm({ kind, open: true })
  const closeConfirm = () => setConfirm((current) => ({ ...current, open: false }))
  const [adjustment, setAdjustment] = useState<AdjustmentTarget | null>(null)
  const [paying, setPaying] = useState<PaycheckRow | null>(null)
  const [expanded, setExpanded] = useState<ReadonlySet<string>>(() => new Set())
  const [refused, setRefused] = useState<{ title: string; message: string } | null>(null)

  const names = Object.fromEntries(members.map((m) => [m.userId, `${m.firstName} ${m.lastName}`.trim()]))
  const explain = (error: unknown) => payrollErrorMessage(requestErrorMessage(error), names)

  const action = useMutation({
    mutationFn: async (kind: ConfirmKind) => {
      const path = { params: { path: { id: run.id } } }
      if (kind === "approve") return unwrap(api.POST("/api/payroll-runs/{id}/approve", path))
      if (kind === "reopen") return unwrap(api.POST("/api/payroll-runs/{id}/reopen", path))
      await unwrap(api.DELETE("/api/payroll-runs/{id}", path))
      return null
    },
    onSuccess: async (saved, kind) => {
      closeConfirm()
      setRefused(null)
      if (saved) queryClient.setQueryData(payrollRunQuery(organizationId, run.id).queryKey, saved)
      toast.success(
        kind === "approve"
          ? `${format.period(period)} approved: its shifts are locked`
          : kind === "reopen"
            ? `${format.period(period)} reopened as a draft: its shifts are unlocked`
            : `Draft of ${format.period(period)} deleted`,
      )
      if (kind === "delete") queryClient.removeQueries({ queryKey: payrollRunQuery(organizationId, run.id).queryKey })
      await invalidatePayrollRuns(queryClient, organizationId)
    },
    onError: (error, kind) => {
      closeConfirm()
      setRefused({
        title: kind === "approve" ? "Not approved" : kind === "reopen" ? "Not reopened" : "Not deleted",
        message: explain(error),
      })
    },
  })

  const removeAdjustment = useMutation({
    mutationFn: (adjustmentId: string) =>
      unwrap(api.DELETE("/api/payroll-runs/{id}/adjustments/{adjustmentId}", { params: { path: { id: run.id, adjustmentId } } })),
    onSuccess: async () => {
      toast.success("Adjustment removed")
      await invalidatePayrollRuns(queryClient, organizationId)
    },
    onError: (error) => toast.error("Could not remove the adjustment", { description: explain(error) }),
  })

  const undoPaid = useMutation({
    mutationFn: (row: PaycheckRow) =>
      unwrap(
        api.DELETE("/api/payroll-runs/{id}/paychecks/{paycheckId}/payment", {
          params: { path: { id: run.id, paycheckId: row.paycheck.id } },
        }),
      ),
    onSuccess: async (_, row) => {
      toast.success(`${row.name}: no longer marked paid`)
      await invalidatePayrollRuns(queryClient, organizationId)
    },
    onError: (error) => toast.error("Could not undo the paid mark", { description: explain(error) }),
  })

  function toggle(id: string) {
    setExpanded((current) => {
      const next = new Set(current)
      if (next.has(id)) next.delete(id)
      else next.add(id)
      return next
    })
  }

  const totalSeconds = run.paychecks.reduce((sum, p) => sum + p.paidSeconds, 0)
  const columns = 7

  return (
    <>
      <Card>
        <CardHeader>
          <CardTitle className="flex flex-wrap items-center gap-2">
            {format.period(period)}
            <StateBadge state={state} paid={run.paidPaychecks} of={run.paychecks.length} />
          </CardTitle>
          <CardDescription className="flex flex-col gap-0.5">
            <span>
              {roundingLabel(run.roundingUnit, run.roundingDirection)}. Generated by {nameOf(run.generatedBy)} on{" "}
              {format.dateTime(run.generatedAt)}.
            </span>
            {run.approvedAt && (
              <span>
                Approved by {nameOf(run.approvedBy)} on {format.dateTime(run.approvedAt)}. The month's shifts and rates are
                locked.
              </span>
            )}
          </CardDescription>
          <CardAction className="text-right">
            <div className="text-2xl font-semibold tabular-nums">{money(run.total)}</div>
            <div className="text-xs text-muted-foreground">
              gross, {run.paychecks.length} {run.paychecks.length === 1 ? "paycheck" : "paychecks"}
              {!draft && `, ${run.paidPaychecks} paid`}
            </div>
          </CardAction>
        </CardHeader>
        <CardContent className="flex flex-wrap gap-2">
          {draft ? (
            <>
              {canApprove && (
                <Button onClick={() => ask("approve")} disabled={action.isPending}>
                  <LockIcon />
                  Approve
                </Button>
              )}
              <Button variant="outline" onClick={() => setGenerate(true)}>
                <RefreshCwIcon />
                Regenerate
              </Button>
              <Button variant="outline" onClick={() => setAdjustment({})}>
                <PlusIcon />
                Add adjustment
              </Button>
              <Button variant="ghost" className="text-destructive hover:text-destructive" onClick={() => ask("delete")}>
                <Trash2Icon />
                Delete draft
              </Button>
            </>
          ) : (
            <>
              <ExportButton run={run} kind="paychecks" label="Paychecks CSV" />
              <ExportButton run={run} kind="shifts" label="Shifts CSV" />
              {canApprove && (
                <Button
                  variant="ghost"
                  onClick={() => ask("reopen")}
                  disabled={action.isPending}
                >
                  <Undo2Icon />
                  Reopen
                </Button>
              )}
            </>
          )}
        </CardContent>
      </Card>

      {refused && (
        <Alert variant="destructive">
          <CircleAlertIcon />
          <AlertTitle>{refused.title}</AlertTitle>
          <AlertDescription>{refused.message}</AlertDescription>
        </Alert>
      )}

      {draft &&
        (blockers.length > 0 ? (
          <Alert>
            <TriangleAlertIcon className="text-amber-500" />
            <AlertTitle>Not ready to approve</AlertTitle>
            <AlertDescription>
              <ul className="list-disc pl-4">
                {blockers.map((blocker) => (
                  <li key={blocker}>{blocker}</li>
                ))}
              </ul>
              {run.linesWithoutRate > 0 && (
                <Button asChild variant="link" className="h-auto p-0">
                  <Link to="/payroll/rates">Set rates</Link>
                </Button>
              )}
            </AlertDescription>
          </Alert>
        ) : (
          <Alert>
            <CheckCircle2Icon className="text-emerald-600" />
            <AlertTitle>Ready to approve</AlertTitle>
            <AlertDescription>
              {canApprove
                ? "Review the paychecks, then approve. Approving locks the month's shifts and rates."
                : "Review the paychecks. An owner approves the run, which locks the month's shifts and rates."}
            </AlertDescription>
          </Alert>
        ))}

      <Card className="gap-0 pb-0">
        <CardHeader className="pb-4">
          <CardTitle>Paychecks</CardTitle>
          <CardDescription>
            {draft
              ? "Click a person to see their shifts and adjustments."
              : canApprove
                ? "Mark each paycheck paid once the money has left."
                : "An owner marks paychecks paid."}
          </CardDescription>
        </CardHeader>
        <CardContent className="px-0">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead className="pl-6">Person</TableHead>
                <TableHead className="text-right">Shifts</TableHead>
                <TableHead className="text-right">Paid time</TableHead>
                <TableHead className="text-right">Pay for hours</TableHead>
                <TableHead className="text-right">Adjustments</TableHead>
                <TableHead className="text-right">Total</TableHead>
                <TableHead className="pr-6">{draft ? "" : "Payment"}</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {rows.map((row) => {
                const { paycheck } = row
                const open = expanded.has(paycheck.id)
                return (
                  <Fragment key={paycheck.id}>
                    <TableRow className={open ? "border-b-0" : undefined}>
                      <TableCell className="pl-6">
                        <button
                          type="button"
                          className="flex items-center gap-1.5 text-left font-medium hover:underline"
                          aria-expanded={open}
                          onClick={() => toggle(paycheck.id)}
                        >
                          <ChevronDownIcon className={`size-4 shrink-0 transition-transform ${open ? "" : "-rotate-90"}`} />
                          {row.name}
                          {row.deactivated && <span className="font-normal text-muted-foreground">(deactivated)</span>}
                        </button>
                        {paycheck.linesWithoutRate > 0 && (
                          <Badge variant="destructive" className="mt-1 ml-5.5">
                            <TriangleAlertIcon />
                            {paycheck.linesWithoutRate} without rate
                          </Badge>
                        )}
                      </TableCell>
                      <TableCell className="text-right tabular-nums">{paycheck.lines.length}</TableCell>
                      <TableCell className="text-right tabular-nums">{formatDuration(minutesOf(paycheck.paidSeconds))}</TableCell>
                      <TableCell className="text-right tabular-nums">{money(paycheck.payForHours)}</TableCell>
                      <TableCell className="text-right tabular-nums">
                        {paycheck.adjustments.length === 0 ? "—" : money(paycheck.adjustmentsTotal)}
                      </TableCell>
                      <TableCell className={`text-right font-medium tabular-nums ${paycheck.total < 0 ? "text-destructive" : ""}`}>
                        {money(paycheck.total)}
                      </TableCell>
                      <TableCell className="pr-6">
                        {!draft && (
                          <PaymentCell
                            row={row}
                            canPay={canApprove}
                            busy={undoPaid.isPending}
                            onPay={() => setPaying(row)}
                            onUndo={() => undoPaid.mutate(row)}
                          />
                        )}
                      </TableCell>
                    </TableRow>
                    {open && (
                      <TableRow className="hover:bg-transparent">
                        <TableCell colSpan={columns} className="bg-muted/30 px-6 pt-0 pb-4">
                          <PaycheckDetails
                            row={row}
                            run={run}
                            onAddAdjustment={() => setAdjustment({ userId: paycheck.userId })}
                            onEditAdjustment={(a) => setAdjustment({ userId: paycheck.userId, adjustment: a })}
                            onRemoveAdjustment={(id) => removeAdjustment.mutate(id)}
                            removing={removeAdjustment.isPending}
                          />
                        </TableCell>
                      </TableRow>
                    )}
                  </Fragment>
                )
              })}
            </TableBody>
            <TableFooter>
              <TableRow>
                <TableCell className="pl-6">Total</TableCell>
                <TableCell className="text-right tabular-nums">
                  {run.paychecks.reduce((sum, p) => sum + p.lines.length, 0)}
                </TableCell>
                <TableCell className="text-right tabular-nums">{formatDuration(minutesOf(totalSeconds))}</TableCell>
                <TableCell className="text-right tabular-nums">
                  {money(run.paychecks.reduce((sum, p) => sum + p.payForHours, 0))}
                </TableCell>
                <TableCell className="text-right tabular-nums">
                  {money(run.paychecks.reduce((sum, p) => sum + p.adjustmentsTotal, 0))}
                </TableCell>
                <TableCell className="text-right tabular-nums">{money(run.total)}</TableCell>
                <TableCell className="pr-6" />
              </TableRow>
            </TableFooter>
          </Table>
        </CardContent>
      </Card>

      <PayrollGenerateDialog
        organizationId={organizationId}
        period={period}
        run={run}
        open={generate}
        onClose={() => setGenerate(false)}
      />
      <PaycheckAdjustmentDialog
        organizationId={organizationId}
        run={run}
        members={members}
        target={adjustment}
        onClose={() => setAdjustment(null)}
      />
      <PaycheckPaymentDialog organizationId={organizationId} run={run} row={paying} onClose={() => setPaying(null)} />

      <AlertDialog open={confirm.open} onOpenChange={(open) => !open && closeConfirm()}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>
              {shown === "approve"
                ? `Approve ${format.period(period)}?`
                : shown === "reopen"
                  ? `Reopen ${format.period(period)}?`
                  : `Delete the draft of ${format.period(period)}?`}
            </AlertDialogTitle>
            <AlertDialogDescription>
              {shown === "approve"
                ? `${money(run.total)} gross in ${run.paychecks.length} paychecks. The month's shifts and rates are locked until the run is reopened.`
                : shown === "reopen"
                  ? "The run goes back to a draft and the month's shifts and rates are unlocked. It has to be approved again."
                  : "Its paychecks and adjustments are deleted. You can generate the month again."}
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Cancel</AlertDialogCancel>
            <AlertDialogAction
              className={shown === "delete" ? "bg-destructive text-white hover:bg-destructive/90" : undefined}
              disabled={action.isPending}
              onClick={(event) => {
                event.preventDefault()
                action.mutate(confirm.kind)
              }}
            >
              {shown === "approve" ? "Approve" : shown === "reopen" ? "Reopen" : "Delete draft"}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </>
  )
}

function PaymentCell({
  row,
  canPay,
  busy,
  onPay,
  onUndo,
}: {
  row: PaycheckRow
  canPay: boolean
  busy: boolean
  onPay: () => void
  onUndo: () => void
}) {
  const format = useDateTimeFormat()
  const { paycheck } = row
  if (paycheck.paidOn) {
    return (
      <div className="flex items-center gap-1">
        <Badge variant="outline" className="border-emerald-600 text-emerald-700 dark:text-emerald-400">
          <CheckCircle2Icon />
          {format.businessDate(paycheck.paidOn, { day: "numeric", month: "short" })}
          {paycheck.paymentMethod && ` · ${PAYMENT_METHOD_LABELS[paycheck.paymentMethod]}`}
        </Badge>
        {canPay && (
          <Button variant="ghost" size="icon" className="size-7" onClick={onUndo} disabled={busy} aria-label={`Undo paid: ${row.name}`}>
            <Undo2Icon />
          </Button>
        )}
      </div>
    )
  }
  if (!canPay) return <span className="text-sm text-muted-foreground">Unpaid</span>
  return (
    <Button size="sm" variant="outline" onClick={onPay}>
      Mark paid
    </Button>
  )
}

function PaycheckDetails({
  row,
  run,
  onAddAdjustment,
  onEditAdjustment,
  onRemoveAdjustment,
  removing,
}: {
  row: PaycheckRow
  run: PayrollRun
  onAddAdjustment: () => void
  onEditAdjustment: (adjustment: PayrollRun["paychecks"][number]["adjustments"][number]) => void
  onRemoveAdjustment: (id: string) => void
  removing: boolean
}) {
  const format = useDateTimeFormat()
  const locale = useLocale()
  const money = (minor: MinorUnits) => formatMoney(minor, run.currency, locale)
  const { paycheck } = row
  const draft = run.status === "draft"
  const sumOfLines = linesAmount(paycheck)
  const rounded = run.roundingUnit !== "none"

  return (
    <div className="flex flex-col gap-4 pt-2">
      {paycheck.lines.length > 0 && (
        <Table className="rounded-md border bg-background">
          <TableHeader>
            <TableRow>
              <TableHead className="pl-4">Shift</TableHead>
              <TableHead className="text-right">Length</TableHead>
              {rounded && <TableHead className="text-right">Paid</TableHead>}
              <TableHead className="text-right">Rate per hour</TableHead>
              <TableHead className="pr-4 text-right">Amount</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {paycheck.lines.map((line) => (
              <TableRow key={line.shiftId} className={line.rate === null ? "bg-destructive/5 text-destructive" : undefined}>
                <TableCell className="pl-4 tabular-nums">
                  {format.businessDate(line.date, { weekday: "short", day: "numeric", month: "short" })}
                </TableCell>
                <TableCell className="text-right tabular-nums">{formatDuration(minutesOf(line.seconds))}</TableCell>
                {rounded && <TableCell className="text-right tabular-nums">{formatDuration(minutesOf(line.paidSeconds))}</TableCell>}
                <TableCell className="text-right tabular-nums">
                  {line.rate === null ? (
                    <span className="inline-flex items-center gap-1 font-medium">
                      <TriangleAlertIcon className="size-3.5" />
                      No rate
                    </span>
                  ) : (
                    <>
                      {line.rateKind === "day" && (
                        <Badge variant="secondary" className="mr-1.5">
                          Day rate
                        </Badge>
                      )}
                      {money(line.rate)}
                    </>
                  )}
                </TableCell>
                <TableCell className="pr-4 text-right tabular-nums">{line.amount === null ? "—" : money(line.amount)}</TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      )}
      {sumOfLines !== paycheck.payForHours && paycheck.linesWithoutRate === 0 && (
        <p className="text-xs text-muted-foreground">
          Pay for hours is rounded once from the exact time, so it is {money(Math.abs(paycheck.payForHours - sumOfLines))}{" "}
          {paycheck.payForHours > sumOfLines ? "more" : "less"} than the sum of the shift amounts.
        </p>
      )}

      <div className="flex flex-col gap-2">
        <div className="flex items-center justify-between">
          <h3 className="text-sm font-medium">Adjustments</h3>
          {draft && (
            <Button size="sm" variant="outline" onClick={onAddAdjustment}>
              <PlusIcon />
              Add
            </Button>
          )}
        </div>
        {paycheck.adjustments.length === 0 ? (
          <p className="text-sm text-muted-foreground">No bonus or deduction.</p>
        ) : (
          <ul className="divide-y rounded-md border bg-background">
            {paycheck.adjustments.map((adjustment) => (
              <li key={adjustment.id} className="flex items-center gap-2 px-4 py-2 text-sm">
                <span className="flex-1">{adjustment.label}</span>
                <span className={`tabular-nums ${adjustment.amount < 0 ? "text-destructive" : ""}`}>
                  {adjustment.amount > 0 && "+"}
                  {money(adjustment.amount)}
                </span>
                {draft && (
                  <>
                    <Button
                      variant="ghost"
                      size="icon"
                      className="size-7"
                      aria-label={`Change ${adjustment.label}`}
                      onClick={() => onEditAdjustment(adjustment)}
                    >
                      <PencilIcon />
                    </Button>
                    <Button
                      variant="ghost"
                      size="icon"
                      className="size-7 text-destructive hover:text-destructive"
                      aria-label={`Remove ${adjustment.label}`}
                      disabled={removing}
                      onClick={() => onRemoveAdjustment(adjustment.id)}
                    >
                      <Trash2Icon />
                    </Button>
                  </>
                )}
              </li>
            ))}
          </ul>
        )}
      </div>

      {paycheck.paidOn && paycheck.paidMarkedAt && (
        <p className="text-xs text-muted-foreground">
          Paid on {format.businessDate(paycheck.paidOn)}
          {paycheck.paymentMethod && ` by ${PAYMENT_METHOD_LABELS[paycheck.paymentMethod].toLowerCase()}`}, marked on{" "}
          {format.dateTime(paycheck.paidMarkedAt)}.
        </p>
      )}
    </div>
  )
}

/** Downloads the accountant's CSV of an approved run (ADR 0027 §12). */
function ExportButton({ run, kind, label }: { run: PayrollRun; kind: ExportKind; label: string }) {
  const [busy, setBusy] = useState(false)

  async function download() {
    setBusy(true)
    try {
      const request = { params: { path: { id: run.id } }, headers: { Accept: "text/csv" }, parseAs: "blob" } as const
      const blob = await unwrap(
        kind === "paychecks"
          ? api.GET("/api/payroll-runs/{id}/export/paychecks", request)
          : api.GET("/api/payroll-runs/{id}/export/shifts", request),
      )
      const url = URL.createObjectURL(blob)
      const link = document.createElement("a")
      link.href = url
      link.download = exportFileName(run.month, kind)
      link.click()
      URL.revokeObjectURL(url)
    } catch (error) {
      toast.error("Could not download the file", { description: requestErrorMessage(error) })
    } finally {
      setBusy(false)
    }
  }

  return (
    <Button variant="outline" onClick={download} disabled={busy}>
      <DownloadIcon />
      {label}
    </Button>
  )
}

function RunsCard({
  runs,
  current,
  onOpen,
}: {
  runs: PayrollRunSummary[]
  current: string
  onOpen: (period: BusinessPeriod) => void
}) {
  const format = useDateTimeFormat()
  const locale = useLocale()

  return (
    <Card className="gap-0 pb-0">
      <CardHeader className="pb-4">
        <CardTitle>All months</CardTitle>
      </CardHeader>
      <CardContent className="px-0">
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead className="pl-6">Month</TableHead>
              <TableHead>State</TableHead>
              <TableHead className="text-right">Paychecks</TableHead>
              <TableHead className="pr-6 text-right">Total</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {[...runs]
              .sort((a, b) => compareBusinessDates(`${b.month}-01`, `${a.month}-01`))
              .map((run) => {
                const period = monthOf(`${run.month}-01`)
                return (
                  <TableRow key={run.id} data-state={run.month === current ? "selected" : undefined}>
                    <TableCell className="pl-6">
                      <Button variant="link" className="h-auto p-0" onClick={() => onOpen(period)}>
                        {format.period(period)}
                      </Button>
                    </TableCell>
                    <TableCell>
                      <StateBadge state={runState(run)} paid={run.paidPaychecks} of={run.paychecks} />
                      {run.linesWithoutRate > 0 && (
                        <Badge variant="destructive" className="ml-1.5">
                          {run.linesWithoutRate} without rate
                        </Badge>
                      )}
                    </TableCell>
                    <TableCell className="text-right tabular-nums">{run.paychecks}</TableCell>
                    <TableCell className="pr-6 text-right tabular-nums">{formatMoney(run.total, run.currency, locale)}</TableCell>
                  </TableRow>
                )
              })}
          </TableBody>
        </Table>
      </CardContent>
    </Card>
  )
}
