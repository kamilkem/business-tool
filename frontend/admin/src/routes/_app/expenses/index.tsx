import { useCallback, useEffect, useState } from "react"
import { useSuspenseQuery } from "@tanstack/react-query"
import { createFileRoute, useNavigate } from "@tanstack/react-router"
import { PlusIcon, SearchIcon, XIcon } from "lucide-react"
import { useTranslation } from "react-i18next"

import { Badge } from "@business-tool/ui/components/badge"
import { Button } from "@business-tool/ui/components/button"
import { Card, CardContent, CardHeader, CardTitle } from "@business-tool/ui/components/card"
import { Input } from "@business-tool/ui/components/input"
import { PeriodPicker } from "@business-tool/ui/components/period-picker"
import { Select, SelectContent, SelectItem, SelectSeparator, SelectTrigger, SelectValue } from "@business-tool/ui/components/select"
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@business-tool/ui/components/table"
import { useLocale } from "@business-tool/ui/hooks/use-locale"
import { useDateTimeFormat } from "@business-tool/ui/hooks/use-organization-time-zone"
import { type BusinessPeriod, isWholeMonth, thisMonth } from "@business-tool/ui/lib/date-time"
import { type MinorUnits, formatMoney } from "@business-tool/ui/lib/money"
import { cn } from "@business-tool/ui/lib/utils"

import { ExpenseCategorySelect } from "@/components/expense-category-select"
import { ExpenseDialog, type ExpenseTarget } from "@/components/expense-dialog"
import { ExpenseSheet } from "@/components/expense-sheet"
import { ExpensesNav } from "@/components/expenses-nav"
import { KsefImportStatus } from "@/components/ksef-import-status"
import { can, requireCapability } from "@/lib/access"
import { type ExpenseCategory, splitCategories } from "@/lib/expense-categories"
import {
  EXPENSES_MAX_DAYS,
  type Expense,
  type ExpenseCurrencyTotal,
  type ExpensesSearch,
  expensesFilter,
  hasFilters,
  isCorrection,
  kindLabel,
  sourceLabel,
  validateExpensesSearch,
  visibleTotals,
} from "@/lib/expenses"
import { ksefAvailableIn } from "@/lib/ksef"
import { useCategorizeExpense } from "@/lib/use-categorize-expense"
import { expenseCategoriesQuery, expensesQuery, ksefConnectionQuery, organizationQuery, vatRatesQuery } from "@/lib/queries"

/*
 * Expenses (#56): purchase invoices of a period by issue date, imported from KSeF or entered by
 * hand, with totals per currency, a drawer with the details, and the category set inline. For
 * organizations in Poland, the KSeF import's status and "Import now" (ADR 0023 §5).
 */
export const Route = createFileRoute("/_app/expenses/")({
  validateSearch: (search: Record<string, unknown>): ExpensesSearch => validateExpensesSearch(search),
  beforeLoad: requireCapability("expenses"),
  loaderDeps: ({ search }) => search,
  loader: async ({ context, deps }) => {
    const { organization } = context.membership
    const { queryClient } = context
    const period = periodOf(deps, thisMonth(organization.timezone))
    const [details] = await Promise.all([
      queryClient.ensureQueryData(organizationQuery(organization.id)),
      queryClient.ensureQueryData(expensesQuery(organization.id, expensesFilter(deps, period))),
      queryClient.ensureQueryData(expenseCategoriesQuery(organization.id, true)),
      queryClient.ensureQueryData(vatRatesQuery(organization.id)),
    ])
    if (ksefAvailableIn(details.countryCode) && can(context.membership.role, "ksef")) {
      await queryClient.ensureQueryData(ksefConnectionQuery(organization.id))
    }
  },
  component: ExpensesPage,
})

function periodOf(search: ExpensesSearch, fallback: BusinessPeriod): BusinessPeriod {
  return search.from && search.to ? { from: search.from, to: search.to } : fallback
}

function ExpensesPage() {
  const { membership } = Route.useRouteContext()
  const organizationId = membership.organization.id
  const format = useDateTimeFormat()
  const search = Route.useSearch()
  const navigate = useNavigate({ from: Route.fullPath })
  const period = periodOf(search, format.thisMonth())

  const { data: organization } = useSuspenseQuery(organizationQuery(organizationId))
  const { data: list } = useSuspenseQuery(expensesQuery(organizationId, expensesFilter(search, period)))
  const { data: categories } = useSuspenseQuery(expenseCategoriesQuery(organizationId, true))
  const { active: activeCategories } = splitCategories(categories)
  const { data: vatRates } = useSuspenseQuery(vatRatesQuery(organizationId))
  const [formTarget, setFormTarget] = useState<ExpenseTarget | null>(null)
  const { t } = useTranslation("admin")

  // The drawer keeps its own copy: an expense that leaves a filtered list stays open.
  const [selected, setSelected] = useState<Expense | null>(null)
  const categorize = useCategorizeExpense(organizationId, (saved) =>
    setSelected((current) => (current?.id === saved.id ? saved : current)),
  )
  const busyId = categorize.isPending ? categorize.variables.expense.id : null

  const setSearch = useCallback(
    (next: Partial<ExpensesSearch>) => void navigate({ search: (previous) => ({ ...previous, ...next }) }),
    [navigate],
  )

  function setPeriod(next: BusinessPeriod) {
    // This month is the default, so it stays out of the URL.
    const current = format.thisMonth()
    const isCurrent = next.from === current.from && next.to === current.to
    setSearch({ from: isCurrent ? undefined : next.from, to: isCurrent ? undefined : next.to })
  }

  const showKsef = ksefAvailableIn(organization.countryCode) && can(membership.role, "ksef")

  return (
    <div className="flex flex-col gap-6">
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div className="flex flex-col gap-3">
          <ExpensesNav />
          <div>
            <h1 className="text-2xl font-semibold tracking-tight">{t("expenses.title")}</h1>
            <p className="text-sm text-muted-foreground">
              {showKsef ? t("expenses.descriptionKsef") : t("expenses.description")}
            </p>
          </div>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <PeriodPicker value={period} onChange={setPeriod} maxDays={EXPENSES_MAX_DAYS} />
          <Button onClick={() => setFormTarget({ kind: "add" })}>
            <PlusIcon />
            {t("expenses.add")}
          </Button>
        </div>
      </div>

      {showKsef && <KsefImportStatus membership={membership} />}

      <Filters search={search} categories={categories} onChange={setSearch} />

      <Totals totals={visibleTotals(list.totals)} period={period} filtered={hasFilters(search)} />

      <Card className="gap-0 py-0">
        <CardContent className="px-0">
          {list.items.length === 0 ? (
            <p className="px-6 py-10 text-center text-sm text-muted-foreground">
              {hasFilters(search)
                ? t("expenses.noneMatching")
                : t("expenses.none", { context: isWholeMonth(period) ? "month" : undefined, period: format.period(period) })}
            </p>
          ) : (
            <ExpensesTable
              expenses={list.items}
              activeCategories={activeCategories}
              busyId={busyId}
              onOpen={setSelected}
              onCategorize={(expense, categoryId) => categorize.mutate({ expense, categoryId })}
            />
          )}
        </CardContent>
      </Card>

      <ExpenseSheet
        expense={selected}
        organizationId={organizationId}
        activeCategories={activeCategories}
        categorizing={busyId !== null && busyId === selected?.id}
        onCategorize={(expense, categoryId) => categorize.mutate({ expense, categoryId })}
        onEdit={(expense) => setFormTarget({ kind: "edit", expense })}
        onClose={() => setSelected(null)}
      />

      <ExpenseDialog
        organizationId={organizationId}
        activeCategories={activeCategories}
        vatRates={vatRates}
        target={formTarget}
        // An edited expense stays open in the drawer with its new values.
        onSaved={(saved) => setSelected((current) => (current?.id === saved.id ? saved : current))}
        onClose={() => setFormTarget(null)}
      />
    </div>
  )
}

// --- Filters ---------------------------------------------------------------------------------------

/** Radix Select values cannot be empty: this one stands for "every category". */
const ALL = "all"

function Filters({
  search,
  categories,
  onChange,
}: {
  search: ExpensesSearch
  categories: readonly ExpenseCategory[]
  onChange: (next: Partial<ExpensesSearch>) => void
}) {
  const { t } = useTranslation("admin")
  const { active, archived } = splitCategories(categories)
  const [seller, setSeller] = useState(search.seller ?? "")

  // A URL change the field did not make (back, a link) fills it; its own debounced write does not,
  // so a trailing space being typed is kept.
  const [seenSeller, setSeenSeller] = useState(search.seller)
  if (seenSeller !== search.seller) {
    setSeenSeller(search.seller)
    if ((seller.trim() || undefined) !== search.seller) setSeller(search.seller ?? "")
  }

  // Typing waits for a pause before it filters.
  useEffect(() => {
    const value = seller.trim() || undefined
    if (value === search.seller) return
    const timer = window.setTimeout(() => onChange({ seller: value }), 300)
    return () => window.clearTimeout(timer)
  }, [seller, search.seller, onChange])

  return (
    <div className="flex flex-wrap items-center gap-2">
      <Button
        variant={search.uncategorized ? "default" : "outline"}
        size="sm"
        aria-pressed={search.uncategorized === true}
        onClick={() => onChange({ uncategorized: search.uncategorized ? undefined : true, categoryId: undefined })}
      >
        {t("expenses.uncategorized")}
      </Button>
      <Select
        value={search.categoryId ?? ALL}
        onValueChange={(value) =>
          onChange({ categoryId: value === ALL ? undefined : value, uncategorized: undefined })
        }
      >
        <SelectTrigger size="sm" aria-label={t("expenses.category")} className="w-48">
          <SelectValue />
        </SelectTrigger>
        <SelectContent>
          <SelectItem value={ALL}>{t("expenses.allCategories")}</SelectItem>
          {active.length > 0 && <SelectSeparator />}
          {active.map((category) => (
            <SelectItem key={category.id} value={category.id}>
              {category.name}
            </SelectItem>
          ))}
          {archived.length > 0 && <SelectSeparator />}
          {archived.map((category) => (
            <SelectItem key={category.id} value={category.id}>
              {category.name}
              <span className="text-muted-foreground"> {t("expenses.archived")}</span>
            </SelectItem>
          ))}
        </SelectContent>
      </Select>
      <div className="relative w-full sm:w-64">
        <SearchIcon className="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground" />
        <Input
          type="search"
          aria-label={t("expenses.seller")}
          placeholder={t("expenses.sellerPlaceholder")}
          className="h-8 pl-8"
          value={seller}
          onChange={(event) => setSeller(event.target.value)}
        />
      </div>
      {hasFilters(search) && (
        <Button
          variant="ghost"
          size="sm"
          onClick={() => {
            setSeller("")
            onChange({ categoryId: undefined, uncategorized: undefined, seller: undefined })
          }}
        >
          <XIcon />
          {t("expenses.clearFilters")}
        </Button>
      )}
    </div>
  )
}

// --- Totals ----------------------------------------------------------------------------------------

function Totals({
  totals,
  period,
  filtered,
}: {
  totals: ExpenseCurrencyTotal[]
  period: BusinessPeriod
  filtered: boolean
}) {
  const format = useDateTimeFormat()
  const locale = useLocale()
  const { t } = useTranslation("admin")

  return (
    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
      {totals.map((total) => {
        const money = (minor: MinorUnits) => formatMoney(minor, total.currency, locale)
        return (
          <Card key={total.currency} size="sm">
            <CardHeader>
              <CardTitle className="font-normal text-muted-foreground">
                {totals.length > 1 ? t("expenses.totals.grossIn", { currency: total.currency }) : t("expenses.totals.gross")}
                {filtered ? t("expenses.totals.filtered") : ""}
              </CardTitle>
              <p className={cn("text-2xl font-semibold tabular-nums", total.grossTotal < 0 && "text-destructive")}>
                {money(total.grossTotal)}
              </p>
            </CardHeader>
            <CardContent className="flex flex-col gap-0.5 text-sm text-muted-foreground">
              <span className="tabular-nums">
                {t("expenses.totals.netVat", { net: money(total.netTotal), vat: money(total.vatTotal) })}
              </span>
              <span>
                {t("expenses.totals.count", {
                  count: total.invoiceCount,
                  context: isWholeMonth(period) ? "month" : undefined,
                  period: format.period(period),
                })}
              </span>
            </CardContent>
          </Card>
        )
      })}
    </div>
  )
}

// --- The list --------------------------------------------------------------------------------------

function ExpensesTable({
  expenses,
  activeCategories,
  busyId,
  onOpen,
  onCategorize,
}: {
  expenses: readonly Expense[]
  activeCategories: readonly ExpenseCategory[]
  busyId: string | null
  onOpen: (expense: Expense) => void
  onCategorize: (expense: Expense, categoryId: string | null) => void
}) {
  const format = useDateTimeFormat()
  const locale = useLocale()
  const { t } = useTranslation("admin")

  return (
    <Table>
      <TableHeader>
        <TableRow>
          <TableHead className="pl-6">{t("expenses.column.issued")}</TableHead>
          <TableHead>{t("expenses.seller")}</TableHead>
          <TableHead>{t("expenses.column.invoice")}</TableHead>
          <TableHead>{t("expenses.category")}</TableHead>
          <TableHead className="pr-6 text-right">{t("expenses.totals.gross")}</TableHead>
        </TableRow>
      </TableHeader>
      <TableBody>
        {expenses.map((expense) => {
          const correction = isCorrection(expense.kind)
          return (
            <TableRow key={expense.id} className="cursor-pointer" onClick={() => onOpen(expense)}>
              <TableCell className="pl-6 tabular-nums">
                {format.businessDate(expense.issueDate, { day: "numeric", month: "short" })}
              </TableCell>
              <TableCell className="max-w-64">
                <button
                  type="button"
                  className="block max-w-full truncate text-left font-medium hover:underline focus-visible:underline focus-visible:outline-none"
                  onClick={(event) => {
                    event.stopPropagation()
                    onOpen(expense)
                  }}
                >
                  {expense.sellerName}
                </button>
                <span className="text-xs text-muted-foreground tabular-nums">{expense.sellerTaxId ?? t("expenses.noTaxId")}</span>
              </TableCell>
              <TableCell>
                <div className="flex flex-col items-start gap-1">
                  <span className="tabular-nums">{expense.invoiceNumber ?? "—"}</span>
                  <span className="flex gap-1">
                    <Badge variant="secondary">{expense.source === "manual" ? t("expenses.source.manualShort") : sourceLabel(expense.source)}</Badge>
                    {correction && <Badge variant="destructive">{kindLabel(expense.kind)}</Badge>}
                  </span>
                </div>
              </TableCell>
              <TableCell onClick={(event) => event.stopPropagation()}>
                <ExpenseCategorySelect
                  expense={expense}
                  activeCategories={activeCategories}
                  disabled={busyId === expense.id}
                  onChange={(categoryId) => onCategorize(expense, categoryId)}
                />
              </TableCell>
              <TableCell
                className={cn("pr-6 text-right font-medium tabular-nums", expense.grossTotal < 0 && "text-destructive")}
              >
                {formatMoney(expense.grossTotal, expense.currency, locale)}
              </TableCell>
            </TableRow>
          )
        })}
      </TableBody>
    </Table>
  )
}
