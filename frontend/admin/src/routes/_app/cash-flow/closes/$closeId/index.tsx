import { useState } from "react"
import { useMutation, useQuery, useQueryClient, useSuspenseQuery } from "@tanstack/react-query"
import { Link, createFileRoute, useNavigate } from "@tanstack/react-router"
import { ArrowLeftIcon, PencilIcon, Trash2Icon, TriangleAlertIcon } from "lucide-react"
import { useTranslation } from "react-i18next"
import { toast } from "sonner"

import { unwrap } from "@business-tool/api-client"
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
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@business-tool/ui/components/card"
import { Table, TableBody, TableCell, TableFooter, TableHead, TableHeader, TableRow } from "@business-tool/ui/components/table"
import { OrganizationCurrencyContext, useMoneyFormat } from "@business-tool/ui/hooks/use-organization-currency"
import { useDateTimeFormat } from "@business-tool/ui/hooks/use-organization-time-zone"
import { monthOf } from "@business-tool/ui/lib/date-time"
import { requestErrorMessage } from "@business-tool/ui/lib/request-error"
import { cn } from "@business-tool/ui/lib/utils"

import { requireCapability } from "@/lib/access"
import { api } from "@/lib/api"
import { type DayClose, type DayCloseStorageItem, LONG_DATE, bookedCashOf, nonCashTotalOf } from "@/lib/day-closes"
import { dayCloseQuery, invalidateDayCloses, revenueEntriesQuery } from "@/lib/queries"

/*
 * One end-of-day close (ADR 0033 §6, §7): each storage's count against its expected opening, the
 * deposits, the non-cash amounts, the cash income (a shortage flagged), who closed and corrected it,
 * and the revenue entry it booked. Owners and managers correct or delete it from here.
 */
export const Route = createFileRoute("/_app/cash-flow/closes/$closeId/")({
  beforeLoad: requireCapability("cashFlow"),
  loader: async ({ context, params }) => {
    await context.queryClient.ensureQueryData(dayCloseQuery(context.membership.organization.id, params.closeId))
  },
  component: DayClosePage,
})

function DayClosePage() {
  const { membership } = Route.useRouteContext()
  const { closeId } = Route.useParams()
  const { data: close } = useSuspenseQuery(dayCloseQuery(membership.organization.id, closeId))

  return (
    <OrganizationCurrencyContext value={close.currency}>
      <DayCloseDetails organizationId={membership.organization.id} close={close} />
    </OrganizationCurrencyContext>
  )
}

function DayCloseDetails({ organizationId, close }: { organizationId: string; close: DayClose }) {
  const { t } = useTranslation("admin")
  const format = useDateTimeFormat()
  const money = useMoneyFormat()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [confirmDelete, setConfirmDelete] = useState(false)
  const month = monthOf(close.date)
  const date = format.businessDate(close.date, LONG_DATE)

  const remove = useMutation({
    mutationFn: () => unwrap(api.DELETE("/api/day-closes/{id}", { params: { path: { id: close.id } } })),
    onSuccess: async () => {
      setConfirmDelete(false)
      toast.success(t("dayCloses.delete.done", { date }))
      await navigate({ to: "/cash-flow/closes", search: { from: month.from, to: month.to } })
      // After leaving the page, so the deleted close is not fetched again.
      void invalidateDayCloses(queryClient, organizationId)
    },
    onError: (error) => {
      setConfirmDelete(false)
      toast.error(t("dayCloses.delete.failed"), { description: requestErrorMessage(error, { format }) })
    },
  })

  return (
    <div className="flex flex-col gap-6">
      <div className="flex flex-col gap-3">
        <Button asChild variant="ghost" size="sm" className="-ml-2 w-fit">
          <Link to="/cash-flow/closes" search={{ from: month.from, to: month.to }}>
            <ArrowLeftIcon />
            {t("dayCloses.backToList")}
          </Link>
        </Button>
        <div className="flex flex-wrap items-end justify-between gap-4">
          <div>
            <h1 className="text-2xl font-semibold tracking-tight">{t("dayCloses.detail.title", { date })}</h1>
            <p className="text-sm text-muted-foreground">
              {t("dayCloses.detail.closedBy", {
                register: close.registerName ?? t("dayCloses.unknownRegister"),
                name: `${close.closedByFirstName} ${close.closedByLastName}`,
                when: format.dateTime(close.closedAt),
              })}
              {close.updatedAt &&
                ` ${t("dayCloses.detail.correctedBy", {
                  name: `${close.updatedByFirstName ?? ""} ${close.updatedByLastName ?? ""}`.trim(),
                  when: format.dateTime(close.updatedAt),
                })}`}
            </p>
          </div>
          <div className="flex flex-wrap gap-2">
            <Button asChild variant="outline">
              <Link to="/cash-flow/closes/$closeId/correct" params={{ closeId: close.id }}>
                <PencilIcon />
                {t("dayCloses.correct.action")}
              </Link>
            </Button>
            <Button variant="outline" onClick={() => setConfirmDelete(true)} disabled={remove.isPending}>
              <Trash2Icon />
              {t("common.delete")}
            </Button>
          </div>
        </div>
      </div>

      {close.shortage && (
        <Alert className="border-amber-300 bg-amber-50 text-amber-900 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-200">
          <TriangleAlertIcon />
          <AlertTitle>{t("dayCloses.shortageTitle", { amount: money(-close.cashIncome) })}</AlertTitle>
          <AlertDescription className="text-inherit">{t("dayCloses.shortageDescription")}</AlertDescription>
        </Alert>
      )}

      <div className="grid gap-4 md:grid-cols-3">
        <Card className="gap-2">
          <CardHeader>
            <CardDescription>{t("dayCloses.cashIncome")}</CardDescription>
            <CardTitle className={cn("text-3xl tabular-nums", close.shortage && "text-amber-700 dark:text-amber-400")}>
              {money(close.cashIncome)}
            </CardTitle>
          </CardHeader>
          <CardContent className="text-sm text-muted-foreground">
            {t("dayCloses.detail.bookedAsCash", { amount: money(bookedCashOf(close.cashIncome)) })}
          </CardContent>
        </Card>
        <Card className="gap-2">
          <CardHeader>
            <CardDescription>{t("dayCloses.nonCash")}</CardDescription>
            <CardTitle className="text-3xl tabular-nums">{money(nonCashTotalOf(close))}</CardTitle>
          </CardHeader>
          <CardContent>
            {close.payments.length === 0 ? (
              <p className="text-sm text-muted-foreground">{t("dayCloses.detail.noNonCash")}</p>
            ) : (
              <dl className="flex flex-col gap-1 text-sm">
                {close.payments.map((line) => (
                  <div key={line.paymentMethodId} className="flex justify-between gap-3">
                    <dt className="min-w-0 break-words">{line.name ?? t("dayCloses.unknownMethod")}</dt>
                    <dd className="shrink-0 tabular-nums">{money(line.amount)}</dd>
                  </div>
                ))}
              </dl>
            )}
          </CardContent>
        </Card>
        <RevenueEntryCard organizationId={organizationId} close={close} />
      </div>

      <div className="grid gap-4 lg:grid-cols-2">
        {close.storages.map((storage) => (
          <StorageCard key={storage.storageId} storage={storage} />
        ))}
      </div>

      <AlertDialog open={confirmDelete} onOpenChange={setConfirmDelete}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>{t("dayCloses.delete.title", { date })}</AlertDialogTitle>
            <AlertDialogDescription>{t("dayCloses.delete.description")}</AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>{t("common.cancel")}</AlertDialogCancel>
            <AlertDialogAction
              variant="destructive"
              disabled={remove.isPending}
              onClick={(event) => {
                // Stays open until the API answers.
                event.preventDefault()
                remove.mutate()
              }}
            >
              {remove.isPending ? t("common.deleting") : t("dayCloses.delete.confirm")}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  )
}

/** The revenue entry the close booked, found among the entries of its date by its close id (ADR 0033 §9). */
function RevenueEntryCard({ organizationId, close }: { organizationId: string; close: DayClose }) {
  const { t } = useTranslation("admin")
  const money = useMoneyFormat()
  const day = { from: close.date, to: close.date }
  const entries = useQuery(revenueEntriesQuery(organizationId, day))
  const entry = entries.data?.find((candidate) => candidate.dayCloseId === close.id)

  return (
    <Card className="gap-2">
      <CardHeader>
        <CardDescription>{t("dayCloses.detail.revenueEntry")}</CardDescription>
        <CardTitle className="text-3xl tabular-nums">{entry ? money(entry.paymentsTotal) : "—"}</CardTitle>
      </CardHeader>
      <CardContent className="flex flex-col items-start gap-2 text-sm text-muted-foreground">
        {entries.isPending ? (
          t("common.loading")
        ) : entry ? (
          <>
            <span>{t("dayCloses.detail.revenueEntryOf", { register: entry.registerName })}</span>
            <Link to="/income" search={day} className="font-medium text-foreground underline underline-offset-4">
              {t("dayCloses.detail.openInIncome")}
            </Link>
          </>
        ) : (
          t("dayCloses.detail.noRevenueEntry")
        )}
      </CardContent>
    </Card>
  )
}

/** A storage at the close: a counted one against its expected opening, a deposit one with what was put in. */
function StorageCard({ storage }: { storage: DayCloseStorageItem }) {
  const { t } = useTranslation("admin")
  const money = useMoneyFormat()
  const counted = storage.mode === "counted"
  // A counted storage has a line for every denomination; the ones with no pieces are left out.
  const lines = storage.lines.filter((line) => line.pieces > 0)

  return (
    <Card className="gap-0 pb-0">
      <CardHeader className="pb-4">
        <CardTitle className="flex items-center gap-2">
          <span className="break-words">{storage.name}</span>
          <Badge variant="secondary">{t(`dayCloses.mode.${storage.mode}`)}</Badge>
        </CardTitle>
        <dl className="mt-2 grid grid-cols-[1fr_auto] gap-x-4 gap-y-1 text-sm">
          {counted && (
            <>
              <dt className="text-muted-foreground">{t("dayCloses.detail.expectedOpening")}</dt>
              <dd className="text-right tabular-nums">{money(storage.expectedOpening ?? 0)}</dd>
            </>
          )}
          <dt className="text-muted-foreground">{t(counted ? "dayCloses.detail.counted" : "dayCloses.detail.deposited")}</dt>
          <dd className="text-right tabular-nums">{money(storage.total)}</dd>
          <dt className="font-medium">{t("dayCloses.detail.storageCashIncome")}</dt>
          <dd className={cn("text-right font-medium tabular-nums", storage.cashIncome < 0 && "text-amber-700 dark:text-amber-400")}>
            {money(storage.cashIncome)}
          </dd>
        </dl>
      </CardHeader>
      <CardContent className="px-0">
        {lines.length === 0 ? (
          <p className="border-t px-6 py-4 text-sm text-muted-foreground">
            {t(counted ? "dayCloses.detail.nothingCounted" : "dayCloses.detail.nothingDeposited")}
          </p>
        ) : (
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead className="pl-6">{t("dayCloses.denomination")}</TableHead>
                <TableHead className="text-right">{t("dayCloses.pieces")}</TableHead>
                <TableHead className="pr-6 text-right">{t("dayCloses.amount")}</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {lines.map((line) => (
                <TableRow key={line.denominationId}>
                  <TableCell className="pl-6 tabular-nums">{money(line.value)}</TableCell>
                  <TableCell className="text-right tabular-nums">{line.pieces}</TableCell>
                  <TableCell className="pr-6 text-right tabular-nums">{money(line.amount)}</TableCell>
                </TableRow>
              ))}
            </TableBody>
            <TableFooter>
              <TableRow>
                <TableCell className="pl-6" colSpan={2}>
                  {t("dayCloses.total")}
                </TableCell>
                <TableCell className="pr-6 text-right tabular-nums">{money(storage.total)}</TableCell>
              </TableRow>
            </TableFooter>
          </Table>
        )}
      </CardContent>
    </Card>
  )
}
