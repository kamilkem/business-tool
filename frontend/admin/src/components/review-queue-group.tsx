import { useState } from "react"
import { ChevronDownIcon, ChevronRightIcon, MoreHorizontalIcon } from "lucide-react"
import { useTranslation } from "react-i18next"

import { Badge } from "@business-tool/ui/components/badge"
import { Button } from "@business-tool/ui/components/button"
import { Checkbox } from "@business-tool/ui/components/checkbox"
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from "@business-tool/ui/components/dropdown-menu"
import { useLocale } from "@business-tool/ui/hooks/use-locale"
import { useDateTimeFormat } from "@business-tool/ui/hooks/use-organization-time-zone"
import { formatDecimal } from "@business-tool/ui/lib/decimal"
import { formatMoney } from "@business-tool/ui/lib/money"
import { cn } from "@business-tool/ui/lib/utils"

import type { AssignPreset } from "@/components/review-assign-dialog"
import { isCorrection, kindLabel } from "@/lib/expenses"
import { itemAmount } from "@/lib/product-prices"
import type { Basis } from "@/lib/purchase-analysis"
import { type QueueGroup, type QueueItem, selectionState, suggestedLinks } from "@/lib/review-queue"

/** What a row's actions do: open the assign dialog for its items, or confirm their suggestions. */
export interface QueueActions {
  onAssign: (items: QueueItem[], preset: AssignPreset) => void
  onConfirm: (items: QueueItem[]) => void
  onOpenInvoice: (invoiceId: string) => void
  /** Selecting or unselecting these items. */
  onToggle: (items: QueueItem[]) => void
  /** A confirmation is running: the actions wait. */
  busy: boolean
}

interface GroupRowProps extends QueueActions {
  group: QueueGroup
  selected: ReadonlySet<string>
  basis: Basis
}

/**
 * One printed name of the queue: a checkbox for all its items, how many and from whom, a suggestion
 * to confirm when matching made one, the actions, and the items themselves one level down (each can
 * be selected or acted on alone). Stacked rows on a phone, so nothing scrolls sideways.
 */
export function ReviewGroupRow({ group, selected, basis, ...actions }: GroupRowProps) {
  const { t } = useTranslation("admin")
  const format = useDateTimeFormat()
  const [open, setOpen] = useState(false)
  const state = selectionState(group.items, selected)
  const [firstSeller, ...otherSellers] = group.sellers
  const confirmable = group.suggestions.reduce((sum, link) => sum + link.itemIds.length, 0)

  return (
    <li className={cn(state !== "none" && "bg-muted/30")}>
      <div className="flex items-start gap-3 px-3 py-3">
        <Checkbox
          className="mt-1"
          checked={state === "all" ? true : state === "some" ? "indeterminate" : false}
          onCheckedChange={() => actions.onToggle(group.items)}
          aria-label={t("review.group.select", { name: group.name })}
        />
        <div className="flex min-w-0 flex-1 flex-col gap-1.5">
          <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
            <button
              type="button"
              aria-expanded={open}
              onClick={() => setOpen((current) => !current)}
              className="flex min-w-0 items-start gap-1 rounded-sm text-left font-medium break-words outline-none focus-visible:ring-3 focus-visible:ring-ring/50"
            >
              {open ? <ChevronDownIcon className="mt-0.5 size-4 shrink-0" /> : <ChevronRightIcon className="mt-0.5 size-4 shrink-0" />}
              <span className="min-w-0">{group.name}</span>
              <span className="sr-only">{open ? t("review.group.hide") : t("review.group.show")}</span>
            </button>
            <Badge variant="secondary" className="shrink-0">
              {t("review.group.items", { count: group.items.length })}
            </Badge>
          </div>
          <p className="text-xs text-muted-foreground">
            {format.businessDate(group.newestDate)}
            {" · "}
            {firstSeller}
            {otherSellers.length > 0 && ` ${t("review.group.moreSellers", { count: otherSellers.length })}`}
          </p>
          {group.suggestions.map((link) => (
            <p key={link.productId} className="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs">
              <span className="text-muted-foreground">
                {t("review.group.suggested", { name: link.productName, count: link.itemIds.length })}
              </span>
            </p>
          ))}
        </div>
        <div className="flex shrink-0 flex-wrap items-center justify-end gap-1">
          {confirmable > 0 && (
            <Button
              variant="outline"
              size="sm"
              disabled={actions.busy}
              onClick={() => actions.onConfirm(group.items)}
              aria-label={t("review.group.confirmFor", { name: group.name })}
            >
              {t("review.group.confirm")}
            </Button>
          )}
          <Button
            variant={confirmable > 0 ? "ghost" : "outline"}
            size="sm"
            onClick={() => actions.onAssign(group.items, "assign")}
            aria-label={t("review.group.assignFor", { name: group.name })}
          >
            {t("review.group.assign")}
          </Button>
          <ActionsMenu label={t("review.group.actions", { name: group.name })} items={group.items} {...actions} />
        </div>
      </div>
      {open && (
        <ul className="divide-y border-t bg-muted/20">
          {group.items.map((item) => (
            <ItemRow key={item.id} item={item} selected={selected.has(item.id)} basis={basis} {...actions} />
          ))}
        </ul>
      )}
    </li>
  )
}

/** The actions beyond assigning to a product, for the group's items or one item. */
function ActionsMenu({
  label,
  items,
  withAssign = false,
  onAssign,
  onConfirm,
  busy,
}: { label: string; items: QueueItem[]; withAssign?: boolean } & QueueActions) {
  const { t } = useTranslation("admin")
  const confirmable = suggestedLinks(items).length > 0
  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <Button variant="ghost" size="icon-sm" aria-label={label}>
          <MoreHorizontalIcon />
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end">
        {withAssign && <DropdownMenuItem onSelect={() => onAssign(items, "assign")}>{t("review.menu.assign")}</DropdownMenuItem>}
        <DropdownMenuItem onSelect={() => onAssign(items, "create")}>{t("review.menu.create")}</DropdownMenuItem>
        <DropdownMenuItem onSelect={() => onAssign(items, "exclude")}>{t("review.menu.exclude")}</DropdownMenuItem>
        {confirmable && (
          <DropdownMenuItem disabled={busy} onSelect={() => onConfirm(items)}>
            {t("review.menu.confirm")}
          </DropdownMenuItem>
        )}
      </DropdownMenuContent>
    </DropdownMenu>
  )
}

interface ItemRowProps extends QueueActions {
  item: QueueItem
  selected: boolean
  basis: Basis
}

/** One invoice item: its date, seller and invoice, what was bought and for how much on the basis. */
function ItemRow({ item, selected, basis, ...actions }: ItemRowProps) {
  const { t } = useTranslation("admin")
  const locale = useLocale()
  const format = useDateTimeFormat()
  const amount = itemAmount(item, basis)
  const quantity = item.quantity === null ? null : `${formatDecimal(item.quantity, locale)}${item.unit ? ` ${item.unit}` : ""}`
  const [suggestion] = suggestedLinks([item])

  return (
    <li className={cn("flex items-start gap-3 px-3 py-2.5 pl-10", selected && "bg-muted/40")}>
      <Checkbox
        className="mt-0.5"
        checked={selected}
        onCheckedChange={() => actions.onToggle([item])}
        aria-label={t("review.item.select", { date: format.businessDate(item.invoiceIssueDate), seller: item.sellerName })}
      />
      <div className="flex min-w-0 flex-1 flex-col gap-0.5 text-sm">
        <div className="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-0.5">
          <span className="font-medium tabular-nums">{format.businessDate(item.invoiceIssueDate)}</span>
          <span className="tabular-nums">
            {quantity && <span className="mr-3 text-muted-foreground">{quantity}</span>}
            {amount === null ? <span className="text-muted-foreground">—</span> : formatMoney(amount, item.currency, locale)}
          </span>
        </div>
        <span className="break-words text-muted-foreground">{item.sellerName}</span>
        <div className="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-muted-foreground">
          <Button
            variant="link"
            size="sm"
            className="h-auto min-w-0 p-0 text-xs break-all whitespace-normal"
            onClick={() => actions.onOpenInvoice(item.invoiceId)}
          >
            {item.invoiceNumber ?? t("product.purchases.noNumber")}
          </Button>
          {item.invoiceKind !== "regular" && <Badge variant="outline">{kindLabel(item.invoiceKind)}</Badge>}
          {isCorrection(item.invoiceKind) && item.beforeCorrection && <span>{t("review.item.beforeCorrection")}</span>}
          {suggestion && <span>{t("review.item.suggested", { name: suggestion.productName })}</span>}
        </div>
        {item.matchSource !== null && (
          <p className="text-xs text-muted-foreground">
            {t("review.item.matchedBy", { source: t(`review.matchSource.${item.matchSource}`) })}
            {item.matchCaveat !== null && (
              <>
                {" · "}
                {t("review.item.why", { reason: t(`review.matchCaveat.${item.matchCaveat}`) })}
              </>
            )}
          </p>
        )}
      </div>
      <div className="flex shrink-0 items-center">
        {suggestion && (
          <Button variant="outline" size="sm" disabled={actions.busy} onClick={() => actions.onConfirm([item])}>
            {t("review.group.confirm")}
          </Button>
        )}
        <ActionsMenu
          label={t("review.item.actions", { date: format.businessDate(item.invoiceIssueDate), seller: item.sellerName })}
          items={[item]}
          withAssign
          {...actions}
        />
      </div>
    </li>
  )
}
