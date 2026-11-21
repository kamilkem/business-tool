import { afterEach, describe, expect, it } from "vitest"

import { i18n } from "../i18n"
import { roleLabel } from "./members"

describe("roleLabel", () => {
  afterEach(async () => {
    await i18n.changeLanguage("en")
  })

  it("test_it_names_each_role_in_the_shown_language", async () => {
    await i18n.changeLanguage("en")
    expect([roleLabel("owner"), roleLabel("manager"), roleLabel("employee")]).toEqual(["Owner", "Manager", "Employee"])

    await i18n.changeLanguage("pl")
    expect([roleLabel("owner"), roleLabel("manager"), roleLabel("employee")]).toEqual(["Właściciel", "Kierownik", "Pracownik"])
  })
})
