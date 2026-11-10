import { unwrap } from "@business-tool/api-client"
import { queryOptions } from "@tanstack/react-query"

import { api } from "./api"

export const meQuery = queryOptions({
  queryKey: ["me"],
  queryFn: () => unwrap(api.GET("/api/me")),
})
