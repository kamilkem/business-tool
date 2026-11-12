import { isBusinessDate, periodError, type BusinessPeriod } from "@business-tool/ui/lib/date-time"
import { SHIFTS_MAX_DAYS } from "@business-tool/ui/lib/shifts"

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
