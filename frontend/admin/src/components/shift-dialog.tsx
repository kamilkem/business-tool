import { useState, type FormEvent } from "react"
import { ApiError, unwrap, violationsOf } from "@business-tool/api-client"
import { useMutation, useQueryClient } from "@tanstack/react-query"
import { toast } from "sonner"

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
import type { Shift } from "@business-tool/ui/lib/shifts"

import { api } from "@/lib/api"
import { type Member, fullName, isActive } from "@/lib/members"
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
  readableShiftError,
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

  const names = Object.fromEntries(members.map((m) => [m.userId, m.userId === actorId ? "You" : fullName(m)]))
  const people = [...members].sort(
    (a, b) => a.lastName.localeCompare(b.lastName) || a.firstName.localeCompare(b.firstName),
  )
  const personName = (userId: string) => {
    const member = members.find((m) => m.userId === userId)
    return member ? fullName(member) : "this person"
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
    setFormError(has422Violations ? null : readableShiftError(error.message, format, names))
  }

  const add = useMutation({
    mutationFn: (body: AddShiftInput) => unwrap(api.POST("/api/shifts", { body })),
    onSuccess: async (shift) => {
      await invalidateShifts()
      toast.success(`Shift added for ${personName(shift.userId)} on ${format.date(shift.startedAt)}`)
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
          ? `${personName(shift.userId)} clocked out at ${format.time(shift.endedAt as string)}`
          : `Shift of ${personName(shift.userId)} updated`,
      )
      onDone()
    },
    onError,
  })

  const pending = add.isPending || correct.isPending

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
      toast.info("Nothing changed")
      onDone()
      return
    }
    correct.mutate({ id: editing.id, body: result.body })
  }

  return (
    <form onSubmit={onSubmit} noValidate>
      <DialogHeader>
        <DialogTitle>{editing ? (wasOpen ? "End or edit open shift" : "Edit shift") : "Add shift"}</DialogTitle>
        <DialogDescription>
          {editing
            ? wasOpen
              ? `${fullName(editing)} is still clocked in. Enter the clock-out to end the shift, or leave it empty to keep it open.`
              : `${fullName(editing)}, ${format.businessDate(editing.date)}. The shift keeps who changed it and when.`
            : "A shift someone forgot to clock. It is marked as entered by you."}{" "}
          Times in {timeZone.replaceAll("_", " ")}.
        </DialogDescription>
      </DialogHeader>
      <fieldset disabled={pending} className="py-4">
        <FieldGroup>
          {!editing && (
            <Field data-invalid={fieldErrors.userId ? true : undefined}>
              <FieldLabel htmlFor="shift-user">Employee</FieldLabel>
              <Select value={values.userId} onValueChange={(userId) => update({ userId })}>
                <SelectTrigger id="shift-user" className="w-full" aria-invalid={fieldErrors.userId ? true : undefined}>
                  <SelectValue placeholder="Choose a person" />
                </SelectTrigger>
                <SelectContent>
                  {people.map((member) => (
                    <SelectItem key={member.userId} value={member.userId}>
                      {fullName(member)}
                      {!isActive(member) && <span className="text-muted-foreground"> (deactivated)</span>}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
              {fieldErrors.userId && <FieldError>{fieldErrors.userId}</FieldError>}
            </Field>
          )}
          <ShiftMomentField
            label="Clock-in"
            name="start"
            value={values.start}
            error={fieldErrors.startedAt}
            timeZone={timeZone}
            onChange={(start) => update({ start })}
          />
          <ShiftMomentField
            label="Clock-out"
            name="end"
            value={values.end}
            error={fieldErrors.endedAt}
            timeZone={timeZone}
            description={
              wasOpen
                ? "Leave empty to keep the shift open."
                : !endDateSet && values.end.date !== values.start.date && values.end.date !== ""
                  ? "The next day: the clock-out is not after the clock-in."
                  : undefined
            }
            autoFocus={wasOpen}
            onChange={(end, dateChanged) => update({ end }, dateChanged)}
          />
          <Field data-invalid={fieldErrors.note ? true : undefined}>
            <FieldLabel htmlFor="shift-note">Note</FieldLabel>
            <Textarea
              id="shift-note"
              value={values.note}
              maxLength={NOTE_MAX_LENGTH}
              placeholder="Optional, e.g. why the shift was entered or changed"
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
      <DialogFooter>
        <Button type="submit" disabled={pending}>
          {pending ? "Saving…" : editing ? "Save" : "Add shift"}
        </Button>
      </DialogFooter>
    </form>
  )
}
