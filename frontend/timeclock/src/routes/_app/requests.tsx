import { useQuery } from "@tanstack/react-query"
import { Link, createFileRoute } from "@tanstack/react-router"
import { ArrowLeftIcon, CircleAlertIcon, LoaderCircleIcon } from "lucide-react"
import { Trans, useTranslation } from "react-i18next"

import { Alert, AlertDescription } from "@business-tool/ui/components/alert"
import { Badge } from "@business-tool/ui/components/badge"
import { Button } from "@business-tool/ui/components/button"
import { Skeleton } from "@business-tool/ui/components/skeleton"
import { useDateTimeFormat } from "@business-tool/ui/hooks/use-organization-time-zone"
import { browserTimeZone } from "@business-tool/ui/lib/date-time"
import { requestErrorMessage } from "@business-tool/ui/lib/request-error"
import { cn } from "@business-tool/ui/lib/utils"

import { WithdrawRequestButton } from "@/components/withdraw-request-dialog"
import { myShiftChangeRequestsQuery } from "@/lib/queries"
import {
  type ShiftChangeRequest,
  canWithdraw,
  previousTimes,
  requestTitle,
  requestWhen,
  statusLabel,
  timeRange,
} from "@/lib/shift-change-requests"

export const Route = createFileRoute("/_app/requests")({
  component: MyRequestsScreen,
})

/** The signed-in person's requests to change their shifts, newest first, with how they were decided. */
function MyRequestsScreen() {
  const { t } = useTranslation("timeclock")
  const { me, membership } = Route.useRouteContext()
  const format = useDateTimeFormat()

  // The layout shows "no organization" instead of this screen.
  if (!membership) return null

  return (
    <main className="mx-auto flex w-full max-w-sm flex-1 flex-col gap-3 px-4 pb-4">
      <div className="flex items-center gap-1">
        <Button asChild variant="ghost" size="icon" className="-ml-2 size-11">
          <Link to="/shifts" aria-label={t("requests.backToShifts")}>
            <ArrowLeftIcon />
          </Link>
        </Button>
        <h1 className="text-lg font-semibold">{t("requests.title")}</h1>
      </div>

      <Requests organizationId={membership.organization.id} userId={me.id} />

      {format.timeZone !== browserTimeZone() && (
        <p className="text-center text-xs text-muted-foreground">{t("common.timesIn", { timeZone: format.timeZone.replaceAll("_", " ") })}</p>
      )}
    </main>
  )
}

function Requests({ organizationId, userId }: { organizationId: string; userId: string }) {
  const { t } = useTranslation("timeclock")
  const requests = useQuery(myShiftChangeRequestsQuery(organizationId, userId))

  if (requests.isPending) {
    return (
      <div className="flex flex-col gap-2" aria-busy>
        <Skeleton className="h-24 w-full rounded-xl" />
        <Skeleton className="h-24 w-full rounded-xl" />
      </div>
    )
  }

  if (requests.isError) {
    return (
      <div className="flex flex-col gap-3">
        <Alert variant="destructive">
          <CircleAlertIcon />
          <AlertDescription>{requestErrorMessage(requests.error)}</AlertDescription>
        </Alert>
        <Button variant="outline" className="h-11" onClick={() => requests.refetch()} disabled={requests.isFetching}>
          {requests.isFetching && <LoaderCircleIcon className="animate-spin" />}
          {t("common.tryAgain")}
        </Button>
      </div>
    )
  }

  if (requests.data.length === 0) {
    return (
      <p className="py-6 text-center text-muted-foreground">
        <Trans
          t={t}
          i18nKey="requests.none"
          components={{ shifts: <Link to="/shifts" className="underline underline-offset-4" /> }}
        />
      </p>
    )
  }

  return (
    <ul className="flex flex-col gap-2">
      {requests.data.map((request) => (
        <RequestItem key={request.id} organizationId={organizationId} request={request} />
      ))}
    </ul>
  )
}

const STATUS_CLASS: Record<ShiftChangeRequest["status"], string> = {
  pending: "bg-amber-100 text-amber-900 dark:bg-amber-950 dark:text-amber-200",
  approved: "bg-green-100 text-green-900 dark:bg-green-950 dark:text-green-200",
  rejected: "bg-destructive/10 text-destructive dark:bg-destructive/20",
  withdrawn: "bg-muted text-muted-foreground",
}

function RequestItem({ organizationId, request }: { organizationId: string; request: ShiftChangeRequest }) {
  const { t } = useTranslation("timeclock")
  const format = useDateTimeFormat()
  const before = previousTimes(format, request)

  return (
    <li className="rounded-xl border bg-card px-4 py-3">
      <div className="flex items-center justify-between gap-3">
        <span className="font-medium">{requestTitle(request)}</span>
        <Badge className={cn("border-transparent", STATUS_CLASS[request.status])}>{statusLabel(request.status)}</Badge>
      </div>
      <div className="text-sm tabular-nums">{timeRange(format, request.startedAt, request.endedAt)}</div>
      {before && <div className="text-sm tabular-nums text-muted-foreground">{before}</div>}
      <p className="mt-1 text-sm break-words text-muted-foreground">{request.note}</p>
      {request.status === "rejected" && request.rejectionReason && (
        <p className="mt-1 text-sm break-words text-destructive">{t("requests.rejectionReason", { reason: request.rejectionReason })}</p>
      )}
      <div className="mt-1 flex items-center justify-between gap-3">
        <span className="text-xs text-muted-foreground">{requestWhen(format, request)}</span>
        {canWithdraw(request) && <WithdrawRequestButton organizationId={organizationId} request={request} />}
      </div>
    </li>
  )
}
