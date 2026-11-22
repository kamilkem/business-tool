import type { LinkProps } from "@tanstack/react-router"
import { BanknoteIcon, ClockIcon, ReceiptIcon, TrendingUpIcon, WalletIcon, type LucideIcon } from "lucide-react"

import { type Capability, can } from "./access"
import type { Role } from "./members"

export type ModuleKey = "cashFlow" | "expenses" | "income" | "payroll" | "time"

/** The navigation section a module sits in: `nav.groups.<group>` in the catalog. */
export type NavGroup = "finances" | "staff"

export interface ModuleInfo {
  /** Also its title in the catalog: `modules.<key>.title`. */
  key: ModuleKey
  group: NavGroup
  icon: LucideIcon
  /** The module's page, once it is built; until then it is shown as "coming soon". */
  to?: LinkProps["to"]
  /** What the page needs (its route guards with the same capability); the API refuses everyone else. */
  capability?: Capability
}

/** Business modules shown in the navigation. */
export const modules: ModuleInfo[] = [
  {
    key: "income",
    group: "finances",
    icon: TrendingUpIcon,
    to: "/income",
    capability: "income",
  },
  {
    key: "expenses",
    group: "finances",
    icon: ReceiptIcon,
    to: "/expenses",
    capability: "expenses",
  },
  {
    key: "cashFlow",
    group: "finances",
    icon: BanknoteIcon,
    to: "/cash-flow/closes",
    capability: "cashFlow",
  },
  {
    key: "payroll",
    group: "finances",
    icon: WalletIcon,
    to: "/payroll",
    capability: "payroll",
  },
  {
    key: "time",
    group: "staff",
    icon: ClockIcon,
    to: "/time",
    capability: "time",
  },
]

/** Modules the role sees: built ones it may open, and the ones still to come. */
export function modulesFor(role: Role | undefined, group?: NavGroup): ModuleInfo[] {
  return modules.filter(
    (module) => (!group || module.group === group) && (!module.to || !module.capability || can(role, module.capability)),
  )
}
