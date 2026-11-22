import { type BusinessDate, type BusinessPeriod, addDays, compareBusinessDates } from "@business-tool/ui/lib/date-time"
import type { MinorUnits } from "@business-tool/ui/lib/money"

import { type CashBalance, cashOnHand } from "./cash-flow"
import { DAY_CLOSES_MAX_DAYS, type DayClose } from "./day-closes"

/*
 * The dashboard's Cash on hand card (#191): the cash in each storage now and the total, from the
 * Cash flow balances (ADR 0033 §4), and the last day that was closed, flagged when its cash income
 * was negative (a shortage to review, §6). It is about now, not about the month the dashboard shows.
 */

export interface CashOnHand {
  /** In the organization's currency. */
  total: MinorUnits
  /** Whether a counted storage's part is an estimate until its next close. */
  estimate: boolean
  /** Every active storage, and a deactivated one while it still holds cash, in the API's order. */
  storages: readonly CashBalance[]
}

export function cashOnHandOf(balances: readonly CashBalance[]): CashOnHand {
  return {
    total: cashOnHand(balances),
    estimate: balances.some((balance) => balance.estimate),
    storages: balances,
  }
}

/** The days to look for the last close: as many as the API lists at once, up to today. */
export function lastClosePeriod(today: BusinessDate): BusinessPeriod {
  return { from: addDays(today, -(DAY_CLOSES_MAX_DAYS - 1)), to: today }
}

export interface LastCloseDay {
  date: BusinessDate
  /** The closes of that date: one per register that closed it. */
  closes: readonly DayClose[]
  /** Whether any of them has a negative cash income. */
  shortage: boolean
}

/** The latest date that was closed, with its closes; null when no close is in the list. */
export function lastCloseDay(closes: readonly DayClose[]): LastCloseDay | null {
  const date = closes.reduce<BusinessDate | null>(
    (latest, close) => (latest === null || compareBusinessDates(close.date, latest) > 0 ? close.date : latest),
    null,
  )
  if (date === null) return null
  const ofDate = closes.filter((close) => close.date === date)
  return { date, closes: ofDate, shortage: ofDate.some((close) => close.shortage) }
}
