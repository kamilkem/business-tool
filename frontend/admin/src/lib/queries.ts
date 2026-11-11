import { unwrap } from "@business-tool/api-client"
import type { BusinessPeriod } from "@business-tool/ui/lib/date-time"
import { type QueryClient, queryOptions } from "@tanstack/react-query"

import { api } from "./api"

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

/** Shifts that started in the period (business dates in the organization's time zone), optionally one person's. */
export const shiftsQuery = (organizationId: string, period: BusinessPeriod, userId?: string) =>
  queryOptions({
    queryKey: ["shifts", organizationId, { ...period, userId: userId ?? null }],
    queryFn: () => unwrap(api.GET("/api/shifts", { params: { query: { ...period, ...(userId ? { userId } : {}) } } })),
  })
