import type { components } from "@business-tool/api-client"

/** One shift from GET /shifts (`ShiftListItem`), as the admin's time page and the timeclock list it. */
export type Shift = components["schemas"]["ShiftListItem"]

/** The longest period GET /shifts accepts (ListShiftsQuery::MAX_DAYS). */
export const SHIFTS_MAX_DAYS = 92

/** Still clocked in: no clock-out, so no duration yet. */
export function isOpen(shift: Shift): boolean {
  return shift.endedAt === null
}

/**
 * Whether an owner or manager can try to delete the shift: only once it has ended, as the API
 * refuses a running one (`time_tracking.open_shift_cannot_be_deleted`). A shift in a locked month
 * or with a pending change request is refused too, but the list does not say so: the API's 409 does.
 */
export function canBeDeleted(shift: Shift): boolean {
  return !isOpen(shift)
}

/** The maximum shift length while an organization has not set one (MaxShiftLength::DEFAULT_HOURS). */
export const DEFAULT_MAX_SHIFT_HOURS = 12

/**
 * Whether a shift runs longer than the organization's maximum shift length (#33): a possibly
 * forgotten clock-out. An open shift counts up to `now`; exactly the limit is still within it, as
 * on the API (MaxShiftLength::isExceededBy()).
 */
export function isLongerThanMax(
  shift: { startedAt: string; endedAt: string | null },
  maxShiftHours: number,
  now: Date,
): boolean {
  const end = shift.endedAt === null ? now.getTime() : Date.parse(shift.endedAt)
  // The API counts whole seconds.
  const seconds = Math.floor(end / 1000) - Math.floor(Date.parse(shift.startedAt) / 1000)
  return seconds > maxShiftHours * 3600
}

/** Whole hours from the shift's start to `now`, for "clocked in for N h". */
export function hoursSince(startedAt: string, now: Date): number {
  return Math.max(0, Math.floor((now.getTime() - Date.parse(startedAt)) / 3_600_000))
}

export interface EmployeeTotal {
  userId: string
  firstName: string
  lastName: string
  /** Every shift in the period, open ones included. */
  shifts: number
  /** Shifts with no clock-out yet; their time is not in `workedMinutes`. */
  openShifts: number
  /** Whole minutes of the closed shifts, the time that payroll counts. */
  workedMinutes: number
}

/**
 * Totals per person over the shifts of a period, sorted by last name, then first name.
 * Open shifts count as shifts but add no time until they are closed.
 */
export function totalsPerEmployee(shifts: readonly Shift[]): EmployeeTotal[] {
  const totals = new Map<string, EmployeeTotal>()
  for (const shift of shifts) {
    let total = totals.get(shift.userId)
    if (!total) {
      total = {
        userId: shift.userId,
        firstName: shift.firstName,
        lastName: shift.lastName,
        shifts: 0,
        openShifts: 0,
        workedMinutes: 0,
      }
      totals.set(shift.userId, total)
    }
    total.shifts += 1
    if (isOpen(shift)) total.openShifts += 1
    else total.workedMinutes += shift.durationMinutes ?? 0
  }

  return [...totals.values()].sort(
    (a, b) =>
      a.lastName.localeCompare(b.lastName) ||
      a.firstName.localeCompare(b.firstName) ||
      a.userId.localeCompare(b.userId),
  )
}

/** The sum of all the people's totals: the admin table's footer, or one person's month on the timeclock. */
export function grandTotal(totals: readonly EmployeeTotal[]): Omit<EmployeeTotal, "userId" | "firstName" | "lastName"> {
  return totals.reduce(
    (sum, t) => ({
      shifts: sum.shifts + t.shifts,
      openShifts: sum.openShifts + t.openShifts,
      workedMinutes: sum.workedMinutes + t.workedMinutes,
    }),
    { shifts: 0, openShifts: 0, workedMinutes: 0 },
  )
}
