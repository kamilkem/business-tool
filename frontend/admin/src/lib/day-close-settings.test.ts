import { ApiError } from "@business-tool/api-client"
import { afterEach, beforeEach, describe, expect, it } from "vitest"

import { i18n } from "../i18n"
import {
  type DayCloseSettings,
  dayCloseSettingsChanged,
  dayCloseSettingsFormErrors,
  dayEndTimeProblem,
  isDayEndTime,
} from "./day-close-settings"

describe("isDayEndTime", () => {
  it("test_it_takes_times_from_midnight_to_noon", () => {
    expect(isDayEndTime("00:00")).toBe(true)
    expect(isDayEndTime("05:00")).toBe(true)
    expect(isDayEndTime("11:59")).toBe(true)
    expect(isDayEndTime("12:00")).toBe(true)
  })

  it("test_it_refuses_times_after_noon_and_anything_that_is_not_hh_mm", () => {
    expect(isDayEndTime("12:01")).toBe(false)
    expect(isDayEndTime("18:00")).toBe(false)
    expect(isDayEndTime("05:60")).toBe(false)
    expect(isDayEndTime("5:00")).toBe(false)
    expect(isDayEndTime("05:00:00")).toBe(false)
    expect(isDayEndTime("")).toBe(false)
  })
})

describe("dayEndTimeProblem", () => {
  it("test_it_explains_a_time_the_api_would_refuse", () => {
    expect(dayEndTimeProblem("05:00")).toBeNull()
    expect(dayEndTimeProblem("13:00")).toBe("The day-end time must be a time from 00:00 to 12:00.")
  })

  describe("in Polish", () => {
    beforeEach(async () => {
      await i18n.changeLanguage("pl")
    })
    afterEach(async () => {
      await i18n.changeLanguage("en")
    })

    it("test_it_explains_it_in_polish", () => {
      expect(dayEndTimeProblem("")).toContain("12:00")
      expect(dayEndTimeProblem("")).not.toBe("The day-end time must be a time from 00:00 to 12:00.")
    })
  })
})

describe("dayCloseSettingsChanged", () => {
  const settings: DayCloseSettings = { mode: "day_end_time", dayEndsAt: "05:00", updatedAt: null }

  it("test_it_says_whether_saving_would_change_something", () => {
    expect(dayCloseSettingsChanged(settings, { mode: "day_end_time", dayEndsAt: "05:00" })).toBe(false)
    expect(dayCloseSettingsChanged(settings, { mode: "employee_picks", dayEndsAt: "05:00" })).toBe(true)
    expect(dayCloseSettingsChanged(settings, { mode: "day_end_time", dayEndsAt: "04:30" })).toBe(true)
  })
})

describe("dayCloseSettingsFormErrors", () => {
  it("test_it_shows_the_refused_day_end_time_on_its_field", () => {
    const invalid = new ApiError(422, { detail: "Invalid", code: "cash_flow.invalid_day_end_time", parameters: { latest: "12:00" } })

    expect(dayCloseSettingsFormErrors(invalid)).toEqual({ dayEndsAt: "The day-end time must be a time from 00:00 to 12:00." })
  })

  it("test_it_shows_violations_on_their_fields_and_others_under_the_form", () => {
    const invalid = new ApiError(422, {
      detail: "Invalid",
      violations: [
        { propertyPath: "dayEndsAt", message: "This value should not be blank." },
        { propertyPath: "mode", message: "The value you selected is not a valid choice." },
      ],
    })

    expect(dayCloseSettingsFormErrors(invalid)).toEqual({
      dayEndsAt: "This value should not be blank.",
      form: "The value you selected is not a valid choice.",
    })
  })

  it("test_it_shows_a_refusal_translated_from_its_code_under_the_form", () => {
    const forbidden = new ApiError(403, { detail: "Forbidden", code: "cash_flow.access_forbidden.to_manage", parameters: {} })

    expect(dayCloseSettingsFormErrors(forbidden)).toEqual({ form: "Only owners and managers can change cash flow settings or move cash." })
  })
})
