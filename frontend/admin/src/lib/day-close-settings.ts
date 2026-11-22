import { ApiError, type components, violationsOf } from "@business-tool/api-client"
import { requestErrorMessage } from "@business-tool/ui/lib/request-error"

import { i18n } from "../i18n"

/*
 * How an end-of-day close gets its business date (ADR 0033 §5): from the day-end time, a local
 * time before which a close belongs to the previous date, or picked by the employee (today or
 * yesterday, preselected from the day-end time). Owners and managers pick any date up to today
 * either way.
 */

export type DayCloseSettings = components["schemas"]["DayCloseSettings"]
export type DayCloseDateMode = DayCloseSettings["mode"]

/** The modes, in the order the form offers them; their texts are `dayCloseSettings.mode.<value>`. */
export const DAY_CLOSE_DATE_MODES: readonly DayCloseDateMode[] = ["day_end_time", "employee_picks"]

/** The latest day-end time the API takes (`DayEndTime::LATEST`). */
export const LATEST_DAY_END_TIME = "12:00"

/** Whether the text is a day-end time the API takes: `HH:MM` from 00:00 to 12:00. */
export function isDayEndTime(text: string): boolean {
  const match = /^(\d{2}):(\d{2})$/.exec(text)
  if (!match) return false
  const hours = Number(match[1])
  const minutes = Number(match[2])
  return minutes < 60 && hours * 60 + minutes <= 12 * 60
}

/** Whether the form's values differ from the saved settings, so saving would change something. */
export function dayCloseSettingsChanged(settings: DayCloseSettings, values: { mode: DayCloseDateMode; dayEndsAt: string }): boolean {
  return settings.mode !== values.mode || settings.dayEndsAt !== values.dayEndsAt
}

/** Where to show a refused save: on the day-end time, or under the form. */
export interface DayCloseSettingsFormErrors {
  dayEndsAt?: string
  form?: string
}

/** The message for a day-end time the form would not send, or null when the text is fine. */
export function dayEndTimeProblem(text: string): string | null {
  if (isDayEndTime(text)) return null
  return i18n.t("apiError.cash_flow.invalid_day_end_time", { latest: LATEST_DAY_END_TIME })
}

/** Turns the API's refusal of a save into messages for the form. */
export function dayCloseSettingsFormErrors(error: unknown): DayCloseSettingsFormErrors {
  if (error instanceof ApiError && error.status === 422) {
    const { dayEndsAt, ...others } = violationsOf(error)
    const rest = Object.values(others).join(" ")
    const result: DayCloseSettingsFormErrors = {}
    if (dayEndsAt) result.dayEndsAt = dayEndsAt
    else if (error.code === "cash_flow.invalid_day_end_time") result.dayEndsAt = requestErrorMessage(error)
    if (rest || !result.dayEndsAt) result.form = rest || requestErrorMessage(error)
    return result
  }
  return { form: requestErrorMessage(error) }
}
