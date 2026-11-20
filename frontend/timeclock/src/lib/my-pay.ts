import { formatDuration } from "@business-tool/ui/lib/date-time"

import type { MyPay } from "./queries"
import { shiftCount } from "./my-shifts"

/** Whole minutes of a length in seconds, for `formatDuration()`: 2 h 29 min 59 s is "2 h 29 min". */
export function minutesOf(seconds: number): number {
  return Math.floor(seconds / 60)
}

/** "Estimated pay" or "Approved pay": an estimate is never shown as if it were what will be paid. */
export function payHeading(pay: MyPay): string {
  return pay.status === "approved" ? "Approved pay" : "Estimated pay"
}

/** What the amount is, in a sentence under it. */
export function payExplanation(pay: MyPay): string {
  if (pay.status === "approved") return "Gross, as approved for payroll."
  return "Gross, from your finished shifts so far at your rate. The approved amount can differ: hours may be rounded, and bonuses or advances are added then."
}

/**
 * The warning when shifts have no rate, or null: the estimate leaves them out, so the person should
 * know the amount is too low and whom to ask.
 */
export function noRateNotice(pay: MyPay): string | null {
  if (pay.shiftsWithoutRate === 0) return null
  const which =
    pay.shiftsWithoutRate === pay.shifts
      ? "No pay rate is set for you yet, so this shows no pay."
      : `No pay rate is set for ${shiftCount(pay.shiftsWithoutRate)}, so ${pay.shiftsWithoutRate === 1 ? "it isn't" : "they aren't"} counted.`
  return `${which} Ask your manager to set your rate.`
}

/** "in 3 shifts", or the paid time when the approved run rounded it: "paid 7 h 30 min (rounded)". */
export function hoursDetail(pay: MyPay): string {
  if (pay.shifts === 0) return "No finished shifts"
  const shifts = `in ${shiftCount(pay.shifts)}`
  if (pay.paidSeconds === pay.seconds) return shifts
  return `${shifts} · paid ${formatDuration(minutesOf(pay.paidSeconds))} (rounded)`
}
