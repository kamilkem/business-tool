import { createContext, use, useMemo } from "react"

import { createDateTimeFormat, type DateTimeFormat } from "../lib/date-time"

/**
 * The selected organization's IANA time zone (`/me` → `memberships[].organization.timezone`).
 * Each app provides it where it knows the organization:
 * `<OrganizationTimeZoneContext value={membership.organization.timezone}>`.
 */
export const OrganizationTimeZoneContext = createContext<string | null>(null)

/**
 * The selected organization's time zone. Throws outside the provider, so a screen never falls back
 * to the browser's zone without anyone noticing (ADR 0002 §4).
 */
export function useOrganizationTimeZone(): string {
  const timeZone = use(OrganizationTimeZoneContext)
  if (!timeZone) {
    throw new Error("useOrganizationTimeZone() needs an <OrganizationTimeZoneContext> with the organization's time zone.")
  }
  return timeZone
}

/** Date and time formatting in the selected organization's time zone and the browser's locale. */
export function useDateTimeFormat(): DateTimeFormat {
  const timeZone = useOrganizationTimeZone()
  return useMemo(() => createDateTimeFormat(timeZone), [timeZone])
}
