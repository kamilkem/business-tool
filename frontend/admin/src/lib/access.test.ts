import { isRedirect } from "@tanstack/react-router"
import { describe, expect, it } from "vitest"

import { type Capability, type Membership, adminMemberships, can, requireCapability } from "./access"
import type { Role } from "./members"
import { modulesFor } from "./modules"

const CAPABILITIES: Capability[] = ["admin", "dashboard", "team", "time", "shiftRequests", "settings", "income", "expenses", "cashFlow", "payroll", "ksef"]

function membership(role: Role, name = "Demo Bar"): Membership {
  return {
    id: `membership-${name}`,
    role,
    organization: { id: `organization-${name}`, name, timezone: "Europe/Warsaw", currency: "PLN" },
  }
}

/** Runs a route guard and returns where it redirected, or what it put in the context. */
function guard(capability: Capability, selected: Membership | null) {
  try {
    return { context: requireCapability(capability)({ context: { membership: selected } }) }
  } catch (error) {
    if (isRedirect(error)) return { redirectedTo: error.options.to }
    throw error
  }
}

describe("can", () => {
  it("test_it_lets_owners_and_managers_use_every_admin_screen", () => {
    for (const capability of CAPABILITIES) {
      expect(can("owner", capability)).toBe(true)
      expect(can("manager", capability)).toBe(true)
    }
  })

  it("test_it_lets_employees_use_no_admin_screen", () => {
    for (const capability of [...CAPABILITIES, "ksefToken" as const, "payrollApprove" as const, "incomeClose" as const]) {
      expect(can("employee", capability)).toBe(false)
    }
  })

  it("test_it_lets_only_owners_save_or_remove_the_ksef_token", () => {
    expect(can("owner", "ksefToken")).toBe(true)
    expect(can("manager", "ksefToken")).toBe(false)
    expect(can(null, "ksefToken")).toBe(false)
  })

  it("test_it_lets_only_owners_approve_reopen_and_pay_payroll", () => {
    expect(can("owner", "payrollApprove")).toBe(true)
    expect(can("manager", "payrollApprove")).toBe(false)
    expect(can("manager", "payroll")).toBe(true)
    expect(can(null, "payrollApprove")).toBe(false)
  })

  it("test_it_lets_only_owners_close_and_reopen_an_income_month", () => {
    expect(can("owner", "incomeClose")).toBe(true)
    expect(can("manager", "incomeClose")).toBe(false)
    expect(can("manager", "income")).toBe(true)
    expect(can(null, "incomeClose")).toBe(false)
  })

  it("test_it_lets_no_role_use_anything_when_no_organization_is_selected", () => {
    for (const capability of CAPABILITIES) {
      expect(can(null, capability)).toBe(false)
      expect(can(undefined, capability)).toBe(false)
    }
  })
})

describe("requireCapability", () => {
  it("test_it_lets_a_manager_through_and_narrows_the_membership", () => {
    const manager = membership("manager")

    expect(guard("team", manager)).toEqual({ context: { membership: manager } })
  })

  it("test_it_sends_an_employee_typing_a_forbidden_url_to_the_start_page", () => {
    for (const capability of CAPABILITIES) {
      expect(guard(capability, membership("employee"))).toEqual({ redirectedTo: "/" })
    }
  })

  it("test_it_sends_a_user_without_an_organization_to_the_start_page", () => {
    expect(guard("settings", null)).toEqual({ redirectedTo: "/" })
  })

  it("test_it_follows_the_role_in_the_selected_organization_not_the_user", () => {
    const ownerOfBar = membership("owner", "Demo Bar")
    const employeeOfCafe = membership("employee", "Demo Café")

    expect(guard("time", ownerOfBar)).toEqual({ context: { membership: ownerOfBar } })
    expect(guard("time", employeeOfCafe)).toEqual({ redirectedTo: "/" })
  })
})

describe("adminMemberships", () => {
  it("test_it_offers_the_organizations_the_user_owns_or_manages", () => {
    const bar = membership("owner", "Demo Bar")
    const cafe = membership("employee", "Demo Café")
    const pub = membership("manager", "Demo Pub")

    expect(adminMemberships([bar, cafe, pub])).toEqual([bar, pub])
    expect(adminMemberships([cafe])).toEqual([])
  })
})

describe("modulesFor", () => {
  it("test_it_shows_built_modules_only_to_roles_that_may_open_them", () => {
    expect(modulesFor("manager").some((m) => m.to === "/time")).toBe(true)
    expect(modulesFor("employee").some((m) => m.to === "/time")).toBe(false)
    expect(modulesFor("employee").some((m) => m.key === "payroll")).toBe(false)
  })

  it("test_it_groups_modules_into_finances_and_staff", () => {
    expect(modulesFor("owner", "finances").map((m) => m.key)).toEqual(["income", "expenses", "cashFlow", "payroll"])
    expect(modulesFor("owner", "staff").map((m) => m.key)).toEqual(["time"])
  })

  it("test_it_links_income_to_its_page_for_owners_and_managers_only", () => {
    expect(modulesFor("owner").find((m) => m.key === "income")?.to).toBe("/income")
    expect(modulesFor("employee").some((m) => m.key === "income")).toBe(false)
  })

  it("test_it_links_payroll_to_its_page_for_owners_and_managers_only", () => {
    expect(modulesFor("manager").find((m) => m.key === "payroll")?.to).toBe("/payroll")
    expect(modulesFor("owner").find((m) => m.key === "payroll")?.to).toBe("/payroll")
  })

  it("test_it_links_cash_flow_to_the_end_of_day_closes_for_owners_and_managers_only", () => {
    expect(modulesFor("manager").find((m) => m.key === "cashFlow")?.to).toBe("/cash-flow")
    expect(modulesFor("owner").find((m) => m.key === "cashFlow")?.to).toBe("/cash-flow")
    expect(modulesFor("employee").some((m) => m.key === "cashFlow")).toBe(false)
  })

  it("test_it_links_expenses_to_its_categories_for_owners_and_managers_only", () => {
    expect(modulesFor("manager").find((m) => m.key === "expenses")?.to).toBe("/expenses")
    expect(modulesFor("employee").some((m) => m.key === "expenses")).toBe(false)
  })
})
