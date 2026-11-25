import { ArrowDownIcon, ArrowRightIcon, ArrowUpIcon, ChevronRightIcon } from "lucide-react"
import { useTranslation } from "react-i18next"

import { Badge } from "@business-tool/ui/components/badge"
import { Button } from "@business-tool/ui/components/button"
import { useLocale } from "@business-tool/ui/hooks/use-locale"
import { useDateTimeFormat } from "@business-tool/ui/hooks/use-organization-time-zone"
import { useMoneyFormat } from "@business-tool/ui/hooks/use-organization-currency"
import { cn } from "@business-tool/ui/lib/utils"

import {
  type AnalysisNode,
  type AnalysisProduct,
  type Basis,
  type ChangeDirection,
  averagePriceOf,
  formatShare,
  lastPriceOf,
  priceChangeOf,
  quantityLabel,
  referenceLabel,
  shareTenths,
} from "@/lib/purchase-analysis"

/*
 * The rows of the Analiza overview (#244): categories that open to subcategories and products, and
 * at the end the items that have no product yet. Wide containers show a table of seven columns;
 * narrower ones stack each row: name, spend and share on top, the rest below with its labels, so
 * the page never scrolls sideways. The width is the list's own (a container query), not the
 * window's: the sidebar takes a part of it.
 */

/** The room each level takes, in px. */
const INDENT = 20
/** The chevron button's width: a row without one keeps its name in line with its siblings. */
const CHEVRON = 28

const COLUMNS = "@5xl:grid-cols-[minmax(0,1fr)_6.5rem_3.5rem_7.5rem_7.5rem_7.5rem_10rem]"
const ROW = cn("flex flex-col gap-1 px-3 py-2.5 @5xl:grid @5xl:items-center @5xl:gap-x-3", COLUMNS)

/** The column headings, for the wide layout only. */
export function AnalysisHeader({ basis }: { basis: Basis }) {
  const { t } = useTranslation("admin")
  return (
    <div
      className={cn(
        "hidden gap-x-3 border-b bg-muted/40 px-3 py-2 text-xs font-medium text-muted-foreground @5xl:grid",
        COLUMNS,
      )}
    >
      <span>{t("analysis.columns.name")}</span>
      <span className="text-right">{t("analysis.columns.spend", { context: basis })}</span>
      <span className="text-right">{t("analysis.columns.share")}</span>
      <span className="text-right">{t("analysis.columns.quantity")}</span>
      <span className="text-right">{t("analysis.columns.averagePrice", { context: basis })}</span>
      <span className="text-right">{t("analysis.columns.lastPrice", { context: basis })}</span>
      <span className="text-right">{t("analysis.columns.change")}</span>
    </div>
  )
}

interface AnalysisTreeProps {
  nodes: readonly AnalysisNode[]
  basis: Basis
  /** What the shares are of: the total spend on the basis. */
  total: number
  expanded: ReadonlySet<string>
  onToggle: (id: string) => void
}

/** The tree's rows from `nodes` down; a branch shows its children while it is open. */
export function AnalysisTree({ nodes, basis, total, expanded, onToggle }: AnalysisTreeProps) {
  return (
    <ul className="divide-y">
      {nodes.map((node) => (
        <AnalysisBranch key={node.id} node={node} depth={0} basis={basis} total={total} expanded={expanded} onToggle={onToggle} />
      ))}
    </ul>
  )
}

interface BranchProps extends Omit<AnalysisTreeProps, "nodes"> {
  node: AnalysisNode
  depth: number
}

function AnalysisBranch({ node, depth, basis, total, expanded, onToggle }: BranchProps) {
  const { t } = useTranslation("admin")
  const locale = useLocale()
  const money = useMoneyFormat()
  const open = node.kind !== "product" && expanded.has(node.id)
  const tenths = shareTenths(node.spend, total)
  const indent = { "--indent": `${8 + depth * INDENT}px` } as React.CSSProperties

  const spend = <span className="font-medium tabular-nums @5xl:text-right">{money(node.spend)}</span>
  const share = (
    <span className="w-12 text-right text-sm text-muted-foreground tabular-nums @5xl:w-auto">
      {tenths === null ? "" : formatShare(tenths, locale)}
    </span>
  )

  return (
    <li style={indent}>
      <div className={ROW}>
        <div className="flex items-center justify-between gap-3 @5xl:contents">
          <div className="flex min-w-0 items-start pl-(--indent) @5xl:items-center">
            {node.kind === "product" ? (
              <span className="shrink-0" style={{ width: CHEVRON }} aria-hidden />
            ) : (
              <Button
                variant="ghost"
                size="icon-sm"
                className="-my-1"
                aria-expanded={open}
                aria-label={t(open ? "analysis.collapse" : "analysis.expand", { name: node.name })}
                onClick={() => onToggle(node.id)}
              >
                <ChevronRightIcon className={cn("transition-transform", open && "rotate-90")} />
              </Button>
            )}
            <div className="flex min-w-0 flex-wrap items-baseline gap-x-2 self-center">
              <span className={cn("break-words @5xl:truncate", node.kind !== "product" && "font-medium")}>
                {node.kind === "product" ? node.product.name : node.name}
              </span>
              {node.kind !== "product" && (
                <span className="shrink-0 text-xs text-muted-foreground">
                  {t("analysis.productsIn", { count: countProducts(node) })}
                </span>
              )}
            </div>
          </div>
          <div className="flex shrink-0 items-center gap-2 @5xl:contents">
            {spend}
            {share}
          </div>
        </div>
        {node.kind === "product" && <ProductDetails product={node.product} basis={basis} />}
      </div>
      {open && (
        <ul className="divide-y border-t">
          {node.children.map((child) => (
            <AnalysisBranch
              key={child.id}
              node={child}
              depth={depth + 1}
              basis={basis}
              total={total}
              expanded={expanded}
              onToggle={onToggle}
            />
          ))}
        </ul>
      )}
    </li>
  )
}

/** How many products are in a branch, at any depth. */
function countProducts(node: AnalysisNode): number {
  return node.kind === "product" ? 1 : node.children.reduce((sum, child) => sum + countProducts(child), 0)
}

const CHANGE_STYLE: Record<ChangeDirection, string> = {
  // A higher price is bad news for a buyer, a lower one good.
  up: "text-destructive",
  down: "text-emerald-700 dark:text-emerald-400",
  flat: "text-muted-foreground",
}

const CHANGE_ICON = { up: ArrowUpIcon, down: ArrowDownIcon, flat: ArrowRightIcon } as const

/** The quantity, average price, last price and its change, on the wide layout's columns or under the name when stacked. */
function ProductDetails({ product, basis }: { product: AnalysisProduct; basis: Basis }) {
  const { t } = useTranslation("admin")
  const locale = useLocale()
  const money = useMoneyFormat()
  const format = useDateTimeFormat()
  const reference = referenceLabel(product, locale)
  const quantity = quantityLabel(product, locale)
  const average = averagePriceOf(product, basis)
  const last = lastPriceOf(product, basis)
  const change = priceChangeOf(last, locale)
  const ChangeIcon = change ? CHANGE_ICON[change.direction] : null
  const none = <span className="text-muted-foreground">—</span>

  return (
    <div className="flex flex-wrap gap-x-4 gap-y-1 pl-[calc(var(--indent)+28px)] text-sm @5xl:contents">
      <Cell label={t("analysis.columns.quantity")}>{quantity ?? none}</Cell>
      <Cell label={t("analysis.columns.averagePrice", { context: basis })}>
        {average === null ? none : (
          <>
            <span className="tabular-nums">{money(average)}</span>
            {reference && <span className="text-xs text-muted-foreground"> / {reference}</span>}
          </>
        )}
      </Cell>
      <Cell label={t("analysis.columns.lastPrice", { context: basis })}>
        {last === null ? none : (
          <>
            <span className="tabular-nums">{money(last.price)}</span>
            {product.lastPriceDate && (
              <span className="block text-xs text-muted-foreground @5xl:mt-0.5">{format.businessDate(product.lastPriceDate)}</span>
            )}
          </>
        )}
      </Cell>
      <Cell label={t("analysis.columns.change")}>
        {change === null || ChangeIcon === null ? none : (
          <span
            className={cn("inline-flex items-center gap-1 whitespace-nowrap tabular-nums", CHANGE_STYLE[change.direction])}
            title={t(`analysis.change.${change.direction}`)}
          >
            <ChangeIcon className="size-3.5" aria-label={t(`analysis.change.${change.direction}`)} />
            <span>
              {change.direction === "flat" ? money(0) : `${change.amount > 0 ? "+" : "−"}${money(Math.abs(change.amount))}`}
              {change.percent && <span className="text-xs"> ({change.percent})</span>}
            </span>
          </span>
        )}
      </Cell>
    </div>
  )
}

/** One of the product's figures: labelled when stacked, a right aligned column when wide. */
function Cell({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <span className="@5xl:text-right">
      <span className="mr-1 text-xs text-muted-foreground @5xl:hidden">{label}</span>
      {children}
    </span>
  )
}

interface NotAssignedProps {
  total: number
  amount: number
  count: number
}

/**
 * The items bought but not yet linked to a product (ADR 0041 §8), after the tree. They are in the
 * total, so the shares add up; the review queue (#246) is where they get a product.
 */
export function NotAssignedRow({ total, amount, count }: NotAssignedProps) {
  const { t } = useTranslation("admin")
  const locale = useLocale()
  const money = useMoneyFormat()
  const tenths = shareTenths(amount, total)

  return (
    <div className={cn(ROW, "border-t bg-muted/20")}>
      <div className="flex items-center justify-between gap-3 @5xl:contents">
        <div className="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1" style={{ paddingLeft: 8 + CHEVRON }}>
          <span className="font-medium break-words @5xl:truncate">{t("analysis.notAssigned.name")}</span>
          <Badge variant="secondary" className="shrink-0">
            {t("analysis.notAssigned.items", { count })}
          </Badge>
        </div>
        <div className="flex shrink-0 items-center gap-2 @5xl:contents">
          <span className="font-medium tabular-nums @5xl:text-right">{money(amount)}</span>
          <span className="w-12 text-right text-sm text-muted-foreground tabular-nums @5xl:w-auto">
            {tenths === null ? "" : formatShare(tenths, locale)}
          </span>
        </div>
      </div>
      <p className="text-xs text-muted-foreground @5xl:col-span-full" style={{ paddingLeft: 8 + CHEVRON }}>
        {t("analysis.notAssigned.hint")}
      </p>
    </div>
  )
}
