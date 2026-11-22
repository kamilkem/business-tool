import { ApiError, unwrap } from "@business-tool/api-client"
import type { BusinessPeriod } from "@business-tool/ui/lib/date-time"
import { type QueryClient, queryOptions } from "@tanstack/react-query"

import { api } from "./api"
import type { ExpensesFilter } from "./expenses"
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

/**
 * The organization's KSeF connection, never its token; null while no token was ever saved (404).
 * The id keys the cache: the API answers for the organization in the X-Organization-Id header.
 */
export const ksefConnectionQuery = (organizationId: string) =>
  queryOptions({
    queryKey: ["ksef-connection", organizationId],
    queryFn: async () => {
      try {
        return await unwrap(api.GET("/api/ksef-connection"))
      } catch (error) {
        if (error instanceof ApiError && error.status === 404) return null
        throw error
      }
    },
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

/**
 * Payment methods of the organization, all of them: active ones in its order, then the deactivated
 * ones (ADR 0030). The payment methods page shows both; the entry form offers the active ones and
 * keeps a deactivated one only on an entry that has it.
 */
export const paymentMethodsQuery = (organizationId: string) =>
  queryOptions({
    queryKey: ["payment-methods", organizationId],
    queryFn: () => unwrap(api.GET("/api/payment-methods", { params: { query: { includeDeactivated: true } } })),
  })

/**
 * Expense categories of the organization: active ones by name, then (with includeArchived) the
 * archived ones. Forms that pick a category for an expense need the active ones only.
 */
export const expenseCategoriesQuery = (organizationId: string, includeArchived: boolean) =>
  queryOptions({
    queryKey: ["expense-categories", organizationId, { includeArchived }],
    queryFn: () =>
      unwrap(api.GET("/api/expense-categories", { params: { query: includeArchived ? { includeArchived: true } : {} } })),
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
    // Whether a payment method is used (and so can be deleted) follows the entries.
    queryClient.invalidateQueries({ queryKey: ["payment-methods", organizationId] }),
    // So does whether a register has entries.
    queryClient.invalidateQueries({ queryKey: ["registers", organizationId] }),
  ])
}

/**
 * Expenses of the period by issue date, newest first, with totals per currency (GET /expenses).
 * Category changes update every list under `["expenses", organizationId]`.
 */
export const expensesQuery = (organizationId: string, filter: ExpensesFilter) =>
  queryOptions({
    queryKey: ["expenses", organizationId, filter],
    queryFn: () => unwrap(api.GET("/api/expenses", { params: { query: filter } })),
  })

/** Payroll runs, newest month first, with their totals (GET /payroll-runs). */
export const payrollRunsQuery = (organizationId: string) =>
  queryOptions({
    queryKey: ["payroll-runs", organizationId],
    queryFn: () => unwrap(api.GET("/api/payroll-runs")),
  })

/** One payroll run with its paychecks, lines and adjustments. */
export const payrollRunQuery = (organizationId: string, id: string) =>
  queryOptions({
    queryKey: ["payroll-run", organizationId, id],
    queryFn: () => unwrap(api.GET("/api/payroll-runs/{id}", { params: { path: { id } } })),
  })

/** Every member's hourly rates, by member, newest first. */
export const hourlyRatesQuery = (organizationId: string) =>
  queryOptions({
    queryKey: ["hourly-rates", organizationId],
    queryFn: () => unwrap(api.GET("/api/hourly-rates")),
  })

/** Every member's day rates, by member, newest first. */
export const dayRatesQuery = (organizationId: string) =>
  queryOptions({
    queryKey: ["day-rates", organizationId],
    queryFn: () => unwrap(api.GET("/api/day-rates")),
  })

/** After a run is generated, changed, approved, reopened, paid or deleted: the list and every run. */
export function invalidatePayrollRuns(queryClient: QueryClient, organizationId: string) {
  return Promise.all([
    queryClient.invalidateQueries({ queryKey: ["payroll-runs", organizationId] }),
    queryClient.invalidateQueries({ queryKey: ["payroll-run", organizationId] }),
  ])
}

/** After a rate or day rate is added, changed or deleted. */
export function invalidatePayRates(queryClient: QueryClient, organizationId: string) {
  return Promise.all([
    queryClient.invalidateQueries({ queryKey: ["hourly-rates", organizationId] }),
    queryClient.invalidateQueries({ queryKey: ["day-rates", organizationId] }),
  ])
}

/**
 * The business summary of the months `from`–`to` (`YYYY-MM`, at most 12; ADR 0029): income, expenses,
 * payroll and the result per currency. The dashboard's cards and trend. It adds up what the Income,
 * Expenses and Payroll pages change, so it is refetched whenever the dashboard opens rather than
 * invalidated by each of their writes.
 */
export const businessSummaryQuery = (organizationId: string, range: { from: string; to: string }) =>
  queryOptions({
    queryKey: ["business-summary", organizationId, range],
    staleTime: 0,
    queryFn: () => unwrap(api.GET("/api/business-summary", { params: { query: range } })),
  })

/** The end-of-day closes of the period (GET /day-closes), latest first; owners and managers only. */
export const dayClosesQuery = (organizationId: string, period: BusinessPeriod) =>
  queryOptions({
    queryKey: ["day-closes", organizationId, period],
    queryFn: () => unwrap(api.GET("/api/day-closes", { params: { query: period } })),
  })

/** One end-of-day close, with what the ledger expected (GET /day-closes/{id}). */
export const dayCloseQuery = (organizationId: string, id: string) =>
  queryOptions({
    queryKey: ["day-close", organizationId, id],
    queryFn: () => unwrap(api.GET("/api/day-closes/{id}", { params: { path: { id } } })),
  })

/** What a close covers now: the active storages, denominations, registers and non-cash methods (GET /day-close-form). */
export const dayCloseFormQuery = (organizationId: string) =>
  queryOptions({
    queryKey: ["day-close-form", organizationId],
    queryFn: () => unwrap(api.GET("/api/day-close-form")),
  })

/**
 * After a close is recorded, corrected or deleted: every close (a correction or delete recalculates
 * the next one), the form's proposed date, and the revenue entries the closes book (ADR 0033 §9).
 */
export function invalidateDayCloses(queryClient: QueryClient, organizationId: string) {
  return Promise.all([
    queryClient.invalidateQueries({ queryKey: ["day-closes", organizationId] }),
    queryClient.invalidateQueries({ queryKey: ["day-close", organizationId] }),
    queryClient.invalidateQueries({ queryKey: ["day-close-form", organizationId] }),
    invalidateRevenue(queryClient, organizationId),
  ])
}
