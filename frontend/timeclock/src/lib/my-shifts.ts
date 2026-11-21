import { monthOf, type BusinessPeriod, type DateTimeFormat } from "@business-tool/ui/lib/date-time"
import type { Shift } from "@business-tool/ui/lib/shifts"

import { i18n } from "../i18n"

/** A calendar month in the search params, "YYYY-MM". */
export type Month = string

const MONTH = /^(\d{4})-(0[1-9]|1[0-2])$/

export interface MyShiftsSearch {
  month?: Month
}

/**
 * The shifts screen's search params (`?month=2026-10`). Without one it shows this month in the
 * organization's time zone. An invalid month is set to `undefined` rather than left out: the router
 * merges the raw search params under the validated ones.
 */
export function validateMyShiftsSearch(search: Record<string, unknown>): MyShiftsSearch {
  const { month } = search
  return { month: typeof month === "string" && MONTH.test(month) ? month : undefined }
}

/** The month in the search params as a period, or `fallback` (this month). */
export function monthPeriodOf(month: Month | undefined, fallback: BusinessPeriod): BusinessPeriod {
  return month ? monthOf(`${month}-01`) : fallback
}

/** "2026-10" for October 2026 (any period: the month it starts in). */
export function monthKey(period: BusinessPeriod): Month {
  return period.from.slice(0, 7)
}

/**
 * "16:00 – 23:30", "22:00 – Thu 04:00" for a night shift that ended on a later date, or
 * "22:00 – now" while it is open. Times are on the organization's clock.
 */
export function shiftHours(format: DateTimeFormat, shift: Shift): string {
  const start = format.time(shift.startedAt)
  if (shift.endedAt === null) return i18n.t("shifts.untilNow", { ns: "timeclock", start })
  const end = format.time(shift.endedAt)
  if (format.dateOf(shift.endedAt) === shift.date) return `${start} – ${end}`
  return `${start} – ${format.date(shift.endedAt, { weekday: "short" })} ${end}`
}

/**
 * Who entered or changed the shift and when, so an employee notices a change to their hours:
 * "Entered by a manager on 12 Nov 2026, 01:00" or "Changed by a manager on …". Null while the
 * shift is as clocked. An owner or manager who changed their own shift reads "by you".
 */
export function shiftChange(format: DateTimeFormat, shift: Shift, myUserId: string): string | null {
  if (shift.source === "clock" && shift.correctedAt === null) return null
  const who = shift.correctedBy === myUserId ? "You" : "Manager"
  const what = shift.source === "manager" ? "entered" : "changed"
  if (shift.correctedAt === null) return i18n.t(`shifts.change.${what}By${who}`, { ns: "timeclock" })
  return i18n.t(`shifts.change.${what}By${who}On`, { ns: "timeclock", when: format.dateTime(shift.correctedAt) })
}

/** "in 1 shift", "in 12 shifts" ("z 12 zmian"), under a total of worked time. */
export function inShifts(count: number): string {
  return i18n.t("common.inShifts", { ns: "timeclock", count })
}
