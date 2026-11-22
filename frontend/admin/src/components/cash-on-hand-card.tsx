import { useSuspenseQuery } from "@tanstack/react-query"
import { Link } from "@tanstack/react-router"
import { ArrowRightIcon, BanknoteIcon, TriangleAlertIcon } from "lucide-react"
import { useTranslation } from "react-i18next"

import { Badge } from "@business-tool/ui/components/badge"
import { Button } from "@business-tool/ui/components/button"
import {
  Card,
  CardAction,
  CardContent,
  CardDescription,
  CardFooter,
  CardHeader,
  CardTitle,
} from "@business-tool/ui/components/card"
import { useMoneyFormat } from "@business-tool/ui/hooks/use-organization-currency"
import { useDateTimeFormat } from "@business-tool/ui/hooks/use-organization-time-zone"
import type { MinorUnits } from "@business-tool/ui/lib/money"
import { cn } from "@business-tool/ui/lib/utils"

import { type LastCloseDay, cashOnHandOf, lastCloseDay, lastClosePeriod } from "@/lib/cash-on-hand"
import { DAY_CLOSES_MAX_DAYS } from "@/lib/day-closes"
import { cashBalancesQuery, dayClosesQuery } from "@/lib/queries"

/**
 * The dashboard's Cash on hand (#191): the cash in each storage now and the total, and the last
 * closed day with a flag when its cash income was negative. Owners and managers (`cashFlow`).
 */
export function CashOnHandCard({ organizationId, className }: { organizationId: string; className?: string }) {
  const { t } = useTranslation("admin")
  const format = useDateTimeFormat()
  const { data: balances } = useSuspenseQuery(cashBalancesQuery(organizationId))
  const { data: closes } = useSuspenseQuery(dayClosesQuery(organizationId, lastClosePeriod(format.today())))
  const onHand = cashOnHandOf(balances)
  const lastDay = lastCloseDay(closes)

  return (
    <Card className={cn("gap-4", className)}>
      <CardHeader>
        <CardDescription className="flex items-center gap-2">
          <BanknoteIcon className="size-4" />
          {t("dashboard.cash.title")}
        </CardDescription>
        <CardAction>
          <Badge variant="outline">{t("dashboard.cash.now")}</Badge>
        </CardAction>
        <CardTitle className="text-2xl break-words tabular-nums">
          <Amount amount={onHand.total} estimate={onHand.estimate} />
        </CardTitle>
        {onHand.estimate && <p className="text-sm text-muted-foreground">{t("dashboard.cash.estimateHint")}</p>}
      </CardHeader>
      <CardContent className="flex flex-1 flex-col gap-4 text-sm">
        {onHand.storages.length === 0 ? (
          <div className="flex flex-col items-start gap-2 text-muted-foreground">
            <p>{t("dashboard.cash.noStorages")}</p>
            <Button asChild variant="outline" size="sm">
              <Link to="/cash-flow/storages">{t("dashboard.cash.addStorage")}</Link>
            </Button>
          </div>
        ) : (
          <ul className="flex flex-col divide-y" aria-label={t("dashboard.cash.storages")}>
            {onHand.storages.map((storage) => (
              <li key={storage.storageId} className="flex items-baseline justify-between gap-3 py-1.5 first:pt-0 last:pb-0">
                <span className="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1">
                  <span className="break-words">{storage.name}</span>
                  {!storage.active && <Badge variant="outline">{t("cashFlow.balances.deactivated")}</Badge>}
                </span>
                <span className={cn("shrink-0 tabular-nums", storage.total < 0 && "text-amber-700 dark:text-amber-400")}>
                  <Amount amount={storage.total} estimate={storage.estimate} />
                </span>
              </li>
            ))}
          </ul>
        )}
        <LastClose day={lastDay} />
      </CardContent>
      <CardFooter>
        <Button variant="link" size="sm" className="h-auto px-0" asChild>
          <Link to="/cash-flow" search={{ from: undefined, to: undefined, storageId: undefined }}>
            {t("dashboard.cash.open")}
            <ArrowRightIcon />
          </Link>
        </Button>
      </CardFooter>
    </Card>
  )
}

/** An amount, with "≈" (and "Estimate" for screen readers) when a counted storage's part is estimated. */
function Amount({ amount, estimate }: { amount: MinorUnits; estimate: boolean }) {
  const { t } = useTranslation("admin")
  const money = useMoneyFormat()
  return (
    <>
      {estimate && <span className="sr-only">{t("cashFlow.balances.estimate")}: </span>}
      {estimate && (
        <span aria-hidden className="text-muted-foreground">
          ≈{" "}
        </span>
      )}
      {money(amount)}
    </>
  )
}

/** The last closed day: its date, linked to its close (or the closes of that day), and a shortage to review. */
function LastClose({ day }: { day: LastCloseDay | null }) {
  const { t } = useTranslation("admin")
  const format = useDateTimeFormat()

  if (day === null) {
    return <p className="text-muted-foreground">{t("dashboard.cash.noClose", { count: DAY_CLOSES_MAX_DAYS })}</p>
  }

  const label = t("dashboard.cash.lastClose", { date: format.businessDate(day.date) })
  const [only] = day.closes
  const link =
    day.closes.length === 1 && only ? (
      <Link to="/cash-flow/closes/$closeId" params={{ closeId: only.id }} className="underline-offset-4 hover:underline">
        {label}
      </Link>
    ) : (
      <Link
        to="/cash-flow/closes"
        search={{ from: day.date, to: day.date }}
        className="underline-offset-4 hover:underline"
      >
        {label}
      </Link>
    )

  return (
    <div className="flex flex-wrap items-center gap-2 border-t pt-3">
      <span className="text-muted-foreground">{link}</span>
      {day.shortage && (
        <Badge variant="destructive">
          <TriangleAlertIcon />
          {t("dashboard.cash.shortage")}
        </Badge>
      )}
    </div>
  )
}
