import { ApiError } from "@business-tool/api-client"
import { describe, expect, it } from "vitest"

import { type Register, registerFormErrors, splitRegisters, withRegister } from "./registers"

function register(name: string, active = true, id = `register-${name}`): Register {
  return {
    id,
    name,
    active,
    createdAt: "2026-11-01T10:00:00+00:00",
    deactivatedAt: active ? null : "2026-11-10T10:00:00+00:00",
  }
}

const bar = register("Bar")
const kitchen = register("Kitchen")
const terrace = register("Terrace", false)

describe("splitRegisters", () => {
  it("test_it_splits_active_and_deactivated_registers_keeping_their_order", () => {
    expect(splitRegisters([bar, kitchen, terrace])).toEqual({ active: [bar, kitchen], deactivated: [terrace] })
    expect(splitRegisters([])).toEqual({ active: [], deactivated: [] })
  })
})

describe("withRegister", () => {
  it("test_it_puts_an_added_register_in_name_order_before_the_deactivated_ones", () => {
    const cellar = register("Cellar")

    expect(withRegister([bar, kitchen, terrace], cellar)).toEqual([bar, cellar, kitchen, terrace])
  })

  it("test_it_moves_a_renamed_register_to_its_new_place", () => {
    const renamed = { ...bar, name: "Main bar" }

    expect(withRegister([bar, kitchen, terrace], renamed)).toEqual([kitchen, renamed, terrace])
  })

  it("test_it_moves_a_deactivated_register_among_the_deactivated_ones", () => {
    const deactivated = { ...bar, active: false, deactivatedAt: "2026-11-12T10:00:00+00:00" }

    expect(withRegister([bar, kitchen, terrace], deactivated)).toEqual([kitchen, deactivated, terrace])
  })

  it("test_it_does_not_change_the_list_it_was_given", () => {
    const list = [bar, kitchen]
    withRegister(list, register("Cellar"))

    expect(list).toEqual([bar, kitchen])
  })
})

describe("registerFormErrors", () => {
  const registers = [bar, kitchen, terrace]

  it("test_it_shows_a_duplicate_name_on_the_name_field", () => {
    const conflict = new ApiError(409, { detail: 'Register "Bar" already exists.' })

    expect(registerFormErrors(conflict, "  Bar ", registers)).toEqual({ name: "A register named “Bar” already exists." })
  })

  it("test_it_says_when_the_name_belongs_to_a_deactivated_register", () => {
    const conflict = new ApiError(409, { detail: "Conflict" })

    expect(registerFormErrors(conflict, "Terrace", registers).name).toContain("deactivated register")
  })

  it("test_it_shows_name_violations_on_the_name_field", () => {
    const invalid = new ApiError(422, {
      detail: "name: This value should not be blank.",
      violations: [{ propertyPath: "name", message: "This value should not be blank." }],
    })

    expect(registerFormErrors(invalid, "", registers)).toEqual({ name: "This value should not be blank." })
  })

  it("test_it_shows_violations_of_other_fields_under_the_form", () => {
    const invalid = new ApiError(422, {
      detail: "id: Unexpected.",
      violations: [{ propertyPath: "id", message: "Unexpected." }],
    })

    expect(registerFormErrors(invalid, "Bar", registers)).toEqual({ form: "Unexpected." })
  })

  it("test_it_shows_a_422_without_violations_under_the_form", () => {
    expect(registerFormErrors(new ApiError(422, { detail: "Invalid body." }), "Bar", registers)).toEqual({
      form: "Some values were not accepted. Check them and try again.",
    })
  })

  it("test_it_shows_any_other_failure_under_the_form", () => {
    expect(registerFormErrors(new ApiError(500, { detail: "Server error" }), "Bar", registers)).toEqual({
      form: "Something went wrong on our side. Try again in a moment.",
    })
    expect(registerFormErrors(new TypeError("Failed to fetch"), "Bar", registers)).toEqual({ form: "Could not reach the server. Check your connection and try again." })
  })
})
