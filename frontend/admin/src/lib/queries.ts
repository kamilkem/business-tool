import { ApiError, unwrap } from "@business-tool/api-client"
import type { BusinessPeriod } from "@business-tool/ui/lib/date-time"
import { type QueryClient, infiniteQueryOptions, queryOptions } from "@tanstack/react-query"

import { api } from "./api"
import type { ExpensesFilter } from "./expenses"
import { QUEUE_PAGE_SIZE, QUEUE_STATUSES } from "./review-queue"
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

/** The organization's product category tree (ADR 0041 §5), flat by name, each with its parent's id. */
export const productCategoriesQuery = (organizationId: string) =>
  queryOptions({
    queryKey: ["product-categories", organizationId],
    queryFn: () => unwrap(api.GET("/api/product-categories")),
  })

/**
 * The purchase analysis of the period (GET /purchase-analysis, ADR 0041 §8): the category tree, the
 * products and what was left out, on both net and gross, so the toggle needs no request. Whatever
 * changes products, categories or purchased items invalidates `["purchase-analysis", organizationId]`.
 */
export const purchaseAnalysisQuery = (organizationId: string, period: BusinessPeriod) =>
  queryOptions({
    queryKey: ["purchase-analysis", organizationId, period],
    queryFn: () => unwrap(api.GET("/api/purchase-analysis", { params: { query: period } })),
  })

/** The organization's products by name (GET /products), to pick one to merge into or to move an item to. */
export const productsQuery = (organizationId: string) =>
  queryOptions({
    queryKey: ["products", organizationId, "list"],
    queryFn: () => unwrap(api.GET("/api/products")),
  })

/** One product's settings (GET /products/{id}). Under `["products", organizationId]`, with the lists and the price history. */
export const productQuery = (organizationId: string, id: string) =>
  queryOptions({
    queryKey: ["products", organizationId, "detail", id],
    queryFn: () => unwrap(api.GET("/api/products/{id}", { params: { path: { id } } })),
  })

/**
 * The price points of a product in a period, oldest first (GET /products/{id}/price-history; ADR
 * 0041 §8), on both net and gross.
 */
export const priceHistoryQuery = (organizationId: string, productId: string, period: BusinessPeriod) =>
  queryOptions({
    queryKey: ["products", organizationId, "price-history", productId, period],
    queryFn: () =>
      unwrap(api.GET("/api/products/{id}/price-history", { params: { path: { id: productId }, query: period } })),
  })

/** How many purchased items of a product the product page lists at once. */
export const PRODUCT_ITEMS_PER_PAGE = 50

/** A page of the purchased items linked to a product, issued in the period (GET /purchased-items). */
export const productItemsQuery = (organizationId: string, productId: string, period: BusinessPeriod, page: number) =>
  queryOptions({
    queryKey: ["products", organizationId, "items", productId, period, page],
    queryFn: () =>
      unwrap(
        api.GET("/api/purchased-items", {
          params: { query: { productId, ...period, page, itemsPerPage: PRODUCT_ITEMS_PER_PAGE } },
        }),
      ),
  })

/** The `status` filter as the API reads it: comma-separated (`unassigned,suggested`). */
const QUEUE_QUERY_SERIALIZER = { array: { style: "form", explode: false } } as const

/**
 * The review queue (GET /purchased-items, status `unassigned` and `suggested`, newest invoice first),
 * a page of QUEUE_PAGE_SIZE items at a time; `search` is a part of the printed name. Under
 * `["purchased-items", organizationId]`, with the queue's count.
 */
export const reviewQueueQuery = (organizationId: string, search: string | undefined) =>
  infiniteQueryOptions({
    queryKey: ["purchased-items", organizationId, "review-queue", search ?? null],
    initialPageParam: 1,
    queryFn: ({ pageParam }) =>
      unwrap(
        api.GET("/api/purchased-items", {
          params: { query: { status: QUEUE_STATUSES, ...(search ? { search } : {}), page: pageParam, itemsPerPage: QUEUE_PAGE_SIZE } },
          querySerializer: QUEUE_QUERY_SERIALIZER,
        }),
      ),
    getNextPageParam: (last) => (last.page * last.itemsPerPage < last.total ? last.page + 1 : undefined),
  })

/** How many items wait in the review queue, for the badge on the Review queue tab of the Analysis section: the total of a page of one. */
export const reviewQueueCountQuery = (organizationId: string) =>
  queryOptions({
    queryKey: ["purchased-items", organizationId, "review-count"],
    queryFn: async () =>
      (
        await unwrap(
          api.GET("/api/purchased-items", {
            params: { query: { status: QUEUE_STATUSES, page: 1, itemsPerPage: 1 } },
            querySerializer: QUEUE_QUERY_SERIALIZER,
          }),
        )
      ).total,
  })

/**
 * The products the model proposes for items no product fits (GET /product-proposals, ADR 0044), grouped
 * by proposed name. Under `["product-proposals", organizationId]`, which every purchasing write invalidates.
 */
export const productProposalsQuery = (organizationId: string) =>
  queryOptions({
    queryKey: ["product-proposals", organizationId],
    queryFn: () => unwrap(api.GET("/api/product-proposals")),
  })

/**
 * What waits for the model, whether it is on and what its last run did (GET /purchased-items/match, ADR 0046
 * §5). Under `["purchased-items", organizationId]`, so a write to the items refreshes the waiting count.
 */
export const matchingStatusQuery = (organizationId: string) =>
  queryOptions({
    queryKey: ["purchased-items", organizationId, "matching"],
    queryFn: () => unwrap(api.GET("/api/purchased-items/match")),
  })

/** After a write to products or purchased items: the product screens, the overview and the review queue show what the API has now. */
export async function invalidatePurchasing(queryClient: QueryClient, organizationId: string) {
  await Promise.all([
    queryClient.invalidateQueries({ queryKey: ["products", organizationId] }),
    queryClient.invalidateQueries({ queryKey: ["purchase-analysis", organizationId] }),
    queryClient.invalidateQueries({ queryKey: ["purchased-items", organizationId] }),
    queryClient.invalidateQueries({ queryKey: ["product-proposals", organizationId] }),
  ])
}

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

/** One month of income (GET /revenue-months/{month}, YYYY-MM): open or closed, and every close and reopen (ADR 0034). */
export const revenueMonthQuery = (organizationId: string, month: string) =>
  queryOptions({
    queryKey: ["revenue-months", organizationId, month],
    queryFn: () => unwrap(api.GET("/api/revenue-months/{month}", { params: { path: { month } } })),
  })

/**
 * The change history of revenue entries, newest first (GET /revenue-entry-changes): one entry's, or
 * that of the entries dated in a period, deleted ones included (ADR 0034 §4).
 */
export const revenueEntryChangesQuery = (organizationId: string, scope: { entryId: string } | BusinessPeriod) =>
  queryOptions({
    queryKey: ["revenue-entry-changes", organizationId, scope],
    queryFn: () => unwrap(api.GET("/api/revenue-entry-changes", { params: { query: scope } })),
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
    queryClient.invalidateQueries({ queryKey: ["revenue-entry-changes", organizationId] }),
    // Whether a payment method is used (and so can be deleted) follows the entries.
    queryClient.invalidateQueries({ queryKey: ["payment-methods", organizationId] }),
    // So does whether a register has entries.
    queryClient.invalidateQueries({ queryKey: ["registers", organizationId] }),
  ])
}

/**
 * Expenses of the period by issue date, newest first, with totals per currency (GET /expenses).
 * Writes invalidate every list under `["expenses", organizationId]`.
 */
export const expensesQuery = (organizationId: string, filter: ExpensesFilter) =>
  queryOptions({
    queryKey: ["expenses", organizationId, filter],
    queryFn: () => unwrap(api.GET("/api/expenses", { params: { query: filter } })),
  })

/**
 * One expense with its items (GET /expenses/{id}; ADR 0040). Under `["expenses", organizationId]`,
 * so the writes that refresh the lists refresh it too.
 */
export const expenseQuery = (organizationId: string, id: string) =>
  queryOptions({
    queryKey: ["expenses", organizationId, "detail", id],
    queryFn: () => unwrap(api.GET("/api/expenses/{id}", { params: { path: { id } } })),
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

/** The organization's cash storages (GET /cash-storages), deactivated ones too: active first, each group by name. */
export const cashStoragesQuery = (organizationId: string) =>
  queryOptions({
    queryKey: ["cash-storages", organizationId],
    queryFn: () => unwrap(api.GET("/api/cash-storages", { params: { query: { includeDeactivated: true } } })),
  })

/** The denominations of the organization's currency (GET /denominations), deactivated ones too: active first, each by value. */
export const denominationsQuery = (organizationId: string) =>
  queryOptions({
    queryKey: ["denominations", organizationId],
    queryFn: () => unwrap(api.GET("/api/denominations", { params: { query: { includeDeactivated: true } } })),
  })

/** The maximum shift length (GET /time-tracking-settings, #33): the defaults until an owner or manager saves them. */
export const timeTrackingSettingsQuery = (organizationId: string) =>
  queryOptions({
    queryKey: ["time-tracking-settings", organizationId],
    queryFn: () => unwrap(api.GET("/api/time-tracking-settings")),
  })

/** How a close gets its date (GET /day-close-settings): the defaults until an owner or manager saves them. */
export const dayCloseSettingsQuery = (organizationId: string) =>
  queryOptions({
    queryKey: ["day-close-settings", organizationId],
    queryFn: () => unwrap(api.GET("/api/day-close-settings")),
  })

/**
 * After a storage, denomination or the close-of-day settings change: the close form offers the
 * active ones and the proposed date, and the closes, balances and movements show the storages
 * under their current names and flag deactivated denominations still held.
 */
export function invalidateDayCloseForm(queryClient: QueryClient, organizationId: string) {
  return Promise.all([
    queryClient.invalidateQueries({ queryKey: ["day-close-form", organizationId] }),
    queryClient.invalidateQueries({ queryKey: ["cash-balances", organizationId] }),
    queryClient.invalidateQueries({ queryKey: ["cash-movements", organizationId] }),
    queryClient.invalidateQueries({ queryKey: ["day-closes", organizationId] }),
    queryClient.invalidateQueries({ queryKey: ["day-close", organizationId] }),
  ])
}

/** What each storage holds now (GET /cash-balances), by its last count plus the movements since; owners and managers only. */
export const cashBalancesQuery = (organizationId: string) =>
  queryOptions({
    queryKey: ["cash-balances", organizationId],
    queryFn: () => unwrap(api.GET("/api/cash-balances")),
  })

/** The cash movements of the period (GET /cash-movements), newest first, on one storage or all of them. */
export const cashMovementsQuery = (organizationId: string, period: BusinessPeriod, storageId?: string) =>
  queryOptions({
    queryKey: ["cash-movements", organizationId, period, storageId ?? null],
    queryFn: () => unwrap(api.GET("/api/cash-movements", { params: { query: { ...period, storageId } } })),
  })

/**
 * After a cash movement is added or deleted: the balances and the history; the closes after it,
 * whose expected openings are recalculated, with the revenue entries they book (ADR 0033 §7); and
 * whether its storages and denominations are used (they can no longer be deleted).
 */
export function invalidateCashLedger(queryClient: QueryClient, organizationId: string) {
  return Promise.all([
    queryClient.invalidateQueries({ queryKey: ["cash-balances", organizationId] }),
    queryClient.invalidateQueries({ queryKey: ["cash-movements", organizationId] }),
    queryClient.invalidateQueries({ queryKey: ["cash-storages", organizationId] }),
    queryClient.invalidateQueries({ queryKey: ["denominations", organizationId] }),
    invalidateDayCloses(queryClient, organizationId),
  ])
}
