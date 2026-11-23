import { ApiError, type components } from "@business-tool/api-client"
import type { BusinessDate, DateTimeFormat, Instant } from "@business-tool/ui/lib/date-time"

import { type RequestErrorOptions, requestErrorMessage } from "@business-tool/ui/lib/request-error"

import { i18n } from "../i18n"

export type ShiftChangeRequest = components["schemas"]["ShiftChangeRequest"]
export type ShiftChangeRequestStatus = ShiftChangeRequest["status"]
export type RejectShiftChangeRequestInput = components["schemas"]["ShiftChangeRequest.RejectShiftChangeRequestInput"]

/** The status filter: one status, or every request ("all" sends no `status`). */
export type RequestsFilter = ShiftChangeRequestStatus | "all"

/** The filter's options, in the order the page shows them; `requestsFilterLabel()` names them. */
export const REQUEST_FILTERS: readonly RequestsFilter[] = ["pending", "approved", "rejected", "withdrawn", "all"]

export function requestsFilterLabel(filter: RequestsFilter): string {
  return i18n.t(`shiftRequests.filter.${filter}`, { ns: "admin" })
}

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
  if (status === "approved" || status === "rejected" || status === "withdrawn" || status === "all") return { status }
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
  if (endedAt === null) return i18n.t("shiftRequests.openRange", { ns: "admin", start })
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
  const summary = (key: "missingShift" | "both" | "clockIn" | "keepsOpen" | "endsOpen" | "clockOut") =>
    i18n.t(`shiftRequests.change.${key}`, { ns: "admin" })
  if (request.kind === "missing_shift") return summary("missingShift")
  const { start, end } = changedEnds(request)
  if (start && end) return summary("both")
  if (start) return summary("clockIn")
  if (request.endedAt === null) return summary("keepsOpen")
  return request.shiftEndedAt === null ? summary("endsOpen") : summary("clockOut")
}

export interface DecisionError {
  message: string
  /** The request is still pending, so it can be decided again (after the other shift is fixed, say). */
  stillPending: boolean
}

/**
 * Why approving or rejecting failed, for people. The shift rules (409 overlap, 422 end before start
 * or in the future, 422 a shift that has ended since) leave the request pending; "already decided"
 * means someone else was quicker, "withdrawn" that the requester took it back, and a 404 that the
 * request is gone. Times are shown in the
 * organization's zone (`format`), user ids as names.
 */
export function decisionError(
  error: unknown,
  format: DateTimeFormat,
  options: Omit<RequestErrorOptions, "format"> = {},
): DecisionError {
  const message = requestErrorMessage(error, { ...options, format })
  if (!(error instanceof ApiError)) return { message, stillPending: true }
  const settled =
    error.code === "time_tracking.shift_change_request_already_decided" ||
    error.code === "time_tracking.shift_change_request_withdrawn" ||
    error.status === 404
  return { message, stillPending: !settled }
}
