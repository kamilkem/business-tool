import { ApiError, createApiClient, createSession, signInAgainUrl } from "@business-tool/api-client"
import { QueryClient } from "@tanstack/react-query"

export const session = createSession("business-tool.timeclock.session")

export const api = createApiClient({
  session,
  onUnauthorized: () => {
    queryClient.clear()
    // The session ended (signed out elsewhere, password reset, or unused for
    // 30 days; ADR 0020): sign in again and come back here, so clocking out
    // is one sign-in away (ADR 0016).
    window.location.assign(signInAgainUrl())
  },
})

export const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      staleTime: 30_000,
      // Retry once after a network or server error; a 4xx answer won't change.
      retry: (failureCount, error) => failureCount < 1 && !(error instanceof ApiError && error.status < 500),
    },
    mutations: {
      // Clocking in or out without a connection fails at once instead of
      // waiting and firing later at a time the person did not choose.
      networkMode: "always",
    },
  },
})
