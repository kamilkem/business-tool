import { ApiError, unwrap, type components } from "@business-tool/api-client"
import { queryOptions } from "@tanstack/react-query"
import type { BusinessPeriod } from "@business-tool/ui/lib/date-time"

import { api } from "./api"

export type Shift = components["schemas"]["Shift"]
export type MyPay = components["schemas"]["MyPay"]

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

/** The key of every list of the signed-in person's shifts in the organization, to refresh after clocking. */
export const myShiftsKey = (organizationId: string) => ["shifts", "list", organizationId] as const

/**
 * The signed-in person's shifts that started in the period (business dates in the organization's
 * time zone), newest first. `userId` is passed even though employees only ever get their own, so an
 * owner or manager clocking in on the timeclock also sees only theirs.
 */
export function myShiftsQuery(organizationId: string, userId: string, period: BusinessPeriod) {
  return queryOptions({
    queryKey: [...myShiftsKey(organizationId), { userId, ...period }],
    queryFn: () => unwrap(api.GET("/api/shifts", { params: { query: { ...period, userId } } })),
  })
}

/** The key of the signed-in person's shift change requests in the organization, to refresh after a submit. */
export const myShiftChangeRequestsKey = (organizationId: string) => ["shift-change-requests", organizationId] as const

/**
 * The signed-in person's shift change requests, newest first, every status. The API answers an
 * employee with their own only; an owner or manager would get everyone's, so they are filtered
 * to `userId` here (the timeclock shows a person only their own).
 */
export function myShiftChangeRequestsQuery(organizationId: string, userId: string) {
  return queryOptions({
    queryKey: [...myShiftChangeRequestsKey(organizationId), { userId }],
    queryFn: async () =>
      (await unwrap(api.GET("/api/shift-change-requests"))).filter((request) => request.userId === userId),
  })
}

/** The key of every month of the signed-in person's pay in the organization, to refresh after clocking out. */
export const myPayKey = (organizationId: string) => ["my-pay", organizationId] as const

/**
 * The signed-in person's own hours and gross pay in a month (`"YYYY-MM"`): the approved paycheck, or
 * an estimate. The API always answers with the signed-in person's own, whatever their role; `userId`
 * is in the key so a cached answer is never shown to someone else signing in on the same phone.
 */
export function myPayQuery(organizationId: string, userId: string, month: string) {
  return queryOptions({
    queryKey: [...myPayKey(organizationId), { userId, month }],
    queryFn: () => unwrap(api.GET("/api/my-pay", { params: { query: { month } } })),
  })
}
