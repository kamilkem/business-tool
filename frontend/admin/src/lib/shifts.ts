import type { components } from "@business-tool/api-client"
import { isBusinessDate, periodError, type BusinessPeriod } from "@business-tool/ui/lib/date-time"

export type Shift = components["schemas"]["ShiftListItem"]

/** The longest period GET /shifts accepts (ListShiftsQuery::MAX_DAYS). */
export const SHIFTS_MAX_DAYS = 92

/** Still clocked in: no clock-out, so no duration yet. */
export function isOpen(shift: Shift): boolean {
  return shift.endedAt === null
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

/** The sum of all the people's totals, for the table's footer. */
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

export interface ShiftsSearch {
  from?: string
  to?: string
  userId?: string
}

/**
 * The time page's search params (`?from=&to=&userId=`). A period the API would refuse (a bad date,
 * `to` before `from`, longer than 92 days) is dropped as a whole, so the page falls back to this month.
 * Dropped keys are set to `undefined` rather than left out: the router merges the raw search params
 * under the validated ones, so a missing key would let the raw value through.
 */
export function validateShiftsSearch(search: Record<string, unknown>): ShiftsSearch {
  const result: ShiftsSearch = { from: undefined, to: undefined, userId: undefined }
  const { from, to, userId } = search
  if (typeof from === "string" && typeof to === "string" && isBusinessDate(from) && isBusinessDate(to)) {
    if (periodError({ from, to }, SHIFTS_MAX_DAYS) === null) {
      result.from = from
      result.to = to
    }
  }
  if (typeof userId === "string" && userId !== "") result.userId = userId
  return result
}

/** The period in the search params, or `fallback` (this month in the organization's time zone). */
export function periodOf(search: ShiftsSearch, fallback: BusinessPeriod): BusinessPeriod {
  return search.from && search.to ? { from: search.from, to: search.to } : fallback
}
