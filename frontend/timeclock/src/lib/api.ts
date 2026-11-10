import { createApiClient, createSession } from "@business-tool/api-client"
import { QueryClient } from "@tanstack/react-query"

export const session = createSession("business-tool.timeclock.session")

export const api = createApiClient({
  session,
  onUnauthorized: () => {
    queryClient.clear()
    window.location.assign("/login")
  },
})

export const queryClient = new QueryClient({
  defaultOptions: { queries: { staleTime: 30_000, retry: 1 } },
})
