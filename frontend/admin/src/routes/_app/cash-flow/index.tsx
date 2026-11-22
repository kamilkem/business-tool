import { useState } from "react"
import { unwrap } from "@business-tool/api-client"
import { useMutation, useQuery, useQueryClient, useSuspenseQuery } from "@tanstack/react-query"
import { Link, createFileRoute, useNavigate } from "@tanstack/react-router"
import {
  ArrowRightLeftIcon,
  ChevronRightIcon,
  ClipboardCheckIcon,
  LoaderCircleIcon,
  MinusIcon,
  MoreHorizontalIcon,
  PlusIcon,
  Trash2Icon,
  TriangleAlertIcon,
} from "lucide-react"
import { useTranslation } from "react-i18next"
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
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from "@business-tool/ui/components/collapsible"
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@business-tool/ui/components/dropdown-menu"
import { PeriodPicker } from "@business-tool/ui/components/period-picker"
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@business-tool/ui/components/select"
import { useMoneyFormat } from "@business-tool/ui/hooks/use-organization-currency"
import { useDateTimeFormat } from "@business-tool/ui/hooks/use-organization-time-zone"
import { type BusinessPeriod, isWholeMonth, thisMonth } from "@business-tool/ui/lib/date-time"
import { requestErrorMessage } from "@business-tool/ui/lib/request-error"
import { cn } from "@business-tool/ui/lib/utils"

import { CashFlowNav } from "@/components/cash-flow-nav"
import { type CashMovementDialogState, CashMovementDialog } from "@/components/cash-movement-dialog"
import { api } from "@/lib/api"
import { requireCapability } from "@/lib/access"
import {
  CASH_MOVEMENTS_MAX_DAYS,
  type CashBalance,
  type CashFlowSearch,
  type CashMovement,
  type HistoryEntry,
  affectedCloses,
  cashFlowHistory,
  cashOnHand,
  closesAfterPeriod,
  enteredLater,
  heldLines,
  movementAmount,
  retiredLines,
  validateCashFlowSearch,
} from "@/lib/cash-flow"
import { LONG_DATE } from "@/lib/day-closes"
import type { CashStorage } from "@/lib/cash-storages"
import {
  cashBalancesQuery,
  cashMovementsQuery,
  cashStoragesQuery,
  dayClosesQuery,
  denominationsQuery,
  invalidateCashLedger,
} from "@/lib/queries"

/*
 * Cash flow (ADR 0033 §4): what each storage holds now, the ledger's movements and closes for a
 * period, and the actions on it: add or take out cash, transfer it, audit a deposit storage, and
 * delete a wrong movement.
 */
export const Route = createFileRoute("/_app/cash-flow/")({
  validateSearch: (search: Record<string, unknown>): CashFlowSearch => validateCashFlowSearch(search),
  beforeLoad: requireCapability("cashFlow"),
  loaderDeps: ({ search }) => search,
  loader: async ({ context, deps }) => {
    const { organization } = context.membership
    const period = periodOf(deps, thisMonth(organization.timezone))
    const [storages] = await Promise.all([
      context.queryClient.ensureQueryData(cashStoragesQuery(organization.id)),
      context.queryClient.ensureQueryData(cashBalancesQuery(organization.id)),
      context.queryClient.ensureQueryData(denominationsQuery(organization.id)),
      context.queryClient.ensureQueryData(dayClosesQuery(organization.id, period)),
    ])
    await context.queryClient.ensureQueryData(
      cashMovementsQuery(organization.id, period, knownStorage(storages, deps.storageId)),
    )
  },
  component: CashFlowPage,
})

function periodOf(search: CashFlowSearch, fallback: BusinessPeriod): BusinessPeriod {
  return search.from && search.to ? { from: search.from, to: search.to } : fallback
}

const ALL_STORAGES = "all"

/** The storage to filter by: one of the organization's (deactivated ones too), else none. */
function knownStorage(storages: readonly CashStorage[], storageId: string | undefined): string | undefined {
  return storages.some((storage) => storage.id === storageId) ? storageId : undefined
}

function CashFlowPage() {
  const { membership } = Route.useRouteContext()
  const organizationId = membership.organization.id
  const { t } = useTranslation("admin")
  const format = useDateTimeFormat()
  const money = useMoneyFormat()
  const search = Route.useSearch()
  const navigate = useNavigate({ from: Route.fullPath })
  const period = periodOf(search, format.thisMonth())
  const { data: storages } = useSuspenseQuery(cashStoragesQuery(organizationId))
  const storageId = knownStorage(storages, search.storageId)
  const { data: balances } = useSuspenseQuery(cashBalancesQuery(organizationId))
  const { data: denominations } = useSuspenseQuery(denominationsQuery(organizationId))
  const { data: movements } = useSuspenseQuery(cashMovementsQuery(organizationId, period, storageId))
  const { data: closes } = useSuspenseQuery(dayClosesQuery(organizationId, period))
  const history = cashFlowHistory(movements, closes, storageId)
  const [dialog, setDialog] = useState<CashMovementDialogState | null>(null)
  const [deleting, setDeleting] = useState<CashMovement | null>(null)
  const firstStorage = balances.find((balance) => balance.active)?.storageId ?? ""
  const firstDeposit = balances.find((balance) => balance.active && balance.mode === "deposit")?.storageId
  const filtered = storages.find((storage) => storage.id === storageId)

  function setPeriod(next: BusinessPeriod) {
    // This month is the default, so it stays out of the URL.
    const current = format.thisMonth()
    const isCurrent = next.from === current.from && next.to === current.to
    void navigate({ search: (prev) => ({ ...prev, from: isCurrent ? undefined : next.from, to: isCurrent ? undefined : next.to }) })
  }

  function setStorage(storageId: string) {
    void navigate({ search: (prev) => ({ ...prev, storageId: storageId === ALL_STORAGES ? undefined : storageId }) })
  }

  return (
    <div className="flex flex-col gap-6">
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div className="flex min-w-0 flex-col gap-3">
          <CashFlowNav />
          <div>
            <h1 className="text-2xl font-semibold tracking-tight">{t("cashFlow.title")}</h1>
            <p className="text-sm text-muted-foreground">{t("cashFlow.description")}</p>
          </div>
        </div>
        <div className="flex flex-wrap gap-2">
          <Button onClick={() => setDialog({ kind: "adjustment", storageId: firstStorage })} disabled={balances.length === 0}>
            <PlusIcon />
            {t("cashFlow.actions.adjust")}
          </Button>
          <Button
            variant="outline"
            onClick={() => setDialog({ kind: "transfer", storageId: firstStorage })}
            disabled={balances.filter((balance) => balance.active).length < 2}
          >
            <ArrowRightLeftIcon />
            {t("cashFlow.actions.transfer")}
          </Button>
          <Button
            variant="outline"
            onClick={() => firstDeposit && setDialog({ kind: "count", storageId: firstDeposit })}
            disabled={!firstDeposit}
          >
            <ClipboardCheckIcon />
            {t("cashFlow.actions.count")}
          </Button>
        </div>
      </div>

      <section aria-labelledby="balances-title" className="flex flex-col gap-3">
        <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
          <h2 id="balances-title" className="text-lg font-semibold">
            {t("cashFlow.balances.title")}
          </h2>
          {balances.length > 0 && (
            <p className="text-sm text-muted-foreground">
              {t("cashFlow.balances.onHand", { amount: money(cashOnHand(balances)) })}
            </p>
          )}
        </div>
        {balances.length === 0 ? (
          <Card>
            <CardContent className="flex flex-wrap items-center justify-between gap-3 text-sm text-muted-foreground">
              {t("cashFlow.balances.none")}
              <Button asChild variant="outline" size="sm">
                <Link to="/cash-flow/storages">{t("cashFlow.balances.addStorage")}</Link>
              </Button>
            </CardContent>
          </Card>
        ) : (
          <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            {balances.map((balance) => (
              <BalanceCard
                key={balance.storageId}
                balance={balance}
                canTransfer={balances.some((other) => other.active && other.storageId !== balance.storageId)}
                onAction={(kind, direction) => setDialog({ kind, storageId: balance.storageId, direction })}
              />
            ))}
          </div>
        )}
      </section>

      <Card className="gap-0 pb-0">
        <CardHeader className="flex flex-wrap items-start justify-between gap-4 pb-4">
          <div className="flex flex-col gap-1.5">
            <CardTitle>
              {t("cashFlow.history.title", {
                count: history.length,
                context: isWholeMonth(period) ? "month" : undefined,
                period: format.period(period),
              })}
            </CardTitle>
            <CardDescription>
              {filtered ? t("cashFlow.history.ofStorage", { name: filtered.name }) : t("cashFlow.history.description")}
            </CardDescription>
          </div>
          <div className="flex flex-wrap items-center gap-2">
            <Select value={storageId ?? ALL_STORAGES} onValueChange={setStorage}>
              <SelectTrigger className="w-44" aria-label={t("cashFlow.history.storage")}>
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value={ALL_STORAGES}>{t("cashFlow.history.allStorages")}</SelectItem>
                {storages.map((storage) => (
                  <SelectItem key={storage.id} value={storage.id}>
                    {storage.name}
                    {!storage.active && ` (${t("cashFlow.balances.deactivated")})`}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
            <PeriodPicker value={period} onChange={setPeriod} maxDays={CASH_MOVEMENTS_MAX_DAYS} />
          </div>
        </CardHeader>
        <CardContent className="px-0">
          {history.length === 0 ? (
            <p className="px-6 pb-6 text-sm text-muted-foreground">{t("cashFlow.history.none")}</p>
          ) : (
            <ul className="divide-y border-t">
              {history.map((entry) => (
                <HistoryRow key={`${entry.type}-${entry.id}`} entry={entry} storageId={storageId} onDelete={setDeleting} />
              ))}
            </ul>
          )}
        </CardContent>
      </Card>

      <CashMovementDialog
        organizationId={organizationId}
        state={dialog}
        onClose={() => setDialog(null)}
        balances={balances}
        denominations={denominations}
      />
      <DeleteMovementDialog organizationId={organizationId} movement={deleting} onClose={() => setDeleting(null)} />
    </div>
  )
}

function BalanceCard({
  balance,
  canTransfer,
  onAction,
}: {
  balance: CashBalance
  canTransfer: boolean
  onAction: (kind: CashMovementDialogState["kind"], direction?: CashMovementDialogState["direction"]) => void
}) {
  const { t } = useTranslation("admin")
  const format = useDateTimeFormat()
  const money = useMoneyFormat()
  const held = heldLines(balance)
  const retired = retiredLines(balance)

  return (
    <Card className="gap-4">
      <CardHeader className="flex items-start justify-between gap-2">
        <div className="flex min-w-0 flex-col gap-1.5">
          <CardTitle className="flex flex-wrap items-center gap-2">
            <span className="break-words">{balance.name}</span>
            <Badge variant="secondary">{t(`dayCloses.mode.${balance.mode}`)}</Badge>
            {!balance.active && <Badge variant="outline">{t("cashFlow.balances.deactivated")}</Badge>}
          </CardTitle>
          <CardDescription>
            {balance.countedAt
              ? t("cashFlow.balances.countedAt", { when: format.dateTime(balance.countedAt) })
              : t("cashFlow.balances.neverCounted")}
          </CardDescription>
        </div>
        <DropdownMenu>
          <DropdownMenuTrigger asChild>
            <Button variant="ghost" size="icon" className="-mr-2 -mt-1 shrink-0" aria-label={t("cashFlow.balances.actionsFor", { name: balance.name })}>
              <MoreHorizontalIcon />
            </Button>
          </DropdownMenuTrigger>
          <DropdownMenuContent align="end">
            {balance.active && (
              <DropdownMenuItem onSelect={() => onAction("adjustment", "add")}>
                <PlusIcon />
                {t("cashFlow.form.add")}
              </DropdownMenuItem>
            )}
            <DropdownMenuItem onSelect={() => onAction("adjustment", "remove")}>
              <MinusIcon />
              {t("cashFlow.form.remove")}
            </DropdownMenuItem>
            {canTransfer && (
              <DropdownMenuItem onSelect={() => onAction("transfer")}>
                <ArrowRightLeftIcon />
                {t("cashFlow.actions.transferFrom")}
              </DropdownMenuItem>
            )}
            {balance.active && balance.mode === "deposit" && (
              <>
                <DropdownMenuSeparator />
                <DropdownMenuItem onSelect={() => onAction("count")}>
                  <ClipboardCheckIcon />
                  {t("cashFlow.actions.count")}
                </DropdownMenuItem>
              </>
            )}
          </DropdownMenuContent>
        </DropdownMenu>
      </CardHeader>
      <CardContent className="flex flex-col gap-3">
        <div>
          <p className="text-2xl font-semibold tabular-nums">
            {balance.estimate && <span className="sr-only">{t("cashFlow.balances.estimate")}: </span>}
            {balance.estimate && (
              <span aria-hidden className="text-muted-foreground">
                ≈{" "}
              </span>
            )}
            {money(balance.total)}
          </p>
          {balance.estimate && <p className="text-sm text-muted-foreground">{t("cashFlow.balances.estimateHint")}</p>}
        </div>
        {retired.length > 0 && (
          <div className="flex flex-col items-start gap-2 rounded-md border border-amber-600/30 bg-amber-50 p-3 text-sm text-amber-900 dark:bg-amber-950/40 dark:text-amber-200">
            <p className="flex gap-2">
              <TriangleAlertIcon className="mt-0.5 size-4 shrink-0" aria-hidden />
              {t("cashFlow.balances.retiredHint", {
                denominations: retired.map((line) => `${line.pieces} × ${money(line.value)}`).join(", "),
              })}
            </p>
            <Button size="sm" variant="outline" onClick={() => onAction("adjustment", "remove")}>
              <MinusIcon />
              {t("cashFlow.balances.takeOut")}
            </Button>
          </div>
        )}
        {held.length > 0 && (
          <Collapsible>
            <CollapsibleTrigger asChild>
              <Button variant="ghost" size="sm" className="group -ml-2 w-fit text-muted-foreground">
                <ChevronRightIcon className="transition-transform group-data-[state=open]:rotate-90" />
                {t("cashFlow.balances.breakdown", { count: held.length })}
              </Button>
            </CollapsibleTrigger>
            <CollapsibleContent>
              <dl className="grid grid-cols-[auto_1fr_auto] gap-x-4 gap-y-1 pt-2 text-sm tabular-nums">
                {held.map((line) => (
                  <div key={line.denominationId} className="contents">
                    <dt className="font-medium">
                      {money(line.value)}
                      {!line.active && <span className="sr-only"> ({t("cashFlow.balances.retired")})</span>}
                      {!line.active && <TriangleAlertIcon className="ml-1 inline size-3.5 text-amber-600" aria-hidden />}
                    </dt>
                    <dd className={cn("text-muted-foreground", line.pieces < 0 && "text-amber-700 dark:text-amber-400")}>
                      × {line.pieces}
                    </dd>
                    <dd className="text-right">{money(line.amount)}</dd>
                  </div>
                ))}
              </dl>
              {balance.estimate && held.some((line) => line.pieces < 0) && (
                <p className="pt-2 text-xs text-muted-foreground">{t("cashFlow.balances.negativeEstimate")}</p>
              )}
            </CollapsibleContent>
          </Collapsible>
        )}
      </CardContent>
    </Card>
  )
}

function HistoryRow({
  entry,
  storageId,
  onDelete,
}: {
  entry: HistoryEntry
  storageId: string | undefined
  onDelete: (movement: CashMovement) => void
}) {
  const { t } = useTranslation("admin")
  const format = useDateTimeFormat()
  const money = useMoneyFormat()

  if (entry.type === "close") {
    const { close, storages } = entry
    return (
      <li className="flex flex-wrap items-start justify-between gap-x-4 gap-y-2 px-6 py-3">
        <div className="flex min-w-0 flex-col gap-0.5">
          <div className="flex flex-wrap items-center gap-2">
            <Badge variant="secondary">{t("cashFlow.kind.close")}</Badge>
            <Link
              to="/cash-flow/closes/$closeId"
              params={{ closeId: close.id }}
              className="font-medium underline-offset-4 hover:underline"
            >
              {t("dayCloses.detail.title", { date: format.businessDate(close.date, LONG_DATE) })}
            </Link>
          </div>
          <p className="text-sm text-muted-foreground">
            {t("cashFlow.history.by", { when: format.dateTime(close.closedAt), name: `${close.closedByFirstName} ${close.closedByLastName}` })}
          </p>
          <ul className="text-sm">
            {storages.map((storage) => (
              <li key={storage.storageId}>
                {storage.mode === "counted"
                  ? t("cashFlow.history.closeCounted", {
                      name: storage.name,
                      amount: money(storage.total),
                      expected: money(storage.expectedOpening ?? 0),
                    })
                  : t("cashFlow.history.closeDeposited", { name: storage.name, amount: money(storage.total) })}
              </li>
            ))}
          </ul>
        </div>
        <div className="ml-auto text-right">
          <p className={cn("font-semibold tabular-nums", close.shortage && "text-amber-700 dark:text-amber-400")}>
            {close.shortage && <TriangleAlertIcon className="mr-1 inline size-4 align-[-2px]" aria-hidden />}
            {money(close.cashIncome)}
          </p>
          <p className="text-xs text-muted-foreground">{t(close.shortage ? "dayCloses.shortage" : "dayCloses.cashIncome")}</p>
        </div>
      </li>
    )
  }

  const { movement } = entry
  const amount = movementAmount(movement, storageId)
  const title =
    movement.kind === "transfer"
      ? t("cashFlow.history.transfer", { from: movement.storageName, to: movement.targetStorageName ?? "" })
      : movement.kind === "count"
        ? t("cashFlow.history.count", { name: movement.storageName })
        : movement.total < 0
          ? t("cashFlow.history.removed", { name: movement.storageName })
          : t("cashFlow.history.added", { name: movement.storageName })

  return (
    <li className="flex flex-wrap items-start justify-between gap-x-4 gap-y-2 px-6 py-3">
      <div className="flex min-w-0 flex-1 flex-col gap-0.5">
        <div className="flex flex-wrap items-center gap-2">
          <Badge variant="outline">{t(`cashFlow.kind.${movement.kind}`)}</Badge>
          <span className="font-medium break-words">{title}</span>
        </div>
        <p className="text-sm text-muted-foreground">
          {t("cashFlow.history.by", {
            when: format.dateTime(movement.occurredAt),
            name: `${movement.createdByFirstName} ${movement.createdByLastName}`,
          })}
          {enteredLater(movement) && ` ${t("cashFlow.history.enteredAt", { when: format.dateTime(movement.createdAt) })}`}
        </p>
        {movement.note && <p className="text-sm break-words">{movement.note}</p>}
        <Collapsible>
          <CollapsibleTrigger asChild>
            <Button variant="ghost" size="sm" className="group -ml-2 h-7 w-fit text-muted-foreground">
              <ChevronRightIcon className="transition-transform group-data-[state=open]:rotate-90" />
              {t("cashFlow.history.pieces", { count: movement.lines.filter((line) => line.pieces !== 0).length })}
            </Button>
          </CollapsibleTrigger>
          <CollapsibleContent>
            <p className="text-sm text-muted-foreground tabular-nums">
              {movement.lines
                .filter((line) => line.pieces !== 0)
                .map((line) => `${Math.abs(line.pieces)} × ${money(line.value)}`)
                .join(" · ")}
            </p>
          </CollapsibleContent>
        </Collapsible>
      </div>
      <div className="ml-auto flex items-start gap-1">
        <div className="text-right">
          <p
            className={cn(
              "font-semibold tabular-nums",
              movement.kind !== "count" && amount < 0 && "text-muted-foreground",
            )}
          >
            {movement.kind === "adjustment" || (movement.kind === "transfer" && storageId) ? signed(money, amount) : money(amount)}
          </p>
          {movement.discrepancy !== null && (
            <p
              className={cn(
                "text-xs tabular-nums",
                movement.discrepancy === 0 ? "text-muted-foreground" : "text-amber-700 dark:text-amber-400",
              )}
            >
              {movement.discrepancy === 0
                ? t("cashFlow.history.noDiscrepancy")
                : t("cashFlow.history.discrepancy", { amount: signed(money, movement.discrepancy) })}
            </p>
          )}
        </div>
        <Button
          variant="ghost"
          size="icon"
          className="-mr-2 -mt-1 size-8 text-muted-foreground"
          aria-label={t("cashFlow.delete.label", { title })}
          onClick={() => onDelete(movement)}
        >
          <Trash2Icon />
        </Button>
      </div>
    </li>
  )
}

/** "+120,00 zł" for an amount added, "−120,00 zł" for one taken out. */
function signed(money: (minor: number) => string, amount: number): string {
  return amount > 0 ? `+${money(amount)}` : money(amount)
}

/** Confirms deleting a movement, saying which close's expected opening (and booked cash) it changes. */
function DeleteMovementDialog({
  organizationId,
  movement,
  onClose,
}: {
  organizationId: string
  movement: CashMovement | null
  onClose: () => void
}) {
  const { t } = useTranslation("admin")
  const format = useDateTimeFormat()
  const queryClient = useQueryClient()
  // Kept while the dialog closes, so its text doesn't change during the animation.
  const [shown, setShown] = useState(movement)
  if (movement && movement !== shown) setShown(movement)
  const period = shown ? closesAfterPeriod(shown, format.timeZone, format.today()) : null
  const closes = useQuery({
    ...dayClosesQuery(organizationId, period ?? { from: "", to: "" }),
    enabled: movement !== null && period !== null,
  })
  const affected = shown && closes.data ? affectedCloses(shown, closes.data) : []

  const remove = useMutation({
    mutationFn: (id: string) => unwrap(api.DELETE("/api/cash-movements/{id}", { params: { path: { id } } })),
    onSuccess: async () => {
      onClose()
      toast.success(t("cashFlow.delete.done"))
      await invalidateCashLedger(queryClient, organizationId)
    },
    onError: (error) => {
      onClose()
      toast.error(t("cashFlow.delete.failed"), { description: requestErrorMessage(error, { format }) })
    },
  })

  return (
    <AlertDialog open={movement !== null} onOpenChange={(open) => !open && onClose()}>
      <AlertDialogContent>
        <AlertDialogHeader>
          <AlertDialogTitle>{t("cashFlow.delete.title")}</AlertDialogTitle>
          <AlertDialogDescription>
            {shown &&
              t("cashFlow.delete.what", {
                kind: t(`cashFlow.kind.${shown.kind}`),
                name: shown.targetStorageName ? `${shown.storageName} → ${shown.targetStorageName}` : shown.storageName,
                when: format.dateTime(shown.occurredAt),
              })}{" "}
            {closes.isPending
              ? t("cashFlow.delete.checkingCloses")
              : closes.isError
                ? t("cashFlow.delete.closesUnknown")
                : affected.length === 0
                  ? t("cashFlow.delete.noClose")
                  : t("cashFlow.delete.changesClose", {
                      count: affected.length,
                      dates: affected.map((close) => format.businessDate(close.date)).join(", "),
                    })}
          </AlertDialogDescription>
        </AlertDialogHeader>
        <AlertDialogFooter>
          <AlertDialogCancel disabled={remove.isPending}>{t("common.cancel")}</AlertDialogCancel>
          <AlertDialogAction
            variant="destructive"
            disabled={remove.isPending}
            onClick={(event) => {
              event.preventDefault()
              if (shown) remove.mutate(shown.id)
            }}
          >
            {remove.isPending && <LoaderCircleIcon className="animate-spin" />}
            {remove.isPending ? t("common.deleting") : t("common.delete")}
          </AlertDialogAction>
        </AlertDialogFooter>
      </AlertDialogContent>
    </AlertDialog>
  )
}
