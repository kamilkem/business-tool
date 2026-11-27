import { useState, type FormEvent } from "react"
import { ApiError, unwrap } from "@business-tool/api-client"
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"
import { useTranslation } from "react-i18next"
import { toast } from "sonner"

import { Button } from "@business-tool/ui/components/button"
import { Checkbox } from "@business-tool/ui/components/checkbox"
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@business-tool/ui/components/dialog"
import { Field, FieldContent, FieldDescription, FieldError, FieldGroup, FieldLabel } from "@business-tool/ui/components/field"
import { Input } from "@business-tool/ui/components/input"
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@business-tool/ui/components/select"
import { Skeleton } from "@business-tool/ui/components/skeleton"
import { requestErrorMessage } from "@business-tool/ui/lib/request-error"

import { ProductPicker } from "@/components/product-picker"
import { api } from "@/lib/api"
import { bundleCountToSend, bundleInput, commonBundleCount } from "@/lib/package-size"
import { type ProductCategory, parentChoices } from "@/lib/product-categories"
import { MEASURES, PRODUCT_NAME_MAX_LENGTH, type Measure, type Product, normalizeDecimal } from "@/lib/product-prices"
import { invalidatePurchasing, productCategoriesQuery, productsQuery } from "@/lib/queries"
import {
  MAX_ASSIGN_ITEMS,
  type AssignErrors,
  type QueueItem,
  STALE_CODES,
  assignErrors,
  commonPackageSize,
  groupKey,
  measureGuess,
  packageSizeApplies,
  packageSizeToSend,
} from "@/lib/review-queue"

/** What the dialog is for: link to a product, make a product from the items, or exclude them. */
export type AssignPreset = "assign" | "create" | "exclude"

/** The items to act on and what for; the dialog is closed without one. */
export interface AssignRequest {
  items: QueueItem[]
  preset: AssignPreset
}

/** The select's value for "no category"; a category's id is a UUID, so it cannot clash. */
const NO_CATEGORY = "none"

interface ReviewAssignDialogProps {
  organizationId: string
  request: AssignRequest | null
  onClose: () => void
  /** The items were assigned: the selection can go. */
  onAssigned: () => void
}

/**
 * Gives the items a product (ADR 0041 §3, §9; #242): an existing one, or a new one made from the
 * items (name prefilled with what the invoice printed), and the package size the owner types, which
 * every item gets. Excluding is assigning to an excluded product. The assignment is all or nothing;
 * a product that was created stays when the assignment then fails, and the retry uses it.
 */
export function ReviewAssignDialog({ organizationId, request, onClose, onAssigned }: ReviewAssignDialogProps) {
  return (
    <Dialog open={request !== null} onOpenChange={(open) => !open && onClose()}>
      <DialogContent className="max-h-[calc(100dvh-2rem)] overflow-y-auto sm:max-w-lg">
        {request && <Loader organizationId={organizationId} request={request} onClose={onClose} onAssigned={onAssigned} />}
      </DialogContent>
    </Dialog>
  )
}

/** Waits for the products and the category tree the form picks from. */
function Loader(props: Omit<ReviewAssignDialogProps, "request"> & { request: AssignRequest }) {
  const { t } = useTranslation("admin")
  const products = useQuery(productsQuery(props.organizationId))
  const categories = useQuery(productCategoriesQuery(props.organizationId))

  if (products.isPending || categories.isPending) {
    return (
      <>
        <DialogHeader>
          <DialogTitle>{t(`review.assign.title.${props.request.preset}`)}</DialogTitle>
          <DialogDescription>{t("review.assign.loading")}</DialogDescription>
        </DialogHeader>
        <Skeleton className="h-48 w-full" />
      </>
    )
  }
  if (products.isError || categories.isError) {
    return (
      <>
        <DialogHeader>
          <DialogTitle>{t(`review.assign.title.${props.request.preset}`)}</DialogTitle>
          <DialogDescription>{t("review.assign.loadFailed")}</DialogDescription>
        </DialogHeader>
        <FieldError>{requestErrorMessage(products.error ?? categories.error)}</FieldError>
      </>
    )
  }
  return <AssignForm {...props} products={products.data} categories={categories.data} />
}

type Mode = "existing" | "new"

interface AssignFormProps extends Omit<ReviewAssignDialogProps, "request"> {
  request: AssignRequest
  products: readonly Product[]
  categories: readonly ProductCategory[]
}

function AssignForm({ organizationId, request, products, categories, onClose, onAssigned }: AssignFormProps) {
  const { t } = useTranslation("admin")
  const queryClient = useQueryClient()
  const { items, preset } = request
  const [first] = items
  const names = new Set(items.map((item) => groupKey(item.name))).size

  // Excluding picks among the excluded products, or makes one: there is usually one for deposits, one for fees.
  const choices = preset === "exclude" ? products.filter((product) => product.excluded) : products
  const [mode, setMode] = useState<Mode>(preset === "create" || (preset === "exclude" && choices.length === 0) ? "new" : "existing")
  const [targetId, setTargetId] = useState<string | null>(null)
  const [name, setName] = useState((first?.name ?? "").trim().slice(0, PRODUCT_NAME_MAX_LENGTH))
  const [categoryId, setCategoryId] = useState("")
  const [measure, setMeasure] = useState<Measure>(measureGuess(first?.unit ?? null))
  const [reference, setReference] = useState("")
  const [excluded, setExcluded] = useState(preset === "exclude")
  const [packageSize, setPackageSize] = useState(commonPackageSize(items) ?? "")
  const commonBundle = commonBundleCount(items)
  const [bundled, setBundled] = useState(commonBundle !== null)
  const [bundleCount, setBundleCount] = useState(commonBundle === null ? "" : String(commonBundle))
  const [errors, setErrors] = useState<AssignErrors>({})
  // The product this form made, kept when the assignment failed: a retry must not make it again.
  const [created, setCreated] = useState<Product | null>(null)

  const target = products.find((product) => product.id === targetId) ?? null
  const newMeasure: Measure = excluded ? "none" : measure
  // Which product the items go to decides whether a package size means anything.
  const sizeProduct = mode === "existing" ? target : { measure: newMeasure, excluded }
  const sizeApplies = sizeProduct === null || packageSizeApplies(sizeProduct)
  const unitMeasure = sizeProduct === null || !sizeApplies ? null : sizeProduct.measure
  const tooMany = items.length > MAX_ASSIGN_ITEMS
  // A bundle goes with a package size sent to every item: with none, each item keeps its own size and count (ADR 0048 §7).
  const hasSize = sizeApplies && normalizeDecimal(packageSize) !== null
  const bundle = bundleInput(bundled && hasSize, bundleCount)
  const canSubmit = !tooMany && bundle.state !== "invalid" && (mode === "existing" ? target !== null : name.trim() !== "" || created !== null)

  const assign = useMutation({
    mutationFn: async () => {
      let product: Product
      if (mode === "existing") {
        if (target === null) throw new Error("No product picked")
        product = target
      } else if (created !== null) {
        product = created
      } else {
        product = await unwrap(
          api.POST("/api/products", {
            body: {
              name: name.trim(),
              measure: newMeasure,
              categoryId: categoryId === "" ? null : categoryId,
              referenceQuantity: newMeasure === "none" ? null : normalizeDecimal(reference),
              excluded,
            },
          }),
        )
        setCreated(product)
      }
      await unwrap(
        api.POST("/api/purchased-items/assign", {
          body: {
            itemIds: items.map((item) => item.id),
            productId: product.id,
            packageSize: packageSizeToSend(packageSize, packageSizeApplies(product)),
            bundleCount: packageSizeApplies(product) ? bundleCountToSend(bundle) : null,
          },
        }),
      )
      return product
    },
    onSuccess: async (product) => {
      toast.success(t(product.excluded ? "review.toast.excluded" : "review.toast.assigned", { count: items.length, name: product.name }))
      await invalidatePurchasing(queryClient, organizationId)
      onAssigned()
      onClose()
    },
    onError: async (error) => {
      setErrors(assignErrors(error))
      // A product made before the failure is in the list now.
      if (created !== null || (error instanceof ApiError && STALE_CODES.includes(error.code ?? ""))) {
        await invalidatePurchasing(queryClient, organizationId)
      }
    },
  })

  function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setErrors({})
    assign.mutate()
  }

  const categoryChoices = parentChoices(categories, null)
  const productFieldsLocked = assign.isPending || created !== null

  return (
    <form onSubmit={onSubmit} noValidate>
      <DialogHeader>
        <DialogTitle>{t(`review.assign.title.${preset}`)}</DialogTitle>
        <DialogDescription className="break-words">
          {names === 1
            ? t("review.assign.summary", { count: items.length, name: (first?.name ?? "").trim() })
            : t("review.assign.summaryMixed", { count: items.length, names })}
        </DialogDescription>
      </DialogHeader>

      <fieldset disabled={assign.isPending} className="py-4">
        <FieldGroup>
          {tooMany && (
            <Field data-invalid>
              <FieldError>{t("review.assign.tooMany", { max: MAX_ASSIGN_ITEMS })}</FieldError>
            </Field>
          )}

          <div role="group" aria-label={t("review.assign.mode.label")} className="inline-flex w-fit gap-1 rounded-lg bg-muted p-1">
            {(["existing", "new"] as const).map((option) => (
              <button
                key={option}
                type="button"
                aria-pressed={mode === option}
                disabled={created !== null && option === "existing"}
                onClick={() => setMode(option)}
                className="rounded-md px-3 py-1.5 text-sm font-medium text-muted-foreground transition-colors hover:text-foreground disabled:opacity-50 aria-pressed:bg-background aria-pressed:text-foreground aria-pressed:shadow-sm"
              >
                {t(`review.assign.mode.${option}`)}
              </button>
            ))}
          </div>

          {mode === "existing" ? (
            <Field>
              <FieldLabel>{t("review.assign.product")}</FieldLabel>
              <ProductPicker products={choices} categories={categories} exceptId={null} value={targetId} onChange={setTargetId} />
              {preset === "exclude" && <FieldDescription>{t("review.assign.excludedOnly")}</FieldDescription>}
            </Field>
          ) : (
            <fieldset disabled={productFieldsLocked} className="contents">
              <Field data-invalid={errors.name ? true : undefined}>
                <FieldLabel htmlFor="review-product-name">{t("review.assign.name")}</FieldLabel>
                <Input
                  id="review-product-name"
                  value={name}
                  onChange={(event) => setName(event.target.value)}
                  autoComplete="off"
                  maxLength={PRODUCT_NAME_MAX_LENGTH}
                  aria-invalid={errors.name ? true : undefined}
                />
                {errors.name ? <FieldError>{errors.name}</FieldError> : <FieldDescription>{t("review.assign.nameDescription")}</FieldDescription>}
              </Field>

              <Field>
                <FieldLabel htmlFor="review-product-category">{t("product.settings.category")}</FieldLabel>
                <Select value={categoryId === "" ? NO_CATEGORY : categoryId} onValueChange={(value) => setCategoryId(value === NO_CATEGORY ? "" : value)}>
                  <SelectTrigger id="review-product-category" className="w-full">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value={NO_CATEGORY}>{t("analysis.uncategorized")}</SelectItem>
                    {categoryChoices.map(({ category, path }) => (
                      <SelectItem key={category.id} value={category.id}>
                        {path}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </Field>

              {!excluded && (
                <>
                  <Field>
                    <FieldLabel htmlFor="review-product-measure">{t("product.settings.measure")}</FieldLabel>
                    <Select value={measure} onValueChange={(value) => setMeasure(value as Measure)}>
                      <SelectTrigger id="review-product-measure" className="w-full">
                        <SelectValue />
                      </SelectTrigger>
                      <SelectContent>
                        {MEASURES.map((option) => (
                          <SelectItem key={option} value={option}>
                            {t(`product.measure.${option}`)}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                    <FieldDescription>
                      {measure === "none" ? t("product.settings.measureNoneDescription") : t("product.settings.measureDescription")}
                    </FieldDescription>
                  </Field>

                  {measure !== "none" && (
                    <Field data-invalid={errors.referenceQuantity ? true : undefined}>
                      <FieldLabel htmlFor="review-product-reference">
                        {t("product.settings.reference", { unit: t(`analysis.unit.${measure}`) })}
                      </FieldLabel>
                      <Input
                        id="review-product-reference"
                        value={reference}
                        onChange={(event) => setReference(event.target.value)}
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
                </>
              )}

              <Field orientation="horizontal">
                <Checkbox id="review-product-excluded" checked={excluded} onCheckedChange={(checked) => setExcluded(checked === true)} />
                <FieldContent>
                  <FieldLabel htmlFor="review-product-excluded">{t("product.settings.excluded")}</FieldLabel>
                  <FieldDescription>{t("product.settings.excludedDescription")}</FieldDescription>
                </FieldContent>
              </Field>
            </fieldset>
          )}

          {sizeApplies && (
            <Field data-invalid={errors.packageSize ? true : undefined}>
              <FieldLabel htmlFor="review-package-size">
                {unitMeasure ? t("review.assign.packageSizeIn", { unit: t(`analysis.unit.${unitMeasure as "volume" | "mass" | "count"}`) }) : t("review.assign.packageSize")}
              </FieldLabel>
              <Input
                id="review-package-size"
                value={packageSize}
                onChange={(event) => setPackageSize(event.target.value)}
                inputMode="decimal"
                autoComplete="off"
                placeholder="0.7"
                aria-invalid={errors.packageSize ? true : undefined}
              />
              {errors.packageSize ? (
                <FieldError>{errors.packageSize}</FieldError>
              ) : (
                <FieldDescription>{t("review.assign.packageSizeDescription", { count: items.length })}</FieldDescription>
              )}
            </Field>
          )}

          {sizeApplies && (
            <>
              <Field orientation="horizontal">
                <Checkbox
                  id="review-bundle"
                  checked={bundled && hasSize}
                  disabled={!hasSize}
                  onCheckedChange={(checked) => setBundled(checked === true)}
                />
                <FieldContent>
                  <FieldLabel htmlFor="review-bundle">{t("review.assign.bundle")}</FieldLabel>
                  <FieldDescription>{t("review.assign.bundleDescription")}</FieldDescription>
                </FieldContent>
              </Field>
              {bundled && hasSize && (
                <Field data-invalid={bundle.state === "invalid" ? true : undefined}>
                  <FieldLabel htmlFor="review-bundle-count">{t("review.assign.bundleCount")}</FieldLabel>
                  <Input
                    id="review-bundle-count"
                    value={bundleCount}
                    onChange={(event) => setBundleCount(event.target.value)}
                    inputMode="numeric"
                    autoComplete="off"
                    placeholder="20"
                    aria-invalid={bundle.state === "invalid" ? true : undefined}
                  />
                  {bundle.state === "invalid" ? (
                    <FieldError>{t("product.item.bundleCountInvalid")}</FieldError>
                  ) : (
                    <FieldDescription>{t("review.assign.bundleCountDescription")}</FieldDescription>
                  )}
                </Field>
              )}
            </>
          )}

          {errors.form && (
            <Field data-invalid>
              <FieldError>{errors.form}</FieldError>
            </Field>
          )}
          {created !== null && !errors.form && !assign.isPending && assign.isError && (
            <Field>
              <FieldDescription>{t("review.assign.createdKept", { name: created.name })}</FieldDescription>
            </Field>
          )}
        </FieldGroup>
      </fieldset>

      <DialogFooter>
        <Button type="button" variant="outline" disabled={assign.isPending} onClick={onClose}>
          {t("review.assign.cancel")}
        </Button>
        <Button type="submit" disabled={assign.isPending || !canSubmit}>
          {assign.isPending
            ? t("common.saving", { ns: "ui" })
            : t(preset === "exclude" ? "review.assign.submitExclude" : "review.assign.submit", { count: items.length })}
        </Button>
      </DialogFooter>
    </form>
  )
}
