import { DEFAULT_MAX_SHIFT_HOURS, hoursSince, isLongerThanMax } from "@business-tool/ui/lib/shifts"

import type { Shift } from "./queries"

/**
 * The whole hours the person has been clocked in, when their open shift runs longer than the
 * organization's maximum shift length (#33), for "Did you forget to clock out?"; null otherwise.
 * Until the settings load, the default limit applies.
 */
export function longShiftHours(shift: Shift | null | undefined, maxShiftHours: number | undefined, now: Date): number | null {
  if (!shift || shift.endedAt !== null) return null
  if (!isLongerThanMax(shift, maxShiftHours ?? DEFAULT_MAX_SHIFT_HOURS, now)) return null
  return hoursSince(shift.startedAt, now)
}
