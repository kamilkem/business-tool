import { unwrap } from "@business-tool/api-client"
import { queryOptions } from "@tanstack/react-query"

import { api } from "./api"

export const meQuery = queryOptions({
  queryKey: ["me"],
  queryFn: () => unwrap(api.GET("/api/me")),
})

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
