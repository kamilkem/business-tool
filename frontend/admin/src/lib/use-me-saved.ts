import type { components } from "@business-tool/api-client"
import { useQueryClient } from "@tanstack/react-query"
import { useRouter } from "@tanstack/react-router"

import { meQuery } from "./queries"

/**
 * After the signed-in user changed their own `/me` (the language): puts the new one into the cache
 * and reloads the routes, so the layout's context (and the language it applies) is the new one.
 */
export function useMeSaved() {
  const queryClient = useQueryClient()
  const router = useRouter()

  return (me: components["schemas"]["Me"]) => {
    queryClient.setQueryData(meQuery.queryKey, me)
    void router.invalidate()
  }
}
