import { useState } from "react"
import { useSuspenseQuery } from "@tanstack/react-query"
import { createFileRoute, useNavigate } from "@tanstack/react-router"
import { CheckIcon, XIcon } from "lucide-react"
import { useTranslation } from "react-i18next"

import { Badge } from "@business-tool/ui/components/badge"
import { Button } from "@business-tool/ui/components/button"
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@business-tool/ui/components/card"
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@business-tool/ui/components/select"
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@business-tool/ui/components/table"
import { useDateTimeFormat } from "@business-tool/ui/hooks/use-organization-time-zone"

import { type DecisionTarget, ShiftChangeRequestDialog } from "@/components/shift-change-request-dialog"
import { requireCapability } from "@/lib/access"
import { isActive, memberNames } from "@/lib/members"
import { membersQuery, shiftChangeRequestsQuery } from "@/lib/queries"
import {
  type RequestsFilter,
  type RequestsSearch,
  type ShiftChangeRequest,
  changeSummary,
  filterOf,
  REQUEST_FILTERS,
  requestsFilterLabel,
  timeRange,
  validateRequestsSearch,
} from "@/lib/shift-change-requests"

export const Route = createFileRoute("/_app/shift-requests")({
  validateSearch: (search: Record<string, unknown>): RequestsSearch => validateRequestsSearch(search),
  beforeLoad: requireCapability("shiftRequests"),
  loaderDeps: ({ search }) => ({ status: search.status }),
  loader: async ({ context, deps }) => {
    const organizationId = context.membership.organization.id
    await Promise.all([
      context.queryClient.ensureQueryData(membersQuery(organizationId, true)),
      context.queryClient.ensureQueryData(shiftChangeRequestsQuery(organizationId, filterOf(deps))),
    ])
  },
  component: ShiftRequestsPage,
})

function ShiftRequestsPage() {
  const { membership } = Route.useRouteContext()

  return <ShiftRequests organizationId={membership.organization.id} />
}

/** Who decided a request: a name, or null for the signed-in user. */
type NameOf = (userId: string) => string | null

function ShiftRequests({ organizationId }: { organizationId: string }) {
  const { me } = Route.useRouteContext()
  const format = useDateTimeFormat()
  const search = Route.useSearch()
  const navigate = useNavigate({ from: Route.fullPath })
  const filter = filterOf(search)
  const { data: members } = useSuspenseQuery(membersQuery(organizationId, true))
  const { data: requests } = useSuspenseQuery(shiftChangeRequestsQuery(organizationId, filter))
  const [dialog, setDialog] = useState<DecisionTarget | null>(null)
  const { t } = useTranslation("admin")

  const names = memberNames(members)
  const nameOf: NameOf = (id) => (id === me.id ? null : (names[id] ?? t("time.formerMember")))
  const deactivated = new Set(members.filter((m) => !isActive(m)).map((m) => m.userId))

  function setFilter(next: RequestsFilter) {
    // Pending is the default, so it stays out of the URL.
    void navigate({ search: { status: next === "pending" ? undefined : next } })
  }

  return (
    <div className="mx-auto flex max-w-6xl flex-col gap-6">
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div>
          <h1 className="text-2xl font-semibold tracking-tight">{t("shiftRequests.title")}</h1>
          <p className="text-sm text-muted-foreground">
            {t("shiftRequests.description", { timeZone: format.timeZone.replaceAll("_", " ") })}
          </p>
        </div>
        <Select value={filter} onValueChange={(value) => setFilter(value as RequestsFilter)}>
          <SelectTrigger aria-label={t("shiftRequests.status")} className="w-40">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            {REQUEST_FILTERS.map((option) => (
              <SelectItem key={option} value={option}>
                {requestsFilterLabel(option)}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      </div>

      <Card className="gap-0 pb-0">
        <CardHeader className="pb-4">
          <CardTitle>{t(`shiftRequests.cardTitle.${filter}`)}</CardTitle>
          <CardDescription>{t("shiftRequests.cardDescription")}</CardDescription>
        </CardHeader>
        <CardContent className="px-0">
          {requests.length === 0 ? (
            <p className="border-t px-6 py-6 text-sm text-muted-foreground">{t(`shiftRequests.empty.${filter}`)}</p>
          ) : (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead className="pl-6">{t("time.employee")}</TableHead>
                  <TableHead>{t("time.shifts.date")}</TableHead>
                  <TableHead>{t("shiftRequests.now")}</TableHead>
                  <TableHead>{t("shiftRequests.requested")}</TableHead>
                  <TableHead>{t("time.shifts.note")}</TableHead>
                  <TableHead className="pr-6 text-right">
                    {filter === "pending" ? t("shiftRequests.decision") : t("shiftRequests.status")}
                  </TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {requests.map((request) => (
                  <RequestRow
                    key={request.id}
                    request={request}
                    deactivated={deactivated.has(request.userId)}
                    nameOf={nameOf}
                    onDecide={(decision) => setDialog({ decision, request })}
                  />
                ))}
              </TableBody>
            </Table>
          )}
        </CardContent>
      </Card>

      <ShiftChangeRequestDialog
        organizationId={organizationId}
        names={names}
        target={dialog}
        onClose={() => setDialog(null)}
      />
    </div>
  )
}

function RequestRow({
  request,
  deactivated,
  nameOf,
  onDecide,
}: {
  request: ShiftChangeRequest
  deactivated: boolean
  nameOf: NameOf
  onDecide: (decision: DecisionTarget["decision"]) => void
}) {
  const format = useDateTimeFormat()
  const { t } = useTranslation("admin")
  const person = `${request.firstName} ${request.lastName}`
  // The day of the shift as it is now, or as asked for when there is none.
  const day = format.dateOf(request.shiftStartedAt ?? request.startedAt)
  const dayName = format.businessDate(day, { weekday: "short", day: "numeric", month: "short" })

  return (
    <TableRow className="align-top">
      <TableCell className="pl-6">
        <div className="font-medium">
          {person}
          {deactivated && <span className="font-normal text-muted-foreground"> {t("time.deactivated")}</span>}
        </div>
        <div className="text-xs text-muted-foreground" title={format.dateTime(request.submittedAt)}>
          {t("shiftRequests.asked", { when: `${format.date(request.submittedAt, { day: "numeric", month: "short" })}, ${format.time(request.submittedAt)}` })}
        </div>
      </TableCell>
      <TableCell className="tabular-nums">
        {dayName}
      </TableCell>
      <TableCell className="tabular-nums">
        {request.kind === "missing_shift" && request.status !== "approved" ? (
          <Badge variant="outline">{t("shiftRequests.missingShift")}</Badge>
        ) : request.shiftStartedAt === null ? (
          <span className="text-muted-foreground">—</span>
        ) : (
          timeRange(request.shiftStartedAt, request.shiftEndedAt, format, day)
        )}
      </TableCell>
      <TableCell className="tabular-nums">
        <div className="font-medium">{timeRange(request.startedAt, request.endedAt, format, day)}</div>
        {request.status === "pending" && (
          <div className="text-xs text-muted-foreground">{changeSummary(request)}</div>
        )}
      </TableCell>
      <TableCell className="min-w-40 max-w-64 whitespace-normal text-muted-foreground">
        <span className="line-clamp-3" title={request.note}>
          {request.note}
        </span>
      </TableCell>
      <TableCell className={request.status === "pending" ? "pr-6 text-right" : "max-w-56 min-w-40 pr-6 text-right whitespace-normal"}>
        {request.status === "pending" ? (
          <div className="flex justify-end gap-2">
            <Button variant="outline" size="sm" onClick={() => onDecide("reject")} aria-label={t("shiftRequests.rejectFor", { name: person, day: dayName })}>
              <XIcon />
              {t("shiftRequests.reject")}
            </Button>
            <Button size="sm" onClick={() => onDecide("approve")} aria-label={t("shiftRequests.approveFor", { name: person, day: dayName })}>
              <CheckIcon />
              {t("shiftRequests.approve")}
            </Button>
          </div>
        ) : (
          <Decided request={request} nameOf={nameOf} />
        )}
      </TableCell>
    </TableRow>
  )
}

/** "Approved by you, 12 Nov 2026, 09:14", with the reason of a rejection. */
function Decided({ request, nameOf }: { request: ShiftChangeRequest; nameOf: NameOf }) {
  const format = useDateTimeFormat()
  const { t } = useTranslation("admin")
  const approved = request.status === "approved"
  const decidedBy = request.decidedBy === null ? null : nameOf(request.decidedBy)

  return (
    <div className="flex flex-col items-end gap-1 text-right">
      <Badge variant={approved ? "secondary" : "outline"}>{approved ? t("shiftRequests.approved") : t("shiftRequests.rejected")}</Badge>
      {request.decidedBy !== null && request.decidedAt !== null && (
        <div className="text-xs text-muted-foreground">
          {decidedBy === null
            ? t("shiftRequests.decidedByYou", { when: format.dateTime(request.decidedAt) })
            : t("shiftRequests.decidedBy", { name: decidedBy, when: format.dateTime(request.decidedAt) })}
        </div>
      )}
      {request.rejectionReason && (
        <div className="line-clamp-2 text-xs text-muted-foreground" title={request.rejectionReason}>
          {t("shiftRequests.quoted", { text: request.rejectionReason })}
        </div>
      )}
    </div>
  )
}
