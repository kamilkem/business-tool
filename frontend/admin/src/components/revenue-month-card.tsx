import { useState } from "react"
import { unwrap } from "@business-tool/api-client"
import { useMutation, useQueryClient, useSuspenseQuery } from "@tanstack/react-query"
import { LockIcon, LockOpenIcon } from "lucide-react"
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
import { Card, CardAction, CardContent, CardDescription, CardHeader, CardTitle } from "@business-tool/ui/components/card"
import { useDateTimeFormat } from "@business-tool/ui/hooks/use-organization-time-zone"
import { addDays } from "@business-tool/ui/lib/date-time"
import { requestErrorMessage } from "@business-tool/ui/lib/request-error"

import { api } from "@/lib/api"
import { invalidateRevenue, revenueMonthQuery } from "@/lib/queries"

/**
 * A month of income (ADR 0034): whether it is closed, who closed or reopened it and when, and for
 * owners the button that closes it once it has ended, or reopens it. A closed month locks its
 * entries, here and in Cash flow's end-of-day closes.
 */
export function RevenueMonthCard({
  organizationId,
  month,
  canClose,
  nameOf,
}: {
  organizationId: string
  /** YYYY-MM */
  month: string
  /** Owners only (the `incomeClose` capability). */
  canClose: boolean
  /** Who closed or reopened: a name, or null for the signed-in user. */
  nameOf: (userId: string) => string | null
}) {
  const { t } = useTranslation("admin")
  const format = useDateTimeFormat()
  const queryClient = useQueryClient()
  const { data } = useSuspenseQuery(revenueMonthQuery(organizationId, month))
  // The action being confirmed, kept while its dialog closes (the month changes under it).
  const [confirm, setConfirm] = useState<"close" | "reopen" | null>(null)
  const period = { from: data.firstDate, to: data.lastDate }
  const monthName = format.period(period)
  const who = (userId: string) => nameOf(userId) ?? t("income.history.you")

  const act = useMutation({
    mutationFn: (action: "close" | "reopen") =>
      unwrap(
        action === "close"
          ? api.POST("/api/revenue-months/{month}/close", { params: { path: { month } } })
          : api.POST("/api/revenue-months/{month}/reopen", { params: { path: { month } } }),
      ),
    onSuccess: async (updated) => {
      queryClient.setQueryData(revenueMonthQuery(organizationId, month).queryKey, updated)
      await invalidateRevenue(queryClient, organizationId)
      toast.success(t(updated.closed ? "income.month.toast.closed" : "income.month.toast.reopened", { month: monthName }))
      setConfirm(null)
    },
    onError: async (error) => {
      toast.error(requestErrorMessage(error, { format }))
      setConfirm(null)
      await queryClient.invalidateQueries({ queryKey: ["revenue-months", organizationId] })
    },
  })

  const action: "close" | "reopen" | null = !canClose ? null : data.closed ? "reopen" : data.ended ? "close" : null
  const actions = [...data.actions].reverse()

  return (
    <Card className="gap-3">
      <CardHeader>
        <CardTitle className="flex flex-wrap items-center gap-2">
          {t("income.month.title", { month: monthName })}
          {data.closed ? (
            <Badge>
              <LockIcon />
              {t("income.month.closed")}
            </Badge>
          ) : (
            <Badge variant="outline">{t("income.month.open")}</Badge>
          )}
        </CardTitle>
        <CardDescription>
          {data.closed && data.closedBy && data.closedAt
            ? t("income.month.closedBy", { name: who(data.closedBy), when: format.dateTime(data.closedAt) })
            : data.ended
              ? t(canClose ? "income.month.openEnded" : "income.month.openEndedManager")
              : t("income.month.openNotEnded", { date: format.businessDate(addDays(data.lastDate, 1)) })}
        </CardDescription>
        {action && (
          <CardAction>
            <Button variant={action === "close" ? "default" : "outline"} onClick={() => setConfirm(action)} disabled={act.isPending}>
              {action === "close" ? <LockIcon /> : <LockOpenIcon />}
              {t(action === "close" ? "income.month.close" : "income.month.reopen")}
            </Button>
          </CardAction>
        )}
      </CardHeader>
      {actions.length > 0 && (
        <CardContent>
          <details className="text-sm">
            <summary className="cursor-pointer text-muted-foreground">{t("income.month.log", { count: actions.length })}</summary>
            <ol className="mt-2 flex flex-col gap-1">
              {actions.map((entry, index) => (
                <li key={`${entry.at}-${index}`} className="tabular-nums">
                  {t(entry.action === "closed" ? "income.month.logClosed" : "income.month.logReopened", {
                    name: who(entry.actorId),
                    when: format.dateTime(entry.at),
                  })}
                </li>
              ))}
            </ol>
          </details>
        </CardContent>
      )}

      {confirm && (
        <AlertDialog open onOpenChange={(open) => !open && setConfirm(null)}>
          <AlertDialogContent>
            <AlertDialogHeader>
              <AlertDialogTitle>{t(confirm === "close" ? "income.month.confirmCloseTitle" : "income.month.confirmReopenTitle", { month: monthName })}</AlertDialogTitle>
              <AlertDialogDescription>
                {t(confirm === "close" ? "income.month.confirmCloseDescription" : "income.month.confirmReopenDescription", { month: monthName })}
              </AlertDialogDescription>
            </AlertDialogHeader>
            <AlertDialogFooter>
              <AlertDialogCancel disabled={act.isPending}>{t("common.cancel")}</AlertDialogCancel>
              <AlertDialogAction
                disabled={act.isPending}
                onClick={(event) => {
                  event.preventDefault()
                  act.mutate(confirm)
                }}
              >
                {t(confirm === "close" ? "income.month.close" : "income.month.reopen")}
              </AlertDialogAction>
            </AlertDialogFooter>
          </AlertDialogContent>
        </AlertDialog>
      )}
    </Card>
  )
}
