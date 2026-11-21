import type { components } from "@business-tool/api-client"
import { addDays, type DateTimeFormat, type Instant } from "@business-tool/ui/lib/date-time"

import { i18n } from "../i18n"

/** One of the signed-in person's requests to an owner or manager to change their shifts (#84). */
export type ShiftChangeRequest = components["schemas"]["ShiftChangeRequest"]
export type ShiftChangeRequestStatus = ShiftChangeRequest["status"]

/** "Pending", "Approved" or "Rejected", in the language shown. */
export function statusLabel(status: ShiftChangeRequestStatus): string {
  return i18n.t(`requests.status.${status}`, { ns: "timeclock" })
}

/**
 * "Tue 10 Nov, 22:00 – 03:30", with the end's day when it is not the next morning of a night shift
 * ("Tue 10 Nov, 22:00 – Thu 12 Nov 01:00"), or "– open" for an open shift. On the organization's clock.
 */
export function timeRange(format: DateTimeFormat, startedAt: Instant, endedAt: Instant | null): string {
  const day = format.date(startedAt, { weekday: "short", day: "numeric", month: "short" })
  const start = `${day}, ${format.time(startedAt)}`
  if (endedAt === null) return i18n.t("requests.open", { ns: "timeclock", start })
  const startDate = format.dateOf(startedAt)
  const endDate = format.dateOf(endedAt)
  const end = format.time(endedAt)
  if (endDate === startDate) return `${start} – ${end}`
  // A night shift reads naturally without the next day's name: the end time is smaller.
  if (endDate === addDays(startDate, 1) && end <= format.time(startedAt)) return `${start} – ${end}`
  return `${start} – ${format.date(endedAt, { weekday: "short", day: "numeric", month: "short" })} ${end}`
}

/** "Missing shift" or "New times". */
export function requestTitle(request: ShiftChangeRequest): string {
  return i18n.t(request.kind === "missing_shift" ? "requests.missingShift" : "requests.newTimes", { ns: "timeclock" })
}

/**
 * The shift's times now, to compare with the times asked for while the request is waiting or
 * after it was rejected: "Now Tue 10 Nov, 22:00 – 03:00". Null for a missing shift, or once approved (the
 * shift's times are then the ones asked for).
 */
export function previousTimes(format: DateTimeFormat, request: ShiftChangeRequest): string | null {
  if (request.kind !== "new_times" || request.status === "approved" || request.shiftStartedAt === null) return null
  return i18n.t("requests.now", { ns: "timeclock", range: timeRange(format, request.shiftStartedAt, request.shiftEndedAt) })
}

/** "Sent 12 Nov 2026, 01:00" or "Approved 12 Nov 2026, 09:15", in the organization's time zone. */
export function requestWhen(format: DateTimeFormat, request: ShiftChangeRequest): string {
  if (request.status !== "pending" && request.decidedAt !== null) {
    const when = format.dateTime(request.decidedAt)
    return i18n.t(request.status === "approved" ? "requests.approvedOn" : "requests.rejectedOn", { ns: "timeclock", when })
  }
  return i18n.t("requests.sent", { ns: "timeclock", when: format.dateTime(request.submittedAt) })
}
