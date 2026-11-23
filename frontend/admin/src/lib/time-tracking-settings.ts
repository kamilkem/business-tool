import { ApiError, type components, violationsOf } from "@business-tool/api-client"
import { requestErrorMessage } from "@business-tool/ui/lib/request-error"

import { i18n } from "../i18n"

/*
 * The organization's time tracking settings (#33): the maximum shift length, after which a shift
 * is flagged as a possibly forgotten clock-out and, while open, reported once by e-mail.
 */

export type TimeTrackingSettings = components["schemas"]["TimeTrackingSettings"]

/** The range the API takes (MaxShiftLength::MIN_HOURS and MAX_HOURS). */
export const MIN_MAX_SHIFT_HOURS = 1
export const MAX_MAX_SHIFT_HOURS = 24

/** The hours typed into the form, or null when the text is not a whole number of hours in range. */
export function parseMaxShiftHours(text: string): number | null {
  if (!/^\d{1,2}$/.test(text.trim())) return null
  const hours = Number(text.trim())
  return hours >= MIN_MAX_SHIFT_HOURS && hours <= MAX_MAX_SHIFT_HOURS ? hours : null
}

/** The message for hours the form would not send, or null when the text is fine. */
export function maxShiftHoursProblem(text: string): string | null {
  if (parseMaxShiftHours(text) !== null) return null
  return i18n.t("apiError.time_tracking.invalid_max_shift_length", { min: MIN_MAX_SHIFT_HOURS, max: MAX_MAX_SHIFT_HOURS })
}

/** Where to show a refused save: on the hours, or under the form. */
export interface TimeTrackingSettingsFormErrors {
  maxShiftHours?: string
  form?: string
}

/** Turns the API's refusal of a save into messages for the form. */
export function timeTrackingSettingsFormErrors(error: unknown): TimeTrackingSettingsFormErrors {
  if (error instanceof ApiError && error.status === 422) {
    const { maxShiftHours, ...others } = violationsOf(error)
    const rest = Object.values(others).join(" ")
    const result: TimeTrackingSettingsFormErrors = {}
    if (maxShiftHours) result.maxShiftHours = maxShiftHours
    else if (error.code === "time_tracking.invalid_max_shift_length") result.maxShiftHours = requestErrorMessage(error)
    if (rest || !result.maxShiftHours) result.form = rest || requestErrorMessage(error)
    return result
  }
  return { form: requestErrorMessage(error) }
}
