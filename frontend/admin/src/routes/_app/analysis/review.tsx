import { useEffect, useMemo, useState } from "react"
import { unwrap } from "@business-tool/api-client"
import { useMutation, useQueryClient, useSuspenseInfiniteQuery } from "@tanstack/react-query"
import { createFileRoute, useNavigate, useRouter, type ErrorComponentProps } from "@tanstack/react-router"
import { CircleCheckIcon, SearchIcon, TriangleAlertIcon, XIcon } from "lucide-react"
import { useTranslation } from "react-i18next"
import { toast } from "sonner"

import { Alert, AlertDescription, AlertTitle } from "@business-tool/ui/components/alert"
import { Button } from "@business-tool/ui/components/button"
import { Card, CardContent } from "@business-tool/ui/components/card"
import { Checkbox } from "@business-tool/ui/components/checkbox"
import { Input } from "@business-tool/ui/components/input"
import { Skeleton } from "@business-tool/ui/components/skeleton"
import { requestErrorMessage } from "@business-tool/ui/lib/request-error"

import { AnalysisNav } from "@/components/analysis-nav"
import { BasisToggle } from "@/components/basis-toggle"
import { InvoiceSheet } from "@/components/invoice-sheet"
import { MatchingCard } from "@/components/matching-card"
import { type AssignRequest, ReviewAssignDialog } from "@/components/review-assign-dialog"
import { ReviewGroupRow } from "@/components/review-queue-group"
import { requireCapability } from "@/lib/access"
import { api } from "@/lib/api"
import { type Basis, readBasis, saveBasis } from "@/lib/purchase-analysis"
import { invalidatePurchasing, productCategoriesQuery, reviewQueueQuery } from "@/lib/queries"
import {
  MAX_ASSIGN_ITEMS,
  type QueueItem,
  type ReviewSearch,
  groupByName,
  pruneSelection,
  selectedItems,
  suggestedLinks,
  toggleItems,
  validateReviewSearch,
} from "@/lib/review-queue"

/*
 * The review queue (#246, ADR 0041 §3, §9): the purchased items without a product, or with one that
 * matching only suggested, grouped by the name the invoice printed, newest first. The owner assigns a
 * group, one item or a selection to a product (or to a new one made from them, with the package size
 * they type), confirms a suggestion, or excludes what should not count. Above the queue a card says what
 * matching did last and has the model go through the unassigned items again (ADR 0046). It is where the
 * overview's "Not assigned" row leads; the model's proposed products have a page of their own (#269).
 */
export const Route = createFileRoute("/_app/analysis/review")({
  validateSearch: (search: Record<string, unknown>): ReviewSearch => validateReviewSearch(search),
  beforeLoad: requireCapability("analysis"),
  loaderDeps: ({ search }) => search,
  loader: async ({ context, deps }) => {
    const { organization } = context.membership
    await Promise.all([
      context.queryClient.ensureInfiniteQueryData(reviewQueueQuery(organization.id, deps.q)),
      // The assign dialog's category picker; its products load when it opens.
      context.queryClient.ensureQueryData(productCategoriesQuery(organization.id)),
    ])
  },
  pendingComponent: ReviewPending,
  errorComponent: ReviewError,
  component: ReviewPage,
})

/** The navigation and heading every state of the page starts with. */
function PageHeading() {
  const { t } = useTranslation("admin")
  return (
    <div className="flex flex-col gap-3">
      <AnalysisNav />
      <div>
        <h1 className="text-2xl font-semibold tracking-tight">{t("review.title")}</h1>
        <p className="text-sm text-muted-foreground">{t("review.description")}</p>
      </div>
    </div>
  )
}

function ReviewPending() {
  return (
    <div className="flex flex-col gap-6" aria-busy="true">
      <PageHeading />
      <Skeleton className="h-8 w-56" />
      <Card className="py-0">
        <CardContent className="flex flex-col gap-3 px-4 py-4">
          {Array.from({ length: 6 }, (_, index) => (
            <Skeleton key={index} className="h-10 w-full" />
          ))}
        </CardContent>
      </Card>
    </div>
  )
}

function ReviewError({ error, reset }: ErrorComponentProps) {
  const { t } = useTranslation("admin")
  const router = useRouter()
  return (
    <div className="flex flex-col gap-6">
      <PageHeading />
      <Alert variant="destructive">
        <TriangleAlertIcon />
        <AlertTitle>{t("review.error.title")}</AlertTitle>
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
            {t("review.error.retry")}
          </Button>
        </AlertDescription>
      </Alert>
    </div>
  )
}

function ReviewPage() {
  const { membership } = Route.useRouteContext()
  const organizationId = membership.organization.id
  const { t } = useTranslation("admin")
  const queryClient = useQueryClient()
  const search = Route.useSearch()
  const navigate = useNavigate({ from: Route.fullPath })
  const queue = useSuspenseInfiniteQuery(reviewQueueQuery(organizationId, search.q))

  const [basis, setBasisState] = useState<Basis>(readBasis)
  const [selection, setSelection] = useState<ReadonlySet<string>>(new Set())
  const [request, setRequest] = useState<AssignRequest | null>(null)
  const [invoiceId, setInvoiceId] = useState<string | null>(null)

  const items = useMemo(() => queue.data.pages.flatMap((page) => page.items), [queue.data.pages])
  const groups = useMemo(() => groupByName(items), [items])
  // Items assigned meanwhile (by this page, or elsewhere) drop out of the selection.
  const selected = useMemo(() => pruneSelection(groups, selection), [groups, selection])
  const chosen = useMemo(() => selectedItems(groups, selected), [groups, selected])
  const loaded = groups.reduce((sum, group) => sum + group.items.length, 0)
  const total = queue.data.pages.at(-1)?.total ?? 0
  const suggestedChosen = suggestedLinks(chosen).reduce((sum, link) => sum + link.itemIds.length, 0)
  const allSelected = loaded > 0 && chosen.length === loaded

  const confirm = useMutation({
    // One call per suggested product; each is all or nothing, and the first refusal stops the rest.
    mutationFn: async (list: QueueItem[]) => {
      const links = suggestedLinks(list)
      for (const link of links) {
        await unwrap(api.POST("/api/purchased-items/assign", { body: { itemIds: link.itemIds, productId: link.productId } }))
      }
      return links.reduce((sum, link) => sum + link.itemIds.length, 0)
    },
    onSuccess: (count) => {
      toast.success(t("review.toast.confirmed", { count }))
      setSelection(new Set())
    },
    onError: (error) => toast.error(requestErrorMessage(error)),
    // Some batches may have gone through before a refusal: show what the API has either way.
    onSettled: () => invalidatePurchasing(queryClient, organizationId),
  })

  function setBasis(next: Basis) {
    setBasisState(next)
    saveBasis(next)
  }

  function toggle(list: QueueItem[]) {
    setSelection((current) => toggleItems(current, list))
  }

  return (
    <div className="flex flex-col gap-6">
      <PageHeading />

      <MatchingCard organizationId={organizationId} />

      <div className="flex flex-wrap items-center gap-2">
        <NameSearch search={search} onChange={(q) => void navigate({ search: { q }, replace: true })} />
        <BasisToggle basis={basis} onChange={setBasis} />
      </div>

      {groups.length === 0 ? (
        <Card className="py-0">
          <CardContent className="flex flex-col items-center gap-2 px-6 py-10 text-center text-sm text-muted-foreground">
            <CircleCheckIcon className="size-6" />
            {search.q ? t("review.noMatch", { search: search.q }) : t("review.empty")}
          </CardContent>
        </Card>
      ) : (
        <>
          <div className="flex flex-wrap items-center justify-between gap-3">
            <label className="flex items-center gap-2 text-sm text-muted-foreground">
              <Checkbox
                checked={allSelected ? true : chosen.length > 0 ? "indeterminate" : false}
                onCheckedChange={() => setSelection(allSelected ? new Set() : new Set(items.map((item) => item.id)))}
              />
              {t("review.selectAll")}
            </label>
            <p className="text-sm text-muted-foreground" aria-live="polite">
              {t("review.summary", { count: total })}
            </p>
          </div>

          <Card className="gap-0 overflow-hidden py-0">
            <CardContent className="px-0">
              <ul className="divide-y">
                {groups.map((group) => (
                  <ReviewGroupRow
                    key={group.key}
                    group={group}
                    selected={selected}
                    basis={basis}
                    busy={confirm.isPending}
                    onToggle={toggle}
                    onAssign={(list, preset) => setRequest({ items: list, preset })}
                    onConfirm={(list) => confirm.mutate(list)}
                    onOpenInvoice={setInvoiceId}
                  />
                ))}
              </ul>
            </CardContent>
          </Card>

          {queue.hasNextPage && (
            <div className="flex flex-col items-center gap-2">
              <p className="text-sm text-muted-foreground">{t("review.showing", { loaded, total })}</p>
              <Button variant="outline" disabled={queue.isFetchingNextPage} onClick={() => void queue.fetchNextPage()}>
                {queue.isFetchingNextPage ? t("review.loadingMore") : t("review.loadMore")}
              </Button>
            </div>
          )}
        </>
      )}

      {chosen.length > 0 && (
        <SelectionBar
          count={chosen.length}
          suggested={suggestedChosen}
          busy={confirm.isPending}
          onClear={() => setSelection(new Set())}
          onAssign={(preset) => setRequest({ items: chosen, preset })}
          onConfirm={() => confirm.mutate(chosen)}
        />
      )}

      <ReviewAssignDialog
        organizationId={organizationId}
        request={request}
        onClose={() => setRequest(null)}
        onAssigned={() => setSelection(new Set())}
      />
      <InvoiceSheet organizationId={organizationId} invoiceId={invoiceId} onClose={() => setInvoiceId(null)} />
    </div>
  )
}

/** The name search: typing waits for a pause, and a URL change the field did not make fills it. */
function NameSearch({ search, onChange }: { search: ReviewSearch; onChange: (q: string | undefined) => void }) {
  const { t } = useTranslation("admin")
  const [text, setText] = useState(search.q ?? "")

  const [seen, setSeen] = useState(search.q)
  if (seen !== search.q) {
    setSeen(search.q)
    if ((text.trim() || undefined) !== search.q) setText(search.q ?? "")
  }

  useEffect(() => {
    const value = text.trim() || undefined
    if (value === search.q) return
    const timer = window.setTimeout(() => onChange(value), 300)
    return () => window.clearTimeout(timer)
  }, [text, search.q, onChange])

  return (
    <div className="relative w-full sm:w-72">
      <SearchIcon className="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground" />
      <Input
        type="search"
        value={text}
        onChange={(event) => setText(event.target.value)}
        placeholder={t("review.search")}
        aria-label={t("review.search")}
        autoComplete="off"
        className="pl-8"
      />
    </div>
  )
}

interface SelectionBarProps {
  count: number
  /** How many of the selected items have a suggestion to confirm. */
  suggested: number
  busy: boolean
  onClear: () => void
  onAssign: (preset: "assign" | "create" | "exclude") => void
  onConfirm: () => void
}

/** What can be done to the selected items, kept at the bottom of the screen while the list scrolls. */
function SelectionBar({ count, suggested, busy, onClear, onAssign, onConfirm }: SelectionBarProps) {
  const { t } = useTranslation("admin")
  const tooMany = count > MAX_ASSIGN_ITEMS
  return (
    <div
      role="region"
      aria-label={t("review.selection.label")}
      className="sticky bottom-3 z-10 flex flex-wrap items-center gap-2 rounded-lg border bg-card p-3 shadow-lg"
    >
      <p className="mr-auto text-sm font-medium" aria-live="polite">
        {t("review.selection.count", { count })}
        {tooMany && <span className="block text-xs font-normal text-destructive">{t("review.assign.tooMany", { max: MAX_ASSIGN_ITEMS })}</span>}
      </p>
      <Button size="sm" disabled={tooMany} onClick={() => onAssign("assign")}>
        {t("review.group.assign")}
      </Button>
      <Button size="sm" variant="outline" disabled={tooMany} onClick={() => onAssign("create")}>
        {t("review.menu.create")}
      </Button>
      <Button size="sm" variant="outline" disabled={tooMany} onClick={() => onAssign("exclude")}>
        {t("review.menu.exclude")}
      </Button>
      {suggested > 0 && (
        <Button size="sm" variant="outline" disabled={busy || tooMany} onClick={onConfirm}>
          {t("review.selection.confirm", { count: suggested })}
        </Button>
      )}
      <Button size="icon-sm" variant="ghost" aria-label={t("review.selection.clear")} onClick={onClear}>
        <XIcon />
      </Button>
    </div>
  )
}
