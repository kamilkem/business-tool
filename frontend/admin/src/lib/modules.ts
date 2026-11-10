import { ClockIcon, ReceiptIcon, TrendingUpIcon, WalletIcon, type LucideIcon } from "lucide-react"

export interface ModuleInfo {
  key: string
  title: string
  description: string
  icon: LucideIcon
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
  },
]
