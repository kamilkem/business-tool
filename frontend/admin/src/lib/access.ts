import type { components } from "@business-tool/api-client"
import { redirect } from "@tanstack/react-router"

import type { Role } from "./members"

/*
 * Which admin screens the current role may open (ADR 0021). The role is the one in the
 * selected organization (/me memberships), never the user's: the same person can be a
 * manager in one organization and an employee in another.
 *
 * This is UX only. The API stays the source of truth and still answers 403 on its own.
 */

export type Membership = components["schemas"]["MeMembership"]

/** A screen, or part of one, that some roles may not use. */
export type Capability =
  /** The admin's business screens at all: dashboard, sidebar and the pages below. */
  | "admin"
  /** The dashboard's monthly business summary (ADR 0029 §3); without it the start page is "use the timeclock". */
  | "dashboard"
  | "team"
  | "time"
  | "shiftRequests"
  | "settings"
  /** Income: revenue entries and the registers they are entered for (ADR 0022 §7). */
  | "income"
  /** Income: close and reopen a month, which locks its entries; owners only (ADR 0034 §1). */
  | "incomeClose"
  /** Expenses: purchase invoices and the categories they are sorted into. */
  | "expenses"
  /** Cash flow: review, correct, delete and record end-of-day closes (ADR 0033 §6, §7). */
  | "cashFlow"
  /** Payroll: rates, and runs that are generated, reviewed and adjusted (ADR 0027 §10). */
  | "payroll"
  /** Payroll: approve and reopen a run, and mark paychecks paid; owners only (ADR 0027 §7, §9). */
  | "payrollApprove"
  /** KSeF: see the connection and test the token (ADR 0023 §5). Shown for organizations in Poland only. */
  | "ksef"
  /** KSeF: save or remove the token, owners only (ADR 0023 §2, ADR 0026 §5). */
  | "ksefToken"

const OWNERS_AND_MANAGERS: readonly Role[] = ["owner", "manager"]

const allowedRoles: Record<Capability, readonly Role[]> = {
  admin: OWNERS_AND_MANAGERS,
  dashboard: OWNERS_AND_MANAGERS,
  team: OWNERS_AND_MANAGERS,
  time: OWNERS_AND_MANAGERS,
  shiftRequests: OWNERS_AND_MANAGERS,
  settings: OWNERS_AND_MANAGERS,
  income: OWNERS_AND_MANAGERS,
  incomeClose: ["owner"],
  expenses: OWNERS_AND_MANAGERS,
  cashFlow: OWNERS_AND_MANAGERS,
  payroll: OWNERS_AND_MANAGERS,
  payrollApprove: ["owner"],
  ksef: OWNERS_AND_MANAGERS,
  ksefToken: ["owner"],
}

/** Whether the role may use the capability. No role (no organization selected) may use none. */
export function can(role: Role | null | undefined, capability: Capability): boolean {
  if (!role) return false
  // Every business screen sits inside the admin, so a role without it gets none of them.
  if (capability !== "admin" && !can(role, "admin")) return false
  return allowedRoles[capability].includes(role)
}

/**
 * A route's `beforeLoad`: sends a role that may not use the capability to the start page
 * (the dashboard, or the "use the timeclock" screen for employees), so a typed URL never
 * shows a page the API would refuse. The returned membership narrows the route context.
 */
export function requireCapability(capability: Capability) {
  return ({ context }: { context: { membership: Membership | null } }): { membership: Membership } => {
    const { membership } = context
    if (!membership || !can(membership.role, capability)) {
      throw redirect({ to: "/", replace: true })
    }
    return { membership }
  }
}

/** The memberships whose organizations the user may use the admin for, to offer a switch. */
export function adminMemberships(memberships: readonly Membership[]): Membership[] {
  return memberships.filter((m) => can(m.role, "admin"))
}
