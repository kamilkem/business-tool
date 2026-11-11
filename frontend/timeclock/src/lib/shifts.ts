import { useCallback, useEffect, useRef, useState, useSyncExternalStore } from "react"
import { ApiError, unwrap } from "@business-tool/api-client"
import { requestErrorMessage } from "@business-tool/ui/lib/request-error"
import { useMutation, useQueryClient } from "@tanstack/react-query"
import { useRouter } from "@tanstack/react-router"

import { api } from "./api"
import { currentShiftQuery, meQuery } from "./queries"

export type ClockAction = "in" | "out"

/**
 * Clocks the signed-in person in or out. The request carries no time and no
 * location: the server's clock sets it (ADR 0016). On success the current
 * shift is updated in place; on failure it is refetched, so the screen shows
 * the real state (another tab or phone may have clocked in or out).
 *
 * `submit()` ignores taps while a request is in flight. Disabling the button is
 * not enough: a fast double tap fires both clicks before React re-renders.
 */
export function useClockMutation(organizationId: string) {
  const queryClient = useQueryClient()
  const router = useRouter()
  const { queryKey } = currentShiftQuery(organizationId)
  const inFlight = useRef(false)

  const mutation = useMutation({
    mutationFn: (action: ClockAction) =>
      action === "in" ? unwrap(api.POST("/api/shifts/clock-in")) : unwrap(api.POST("/api/shifts/clock-out")),
    onSuccess: (shift, action) => queryClient.setQueryData(queryKey, action === "in" ? shift : null),
    onError: async (error) => {
      // 401: the API client is already sending the person to sign in again.
      if (error instanceof ApiError && error.status === 401) return
      if (error instanceof ApiError && error.status === 403) {
        // Deactivated since /me was loaded: reload it, the screen then shows no organization.
        await queryClient.invalidateQueries({ queryKey: meQuery.queryKey })
        await router.invalidate()
        return
      }
      await queryClient.invalidateQueries({ queryKey })
    },
    onSettled: () => {
      inFlight.current = false
    },
  })

  const { mutate } = mutation
  const submit = useCallback(
    (action: ClockAction) => {
      if (inFlight.current) return
      inFlight.current = true
      mutate(action)
    },
    [mutate],
  )

  return { ...mutation, submit }
}

export function clockErrorMessage(error: unknown, action: ClockAction): string {
  if (error instanceof ApiError) {
    if (error.status === 409) {
      return action === "in"
        ? "You're already clocked in, maybe from another tab or phone. Here is your current shift."
        : "You're not clocked in, maybe you clocked out from another tab or phone."
    }
    if (error.status === 422) return "You clocked in a moment ago. Wait a few seconds and try again."
    if (error.status === 403) return "You no longer have access to this organization. Ask your manager."
  }
  return requestErrorMessage(error)
}

/** The current moment, updated every second. */
export function useNow(): Date {
  const [now, setNow] = useState(() => new Date())
  useEffect(() => {
    const timer = setInterval(() => setNow(new Date()), 1000)
    return () => clearInterval(timer)
  }, [])
  return now
}

function subscribeOnline(listener: () => void) {
  window.addEventListener("online", listener)
  window.addEventListener("offline", listener)
  return () => {
    window.removeEventListener("online", listener)
    window.removeEventListener("offline", listener)
  }
}

/** False when the browser knows it has no connection (true does not prove the API is reachable). */
export function useOnline(): boolean {
  return useSyncExternalStore(subscribeOnline, () => navigator.onLine)
}
