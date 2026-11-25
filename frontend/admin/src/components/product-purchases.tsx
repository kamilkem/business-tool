import { useState } from "react"
import { keepPreviousData, useQuery } from "@tanstack/react-query"
import { ChevronLeftIcon, ChevronRightIcon, MoreHorizontalIcon } from "lucide-react"
import { useTranslation } from "react-i18next"

import { Badge } from "@business-tool/ui/components/badge"
import { Button } from "@business-tool/ui/components/button"
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@business-tool/ui/components/card"
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from "@business-tool/ui/components/dropdown-menu"
import { Skeleton } from "@business-tool/ui/components/skeleton"
import { useLocale } from "@business-tool/ui/hooks/use-locale"
import { useDateTimeFormat } from "@business-tool/ui/hooks/use-organization-time-zone"
import { type BusinessPeriod } from "@business-tool/ui/lib/date-time"
import { formatDecimal } from "@business-tool/ui/lib/decimal"
import { formatMoney } from "@business-tool/ui/lib/money"
import { requestErrorMessage } from "@business-tool/ui/lib/request-error"
import { cn } from "@business-tool/ui/lib/utils"
import { useMoneyFormat } from "@business-tool/ui/hooks/use-organization-currency"

import { type ItemRef } from "@/components/purchased-item-dialog"
import { PriceChangeLabel } from "@/components/price-change"
import {
  type PricePoint,
  type Product,
  type ProductPriceHistory,
  itemAmount,
  itemsOutsidePrices,
  pointPrice,
} from "@/lib/product-prices"
import { PRODUCT_ITEMS_PER_PAGE, productItemsQuery } from "@/lib/queries"
import { type Basis, priceChangeOf, referenceLabel, unitOf } from "@/lib/purchase-analysis"

/*
 * What was bought (ADR 0041 §9): the purchases the price history is made of, newest first, each with
 * its invoice, and under them the product's other items, which are in its spend but in no price
 * (a correction, no package size, another currency). Both come with an action to move the item to
 * another product. Wide containers show a table; narrower ones stack each row, so the page never
 * scrolls sideways.
 */

const COLUMNS = "@3xl:grid-cols-[5.5rem_minmax(0,1fr)_minmax(0,1.3fr)_7rem_8rem_9.5rem_2.25rem]"
const ROW = cn("flex flex-col gap-1 px-3 py-2.5 @3xl:grid @3xl:items-center @3xl:gap-x-3", COLUMNS)

interface PurchasesProps {
  organizationId: string
  product: Product
  history: ProductPriceHistory
  basis: Basis
  period: BusinessPeriod
  onOpenInvoice: (invoiceId: string) => void
  onEditItem: (item: ItemRef) => void
}

export function ProductPurchases(props: PurchasesProps) {
  // A product without a unit, or an excluded one, has no prices: only its items are left to show.
  const priced = props.product.measure !== "none" && !props.product.excluded
  return (
    <>
      {priced && <PurchasesCard {...props} />}
      <OtherItemsCard {...props} />
    </>
  )
}

function PurchasesCard({ history, basis, onOpenInvoice, onEditItem }: PurchasesProps) {
  const { t } = useTranslation("admin")
  const points = [...history.points].reverse()

  return (
    <Card className="gap-0 overflow-hidden py-0">
      <CardHeader className="border-b py-4">
        <CardTitle>{t("product.purchases.title")}</CardTitle>
        {points.length > 0 && <CardDescription>{t("product.purchases.description", { count: points.length })}</CardDescription>}
      </CardHeader>
      <CardContent className="@container px-0">
        {points.length === 0 ? (
          <p className="px-6 py-8 text-center text-sm text-muted-foreground">{t("product.purchases.none")}</p>
        ) : (
          <>
            <div
              className={cn(
                "hidden gap-x-3 border-b bg-muted/40 px-3 py-2 text-xs font-medium text-muted-foreground @3xl:grid",
                COLUMNS,
              )}
            >
              <span>{t("product.purchases.columns.date")}</span>
              <span>{t("product.purchases.columns.seller")}</span>
              <span>{t("product.purchases.columns.item")}</span>
              <span className="text-right">{t("product.purchases.columns.quantity")}</span>
              <span className="text-right">{t("product.purchases.columns.price", { context: basis })}</span>
              <span className="text-right">{t("analysis.columns.change")}</span>
              <span />
            </div>
            <ul className="divide-y">
              {points.map((point) => (
                <PurchaseRow
                  key={point.itemId}
                  point={point}
                  product={history}
                  basis={basis}
                  onOpenInvoice={onOpenInvoice}
                  onEditItem={onEditItem}
                />
              ))}
            </ul>
          </>
        )}
      </CardContent>
    </Card>
  )
}

/** "6 × 0.7 L": the quantity bought and the package size in the product's unit. */
function quantityText(quantity: string, packageSize: string, measure: string, locale: string): string {
  const unit = unitOf(measure)
  return `${formatDecimal(quantity, locale)} × ${formatDecimal(packageSize, locale)}${unit ? ` ${unit}` : ""}`
}

/** The invoice's number as a button that opens it; a receipt without a number says so. */
function InvoiceButton({ number, onClick }: { number: string | null; onClick: () => void }) {
  const { t } = useTranslation("admin")
  return (
    <Button variant="link" size="sm" className="h-auto min-w-0 justify-start p-0 text-xs break-all whitespace-normal" onClick={onClick}>
      {number ?? t("product.purchases.noNumber")}
    </Button>
  )
}

function ItemMenu({ item, onEditItem }: { item: ItemRef; onEditItem: (item: ItemRef) => void }) {
  const { t } = useTranslation("admin")
  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <Button variant="ghost" size="icon-sm" aria-label={t("product.item.actions", { name: item.name })}>
          <MoreHorizontalIcon />
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end">
        <DropdownMenuItem onSelect={() => onEditItem(item)}>{t("product.item.menu")}</DropdownMenuItem>
      </DropdownMenuContent>
    </DropdownMenu>
  )
}

interface PurchaseRowProps {
  point: PricePoint
  product: Pick<ProductPriceHistory, "measure" | "referenceQuantity">
  basis: Basis
  onOpenInvoice: (invoiceId: string) => void
  onEditItem: (item: ItemRef) => void
}

function PurchaseRow({ point, product, basis, onOpenInvoice, onEditItem }: PurchaseRowProps) {
  const { t } = useTranslation("admin")
  const locale = useLocale()
  const format = useDateTimeFormat()
  const money = useMoneyFormat()
  const price = pointPrice(point, basis)
  const change = priceChangeOf(price, locale)
  const reference = referenceLabel(product, locale)
  const none = <span className="text-muted-foreground">—</span>

  return (
    <li className={ROW}>
      <div className="flex items-start justify-between gap-2 @3xl:contents">
        <div className="flex min-w-0 flex-col @3xl:contents">
          <span className="text-sm font-medium tabular-nums">{format.businessDate(point.date)}</span>
          <div className="flex min-w-0 flex-col">
            <span className="text-sm break-words">{point.sellerName}</span>
            <InvoiceButton number={point.invoiceNumber} onClick={() => onOpenInvoice(point.invoiceId)} />
          </div>
        </div>
        <div className="@3xl:hidden">
          <ItemMenu item={{ id: point.itemId, name: point.itemName, packageSize: point.packageSize }} onEditItem={onEditItem} />
        </div>
      </div>
      <span className="text-sm break-words text-muted-foreground @3xl:text-foreground">{point.itemName}</span>
      <span className="text-sm tabular-nums @3xl:text-right">
        <span className="mr-1 text-xs text-muted-foreground @3xl:hidden">{t("product.purchases.columns.quantity")}</span>
        {quantityText(point.quantity, point.packageSize, product.measure, locale)}
      </span>
      <span className="text-sm @3xl:text-right">
        <span className="mr-1 text-xs text-muted-foreground @3xl:hidden">{t("product.purchases.columns.price", { context: basis })}</span>
        {price === null ? none : (
          <>
            <span className="font-medium tabular-nums">{money(price.price)}</span>
            {reference && <span className="text-xs text-muted-foreground"> / {reference}</span>}
          </>
        )}
      </span>
      <span className="text-sm @3xl:text-right">
        {change === null ? <span className="text-muted-foreground @3xl:inline">{price === null ? "" : t("product.chart.firstPurchase")}</span> : <PriceChangeLabel change={change} />}
      </span>
      <div className="hidden @3xl:block">
        <ItemMenu item={{ id: point.itemId, name: point.itemName, packageSize: point.packageSize }} onEditItem={onEditItem} />
      </div>
    </li>
  )
}

/**
 * The product's other items in the period: in its spend, in no price. Each says why, and can be
 * moved or given a package size. Pages of the API's items, the ones already in the prices left out.
 */
function OtherItemsCard({ organizationId, product, history, basis, period, onEditItem, onOpenInvoice }: PurchasesProps) {
  const { t } = useTranslation("admin")
  const locale = useLocale()
  const format = useDateTimeFormat()
  const [page, setPage] = useState(1)
  const items = useQuery({ ...productItemsQuery(organizationId, product.id, period, page), placeholderData: keepPreviousData })
  const pointIds = new Set(history.points.map((point) => point.itemId))

  if (items.isPending) return <Skeleton className="h-24 w-full" />
  if (items.isError) {
    return (
      <Card className="py-0">
        <CardContent className="px-6 py-4 text-sm text-destructive">{requestErrorMessage(items.error)}</CardContent>
      </Card>
    )
  }

  const rows = itemsOutsidePrices(items.data.items, pointIds, product, history.currency)
  const pages = Math.max(1, Math.ceil(items.data.total / PRODUCT_ITEMS_PER_PAGE))
  // Everything is in the prices above: nothing to add.
  if (rows.length === 0 && pages === 1) return null

  return (
    <Card className="gap-0 overflow-hidden py-0">
      <CardHeader className="border-b py-4">
        <CardTitle>{t("product.items.title")}</CardTitle>
        <CardDescription>{t("product.items.description")}</CardDescription>
      </CardHeader>
      <CardContent className="px-0">
        {rows.length === 0 ? (
          <p className="px-6 py-6 text-center text-sm text-muted-foreground">{t("product.items.none")}</p>
        ) : (
          <ul className="divide-y">
            {rows.map(({ item, reason }) => {
              const amount = itemAmount(item, basis)
              return (
                <li key={item.id} className="flex flex-col gap-1 px-3 py-2.5">
                  <div className="flex items-start justify-between gap-2">
                    <div className="flex min-w-0 flex-col">
                      <span className="text-sm break-words">{item.name}</span>
                      <span className="text-xs text-muted-foreground">
                        {format.businessDate(item.invoiceIssueDate)} · {item.sellerName}
                      </span>
                    </div>
                    <ItemMenu item={{ id: item.id, name: item.name, packageSize: item.packageSize }} onEditItem={onEditItem} />
                  </div>
                  <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted-foreground">
                    <Button variant="link" size="sm" className="h-auto p-0 text-xs" onClick={() => onOpenInvoice(item.invoiceId)}>
                      {item.invoiceNumber ?? t("product.purchases.noNumber")}
                    </Button>
                    {item.quantity !== null && (
                      <span className="tabular-nums">
                        {formatDecimal(item.quantity, locale)}
                        {item.packageSize !== null && ` × ${formatDecimal(item.packageSize, locale)}`}
                      </span>
                    )}
                    <span className="tabular-nums">{amount === null ? "—" : formatMoney(amount, item.currency, locale)}</span>
                    {reason && <Badge variant="secondary">{t(`product.items.reason.${reason}`)}</Badge>}
                  </div>
                </li>
              )
            })}
          </ul>
        )}
        {pages > 1 && (
          <div className="flex items-center justify-between gap-2 border-t px-3 py-2">
            <Button variant="outline" size="sm" disabled={page <= 1} onClick={() => setPage(page - 1)}>
              <ChevronLeftIcon />
              {t("product.items.previous")}
            </Button>
            <span className="text-xs text-muted-foreground tabular-nums">{t("product.items.page", { page, pages })}</span>
            <Button variant="outline" size="sm" disabled={page >= pages} onClick={() => setPage(page + 1)}>
              {t("product.items.next")}
              <ChevronRightIcon />
            </Button>
          </div>
        )}
      </CardContent>
    </Card>
  )
}
