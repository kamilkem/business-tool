import { unwrap } from "@business-tool/api-client"
import type { BusinessPeriod } from "@business-tool/ui/lib/date-time"
import { type QueryClient, queryOptions } from "@tanstack/react-query"

import { api } from "./api"
import type { RequestsFilter } from "./shift-change-requests"

export const meQuery = queryOptions({
  queryKey: ["me"],
  queryFn: () => unwrap(api.GET("/api/me")),
})

/**
 * Reloads the signed-in user after a change to their organizations or memberships.
 * "me" has no observer (the _app layout reads it in beforeLoad), so a plain invalidation
 * would only mark it stale and the layout would keep the cached copy. Follow with
 * router.invalidate() so the layout's context picks up the new data.
 */
export function refetchMe(queryClient: QueryClient) {
  return queryClient.invalidateQueries({ queryKey: meQuery.queryKey, refetchType: "all" })
}

export const organizationQuery = (id: string) =>
  queryOptions({
    queryKey: ["organizations", id],
    queryFn: () => unwrap(api.GET("/api/organizations/{id}", { params: { path: { id } } })),
  })

/** Members of the organization; the id keys the cache so organizations never share it. */
export const membersQuery = (organizationId: string, includeDeactivated: boolean) =>
  queryOptions({
    queryKey: ["members", organizationId, { includeDeactivated }],
    queryFn: () =>
      unwrap(api.GET("/api/members", { params: { query: includeDeactivated ? { includeDeactivated: true } : {} } })),
  })

/**
 * Registers of the organization: active ones by name, then (with includeDeactivated) the
 * deactivated ones. The Income page's entry form needs the active ones only.
 */
export const registersQuery = (organizationId: string, includeDeactivated: boolean) =>
  queryOptions({
    queryKey: ["registers", organizationId, { includeDeactivated }],
    queryFn: () =>
      unwrap(api.GET("/api/registers", { params: { query: includeDeactivated ? { includeDeactivated: true } : {} } })),
  })

/** Shifts that started in the period (business dates in the organization's time zone), optionally one person's. */
export const shiftsQuery = (organizationId: string, period: BusinessPeriod, userId?: string) =>
  queryOptions({
    queryKey: ["shifts", organizationId, { ...period, userId: userId ?? null }],
    queryFn: () => unwrap(api.GET("/api/shifts", { params: { query: { ...period, ...(userId ? { userId } : {}) } } })),
  })

/**
 * Shift change requests, newest first: one status, or all of them. The pending list also gives the
 * count in the navigation, so both share this cache entry.
 */
export const shiftChangeRequestsQuery = (organizationId: string, filter: RequestsFilter) =>
  queryOptions({
    queryKey: ["shift-change-requests", organizationId, { status: filter }],
    queryFn: () =>
      unwrap(
        api.GET("/api/shift-change-requests", { params: { query: filter === "all" ? {} : { status: filter } } }),
      ),
  })

/**
 * Revenue entries of the period (GET /revenue-entries), by date then register name; optionally one
 * register's. Writes invalidate every period with `["revenue-entries", organizationId]`.
 */
export const revenueEntriesQuery = (organizationId: string, period: BusinessPeriod, registerId?: string) =>
  queryOptions({
    queryKey: ["revenue-entries", organizationId, { ...period, registerId: registerId ?? null }],
    queryFn: () =>
      unwrap(api.GET("/api/revenue-entries", { params: { query: { ...period, ...(registerId ? { registerId } : {}) } } })),
  })

/** Totals of the period per currency, payment method, VAT rate and register (GET /revenue-summary). */
export const revenueSummaryQuery = (organizationId: string, period: BusinessPeriod) =>
  queryOptions({
    queryKey: ["revenue-summary", organizationId, period],
    queryFn: () => unwrap(api.GET("/api/revenue-summary", { params: { query: period } })),
  })

/** The VAT rates of the organization's country (ADR 0022 §6); empty when the country has none. */
export const vatRatesQuery = (organizationId: string) =>
  queryOptions({
    queryKey: ["vat-rates", organizationId],
    queryFn: () => unwrap(api.GET("/api/vat-rates")),
    staleTime: Infinity,
  })

/** After a revenue entry is added, changed or deleted: every period's list and summary. */
export function invalidateRevenue(queryClient: QueryClient, organizationId: string) {
  return Promise.all([
    queryClient.invalidateQueries({ queryKey: ["revenue-entries", organizationId] }),
    queryClient.invalidateQueries({ queryKey: ["revenue-summary", organizationId] }),
  ])
}
