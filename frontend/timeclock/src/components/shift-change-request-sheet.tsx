import { useState, type FormEvent } from "react"
import { ApiError, unwrap, violationsOf } from "@business-tool/api-client"
import { useMutation, useQueryClient } from "@tanstack/react-query"
import { useTranslation } from "react-i18next"

import { Button } from "@business-tool/ui/components/button"
import { Field, FieldDescription, FieldError, FieldGroup, FieldLabel } from "@business-tool/ui/components/field"
import { ShiftMomentField } from "@business-tool/ui/components/shift-moment-field"
import {
  Sheet,
  SheetContent,
  SheetDescription,
  SheetFooter,
  SheetHeader,
  SheetTitle,
} from "@business-tool/ui/components/sheet"
import { Textarea } from "@business-tool/ui/components/textarea"
import { useDateTimeFormat } from "@business-tool/ui/hooks/use-organization-time-zone"
import { requestErrorMessage } from "@business-tool/ui/lib/request-error"
import {
  NOTE_MAX_LENGTH,
  type ShiftFormErrors,
  type ShiftFormValues,
  type SubmitShiftChangeRequestInput,
  editShiftForm,
  emptyShiftForm,
  missingShiftRequestBody,
  newTimesRequestBody,
  readableShiftError,
  shiftFormFrom,
  suggestedEndDate,
} from "@business-tool/ui/lib/shift-form"
import type { Shift } from "@business-tool/ui/lib/shifts"

import { api } from "@/lib/api"
import { myShiftChangeRequestsKey } from "@/lib/queries"
import { timeRange } from "@/lib/shift-change-requests"

/** What the request is for: a shift the person worked but did not clock, or new times for one of theirs. */
export type ShiftChangeRequestTarget = { kind: "missing" } | { kind: "new_times"; shift: Shift }

/** The API property path whose errors show under each part of the form. */
const ERROR_PATH: Record<keyof ShiftFormValues, keyof ShiftFormErrors> = {
  userId: "userId",
  start: "startedAt",
  end: "endedAt",
  note: "note",
}

interface ShiftChangeRequestSheetProps {
  organizationId: string
  userId: string
  /** The sheet is open while there is a target. */
  target: ShiftChangeRequestTarget | null
  onClose: () => void
  /** After the request was sent; the requests list is already refreshing. */
  onSent: () => void
}

/**
 * Asks an owner or manager to enter a missing shift or to give a shift new times (ADR 0016 §5:
 * people never change shift times themselves). A bottom sheet, so it fits a phone.
 */
export function ShiftChangeRequestSheet({ target, onClose, ...props }: ShiftChangeRequestSheetProps) {
  return (
    <Sheet open={target !== null} onOpenChange={(open) => !open && onClose()}>
      <SheetContent side="bottom" className="mx-auto max-h-[90svh] max-w-lg overflow-y-auto rounded-t-xl pb-[env(safe-area-inset-bottom)]">
        {/* Keyed so each opening starts from the target's values. */}
        {target && (
          <RequestForm key={target.kind === "new_times" ? target.shift.id : "missing"} target={target} {...props} />
        )}
      </SheetContent>
    </Sheet>
  )
}

function RequestForm({
  organizationId,
  userId,
  target,
  onSent,
}: Omit<ShiftChangeRequestSheetProps, "target" | "onClose"> & { target: ShiftChangeRequestTarget }) {
  const { t } = useTranslation("timeclock")
  const queryClient = useQueryClient()
  const format = useDateTimeFormat()
  const timeZone = format.timeZone
  const shift = target.kind === "new_times" ? target.shift : null
  const wasOpen = shift !== null && shift.endedAt === null

  // The note is the request's own (why it is needed), not the shift's.
  const [initial] = useState<ShiftFormValues>(() =>
    shift ? { ...shiftFormFrom(shift, timeZone), note: "" } : emptyShiftForm(format.today(), userId),
  )
  const [values, setValues] = useState(initial)
  // The clock-out's date follows the times (the next day for a night shift) until the person sets it.
  const [endDateSet, setEndDateSet] = useState(
    () => initial.end.date !== "" && initial.end.date !== suggestedEndDate(initial.start, initial.end.time),
  )
  const [fieldErrors, setFieldErrors] = useState<ShiftFormErrors>({})
  const [formError, setFormError] = useState<string | null>(null)

  function update(next: Partial<ShiftFormValues>, endDateTouched = false) {
    setValues((prev) => editShiftForm(prev, next, { endDateSet: endDateTouched || endDateSet, wasOpen }))
    if (endDateTouched) setEndDateSet(true)
    // A field's error goes once it is edited; the next submit checks again.
    setFieldErrors((prev) => {
      const remaining = { ...prev }
      for (const field of Object.keys(next) as (keyof ShiftFormValues)[]) delete remaining[ERROR_PATH[field]]
      return remaining
    })
    // So is the form's own error (nothing changed, an answer from the API): it was about other values.
    setFormError(null)
  }

  const submit = useMutation({
    mutationFn: (body: SubmitShiftChangeRequestInput) => unwrap(api.POST("/api/shift-change-requests", { body })),
    onSuccess: () => {
      // Not awaited: the requests screen shows the new one as soon as it refetches.
      void queryClient.invalidateQueries({ queryKey: myShiftChangeRequestsKey(organizationId) })
      onSent()
    },
    onError: (error) => {
      const violations = violationsOf(error) as ShiftFormErrors
      const { shiftId, ...fields } = violations
      setFieldErrors(fields)
      const fieldViolations = error instanceof ApiError && error.status === 422 && Object.keys(fields).length > 0
      if (shiftId) setFormError(shiftId)
      else if (fieldViolations) setFormError(null)
      else if (error instanceof ApiError && error.status < 500 && error.status !== 429) {
        setFormError(readableShiftError(error.message, format, { [userId]: "You" }))
      } else setFormError(requestErrorMessage(error))
    },
  })

  function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setFieldErrors({})
    setFormError(null)
    const result = shift
      ? newTimesRequestBody(shift, values, timeZone, format)
      : missingShiftRequestBody(values, timeZone, format)
    if (!result.ok) {
      const { shiftId, ...fields } = result.errors
      setFieldErrors(fields)
      setFormError(shiftId ?? null)
      return
    }
    submit.mutate(result.body)
  }

  const pending = submit.isPending

  return (
    <form onSubmit={onSubmit} noValidate className="flex flex-col">
      <SheetHeader className="pr-12">
        <SheetTitle>{shift ? t("request.askForNewTimes") : t("request.addMissing")}</SheetTitle>
        <SheetDescription>
          {shift
            ? t("request.newTimesDescription", { range: timeRange(format, shift.startedAt, shift.endedAt) })
            : t("request.missingDescription")}{" "}
          {t("request.timesIn", { timeZone: timeZone.replaceAll("_", " ") })}
        </SheetDescription>
      </SheetHeader>
      <fieldset disabled={pending} className="px-4">
        <FieldGroup className="gap-5">
          <ShiftMomentField
            label={t("request.clockIn")}
            name="start"
            value={values.start}
            error={fieldErrors.startedAt}
            timeZone={timeZone}
            onChange={(start) => update({ start })}
          />
          <ShiftMomentField
            label={t("request.clockOut")}
            name="end"
            value={values.end}
            error={fieldErrors.endedAt}
            timeZone={timeZone}
            description={
              wasOpen
                ? t("request.stillAtWork")
                : !endDateSet && values.end.date !== values.start.date && values.end.date !== ""
                  ? t("request.nextDay")
                  : undefined
            }
            onChange={(end, dateChanged) => update({ end }, dateChanged)}
          />
          <Field data-invalid={fieldErrors.note ? true : undefined}>
            <FieldLabel htmlFor="request-note">{t("request.why")}</FieldLabel>
            <Textarea
              id="request-note"
              value={values.note}
              maxLength={NOTE_MAX_LENGTH}
              placeholder={shift ? t("request.whyNewTimesPlaceholder") : t("request.whyMissingPlaceholder")}
              aria-invalid={fieldErrors.note ? true : undefined}
              onChange={(e) => update({ note: e.target.value })}
            />
            {fieldErrors.note ? (
              <FieldError>{fieldErrors.note}</FieldError>
            ) : (
              <FieldDescription>{t("request.managerSees")}</FieldDescription>
            )}
          </Field>
          {formError && (
            <Field data-invalid>
              <FieldError>{formError}</FieldError>
            </Field>
          )}
        </FieldGroup>
      </fieldset>
      <SheetFooter>
        <Button type="submit" className="h-11" disabled={pending}>
          {pending ? t("request.sending") : t("request.send")}
        </Button>
      </SheetFooter>
    </form>
  )
}
