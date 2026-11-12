import { createContext, use, useCallback } from "react"

import { formatMoney, type MinorUnits } from "../lib/money"
import { useLocale } from "./use-locale"

/**
 * The selected organization's currency, ISO 4217 (`/me` → `memberships[].organization.currency`;
 * ADR 0002, ADR 0010). Each app provides it where it knows the organization:
 * `<OrganizationCurrencyContext value={membership.organization.currency}>`.
 */
export const OrganizationCurrencyContext = createContext<string | null>(null)

/** The selected organization's currency. Throws outside the provider, so no screen assumes PLN. */
export function useOrganizationCurrency(): string {
  const currency = use(OrganizationCurrencyContext)
  if (!currency) {
    throw new Error("useOrganizationCurrency() needs an <OrganizationCurrencyContext> with the organization's currency.")
  }
  return currency
}

/** Formats minor units in the organization's currency and the current locale: 123450 → "1234,50 zł". */
export function useMoneyFormat(): (minor: MinorUnits | bigint) => string {
  const currency = useOrganizationCurrency()
  const locale = useLocale()
  return useCallback((minor) => formatMoney(minor, currency, locale), [currency, locale])
}
