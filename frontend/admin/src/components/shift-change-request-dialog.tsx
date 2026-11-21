import { type FormEvent, useState } from "react"
import { unwrap } from "@business-tool/api-client"
import { useMutation, useQueryClient } from "@tanstack/react-query"
import { AlertCircleIcon } from "lucide-react"
import { toast } from "sonner"

import { Alert, AlertDescription, AlertTitle } from "@business-tool/ui/components/alert"
import { Button } from "@business-tool/ui/components/button"
import {
  Dialog,
  DialogClose,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@business-tool/ui/components/dialog"
import { Field, FieldDescription, FieldError, FieldLabel } from "@business-tool/ui/components/field"
import { Textarea } from "@business-tool/ui/components/textarea"
import { useDateTimeFormat } from "@business-tool/ui/hooks/use-organization-time-zone"

import { api } from "@/lib/api"
import {
  type DecisionError,
  REASON_MAX_LENGTH,
  type ShiftChangeRequest,
  changeSummary,
  decisionError,
  rejectBody,
  timeRange,
} from "@/lib/shift-change-requests"

export type Decision = "approve" | "reject"

export interface DecisionTarget {
  decision: Decision
  request: ShiftChangeRequest
}

/** The shift's times now next to the times asked for. A missing shift has none yet. */
export function RequestTimes({ request }: { request: ShiftChangeRequest }) {
  const format = useDateTimeFormat()

  return (
    <dl className="grid gap-3 text-sm sm:grid-cols-2">
      <div className="rounded-md border p-3">
        <dt className="text-xs font-medium text-muted-foreground">Now</dt>
        <dd className="mt-1 tabular-nums">
          {request.shiftStartedAt === null ? (
            <span className="text-muted-foreground">No shift</span>
          ) : (
            timeRange(request.shiftStartedAt, request.shiftEndedAt, format)
          )}
        </dd>
      </div>
      <div className="rounded-md border border-primary/40 bg-primary/5 p-3">
        <dt className="text-xs font-medium text-muted-foreground">Requested</dt>
        <dd className="mt-1 font-medium tabular-nums">{timeRange(request.startedAt, request.endedAt, format)}</dd>
        <dd className="mt-1 text-xs text-muted-foreground">{changeSummary(request)}</dd>
      </div>
    </dl>
  )
}

/** Approve or reject one request; the shift-rule errors of an approval show in the dialog. */
export function ShiftChangeRequestDialog({
  organizationId,
  names,
  target,
  onClose,
}: {
  organizationId: string
  /** User ids to names, for the people an API error names. */
  names: Record<string, string>
  target: DecisionTarget | null
  onClose: () => void
}) {
  return (
    <Dialog open={target !== null} onOpenChange={(open) => !open && onClose()}>
      <DialogContent className="sm:max-w-xl">
        {target && (
          <DecisionForm
            key={`${target.decision}-${target.request.id}`}
            organizationId={organizationId}
            names={names}
            target={target}
            onDone={onClose}
          />
        )}
      </DialogContent>
    </Dialog>
  )
}

function DecisionForm({
  organizationId,
  names,
  target: { decision, request },
  onDone,
}: {
  organizationId: string
  names: Record<string, string>
  target: DecisionTarget
  onDone: () => void
}) {
  const format = useDateTimeFormat()
  const queryClient = useQueryClient()
  const [reason, setReason] = useState("")
  const [error, setError] = useState<DecisionError | null>(null)
  const person = `${request.firstName} ${request.lastName}`
  const reasonTooLong = reason.trim().length > REASON_MAX_LENGTH

  async function refresh(shiftsChanged: boolean) {
    // The list of every status and the count in the navigation.
    await queryClient.invalidateQueries({ queryKey: ["shift-change-requests", organizationId] })
    // An approval entered or changed a shift: the /time table and its totals.
    if (shiftsChanged) await queryClient.invalidateQueries({ queryKey: ["shifts", organizationId] })
  }

  const decide = useMutation({
    mutationFn: () =>
      decision === "approve"
        ? unwrap(api.POST("/api/shift-change-requests/{id}/approve", { params: { path: { id: request.id } } }))
        : unwrap(
            api.POST("/api/shift-change-requests/{id}/reject", {
              params: { path: { id: request.id } },
              body: rejectBody(reason),
            }),
          ),
    onSuccess: async () => {
      await refresh(decision === "approve")
      toast.success(decision === "approve" ? `Shift of ${person} updated` : `Request of ${person} rejected`)
      onDone()
    },
    onError: async (failure) => {
      const next = decisionError(failure, format, { names })
      setError(next)
      // Decided by someone else meanwhile: the list is out of date. (A shift may have changed too.)
      if (!next.stillPending) await refresh(true)
    },
  })

  function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (reasonTooLong) return
    setError(null)
    decide.mutate()
  }

  const approving = decision === "approve"
  const blocked = error !== null && !error.stillPending

  return (
    <form onSubmit={onSubmit} noValidate>
      <DialogHeader>
        <DialogTitle>{approving ? `Approve ${person}'s request?` : `Reject ${person}'s request?`}</DialogTitle>
        <DialogDescription>
          {approving
            ? request.kind === "missing_shift"
              ? `The shift is entered for ${request.firstName}, marked as entered by you.`
              : "The shift gets the requested times, marked as corrected by you."
            : `The shift stays as it is. ${request.firstName} is told by e-mail.`}{" "}
          Times in {format.timeZone.replaceAll("_", " ")}.
        </DialogDescription>
      </DialogHeader>

      <fieldset disabled={decide.isPending} className="flex flex-col gap-4 py-4">
        <RequestTimes request={request} />
        <div className="text-sm">
          <div className="text-xs font-medium text-muted-foreground">
            Note from {request.firstName}, {format.dateTime(request.submittedAt)}
          </div>
          <p className="mt-1 whitespace-pre-line">{request.note}</p>
        </div>

        {!approving && (
          <Field data-invalid={reasonTooLong ? true : undefined}>
            <FieldLabel htmlFor="rejection-reason">Reason (optional)</FieldLabel>
            <Textarea
              id="rejection-reason"
              value={reason}
              onChange={(event) => setReason(event.target.value)}
              aria-invalid={reasonTooLong ? true : undefined}
              placeholder="e.g. The rota says you left at 02:00."
              autoFocus
            />
            {reasonTooLong ? (
              <FieldError>Keep the reason under {REASON_MAX_LENGTH} characters.</FieldError>
            ) : (
              <FieldDescription>Included in the e-mail to {request.firstName}.</FieldDescription>
            )}
          </Field>
        )}

        {error && (
          <Alert variant="destructive">
            <AlertCircleIcon />
            <AlertTitle>{approving ? "Could not approve" : "Could not reject"}</AlertTitle>
            <AlertDescription>
              <p>{error.message}</p>
              {error.stillPending && approving && (
                <p>The request is still pending. Fix the other shift on the Time page, or reject the request.</p>
              )}
            </AlertDescription>
          </Alert>
        )}
      </fieldset>

      <DialogFooter>
        <DialogClose asChild>
          <Button type="button" variant="outline">
            {blocked ? "Close" : "Cancel"}
          </Button>
        </DialogClose>
        {!blocked && (
          <Button type="submit" variant={approving ? "default" : "destructive"} disabled={decide.isPending}>
            {approving ? "Approve" : "Reject"}
          </Button>
        )}
      </DialogFooter>
    </form>
  )
}
