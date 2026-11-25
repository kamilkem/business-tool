import { ArrowDownIcon, ArrowRightIcon, ArrowUpIcon } from "lucide-react"
import { useTranslation } from "react-i18next"

import { useMoneyFormat } from "@business-tool/ui/hooks/use-organization-currency"
import { cn } from "@business-tool/ui/lib/utils"

import { type ChangeDirection, type PriceChange } from "@/lib/purchase-analysis"

const CHANGE_STYLE: Record<ChangeDirection, string> = {
  // A higher price is bad news for a buyer, a lower one good.
  up: "text-destructive",
  down: "text-emerald-700 dark:text-emerald-400",
  flat: "text-muted-foreground",
}

const CHANGE_ICON = { up: ArrowUpIcon, down: ArrowDownIcon, flat: ArrowRightIcon } as const

/** How a price moved against the previous purchase: an arrow, the amount and the percentage. The arrow says it too, for those who cannot tell the colours apart. */
export function PriceChangeLabel({ change, wrap = false, className }: { change: PriceChange; wrap?: boolean; className?: string }) {
  const { t } = useTranslation("admin")
  const money = useMoneyFormat()
  const Icon = CHANGE_ICON[change.direction]
  return (
    <span
      className={cn("inline-flex items-center gap-1 tabular-nums", wrap ? "flex-wrap" : "whitespace-nowrap", CHANGE_STYLE[change.direction], className)}
      title={t(`analysis.change.${change.direction}`)}
    >
      <Icon className="size-3.5" aria-label={t(`analysis.change.${change.direction}`)} />
      <span>
        {change.direction === "flat" ? money(0) : `${change.amount > 0 ? "+" : "−"}${money(Math.abs(change.amount))}`}
        {change.percent && <span className="text-xs"> ({change.percent})</span>}
      </span>
    </span>
  )
}
