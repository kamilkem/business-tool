import { useState } from "react"
import { unwrap } from "@business-tool/api-client"
import { useMutation, useQueryClient } from "@tanstack/react-query"
import { CircleAlertIcon, LoaderCircleIcon, Undo2Icon } from "lucide-react"
import { useTranslation } from "react-i18next"

import { Alert, AlertDescription } from "@business-tool/ui/components/alert"
import {
  AlertDialog,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from "@business-tool/ui/components/alert-dialog"
import { Button } from "@business-tool/ui/components/button"
import { useDateTimeFormat } from "@business-tool/ui/hooks/use-organization-time-zone"
import { requestErrorMessage } from "@business-tool/ui/lib/request-error"

import { api } from "@/lib/api"
import { myShiftChangeRequestsKey } from "@/lib/queries"
import { type ShiftChangeRequest, timeRange } from "@/lib/shift-change-requests"

/**
 * "Withdraw" on one of the person's pending requests, confirmed first: the owners and managers stop
 * seeing it, and it stays in the list as withdrawn. Either way the list is refetched: a request an
 * owner or manager decided in the meantime (409) then shows their decision instead of the button,
 * which closes the dialog. Other failures (no connection) show in the dialog.
 */
export function WithdrawRequestButton({ organizationId, request }: { organizationId: string; request: ShiftChangeRequest }) {
  const { t } = useTranslation("timeclock")
  const format = useDateTimeFormat()
  const queryClient = useQueryClient()
  const [open, setOpen] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const range = timeRange(format, request.startedAt, request.endedAt)

  const withdraw = useMutation({
    mutationFn: () =>
      unwrap(api.POST("/api/shift-change-requests/{id}/withdraw", { params: { path: { id: request.id } } })),
    onSuccess: () => {
      setOpen(false)
    },
    onError: (failure) => setError(requestErrorMessage(failure, { format })),
    // Either way the list changes: withdrawn, or decided by someone else in the meantime.
    onSettled: () => queryClient.invalidateQueries({ queryKey: myShiftChangeRequestsKey(organizationId) }),
  })

  function onOpenChange(next: boolean) {
    if (withdraw.isPending) return
    setOpen(next)
    if (!next) setError(null)
  }

  return (
    <AlertDialog open={open} onOpenChange={onOpenChange}>
      <Button variant="outline" size="sm" className="h-9" onClick={() => setOpen(true)} aria-label={t("requests.withdrawFor", { range })}>
        <Undo2Icon />
        {t("requests.withdraw")}
      </Button>
      <AlertDialogContent>
        <AlertDialogHeader>
          <AlertDialogTitle>{t("requests.withdrawTitle")}</AlertDialogTitle>
          <AlertDialogDescription>
            {range}. {t("requests.withdrawDescription")}
          </AlertDialogDescription>
        </AlertDialogHeader>
        {error && (
          <Alert variant="destructive">
            <CircleAlertIcon />
            <AlertDescription>{error}</AlertDescription>
          </Alert>
        )}
        <AlertDialogFooter>
          <AlertDialogCancel disabled={withdraw.isPending}>{t("requests.keep")}</AlertDialogCancel>
          {error === null && (
            <Button variant="destructive" onClick={() => withdraw.mutate()} disabled={withdraw.isPending}>
              {withdraw.isPending && <LoaderCircleIcon className="animate-spin" />}
              {withdraw.isPending ? t("requests.withdrawing") : t("requests.withdraw")}
            </Button>
          )}
        </AlertDialogFooter>
      </AlertDialogContent>
    </AlertDialog>
  )
}
