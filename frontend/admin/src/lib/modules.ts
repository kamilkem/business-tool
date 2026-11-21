import type { LinkProps } from "@tanstack/react-router"
import { ClockIcon, ReceiptIcon, TrendingUpIcon, WalletIcon, type LucideIcon } from "lucide-react"

import { type Capability, can } from "./access"
import type { Role } from "./members"

export type ModuleKey = "expenses" | "income" | "payroll" | "time"

export interface ModuleInfo {
  /** Also its title in the catalog: `modules.<key>.title`. */
  key: ModuleKey
  icon: LucideIcon
  /** The module's page, once it is built; until then it is shown as "coming soon". */
  to?: LinkProps["to"]
  /** What the page needs (its route guards with the same capability); the API refuses everyone else. */
  capability?: Capability
}

/** Business modules shown in the navigation. */
export const modules: ModuleInfo[] = [
  {
    key: "expenses",
    icon: ReceiptIcon,
    to: "/expenses",
    capability: "expenses",
  },
  {
    key: "income",
    icon: TrendingUpIcon,
    to: "/income",
    capability: "income",
  },
  {
    key: "payroll",
    icon: WalletIcon,
    to: "/payroll",
    capability: "payroll",
  },
  {
    key: "time",
    icon: ClockIcon,
    to: "/time",
    capability: "time",
  },
]

/** Modules the role sees: built ones it may open, and the ones still to come. */
export function modulesFor(role: Role | undefined): ModuleInfo[] {
  return modules.filter((module) => !module.to || !module.capability || can(role, module.capability))
}
