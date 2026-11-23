import { ApiError } from "@business-tool/api-client"
import { afterEach, beforeEach, describe, expect, it } from "vitest"

import { i18n } from "../i18n"
import { maxShiftHoursProblem, parseMaxShiftHours, timeTrackingSettingsFormErrors } from "./time-tracking-settings"

describe("parseMaxShiftHours", () => {
  it("test_it_takes_whole_hours_from_one_to_twenty_four", () => {
    expect(parseMaxShiftHours("1")).toBe(1)
    expect(parseMaxShiftHours("12")).toBe(12)
    expect(parseMaxShiftHours(" 24 ")).toBe(24)
  })

  it("test_it_refuses_anything_else", () => {
    for (const text of ["0", "25", "10.5", "-3", "", "twelve", "012"]) {
      expect(parseMaxShiftHours(text), text).toBeNull()
    }
  })
})

describe("maxShiftHoursProblem", () => {
  it("test_it_explains_hours_the_api_would_refuse", () => {
    expect(maxShiftHoursProblem("12")).toBeNull()
    expect(maxShiftHoursProblem("30")).toBe("The maximum shift length must be from 1 to 24 hours.")
  })

  describe("in Polish", () => {
    beforeEach(async () => {
      await i18n.changeLanguage("pl")
    })
    afterEach(async () => {
      await i18n.changeLanguage("en")
    })

    it("test_it_explains_it_in_polish", () => {
      expect(maxShiftHoursProblem("")).toBe("Maksymalna długość zmiany musi wynosić od 1 do 24 godzin.")
    })
  })
})

describe("timeTrackingSettingsFormErrors", () => {
  it("test_it_shows_the_refused_hours_on_their_field", () => {
    const invalid = new ApiError(422, { detail: "Invalid", code: "time_tracking.invalid_max_shift_length", parameters: { maxShiftHours: 30, min: 1, max: 24 } })

    expect(timeTrackingSettingsFormErrors(invalid)).toEqual({ maxShiftHours: "The maximum shift length must be from 1 to 24 hours." })
  })

  it("test_it_shows_violations_on_their_field", () => {
    const invalid = new ApiError(422, {
      detail: "Invalid",
      violations: [{ propertyPath: "maxShiftHours", message: "This value should be between 1 and 24." }],
    })

    expect(timeTrackingSettingsFormErrors(invalid)).toEqual({ maxShiftHours: "This value should be between 1 and 24." })
  })

  it("test_it_shows_a_refusal_translated_from_its_code_under_the_form", () => {
    const forbidden = new ApiError(403, { detail: "Forbidden", code: "time_tracking.settings_change_forbidden", parameters: { userId: "x" } })

    expect(timeTrackingSettingsFormErrors(forbidden)).toEqual({ form: "Only owners and managers change the time tracking settings." })
  })
})
