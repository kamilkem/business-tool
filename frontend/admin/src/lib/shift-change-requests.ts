import { ApiError, type components } from "@business-tool/api-client"
import type { BusinessDate, DateTimeFormat, Instant } from "@business-tool/ui/lib/date-time"

import { readableShiftError } from "@business-tool/ui/lib/shift-form"

export type ShiftChangeRequest = components["schemas"]["ShiftChangeRequest"]
export type ShiftChangeRequestStatus = ShiftChangeRequest["status"]
export type RejectShiftChangeRequestInput = components["schemas"]["ShiftChangeRequest.RejectShiftChangeRequestInput"]

/** The status filter: one status, or every request ("all" sends no `status`). */
export type RequestsFilter = ShiftChangeRequestStatus | "all"

export const requestFilters: { value: RequestsFilter; label: string }[] = [
  { value: "pending", label: "Pending" },
  { value: "approved", label: "Approved" },
  { value: "rejected", label: "Rejected" },
  { value: "all", label: "All" },
]

export interface RequestsSearch {
  /** Absent means pending, the default, which stays out of the URL. */
  status?: Exclude<RequestsFilter, "pending">
}

/**
 * The requests page's search params (`?status=`). Pending is the default; anything else unknown
 * falls back to it. The key is set to `undefined` rather than left out, because the router merges
 * the raw search params under the validated ones.
 */
export function validateRequestsSearch(search: Record<string, unknown>): RequestsSearch {
  const { status } = search
  if (status === "approved" || status === "rejected" || status === "all") return { status }
  return { status: undefined }
}

export function filterOf(search: RequestsSearch): RequestsFilter {
  return search.status ?? "pending"
}

export const REASON_MAX_LENGTH = 1000

/** POST .../reject body: the reason when one was given, nothing otherwise. */
export function rejectBody(reason: string): RejectShiftChangeRequestInput {
  const trimmed = reason.trim()
  return trimmed === "" ? {} : { reason: trimmed }
}

/**
 * A shift's times as one line, in the organization's time zone: "Tue 10 Nov, 22:00 – 04:00 (11 Nov)", with
 * the end's date when it falls on a later day, or "… – open" for a shift still running. In a table row
 * that already shows the day (`onDate`), a start on that day is just its time: "22:00 – 04:00 (11 Nov)".
 */
export function timeRange(
  startedAt: Instant,
  endedAt: Instant | null,
  format: DateTimeFormat,
  onDate?: BusinessDate,
): string {
  const startDate = format.dateOf(startedAt)
  const start =
    startDate === onDate
      ? format.time(startedAt)
      : `${format.date(startedAt, { weekday: "short", day: "numeric", month: "short" })}, ${format.time(startedAt)}`
  if (endedAt === null) return `${start} – open`
  const endDate = format.dateOf(endedAt)
  const laterDay = endDate === startDate ? "" : ` (${format.businessDate(endDate, { day: "numeric", month: "short" })})`
  return `${start} – ${format.time(endedAt)}${laterDay}`
}

/** The same minute: the page shows minutes, so a clocked 22:00:37 asked for as 22:00 is not a change to point out. */
function sameMinute(a: Instant | null, b: Instant | null): boolean {
  if (a === null || b === null) return a === b
  const minute = (instant: Instant) => Math.floor(new Date(instant).getTime() / 60_000)
  return minute(a) === minute(b)
}

/** Which ends a request changes, as the page shows them. A missing shift changes both: there is nothing yet. */
export function changedEnds(request: ShiftChangeRequest): { start: boolean; end: boolean } {
  if (request.shiftStartedAt === null) return { start: true, end: true }
  return {
    start: !sameMinute(request.startedAt, request.shiftStartedAt),
    end: !sameMinute(request.endedAt, request.shiftEndedAt),
  }
}

/** What a request changes, in a few words under the times asked for. */
export function changeSummary(request: ShiftChangeRequest): string {
  if (request.kind === "missing_shift") return "Enters a shift that was not clocked"
  const { start, end } = changedEnds(request)
  if (start && end) return "Changes the clock-in and the clock-out"
  if (start) return "Changes the clock-in"
  if (request.endedAt === null) return "Keeps the shift open"
  return request.shiftEndedAt === null ? "Ends the open shift" : "Changes the clock-out"
}

const UUID = "[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}"

export interface DecisionError {
  message: string
  /** The request is still pending, so it can be decided again (after the other shift is fixed, say). */
  stillPending: boolean
}

/**
 * Why approving or rejecting failed, for people. The shift rules (409 overlap, 422 end before start
 * or in the future, 422 a shift that has ended since) leave the request pending; a 409 "already
 * decided" means someone else was quicker. Times are shown in the organization's zone, ids as names.
 */
export function decisionError(
  error: unknown,
  format: DateTimeFormat,
  names: Record<string, string>,
  /** The requester's first name, for "the person's shift" in an overlap. */
  person?: string,
): DecisionError {
  if (!(error instanceof ApiError)) {
    return { message: "Could not reach the server. Check your connection and try again.", stillPending: true }
  }
  if (error.status >= 500) {
    return { message: "Something went wrong on our side. Try again in a moment.", stillPending: true }
  }
  let detail = error.message.replace(new RegExp(`Shift change request "${UUID}"`, "g"), "This request")
  if (person) detail = detail.replaceAll("the person's shift", `${person}'s shift`)
  const message = readableShiftError(detail, format, names)
  if (error.status === 409 && /already been decided/.test(error.message)) return { message, stillPending: false }
  if (error.status === 404) return { message: "This request no longer exists.", stillPending: false }
  return { message, stillPending: true }
}
