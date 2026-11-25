import { useMemo, useState, type ReactNode } from "react"
import { useSuspenseQuery } from "@tanstack/react-query"
import { createFileRoute, useNavigate, useRouter, type ErrorComponentProps } from "@tanstack/react-router"
import { InfoIcon, TriangleAlertIcon } from "lucide-react"
import { useTranslation } from "react-i18next"

import { Alert, AlertDescription, AlertTitle } from "@business-tool/ui/components/alert"
import { Button } from "@business-tool/ui/components/button"
import { Card, CardContent } from "@business-tool/ui/components/card"
import { PeriodPicker } from "@business-tool/ui/components/period-picker"
import { Skeleton } from "@business-tool/ui/components/skeleton"
import { useMoneyFormat } from "@business-tool/ui/hooks/use-organization-currency"
import { useDateTimeFormat } from "@business-tool/ui/hooks/use-organization-time-zone"
import { type BusinessPeriod, isWholeMonth, thisMonth } from "@business-tool/ui/lib/date-time"
import { requestErrorMessage } from "@business-tool/ui/lib/request-error"

import { AnalysisHeader, AnalysisTree, NotAssignedRow } from "@/components/purchase-analysis-tree"
import { ExpensesNav } from "@/components/expenses-nav"
import { requireCapability } from "@/lib/access"
import {
  ANALYSIS_MAX_DAYS,
  type AnalysisSearch,
  type Basis,
  amountOf,
  branchIds,
  buildAnalysisTree,
  hasPurchases,
  leftOutNotes,
  readBasis,
  saveBasis,
  validateAnalysisSearch,
} from "@/lib/purchase-analysis"
import { purchaseAnalysisQuery } from "@/lib/queries"

/*
 * Analiza (#244, ADR 0041 §9): what the organization bought in a period, as the category tree
 * drilled down to products, with spend, share, quantity, prices and the last price change. The
 * toggle picks net or gross (net by default, remembered in this browser); both come in one
 * response, so it needs no request. Products and the review queue of items without a product come
 * with #245 and #246.
 */
export const Route = createFileRoute("/_app/expenses/analysis")({
  validateSearch: (search: Record<string, unknown>): AnalysisSearch => validateAnalysisSearch(search),
  beforeLoad: requireCapability("expenses"),
  loaderDeps: ({ search }) => search,
  loader: ({ context, deps }) => {
    const { organization } = context.membership
    const period = periodOf(deps, thisMonth(organization.timezone))
    return context.queryClient.ensureQueryData(purchaseAnalysisQuery(organization.id, period))
  },
  pendingComponent: AnalysisPending,
  errorComponent: AnalysisError,
  component: AnalysisPage,
})

function periodOf(search: AnalysisSearch, fallback: BusinessPeriod): BusinessPeriod {
  return search.from && search.to ? { from: search.from, to: search.to } : fallback
}

/** The navigation and heading every state of the page starts with; `actions` are the controls next to it. */
function PageHeading({ actions }: { actions?: ReactNode }) {
  const { t } = useTranslation("admin")
  return (
    <div className="flex flex-wrap items-end justify-between gap-4">
      <div className="flex flex-col gap-3">
        <ExpensesNav />
        <div>
          <h1 className="text-2xl font-semibold tracking-tight">{t("analysis.title")}</h1>
          <p className="text-sm text-muted-foreground">{t("analysis.description")}</p>
        </div>
      </div>
      {actions}
    </div>
  )
}

function AnalysisPending() {
  return (
    <div className="flex flex-col gap-6" aria-busy="true">
      <PageHeading />
      <Skeleton className="h-8 w-56" />
      <Card className="py-0">
        <CardContent className="flex flex-col gap-3 px-4 py-4">
          {Array.from({ length: 6 }, (_, index) => (
            <Skeleton key={index} className="h-8 w-full" />
          ))}
        </CardContent>
      </Card>
    </div>
  )
}

function AnalysisError({ error, reset }: ErrorComponentProps) {
  const { t } = useTranslation("admin")
  const router = useRouter()
  return (
    <div className="flex flex-col gap-6">
      <PageHeading />
      <Alert variant="destructive">
        <TriangleAlertIcon />
        <AlertTitle>{t("analysis.error.title")}</AlertTitle>
        <AlertDescription className="flex flex-col items-start gap-3">
          <span>{requestErrorMessage(error)}</span>
          <Button
            variant="outline"
            size="sm"
            onClick={() => {
              reset()
              void router.invalidate()
            }}
          >
            {t("analysis.error.retry")}
          </Button>
        </AlertDescription>
      </Alert>
    </div>
  )
}

function AnalysisPage() {
  const { membership } = Route.useRouteContext()
  const organizationId = membership.organization.id
  const { t } = useTranslation("admin")
  const format = useDateTimeFormat()
  const money = useMoneyFormat()
  const search = Route.useSearch()
  const navigate = useNavigate({ from: Route.fullPath })
  const period = periodOf(search, format.thisMonth())
  const { data: analysis } = useSuspenseQuery(purchaseAnalysisQuery(organizationId, period))

  const [basis, setBasisState] = useState<Basis>(readBasis)
  // The ids of the opened branches; every one starts closed.
  const [expanded, setExpanded] = useState<ReadonlySet<string>>(new Set())

  const tree = useMemo(() => buildAnalysisTree(analysis, basis), [analysis, basis])
  const allBranches = useMemo(() => branchIds(tree), [tree])
  const allExpanded = allBranches.length > 0 && allBranches.every((id) => expanded.has(id))
  const total = amountOf(analysis.total, basis)
  const notes = leftOutNotes(analysis.leftOut, basis)

  // Say what was not counted; an empty period can still have left items out (another currency).
  const leftOut =
    notes.length > 0 ? (
      <Alert>
        <InfoIcon />
        <AlertTitle>{t("analysis.leftOut.title")}</AlertTitle>
        <AlertDescription>
          <ul className="list-disc pl-4">
            {notes.map(({ key, count }) => (
              <li key={key}>{t(`analysis.leftOut.${key}`, { count })}</li>
            ))}
          </ul>
        </AlertDescription>
      </Alert>
    ) : null

  function setBasis(next: Basis) {
    setBasisState(next)
    saveBasis(next)
  }

  function setPeriod(next: BusinessPeriod) {
    // This month is the default, so it stays out of the URL.
    const current = format.thisMonth()
    const isCurrent = next.from === current.from && next.to === current.to
    void navigate({ search: isCurrent ? { from: undefined, to: undefined } : next })
  }

  function toggle(id: string) {
    setExpanded((current) => {
      const next = new Set(current)
      if (!next.delete(id)) next.add(id)
      return next
    })
  }

  return (
    <div className="flex flex-col gap-6">
      <PageHeading
        actions={
          <div className="flex flex-wrap items-center gap-2">
            <PeriodPicker value={period} onChange={setPeriod} maxDays={ANALYSIS_MAX_DAYS} />
            <BasisToggle basis={basis} onChange={setBasis} />
          </div>
        }
      />

      {hasPurchases(analysis) ? (
        <>
          <div className="flex flex-wrap items-center justify-between gap-3">
            <p className="text-sm text-muted-foreground">
              {t("analysis.total", { context: basis })}{" "}
              <strong className="text-base font-semibold text-foreground tabular-nums">{money(total)}</strong>
            </p>
            {allBranches.length > 0 && (
              <Button variant="outline" size="sm" onClick={() => setExpanded(allExpanded ? new Set() : new Set(allBranches))}>
                {allExpanded ? t("analysis.collapseAll") : t("analysis.expandAll")}
              </Button>
            )}
          </div>

          {leftOut}

          <Card className="gap-0 overflow-hidden py-0">
            <CardContent className="@container px-0">
              <AnalysisHeader basis={basis} />
              <AnalysisTree nodes={tree} basis={basis} total={total} expanded={expanded} onToggle={toggle} />
              {analysis.notAssigned.count > 0 && (
                <NotAssignedRow
                  total={total}
                  amount={amountOf(analysis.notAssigned, basis)}
                  count={analysis.notAssigned.count}
                />
              )}
            </CardContent>
          </Card>
        </>
      ) : (
        <>
          {leftOut}
          <Card className="py-0">
            <CardContent className="px-6 py-10 text-center text-sm text-muted-foreground">
              {t("analysis.none", { context: isWholeMonth(period) ? "month" : undefined, period: format.period(period) })}
            </CardContent>
          </Card>
        </>
      )}
    </div>
  )
}

/** Net or gross: two buttons of one group, as ExpensesNav's links are. */
function BasisToggle({ basis, onChange }: { basis: Basis; onChange: (basis: Basis) => void }) {
  const { t } = useTranslation("admin")
  const options: Basis[] = ["net", "gross"]
  return (
    <div role="group" aria-label={t("analysis.basis.label")} className="inline-flex gap-1 rounded-lg bg-muted p-1">
      {options.map((option) => (
        <button
          key={option}
          type="button"
          aria-pressed={basis === option}
          onClick={() => onChange(option)}
          className="rounded-md px-3 py-1.5 text-sm font-medium text-muted-foreground transition-colors hover:text-foreground aria-pressed:bg-background aria-pressed:text-foreground aria-pressed:shadow-sm"
        >
          {t(`analysis.basis.${option}`)}
        </button>
      ))}
    </div>
  )
}
