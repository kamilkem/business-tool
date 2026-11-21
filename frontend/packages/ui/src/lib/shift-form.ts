import type { components } from "@business-tool/api-client"

import { i18n } from "../i18n/i18n"
import {
  type BusinessDate,
  type DateTimeFormat,
  type LocalDateTime,
  addDays,
  formatUtcOffset,
  instantsAt,
  isBusinessDate,
  isWallTime,
  localDateTimeOf,
  toZonedIsoString,
  utcOffsetMinutes,
} from "./date-time"

import type { Shift } from "./shifts"

export type AddShiftInput = components["schemas"]["Shift.AddShiftInput"]
export type CorrectShiftInput = components["schemas"]["Shift.CorrectShiftInput.jsonMergePatch"]
export type SubmitShiftChangeRequestInput = components["schemas"]["ShiftChangeRequest.SubmitShiftChangeRequestInput"]

/**
 * Which of two instants a wall time means in the hour that happens twice when clocks go back
 * (02:30 on 25 Oct in Warsaw): the first, still in summer time, or the second.
 */
export type Occurrence = "first" | "second"

/** One end of a shift as typed in the dialog: a date and time on the organization's wall clock. */
export interface ShiftMoment {
  date: string
  time: string
  occurrence: Occurrence
}

/** The dialog's values: strings as the inputs hold them, in the organization's time zone. */
export interface ShiftFormValues {
  userId: string
  start: ShiftMoment
  end: ShiftMoment
  note: string
}

/** Errors by the API's property paths, so the server's 422 violations land on the same fields. */
export type ShiftFormErrors = Partial<Record<"userId" | "shiftId" | "startedAt" | "endedAt" | "note", string>>

export const NOTE_MAX_LENGTH = 1000

const EMPTY_MOMENT: ShiftMoment = { date: "", time: "", occurrence: "first" }

/** A new shift on the given day (today in the organization's zone), for the person picked, if any. */
export function emptyShiftForm(date: BusinessDate, userId = ""): ShiftFormValues {
  return { userId, start: { ...EMPTY_MOMENT, date }, end: { ...EMPTY_MOMENT, date }, note: "" }
}

/** The moment as the wall clock showed it, with the occurrence that names the same instant again. */
function momentOf(instant: string, timeZone: string): ShiftMoment {
  const local = localDateTimeOf(instant, timeZone)
  const candidates = instantsAt(local, timeZone)
  // A repeated wall time: the shift's own minute is the second one when it is the later candidate.
  const minute = Math.floor(new Date(instant).getTime() / 60_000) * 60_000
  const occurrence = candidates.length === 2 && candidates[1]?.getTime() === minute ? "second" : "first"
  return { ...local, occurrence }
}

/** An existing shift as the edit dialog shows it, in the organization's time zone. An open shift has no end. */
export function shiftFormFrom(shift: Shift, timeZone: string): ShiftFormValues {
  return {
    userId: shift.userId,
    start: momentOf(shift.startedAt, timeZone),
    end: shift.endedAt === null ? { ...EMPTY_MOMENT } : momentOf(shift.endedAt, timeZone),
    note: shift.note ?? "",
  }
}

/** The clock-out's date that goes with the times: the next day when it is not after the clock-in (a night shift). */
export function suggestedEndDate(start: ShiftMoment, endTime: string): string {
  if (!isBusinessDate(start.date)) return start.date
  if (!isWallTime(start.time) || !isWallTime(endTime)) return start.date
  return endTime <= start.time ? addDays(start.date, 1) : start.date
}

/**
 * The form after an edit. Until the person sets the clock-out's date themselves (`endDateSet`), it
 * follows the times: the clock-in's date, or the next day for a night shift. An open shift with no
 * clock-out time (`wasOpen`) stays open, so its clock-out has no date either.
 */
export function editShiftForm(
  prev: ShiftFormValues,
  next: Partial<ShiftFormValues>,
  { endDateSet, wasOpen }: { endDateSet: boolean; wasOpen: boolean },
): ShiftFormValues {
  const merged = { ...prev, ...next }
  if (endDateSet) return merged
  if (wasOpen && merged.end.time === "") return { ...merged, end: { ...merged.end, date: "" } }
  return { ...merged, end: { ...merged.end, date: suggestedEndDate(merged.start, merged.end.time) } }
}

/** The two offsets of a repeated wall time, earliest first, e.g. ["+02:00", "+01:00"]; null when it happens once. */
export function repeatedOffsets(moment: Pick<ShiftMoment, "date" | "time">, timeZone: string): [string, string] | null {
  if (!isBusinessDate(moment.date) || !isWallTime(moment.time)) return null
  const [first, second] = instantsAt(moment, timeZone)
  if (!first || !second) return null
  return [formatUtcOffset(utcOffsetMinutes(first, timeZone)), formatUtcOffset(utcOffsetMinutes(second, timeZone))]
}

type Resolved = { instant: Date } | { error: string }

/** The instant a typed moment names in the time zone, or why it names none. */
function resolve(moment: ShiftMoment, timeZone: string, format: DateTimeFormat, end: "clockIn" | "clockOut"): Resolved {
  if (!isBusinessDate(moment.date)) return { error: i18n.t(`shiftForm.error.${end}Date`) }
  if (!isWallTime(moment.time)) return { error: i18n.t(`shiftForm.error.${end}Time`) }
  const local: LocalDateTime = { date: moment.date, time: moment.time }
  const candidates = instantsAt(local, timeZone)
  const instant = moment.occurrence === "second" ? (candidates[1] ?? candidates[0]) : candidates[0]
  if (!instant) {
    return {
      error: i18n.t("shiftForm.error.skippedTime", { time: moment.time, date: format.businessDate(moment.date) }),
    }
  }
  return { instant }
}

export type ShiftFormResult<T> = { ok: true; body: T } | { ok: false; errors: ShiftFormErrors }

interface Times {
  errors: ShiftFormErrors
  startedAt?: Date
  endedAt?: Date
}

/** Checks the client can make before sending; the API checks again (end after start, nothing in the future, no overlap). */
function times(values: ShiftFormValues, timeZone: string, format: DateTimeFormat, endRequired: boolean): Times {
  const errors: ShiftFormErrors = {}
  const start = resolve(values.start, timeZone, format, "clockIn")
  const endEmpty = values.end.date === "" && values.end.time === ""
  const end = endEmpty && !endRequired ? null : resolve(values.end, timeZone, format, "clockOut")

  if ("error" in start) errors.startedAt = start.error
  if (end && "error" in end) errors.endedAt = end.error
  const startedAt = "instant" in start ? start.instant : undefined
  const endedAt = end && "instant" in end ? end.instant : undefined
  if (startedAt && endedAt && endedAt <= startedAt) errors.endedAt = i18n.t("shiftForm.error.endBeforeStart")
  if (values.note.trim().length > NOTE_MAX_LENGTH) errors.note = i18n.t("shiftForm.error.noteTooLong", { count: NOTE_MAX_LENGTH })
  return { errors, startedAt, endedAt }
}

function hasErrors(errors: ShiftFormErrors): boolean {
  return Object.keys(errors).length > 0
}

/** POST /shifts body: a whole shift, both ends required, times with the organization's offset. */
export function addShiftBody(
  values: ShiftFormValues,
  timeZone: string,
  format: DateTimeFormat,
): ShiftFormResult<AddShiftInput> {
  const { errors, startedAt, endedAt } = times(values, timeZone, format, true)
  if (values.userId === "") errors.userId = i18n.t("shiftForm.error.noPerson")
  if (hasErrors(errors) || !startedAt || !endedAt) return { ok: false, errors }

  const note = values.note.trim()
  return {
    ok: true,
    body: {
      userId: values.userId,
      startedAt: toZonedIsoString(startedAt, timeZone),
      endedAt: toZonedIsoString(endedAt, timeZone),
      ...(note === "" ? {} : { note }),
    },
  }
}

function sameMoment(a: ShiftMoment, b: ShiftMoment): boolean {
  return a.date === b.date && a.time === b.time && a.occurrence === b.occurrence
}

/**
 * PATCH /shifts/{id} merge-patch body with only what the person changed in the dialog. An untouched
 * time is left out, so the seconds of a clocked time are kept and no correction is recorded for it.
 * An open shift may stay open (no clock-out entered); an ended one keeps a clock-out.
 */
export function correctShiftBody(
  initial: ShiftFormValues,
  values: ShiftFormValues,
  timeZone: string,
  format: DateTimeFormat,
): ShiftFormResult<CorrectShiftInput> {
  const wasOpen = initial.end.date === "" && initial.end.time === ""
  const { errors, startedAt, endedAt } = times(values, timeZone, format, !wasOpen)
  if (hasErrors(errors)) return { ok: false, errors }

  const body: CorrectShiftInput = {}
  if (startedAt && !sameMoment(initial.start, values.start)) body.startedAt = toZonedIsoString(startedAt, timeZone)
  if (endedAt && !sameMoment(initial.end, values.end)) body.endedAt = toZonedIsoString(endedAt, timeZone)
  if (values.note.trim() !== initial.note.trim()) body.note = values.note.trim()
  return { ok: true, body }
}

/** The note a change request needs: whoever decides it should know why. */
function requestNoteError(values: ShiftFormValues): string | undefined {
  return values.note.trim() === "" ? i18n.t("shiftForm.error.noReason") : undefined
}

/**
 * POST /shift-change-requests body for a shift the person worked but did not clock: both ends and
 * a note required, times with the organization's offset.
 */
export function missingShiftRequestBody(
  values: ShiftFormValues,
  timeZone: string,
  format: DateTimeFormat,
): ShiftFormResult<SubmitShiftChangeRequestInput> {
  const { errors, startedAt, endedAt } = times(values, timeZone, format, true)
  const noteError = requestNoteError(values)
  if (noteError) errors.note ??= noteError
  if (hasErrors(errors) || !startedAt || !endedAt) return { ok: false, errors }

  return {
    ok: true,
    body: {
      startedAt: toZonedIsoString(startedAt, timeZone),
      endedAt: toZonedIsoString(endedAt, timeZone),
      note: values.note.trim(),
    },
  }
}

/**
 * POST /shift-change-requests body asking for new times for one of the person's own shifts. The
 * request carries both ends: a time left as it was is sent as the shift's own instant, so the
 * seconds of a clocked time are kept and the API sees no change there. An open shift may stay open
 * (no clock-out entered: `endedAt: null`); an ended one needs a clock-out. Asking for the times the
 * shift already has is refused here, as the API would (422).
 */
export function newTimesRequestBody(
  shift: Shift,
  values: ShiftFormValues,
  timeZone: string,
  format: DateTimeFormat,
): ShiftFormResult<SubmitShiftChangeRequestInput> {
  const initial = shiftFormFrom(shift, timeZone)
  const wasOpen = shift.endedAt === null
  const { errors, startedAt, endedAt } = times(values, timeZone, format, !wasOpen)
  const noteError = requestNoteError(values)
  if (noteError) errors.note ??= noteError
  if (hasErrors(errors) || !startedAt) return { ok: false, errors }

  const startSame = sameMoment(initial.start, values.start)
  const endSame = endedAt ? sameMoment(initial.end, values.end) : wasOpen
  if (startSame && endSame) {
    return { ok: false, errors: { shiftId: i18n.t("shiftForm.error.unchanged") } }
  }

  return {
    ok: true,
    body: {
      shiftId: shift.id,
      startedAt: startSame ? shift.startedAt : toZonedIsoString(startedAt, timeZone),
      endedAt: !endedAt ? null : endSame ? shift.endedAt : toZonedIsoString(endedAt, timeZone),
      note: values.note.trim(),
    },
  }
}

const UUID = "[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}"
const ISO_INSTANT = /\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})/g

/**
 * A shift error from the API (409 overlap, 422 end before start or in the future) for people:
 * UTC times become the organization's, shift ids go, user ids become names.
 */
export function readableShiftError(message: string, format: DateTimeFormat, names: Record<string, string>): string {
  return message
    .replace(new RegExp(`(S|s)hift "${UUID}"`, "g"), (_, s: string) => (s === "S" ? "The shift" : "shift"))
    .replace(new RegExp(`User "(${UUID})"`, "g"), (_, id: string) => names[id] ?? "This person")
    .replace(ISO_INSTANT, (instant) => format.dateTime(instant))
}
