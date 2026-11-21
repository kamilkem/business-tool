import { formatDuration } from "@business-tool/ui/lib/date-time"

import { i18n } from "../i18n"

import type { MyPay } from "./queries"
import { inShifts } from "./my-shifts"

/** Whole minutes of a length in seconds, for `formatDuration()`: 2 h 29 min 59 s is "2 h 29 min". */
export function minutesOf(seconds: number): number {
  return Math.floor(seconds / 60)
}

/** "Estimated pay" or "Approved pay": an estimate is never shown as if it were what will be paid. */
export function payHeading(pay: MyPay): string {
  return i18n.t(pay.status === "approved" ? "pay.approvedHeading" : "pay.estimatedHeading", { ns: "timeclock" })
}

/** What the amount is, in a sentence under it. */
export function payExplanation(pay: MyPay): string {
  return i18n.t(pay.status === "approved" ? "pay.approvedExplanation" : "pay.estimateExplanation", { ns: "timeclock" })
}

/**
 * The warning when shifts have no rate, or null: the estimate leaves them out, so the person should
 * know the amount is too low and whom to ask.
 */
export function noRateNotice(pay: MyPay): string | null {
  if (pay.shiftsWithoutRate === 0) return null
  if (pay.shiftsWithoutRate === pay.shifts) return i18n.t("pay.noRate", { ns: "timeclock" })
  return i18n.t("pay.someWithoutRate", { ns: "timeclock", count: pay.shiftsWithoutRate })
}

/** "in 3 shifts", or the paid time when the approved run rounded it: "paid 7 h 30 min (rounded)". */
export function hoursDetail(pay: MyPay): string {
  if (pay.shifts === 0) return i18n.t("common.noFinishedShifts", { ns: "timeclock" })
  const shifts = inShifts(pay.shifts)
  if (pay.paidSeconds === pay.seconds) return shifts
  return i18n.t("pay.paidRounded", { ns: "timeclock", shifts, duration: formatDuration(minutesOf(pay.paidSeconds)) })
}
