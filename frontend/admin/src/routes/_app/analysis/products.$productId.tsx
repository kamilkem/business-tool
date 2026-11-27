import { useMemo, useState, type ReactNode } from "react"
import { ApiError } from "@business-tool/api-client"
import { useSuspenseQuery } from "@tanstack/react-query"
import { Link, createFileRoute, useNavigate, useRouter, type ErrorComponentProps } from "@tanstack/react-router"
import { ArrowLeftIcon, InfoIcon, SettingsIcon, TriangleAlertIcon } from "lucide-react"
import { useTranslation } from "react-i18next"

import { Alert, AlertDescription, AlertTitle } from "@business-tool/ui/components/alert"
import { Badge } from "@business-tool/ui/components/badge"
import { Button } from "@business-tool/ui/components/button"
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@business-tool/ui/components/card"
import { PeriodPicker } from "@business-tool/ui/components/period-picker"
import { Skeleton } from "@business-tool/ui/components/skeleton"
import { useLocale } from "@business-tool/ui/hooks/use-locale"
import { useMoneyFormat } from "@business-tool/ui/hooks/use-organization-currency"
import { useDateTimeFormat } from "@business-tool/ui/hooks/use-organization-time-zone"
import { type BusinessPeriod, thisMonth } from "@business-tool/ui/lib/date-time"
import { requestErrorMessage } from "@business-tool/ui/lib/request-error"

import { BasisToggle } from "@/components/basis-toggle"
import { AnalysisNav } from "@/components/analysis-nav"
import { InvoiceSheet } from "@/components/invoice-sheet"
import { PriceChangeLabel } from "@/components/price-change"
import { ProductMergeDialog } from "@/components/product-merge-dialog"
import { ProductPriceChart } from "@/components/product-price-chart"
import { ProductPurchases } from "@/components/product-purchases"
import { ProductSettingsDialog } from "@/components/product-settings-dialog"
import { PurchasedItemDialog, type ItemRef } from "@/components/purchased-item-dialog"
import { requireCapability } from "@/lib/access"
import { parentChoices } from "@/lib/product-categories"
import {
  type ChartPoint,
  type ProductSearch,
  buildPriceChart,
  defaultPeriod,
  priceStats,
  validateProductSearch,
} from "@/lib/product-prices"
import { type Basis, readBasis, referenceLabel, saveBasis } from "@/lib/purchase-analysis"
import { priceHistoryQuery, productCategoriesQuery, productQuery } from "@/lib/queries"

/*
 * One product of the purchase analysis (#245, ADR 0041 §9): the price per reference quantity over
 * the issue date as a chart, with the points coloured by seller; the purchases behind it, each with
 * its invoice; the product's settings, merging and moving an item to another product. It is the
 * drill-down of the Analysis overview (the net/gross choice is shared with it) and shows the last 12
 * months unless a period is picked. The price change is the API's: against the previous purchase of
 * the product from any seller, also one before the period, as in the overview.
 */
export const Route = createFileRoute("/_app/analysis/products/$productId")({
  validateSearch: (search: Record<string, unknown>): ProductSearch => validateProductSearch(search),
  beforeLoad: requireCapability("analysis"),
  loaderDeps: ({ search }) => search,
  loader: async ({ context, params, deps }) => {
    const { organization } = context.membership
    const { queryClient } = context
    const period = periodOf(deps, defaultPeriod(thisMonth(organization.timezone)))
    await Promise.all([
      queryClient.ensureQueryData(productQuery(organization.id, params.productId)),
      queryClient.ensureQueryData(priceHistoryQuery(organization.id, params.productId, period)),
      queryClient.ensureQueryData(productCategoriesQuery(organization.id)),
    ])
  },
  pendingComponent: ProductPending,
  errorComponent: ProductError,
  component: ProductPage,
})

function periodOf(search: ProductSearch, fallback: BusinessPeriod): BusinessPeriod {
  return search.from && search.to ? { from: search.from, to: search.to } : fallback
}

/** The navigation every state of the page starts with. */
function PageTop({ children }: { children?: ReactNode }) {
  const { t } = useTranslation("admin")
  return (
    <div className="flex flex-col gap-3">
      <AnalysisNav />
      <Button asChild variant="ghost" size="sm" className="-ml-2 w-fit">
        <Link to="/analysis">
          <ArrowLeftIcon />
          {t("product.back")}
        </Link>
      </Button>
      {children}
    </div>
  )
}

function ProductPending() {
  return (
    <div className="flex flex-col gap-6" aria-busy="true">
      <PageTop />
      <Skeleton className="h-8 w-64" />
      <Skeleton className="h-80 w-full" />
    </div>
  )
}

function ProductError({ error, reset }: ErrorComponentProps) {
  const { t } = useTranslation("admin")
  const router = useRouter()
  const notFound = error instanceof ApiError && error.status === 404
  return (
    <div className="flex flex-col gap-6">
      <PageTop />
      <Alert variant="destructive">
        <TriangleAlertIcon />
        <AlertTitle>{notFound ? t("product.notFound.title") : t("product.error.title")}</AlertTitle>
        <AlertDescription className="flex flex-col items-start gap-3">
          <span>{notFound ? t("product.notFound.description") : requestErrorMessage(error)}</span>
          {!notFound && (
            <Button
              variant="outline"
              size="sm"
              onClick={() => {
                reset()
                void router.invalidate()
              }}
            >
              {t("product.error.retry")}
            </Button>
          )}
        </AlertDescription>
      </Alert>
    </div>
  )
}

function ProductPage() {
  const { membership } = Route.useRouteContext()
  const organizationId = membership.organization.id
  const { productId } = Route.useParams()
  const { t } = useTranslation("admin")
  const locale = useLocale()
  const format = useDateTimeFormat()
  const search = Route.useSearch()
  const navigate = useNavigate({ from: Route.fullPath })
  const defaults = defaultPeriod(format.thisMonth())
  const period = periodOf(search, defaults)
  const { data: product } = useSuspenseQuery(productQuery(organizationId, productId))
  const { data: history } = useSuspenseQuery(priceHistoryQuery(organizationId, productId, period))
  const { data: categories } = useSuspenseQuery(productCategoriesQuery(organizationId))

  const [basis, setBasisState] = useState<Basis>(readBasis)
  const [settingsOpen, setSettingsOpen] = useState(false)
  const [mergeOpen, setMergeOpen] = useState(false)
  const [invoiceId, setInvoiceId] = useState<string | null>(null)
  const [editing, setEditing] = useState<ItemRef | null>(null)

  const chart = useMemo(() => buildPriceChart(history.points, basis, locale), [history.points, basis, locale])
  const stats = useMemo(() => priceStats(chart.points), [chart.points])
  const reference = referenceLabel(product, locale)
  const categoryPath = useMemo(
    () => parentChoices(categories, null).find((choice) => choice.category.id === product.categoryId)?.path ?? null,
    [categories, product.categoryId],
  )
  const isDefaultPeriod = period.from === defaults.from && period.to === defaults.to

  function setBasis(next: Basis) {
    setBasisState(next)
    saveBasis(next)
  }

  function setPeriod(next: BusinessPeriod) {
    // The last 12 months are the default, so they stay out of the URL.
    const isDefault = next.from === defaults.from && next.to === defaults.to
    void navigate({ search: isDefault ? { from: undefined, to: undefined } : next })
  }

  // "net" and "gross" in the sentence, as the toggle words them: "netto" in Polish.
  const basisWord = t(`analysis.basis.${basis}`).toLowerCase()
  const noPriceReason = product.excluded ? "excluded" : product.measure === "none" ? "noUnit" : null

  return (
    <div className="flex flex-col gap-6">
      <PageTop />

      <div className="flex flex-wrap items-start justify-between gap-4">
        <div className="flex min-w-0 flex-col gap-1">
          <div className="flex flex-wrap items-center gap-2">
            <h1 className="text-2xl font-semibold tracking-tight break-words">{product.name}</h1>
            {product.excluded && <Badge variant="secondary">{t("product.excluded")}</Badge>}
          </div>
          <p className="text-sm text-muted-foreground">
            {categoryPath ?? t("analysis.uncategorized")}
            {" · "}
            {reference ? t("product.pricePer", { reference }) : t("product.noUnit")}
          </p>
        </div>
        <Button variant="outline" onClick={() => setSettingsOpen(true)}>
          <SettingsIcon />
          {t("product.settings.open")}
        </Button>
      </div>

      <div className="flex flex-wrap items-center gap-2">
        <PeriodPicker value={period} onChange={setPeriod} />
        {!isDefaultPeriod && (
          <Button variant="ghost" size="sm" onClick={() => setPeriod(defaults)}>
            {t("product.last12Months")}
          </Button>
        )}
        <BasisToggle basis={basis} onChange={setBasis} />
      </div>

      {noPriceReason ? (
        <Alert>
          <InfoIcon />
          <AlertTitle>{t(`product.noPrices.${noPriceReason}.title`)}</AlertTitle>
          <AlertDescription>{t(`product.noPrices.${noPriceReason}.description`)}</AlertDescription>
        </Alert>
      ) : (
        <>
          {stats && <StatCards stats={stats} />}

          <Card className="py-0">
            <CardHeader className="pt-4">
              <CardTitle>{t("product.chart.title")}</CardTitle>
              <CardDescription>
                {reference ? t("product.chart.descriptionPer", { reference, basis: basisWord }) : t("product.chart.description", { basis: basisWord })}
              </CardDescription>
            </CardHeader>
            <CardContent className="px-2 pb-4 sm:px-4">
              {chart.points.length > 0 ? (
                <ProductPriceChart chart={chart} reference={reference} />
              ) : (
                <p className="px-4 py-10 text-center text-sm text-muted-foreground">
                  {t("product.chart.none", { period: format.period(period) })}
                </p>
              )}
            </CardContent>
          </Card>

          {chart.withoutPrice > 0 && (
            <Alert>
              <InfoIcon />
              <AlertDescription>{t("product.chart.withoutPrice", { count: chart.withoutPrice, basis: basisWord })}</AlertDescription>
            </Alert>
          )}
        </>
      )}

      <ProductPurchases
        organizationId={organizationId}
        product={product}
        history={history}
        basis={basis}
        period={period}
        onOpenInvoice={setInvoiceId}
        onEditItem={setEditing}
      />

      <InvoiceSheet organizationId={organizationId} invoiceId={invoiceId} onClose={() => setInvoiceId(null)} />
      <PurchasedItemDialog
        organizationId={organizationId}
        item={editing}
        product={product}
        categories={categories}
        onClose={() => setEditing(null)}
      />
      <ProductSettingsDialog
        organizationId={organizationId}
        product={product}
        categories={categories}
        open={settingsOpen}
        onOpenChange={setSettingsOpen}
        onMerge={() => setMergeOpen(true)}
      />
      <ProductMergeDialog
        organizationId={organizationId}
        product={product}
        categories={categories}
        open={mergeOpen}
        onOpenChange={setMergeOpen}
        onMerged={(into) =>
          void navigate({ to: "/analysis/products/$productId", params: { productId: into.id }, search: (previous) => previous })
        }
      />
    </div>
  )
}

/** The latest, lowest and highest price in the period, each with its date; the latest with its change. */
function StatCards({ stats }: { stats: NonNullable<ReturnType<typeof priceStats>> }) {
  const { t } = useTranslation("admin")
  const format = useDateTimeFormat()
  const money = useMoneyFormat()
  const cards: { key: "latest" | "lowest" | "highest"; point: ChartPoint }[] = [
    { key: "latest", point: stats.latest },
    { key: "lowest", point: stats.lowest },
    { key: "highest", point: stats.highest },
  ]
  return (
    <dl className="grid grid-cols-3 gap-2 sm:gap-3">
      {cards.map(({ key, point }) => (
        <Card key={key} className="gap-1 py-3 sm:py-4">
          <CardContent className="flex flex-col gap-0.5 px-3 sm:px-4">
            <dt className="text-xs text-muted-foreground">{t(`product.stats.${key}`)}</dt>
            <dd className="text-base font-semibold tabular-nums sm:text-xl">{money(point.price)}</dd>
            <dd className="flex flex-wrap items-center gap-x-2 text-xs text-muted-foreground">
              <span>{format.businessDate(point.date)}</span>
              {key === "latest" && point.change && <PriceChangeLabel change={point.change} wrap />}
            </dd>
          </CardContent>
        </Card>
      ))}
    </dl>
  )
}
