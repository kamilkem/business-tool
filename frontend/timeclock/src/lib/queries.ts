import { ApiError, unwrap, type components } from "@business-tool/api-client"
import { queryOptions } from "@tanstack/react-query"

import { api } from "./api"

export type Shift = components["schemas"]["Shift"]

export const meQuery = queryOptions({
  queryKey: ["me"],
  queryFn: () => unwrap(api.GET("/api/me")),
})

/**
 * The signed-in person's open shift in the organization, or null when they are
 * not clocked in (the API answers 404). The organization id is in the key
 * because the request's X-Organization-Id header comes from the session.
 */
export function currentShiftQuery(organizationId: string) {
  return queryOptions({
    queryKey: ["shifts", "current", organizationId],
    queryFn: async (): Promise<Shift | null> => {
      try {
        return await unwrap(api.GET("/api/shifts/current"))
      } catch (error) {
        if (error instanceof ApiError && error.status === 404) return null
        throw error
      }
    },
  })
}
