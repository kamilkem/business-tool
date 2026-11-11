import type { LinkProps } from "@tanstack/react-router"
import { ClockIcon, ReceiptIcon, TrendingUpIcon, WalletIcon, type LucideIcon } from "lucide-react"

import { type Role, canManageTeam } from "./members"

export interface ModuleInfo {
  key: string
  title: string
  description: string
  icon: LucideIcon
  /** The module's page, once it is built; until then it is shown as "coming soon". */
  to?: LinkProps["to"]
  /** Who may open the page; the API refuses everyone else. */
  allowed?: (role: Role | undefined) => boolean
}

/** Business modules shown in navigation and on the dashboard. */
export const modules: ModuleInfo[] = [
  {
    key: "expenses",
    title: "Expenses",
    description: "Purchase invoices pulled from KSeF and sorted into categories.",
    icon: ReceiptIcon,
  },
  {
    key: "income",
    title: "Income",
    description: "Daily revenue entered by hand, per payment method.",
    icon: TrendingUpIcon,
  },
  {
    key: "payroll",
    title: "Payroll",
    description: "Paychecks calculated from logged hours and rates.",
    icon: WalletIcon,
  },
  {
    key: "time",
    title: "Time tracking",
    description: "Shifts clocked in the timeclock app.",
    icon: ClockIcon,
    to: "/time",
    // Employees see only their own shifts (in the timeclock app); the table of everyone's is for managers.
    allowed: canManageTeam,
  },
]

/** Modules the role sees: built ones it may open, and the ones still to come. */
export function modulesFor(role: Role | undefined): ModuleInfo[] {
  return modules.filter((module) => !module.to || !module.allowed || module.allowed(role))
}
