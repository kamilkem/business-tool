import { useState, type FormEvent } from "react"
import { ApiError, unwrap, violationsOf } from "@business-tool/api-client"
import { useMutation, useQueryClient } from "@tanstack/react-query"
import { useTranslation } from "react-i18next"
import { Trash2Icon } from "lucide-react"
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
import { Button } from "@business-tool/ui/components/button"
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@business-tool/ui/components/dialog"
import { Field, FieldError, FieldGroup, FieldLabel } from "@business-tool/ui/components/field"
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@business-tool/ui/components/select"
import { ShiftMomentField } from "@business-tool/ui/components/shift-moment-field"
import { Textarea } from "@business-tool/ui/components/textarea"
import { useDateTimeFormat } from "@business-tool/ui/hooks/use-organization-time-zone"
import { formatDuration } from "@business-tool/ui/lib/date-time"
import { requestErrorMessage } from "@business-tool/ui/lib/request-error"
import { type Shift, canBeDeleted } from "@business-tool/ui/lib/shifts"

import { api } from "@/lib/api"
import { type Member, fullName, isActive, memberNames } from "@/lib/members"
import {
  type AddShiftInput,
  type CorrectShiftInput,
  NOTE_MAX_LENGTH,
  type ShiftFormErrors,
  type ShiftFormValues,
  addShiftBody,
  correctShiftBody,
  editShiftForm,
  emptyShiftForm,
  shiftFormFrom,
  suggestedEndDate,
} from "@business-tool/ui/lib/shift-form"

/** The API property path whose errors show under each part of the form. */
const ERROR_PATH: Record<keyof ShiftFormValues, keyof ShiftFormErrors> = {
  userId: "userId",
  start: "startedAt",
  end: "endedAt",
  note: "note",
}

/** What the dialog is for: a missing shift (optionally for a person already picked) or an existing one. */
export type ShiftDialogTarget = { kind: "add"; userId?: string } | { kind: "edit"; shift: Shift }

interface ShiftDialogProps {
  organizationId: string
  actorId: string
  /** Members, deactivated ones included: shifts can be entered for people who have left (ADR 0014). */
  members: Member[]
  /** The dialog is open while there is a target. */
  target: ShiftDialogTarget | null
  onClose: () => void
}

export function ShiftDialog({ target, onClose, ...props }: ShiftDialogProps) {
  return (
    <Dialog open={target !== null} onOpenChange={(open) => !open && onClose()}>
      <DialogContent className="sm:max-w-lg">
        {/* Keyed so each opening starts from the target's values. */}
        {target && (
          <ShiftForm
            key={target.kind === "edit" ? target.shift.id : `add-${target.userId ?? ""}`}
            target={target}
            onDone={onClose}
            {...props}
          />
        )}
      </DialogContent>
    </Dialog>
  )
}

function ShiftForm({
  organizationId,
  actorId,
  members,
  target,
  onDone,
}: Omit<ShiftDialogProps, "target" | "onClose"> & { target: ShiftDialogTarget; onDone: () => void }) {
  const queryClient = useQueryClient()
  const format = useDateTimeFormat()
  const { t } = useTranslation("admin")
  const timeZone = format.timeZone
  const editing = target.kind === "edit" ? target.shift : null
  const wasOpen = editing !== null && editing.endedAt === null

  const [initial] = useState<ShiftFormValues>(() =>
    editing ? shiftFormFrom(editing, timeZone) : emptyShiftForm(format.today(), target.kind === "add" ? target.userId : ""),
  )
  const [values, setValues] = useState(initial)
  // The clock-out's date follows the times (the next day for a night shift) until someone sets it.
  const [endDateSet, setEndDateSet] = useState(
    () => initial.end.date !== "" && initial.end.date !== suggestedEndDate(initial.start, initial.end.time),
  )
  const [fieldErrors, setFieldErrors] = useState<ShiftFormErrors>({})
  const [formError, setFormError] = useState<string | null>(null)
  const [confirmDelete, setConfirmDelete] = useState(false)

  const names = memberNames(members)
  const people = [...members].sort(
    (a, b) => a.lastName.localeCompare(b.lastName) || a.firstName.localeCompare(b.firstName),
  )
  const personName = (userId: string) => {
    const member = members.find((m) => m.userId === userId)
    return member ? fullName(member) : t("requestError.someone", { ns: "ui" })
  }

  function update(next: Partial<ShiftFormValues>, endDateTouched = false) {
    setValues((prev) => editShiftForm(prev, next, { endDateSet: endDateTouched || endDateSet, wasOpen }))
    if (endDateTouched) setEndDateSet(true)
    // A field's error goes once it is edited; the next submit checks again.
    setFieldErrors((prev) => {
      const remaining = { ...prev }
      for (const field of Object.keys(next) as (keyof ShiftFormValues)[]) delete remaining[ERROR_PATH[field]]
      return remaining
    })
  }

  async function invalidateShifts() {
    // The totals are computed from the shifts list, so this refreshes both, for every period and person.
    await queryClient.invalidateQueries({ queryKey: ["shifts", organizationId] })
  }

  function onError(error: Error) {
    const violations = violationsOf(error) as ShiftFormErrors
    setFieldErrors(violations)
    const has422Violations = error instanceof ApiError && error.status === 422 && Object.keys(violations).length > 0
    setFormError(has422Violations ? null : requestErrorMessage(error, { names, selfId: actorId, format }))
  }

  const add = useMutation({
    mutationFn: (body: AddShiftInput) => unwrap(api.POST("/api/shifts", { body })),
    onSuccess: async (shift) => {
      await invalidateShifts()
      toast.success(t("shiftDialog.toast.added", { name: personName(shift.userId), date: format.date(shift.startedAt) }))
      onDone()
    },
    onError,
  })

  const correct = useMutation({
    mutationFn: ({ id, body }: { id: string; body: CorrectShiftInput }) =>
      unwrap(
        api.PATCH("/api/shifts/{id}", {
          params: { path: { id } },
          body,
          headers: { "Content-Type": "application/merge-patch+json" },
        }),
      ),
    onSuccess: async (shift, { body }) => {
      await invalidateShifts()
      const ended = wasOpen && shift.endedAt !== null && body.endedAt !== undefined
      toast.success(
        ended
          ? t("shiftDialog.toast.ended", { name: personName(shift.userId), time: format.time(shift.endedAt as string) })
          : t("shiftDialog.toast.updated", { name: personName(shift.userId) }),
      )
      onDone()
    },
    onError,
  })

  const remove = useMutation({
    mutationFn: (shift: Shift) => unwrap(api.DELETE("/api/shifts/{id}", { params: { path: { id: shift.id } } })),
    onSuccess: async (_, shift) => {
      setConfirmDelete(false)
      onDone()
      toast.success(t("shiftDialog.toast.deleted", { name: fullName(shift), date: format.businessDate(shift.date) }))
      await Promise.all([
        invalidateShifts(),
        // Decided requests about the shift keep its id, but no longer its times.
        queryClient.invalidateQueries({ queryKey: ["shift-change-requests", organizationId] }),
      ])
    },
    // Shown in the confirmation (a running, locked or requested shift: 409); the list was probably out of date.
    onError: async () => invalidateShifts(),
  })

  const pending = add.isPending || correct.isPending || remove.isPending

  function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setFieldErrors({})
    setFormError(null)

    if (!editing) {
      const result = addShiftBody(values, timeZone, format)
      if (!result.ok) return setFieldErrors(result.errors)
      add.mutate(result.body)
      return
    }

    const result = correctShiftBody(initial, values, timeZone, format)
    if (!result.ok) return setFieldErrors(result.errors)
    if (Object.keys(result.body).length === 0) {
      toast.info(t("common.nothingChanged"))
      onDone()
      return
    }
    correct.mutate({ id: editing.id, body: result.body })
  }

  return (
    <form onSubmit={onSubmit} noValidate>
      <DialogHeader>
        <DialogTitle>{editing ? (wasOpen ? t("shiftDialog.endTitle") : t("shiftDialog.editTitle")) : t("shiftDialog.addTitle")}</DialogTitle>
        <DialogDescription>
          {editing
            ? wasOpen
              ? t("shiftDialog.endDescription", { name: fullName(editing) })
              : t("shiftDialog.editDescription", { name: fullName(editing), date: format.businessDate(editing.date) })
            : t("shiftDialog.addDescription")}{" "}
          {t("shiftDialog.timesIn", { timeZone: timeZone.replaceAll("_", " ") })}
        </DialogDescription>
      </DialogHeader>
      <fieldset disabled={pending} className="py-4">
        <FieldGroup>
          {!editing && (
            <Field data-invalid={fieldErrors.userId ? true : undefined}>
              <FieldLabel htmlFor="shift-user">{t("time.employee")}</FieldLabel>
              <Select value={values.userId} onValueChange={(userId) => update({ userId })}>
                <SelectTrigger id="shift-user" className="w-full" aria-invalid={fieldErrors.userId ? true : undefined}>
                  <SelectValue placeholder={t("shiftDialog.choosePerson")} />
                </SelectTrigger>
                <SelectContent>
                  {people.map((member) => (
                    <SelectItem key={member.userId} value={member.userId}>
                      {fullName(member)}
                      {!isActive(member) && <span className="text-muted-foreground"> {t("time.deactivated")}</span>}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
              {fieldErrors.userId && <FieldError>{fieldErrors.userId}</FieldError>}
            </Field>
          )}
          <ShiftMomentField
            label={t("time.shifts.clockIn")}
            name="start"
            value={values.start}
            error={fieldErrors.startedAt}
            timeZone={timeZone}
            onChange={(start) => update({ start })}
          />
          <ShiftMomentField
            label={t("time.shifts.clockOut")}
            name="end"
            value={values.end}
            error={fieldErrors.endedAt}
            timeZone={timeZone}
            description={
              wasOpen
                ? t("shiftDialog.keepOpen")
                : !endDateSet && values.end.date !== values.start.date && values.end.date !== ""
                  ? t("shiftDialog.nextDay")
                  : undefined
            }
            autoFocus={wasOpen}
            onChange={(end, dateChanged) => update({ end }, dateChanged)}
          />
          <Field data-invalid={fieldErrors.note ? true : undefined}>
            <FieldLabel htmlFor="shift-note">{t("time.shifts.note")}</FieldLabel>
            <Textarea
              id="shift-note"
              value={values.note}
              maxLength={NOTE_MAX_LENGTH}
              placeholder={t("shiftDialog.notePlaceholder")}
              aria-invalid={fieldErrors.note ? true : undefined}
              onChange={(e) => update({ note: e.target.value })}
            />
            {fieldErrors.note && <FieldError>{fieldErrors.note}</FieldError>}
          </Field>
          {formError && (
            <Field data-invalid>
              <FieldError>{formError}</FieldError>
            </Field>
          )}
        </FieldGroup>
      </fieldset>
      <DialogFooter className="sm:justify-between">
        {editing && canBeDeleted(editing) ? (
          <Button
            type="button"
            variant="ghost"
            className="text-destructive"
            disabled={pending}
            onClick={() => {
              remove.reset()
              setConfirmDelete(true)
            }}
          >
            <Trash2Icon />
            {t("common.delete")}
          </Button>
        ) : (
          <span />
        )}
        <Button type="submit" disabled={pending}>
          {add.isPending || correct.isPending ? t("common.saving", { ns: "ui" }) : editing ? t("common.save") : t("time.addShift")}
        </Button>
      </DialogFooter>

      {editing && canBeDeleted(editing) && (
        <AlertDialog open={confirmDelete} onOpenChange={(open) => !remove.isPending && setConfirmDelete(open)}>
          <AlertDialogContent>
            <AlertDialogHeader>
              <AlertDialogTitle>{t("shiftDialog.delete.title")}</AlertDialogTitle>
              <AlertDialogDescription>
                {t("shiftDialog.delete.description", {
                  name: fullName(editing),
                  date: format.businessDate(editing.date),
                  start: format.time(editing.startedAt),
                  end: format.time(editing.endedAt as string),
                  duration: formatDuration(editing.durationMinutes ?? 0),
                })}
              </AlertDialogDescription>
            </AlertDialogHeader>
            {remove.error && (
              <p role="alert" className="text-sm text-destructive">
                {requestErrorMessage(remove.error, { names, selfId: actorId, format })}
              </p>
            )}
            <AlertDialogFooter>
              <AlertDialogCancel disabled={remove.isPending}>{t("common.cancel")}</AlertDialogCancel>
              <AlertDialogAction
                variant="destructive"
                disabled={remove.isPending || remove.isError}
                onClick={(event) => {
                  event.preventDefault()
                  remove.mutate(editing)
                }}
              >
                {remove.isPending ? t("common.deleting") : t("common.delete")}
              </AlertDialogAction>
            </AlertDialogFooter>
          </AlertDialogContent>
        </AlertDialog>
      )}
    </form>
  )
}
