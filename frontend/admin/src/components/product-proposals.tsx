import { useState, type FormEvent } from "react"
import { ApiError, unwrap } from "@business-tool/api-client"
import { useMutation, useQuery, useQueryClient, useSuspenseQuery } from "@tanstack/react-query"
import { CircleCheckIcon, PlusIcon } from "lucide-react"
import { useTranslation } from "react-i18next"
import { toast } from "sonner"

import { Badge } from "@business-tool/ui/components/badge"
import { Button } from "@business-tool/ui/components/button"
import { Card, CardContent } from "@business-tool/ui/components/card"
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@business-tool/ui/components/dialog"
import { Field, FieldDescription, FieldError, FieldGroup, FieldLabel } from "@business-tool/ui/components/field"
import { Input } from "@business-tool/ui/components/input"
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@business-tool/ui/components/select"
import { Skeleton } from "@business-tool/ui/components/skeleton"
import { requestErrorMessage } from "@business-tool/ui/lib/request-error"

import { PackageSizeFollowUp, type PackageSizeFollowUpRequest } from "@/components/package-size-follow-up"
import { ProductPicker } from "@/components/product-picker"
import { api } from "@/lib/api"
import { type ProductCategory, parentChoices } from "@/lib/product-categories"
import { MEASURES, PRODUCT_NAME_MAX_LENGTH, type Measure, productFormErrors } from "@/lib/product-prices"
import { type ProductProposal, type ProposalFormValues, acceptBody, proposalFormValues } from "@/lib/product-proposals"
import { invalidatePurchasing, productCategoriesQuery, productProposalsQuery, productsQuery } from "@/lib/queries"

/** The select's value for "no category"; a category's id is a UUID, so it cannot clash. */
const NO_CATEGORY = "none"

/** Refusals that mean what is on screen is out of date. */
const STALE_CODES = ["purchasing.product_proposal_not_found", "purchasing.unknown_product_category", "purchasing.purchased_item_product_not_found"]

interface ProductProposalsProps {
  organizationId: string
}

/**
 * The products the model proposes for items that matched nothing in the catalog (ADR 0044), on the
 * Proposed products page (#269), which carries the heading. Each is a decision for the owner: create the
 * product (editing its name, category and measure first), add its items to a product that exists, or
 * dismiss it. Nothing is created until then. The items of a new product got the package size their names state;
 * the units that have none are then asked about, which the owner chooses (ADR 0048, #271).
 */
export function ProductProposals({ organizationId }: ProductProposalsProps) {
  const { t } = useTranslation("admin")
  const queryClient = useQueryClient()
  const proposals = useSuspenseQuery(productProposalsQuery(organizationId))
  const categories = useSuspenseQuery(productCategoriesQuery(organizationId))
  const [creating, setCreating] = useState<ProductProposal | null>(null)
  const [merging, setMerging] = useState<ProductProposal | null>(null)
  const [followUp, setFollowUp] = useState<PackageSizeFollowUpRequest | null>(null)

  // The product a proposal equals by name is shown by name, so the products are read when one does.
  const hasSameName = proposals.data.some((proposal) => proposal.existingProductId !== null)
  const products = useQuery({ ...productsQuery(organizationId), enabled: hasSameName })
  const productName = (id: string | null) => products.data?.find((product) => product.id === id)?.name

  const paths = new Map(parentChoices(categories.data, null).map((choice) => [choice.category.id, choice.path]))

  const refresh = () => invalidatePurchasing(queryClient, organizationId)
  const onError = async (error: unknown) => {
    toast.error(requestErrorMessage(error))
    if (error instanceof ApiError && STALE_CODES.includes(error.code ?? "")) await refresh()
  }

  const dismiss = useMutation({
    mutationFn: (proposal: ProductProposal) =>
      unwrap(api.POST("/api/product-proposals/{id}/dismiss", { params: { path: { id: proposal.id } } })),
    onSuccess: (_, proposal) => toast.success(t("review.proposals.toast.dismissed", { name: proposal.name })),
    onError,
    onSettled: refresh,
  })

  const addToSame = useMutation({
    mutationFn: ({ proposal, productId }: { proposal: ProductProposal; productId: string }) =>
      unwrap(api.POST("/api/product-proposals/{id}/assign", { params: { path: { id: proposal.id } }, body: { productId } })),
    onSuccess: (_, { proposal }) => toast.success(t("review.proposals.toast.added", { count: proposal.itemCount, name: proposal.name })),
    onError,
    onSettled: refresh,
  })

  if (proposals.data.length === 0) {
    return (
      <Card className="py-0">
        <CardContent className="flex flex-col items-center gap-2 px-6 py-10 text-center text-sm text-muted-foreground">
          <CircleCheckIcon className="size-6" />
          {t("review.proposals.empty")}
        </CardContent>
      </Card>
    )
  }

  const busy = dismiss.isPending || addToSame.isPending

  return (
    <>
      <Card className="gap-0 overflow-hidden py-0">
        <CardContent className="px-0">
          <ul className="divide-y">
            {proposals.data.map((proposal) => {
              const same = proposal.existingProductId
              return (
                <li key={proposal.id} className="flex flex-col gap-3 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                  <div className="flex min-w-0 flex-col gap-1">
                    <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
                      <span className="font-medium">{proposal.name}</span>
                      <Badge variant="secondary">{t(`product.measure.${proposal.measure}`)}</Badge>
                      <span className="text-xs text-muted-foreground">
                        {(proposal.categoryId && paths.get(proposal.categoryId)) || t("analysis.uncategorized")}
                      </span>
                    </div>
                    <p className="text-sm text-muted-foreground">
                      {t("review.proposals.items", { count: proposal.itemCount })}
                      {": "}
                      {proposal.exampleNames.join(" · ")}
                      {proposal.itemCount > proposal.exampleNames.length && " …"}
                    </p>
                    {same && (
                      <p className="text-xs text-muted-foreground">
                        {t("review.proposals.sameName", { name: productName(same) ?? proposal.name })}
                      </p>
                    )}
                  </div>
                  <div className="flex shrink-0 flex-wrap items-center gap-2">
                    {same ? (
                      <Button size="sm" disabled={busy} onClick={() => addToSame.mutate({ proposal, productId: same })}>
                        {t("review.proposals.addTo", { name: productName(same) ?? proposal.name })}
                      </Button>
                    ) : (
                      <Button size="sm" disabled={busy} onClick={() => setCreating(proposal)}>
                        <PlusIcon />
                        {t("review.proposals.create")}
                      </Button>
                    )}
                    <Button size="sm" variant="outline" disabled={busy} onClick={() => setMerging(proposal)}>
                      {t("review.proposals.merge")}
                    </Button>
                    <Button size="sm" variant="ghost" disabled={busy} onClick={() => dismiss.mutate(proposal)}>
                      {t("review.proposals.dismiss")}
                    </Button>
                  </div>
                </li>
              )
            })}
          </ul>
        </CardContent>
      </Card>

      <CreateDialog
        organizationId={organizationId}
        proposal={creating}
        categories={categories.data}
        onClose={() => setCreating(null)}
        onError={onError}
        onCreated={setFollowUp}
      />
      <PackageSizeFollowUp organizationId={organizationId} request={followUp} onClose={() => setFollowUp(null)} />
      <MergeDialog
        organizationId={organizationId}
        proposal={merging}
        categories={categories.data}
        onClose={() => setMerging(null)}
        onError={onError}
      />
    </>
  )
}

interface DialogProps {
  organizationId: string
  proposal: ProductProposal | null
  categories: readonly ProductCategory[]
  onClose: () => void
  onError: (error: unknown) => Promise<void>
}

type CreatedHandler = (request: PackageSizeFollowUpRequest) => void

/** Creates the product with what the model proposed, edited; its items become confirmed members of it. */
function CreateDialog({ organizationId, proposal, categories, onClose, onError, onCreated }: DialogProps & { onCreated: CreatedHandler }) {
  const { t } = useTranslation("admin")
  return (
    <Dialog open={proposal !== null} onOpenChange={(open) => !open && onClose()}>
      <DialogContent className="max-h-[calc(100dvh-2rem)] overflow-y-auto sm:max-w-md">
        {proposal && (
          <CreateForm
            key={proposal.id}
            organizationId={organizationId}
            proposal={proposal}
            categories={categories}
            onClose={onClose}
            onError={onError}
            onCreated={onCreated}
            title={t("review.proposals.createTitle")}
          />
        )}
      </DialogContent>
    </Dialog>
  )
}

function CreateForm({
  organizationId,
  proposal,
  categories,
  title,
  onClose,
  onError,
  onCreated,
}: DialogProps & { proposal: ProductProposal; title: string; onCreated: CreatedHandler }) {
  const { t } = useTranslation("admin")
  const queryClient = useQueryClient()
  const [values, setValues] = useState<ProposalFormValues>(() => proposalFormValues(proposal))
  const [errors, setErrors] = useState<ReturnType<typeof productFormErrors>>({})
  const choices = parentChoices(categories, null)
  const hasUnit = values.measure !== "none"

  const create = useMutation({
    mutationFn: () =>
      unwrap(api.POST("/api/product-proposals/{id}/accept", { params: { path: { id: proposal.id } }, body: acceptBody(values) })),
    onSuccess: async () => {
      toast.success(t("review.proposals.toast.created", { count: proposal.itemCount, name: values.name.trim() }))
      await invalidatePurchasing(queryClient, organizationId)
      onClose()
      await askAboutOtherUnits(values.name.trim())
    },
    onError: async (error) => {
      const shown = productFormErrors(error)
      setErrors(shown)
      if (error instanceof ApiError && STALE_CODES.includes(error.code ?? "")) {
        await onError(error)
        onClose()
      }
    },
  })

  /** The accept call answers with no body, so the new product is found by its name, which is unique. */
  async function askAboutOtherUnits(name: string) {
    try {
      const products = await queryClient.fetchQuery(productsQuery(organizationId))
      const created = products.find((product) => product.name.toLowerCase() === name.toLowerCase())
      if (created) onCreated({ productId: created.id, productName: created.name, answer: null })
    } catch {
      // The product is made; the question is a convenience, so a failure to ask is not an error.
    }
  }

  function update(next: Partial<ProposalFormValues>) {
    setValues((current) => ({ ...current, ...next }))
  }

  function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setErrors({})
    create.mutate()
  }

  return (
    <form onSubmit={onSubmit} noValidate>
      <DialogHeader>
        <DialogTitle>{title}</DialogTitle>
        <DialogDescription>{t("review.proposals.createDescription", { count: proposal.itemCount })}</DialogDescription>
      </DialogHeader>
      <fieldset disabled={create.isPending} className="py-4">
        <FieldGroup>
          <Field data-invalid={errors.name ? true : undefined}>
            <FieldLabel htmlFor="proposal-name">{t("product.settings.name")}</FieldLabel>
            <Input
              id="proposal-name"
              value={values.name}
              onChange={(event) => update({ name: event.target.value })}
              autoComplete="off"
              maxLength={PRODUCT_NAME_MAX_LENGTH}
              aria-invalid={errors.name ? true : undefined}
              required
            />
            {errors.name && <FieldError>{errors.name}</FieldError>}
          </Field>

          <Field>
            <FieldLabel htmlFor="proposal-category">{t("product.settings.category")}</FieldLabel>
            <Select
              value={values.categoryId === "" ? NO_CATEGORY : values.categoryId}
              onValueChange={(value) => update({ categoryId: value === NO_CATEGORY ? "" : value })}
            >
              <SelectTrigger id="proposal-category" className="w-full">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value={NO_CATEGORY}>{t("analysis.uncategorized")}</SelectItem>
                {choices.map(({ category, path }) => (
                  <SelectItem key={category.id} value={category.id}>
                    {path}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
            <FieldDescription>{t("product.settings.categoryDescription")}</FieldDescription>
          </Field>

          <Field>
            <FieldLabel htmlFor="proposal-measure">{t("product.settings.measure")}</FieldLabel>
            <Select value={values.measure} onValueChange={(value) => update({ measure: value as Measure })}>
              <SelectTrigger id="proposal-measure" className="w-full">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                {MEASURES.map((measure) => (
                  <SelectItem key={measure} value={measure}>
                    {t(`product.measure.${measure}`)}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
            <FieldDescription>
              {hasUnit ? t("product.settings.measureDescription") : t("product.settings.measureNoneDescription")}
            </FieldDescription>
          </Field>

          {hasUnit && (
            <Field data-invalid={errors.referenceQuantity ? true : undefined}>
              <FieldLabel htmlFor="proposal-reference">
                {t("product.settings.reference", { unit: t(`analysis.unit.${values.measure as "volume" | "mass" | "count"}`) })}
              </FieldLabel>
              <Input
                id="proposal-reference"
                value={values.referenceQuantity}
                onChange={(event) => update({ referenceQuantity: event.target.value })}
                inputMode="decimal"
                autoComplete="off"
                placeholder="1"
                aria-invalid={errors.referenceQuantity ? true : undefined}
              />
              {errors.referenceQuantity ? (
                <FieldError>{errors.referenceQuantity}</FieldError>
              ) : (
                <FieldDescription>{t("product.settings.referenceDescription")}</FieldDescription>
              )}
            </Field>
          )}

          {errors.form && (
            <Field data-invalid>
              <FieldError>{errors.form}</FieldError>
            </Field>
          )}
        </FieldGroup>
      </fieldset>
      <DialogFooter>
        <Button type="submit" disabled={create.isPending}>
          {create.isPending ? t("common.saving", { ns: "ui" }) : t("review.proposals.createSubmit", { count: proposal.itemCount })}
        </Button>
      </DialogFooter>
    </form>
  )
}

/** Adds the proposal's items to a product that exists: the proposal was that product's other name. */
function MergeDialog({ organizationId, proposal, categories, onClose, onError }: DialogProps) {
  const { t } = useTranslation("admin")
  const queryClient = useQueryClient()
  const open = proposal !== null
  const products = useQuery({ ...productsQuery(organizationId), enabled: open })
  const [targetId, setTargetId] = useState<string | null>(null)
  const target = products.data?.find((product) => product.id === targetId) ?? null

  const assign = useMutation({
    mutationFn: (current: { proposal: ProductProposal; productId: string }) =>
      unwrap(
        api.POST("/api/product-proposals/{id}/assign", { params: { path: { id: current.proposal.id } }, body: { productId: current.productId } }),
      ),
    onSuccess: async (_, current) => {
      toast.success(t("review.proposals.toast.added", { count: current.proposal.itemCount, name: target?.name ?? current.proposal.name }))
      await invalidatePurchasing(queryClient, organizationId)
      close()
    },
    onError: async (error) => {
      await onError(error)
      await queryClient.invalidateQueries({ queryKey: productsQuery(organizationId).queryKey })
    },
  })

  function close() {
    setTargetId(null)
    onClose()
  }

  return (
    <Dialog open={open} onOpenChange={(next) => !next && close()}>
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>{t("review.proposals.mergeTitle", { name: proposal?.name ?? "" })}</DialogTitle>
          <DialogDescription>{t("review.proposals.mergeDescription", { count: proposal?.itemCount ?? 0 })}</DialogDescription>
        </DialogHeader>
        {products.isPending ? (
          <Skeleton className="h-40 w-full" />
        ) : products.isError ? (
          <p className="text-sm text-destructive">{requestErrorMessage(products.error)}</p>
        ) : (
          <ProductPicker products={products.data} categories={categories} exceptId={null} value={targetId} onChange={setTargetId} />
        )}
        <DialogFooter>
          <Button
            disabled={target === null || proposal === null || assign.isPending}
            onClick={() => proposal && target && assign.mutate({ proposal, productId: target.id })}
          >
            {assign.isPending ? t("common.saving", { ns: "ui" }) : t("review.proposals.mergeSubmit", { count: proposal?.itemCount ?? 0 })}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
