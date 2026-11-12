import type { components } from "@business-tool/api-client"
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
} from "@business-tool/ui/lib/date-time"

import type { Shift } from "@business-tool/ui/lib/shifts"

export type AddShiftInput = components["schemas"]["Shift.AddShiftInput"]
export type CorrectShiftInput = components["schemas"]["Shift.CorrectShiftInput.jsonMergePatch"]

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
export type ShiftFormErrors = Partial<Record<"userId" | "startedAt" | "endedAt" | "note", string>>

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

/** The two offsets of a repeated wall time, earliest first, e.g. ["+02:00", "+01:00"]; null when it happens once. */
export function repeatedOffsets(moment: Pick<ShiftMoment, "date" | "time">, timeZone: string): [string, string] | null {
  if (!isBusinessDate(moment.date) || !isWallTime(moment.time)) return null
  const [first, second] = instantsAt(moment, timeZone)
  if (!first || !second) return null
  return [formatUtcOffset(utcOffsetMinutes(first, timeZone)), formatUtcOffset(utcOffsetMinutes(second, timeZone))]
}

type Resolved = { instant: Date } | { error: string }

/** The instant a typed moment names in the time zone, or why it names none. */
function resolve(moment: ShiftMoment, timeZone: string, format: DateTimeFormat, what: string): Resolved {
  if (!isBusinessDate(moment.date)) return { error: `Enter the ${what} date.` }
  if (!isWallTime(moment.time)) return { error: `Enter the ${what} time.` }
  const local: LocalDateTime = { date: moment.date, time: moment.time }
  const candidates = instantsAt(local, timeZone)
  const instant = moment.occurrence === "second" ? (candidates[1] ?? candidates[0]) : candidates[0]
  if (!instant) {
    return {
      error: `${moment.time} does not exist on ${format.businessDate(moment.date)}: the clocks moved forward. Enter a time after the change.`,
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
  const start = resolve(values.start, timeZone, format, "clock-in")
  const endEmpty = values.end.date === "" && values.end.time === ""
  const end = endEmpty && !endRequired ? null : resolve(values.end, timeZone, format, "clock-out")

  if ("error" in start) errors.startedAt = start.error
  if (end && "error" in end) errors.endedAt = end.error
  const startedAt = "instant" in start ? start.instant : undefined
  const endedAt = end && "instant" in end ? end.instant : undefined
  if (startedAt && endedAt && endedAt <= startedAt) errors.endedAt = "The clock-out must be after the clock-in."
  if (values.note.trim().length > NOTE_MAX_LENGTH) errors.note = `Keep the note under ${NOTE_MAX_LENGTH} characters.`
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
  if (values.userId === "") errors.userId = "Choose who worked the shift."
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
