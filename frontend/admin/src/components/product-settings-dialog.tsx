import { useState, type FormEvent } from "react"
import { ApiError, unwrap } from "@business-tool/api-client"
import { useMutation, useQueryClient } from "@tanstack/react-query"
import { GitMergeIcon } from "lucide-react"
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
import {
  Field,
  FieldContent,
  FieldDescription,
  FieldError,
  FieldGroup,
  FieldLabel,
  FieldSeparator,
} from "@business-tool/ui/components/field"
import { Input } from "@business-tool/ui/components/input"
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@business-tool/ui/components/select"

import { api } from "@/lib/api"
import { type ProductCategory, parentChoices } from "@/lib/product-categories"
import {
  MEASURES,
  PRODUCT_NAME_MAX_LENGTH,
  type Measure,
  type Product,
  type ProductFormErrors,
  type ProductFormValues,
  formValuesOf,
  productChanges,
  productFormErrors,
} from "@/lib/product-prices"
import { invalidatePurchasing } from "@/lib/queries"
import { productCategoriesQuery } from "@/lib/queries"

/** The select's value for "no category"; a category's id is a UUID, so it cannot clash. */
const NO_CATEGORY = "none"

/** Refusals that mean what is on screen is out of date. */
const STALE_CODES = ["purchasing.product_not_found", "purchasing.unknown_product_category"]

interface ProductSettingsDialogProps {
  organizationId: string
  product: Product
  categories: readonly ProductCategory[]
  open: boolean
  onOpenChange: (open: boolean) => void
  /** Opens the merge dialog for this product. */
  onMerge: () => void
}

/**
 * A product's settings (ADR 0041 §4): name, category (picked from the tree), measure, the reference
 * quantity prices are compared per, and whether it is left out of every total. Merging into another
 * product is its own dialog, reached from here.
 */
export function ProductSettingsDialog({ organizationId, product, categories, open, onOpenChange, onMerge }: ProductSettingsDialogProps) {
  const { t } = useTranslation("admin")
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-h-[calc(100dvh-2rem)] overflow-y-auto sm:max-w-md">
        {/* A new key per product version, so the fields start from what is saved. */}
        <SettingsForm
          key={`${product.id}-${product.name}-${product.categoryId}-${product.measure}-${product.referenceQuantity}-${product.excluded}`}
          organizationId={organizationId}
          product={product}
          categories={categories}
          onClose={() => onOpenChange(false)}
          onMerge={() => {
            onOpenChange(false)
            onMerge()
          }}
          title={t("product.settings.title")}
        />
      </DialogContent>
    </Dialog>
  )
}

interface SettingsFormProps extends Omit<ProductSettingsDialogProps, "open" | "onOpenChange"> {
  title: string
  onClose: () => void
}

function SettingsForm({ organizationId, product, categories, title, onClose, onMerge }: SettingsFormProps) {
  const { t } = useTranslation("admin")
  const queryClient = useQueryClient()
  const [values, setValues] = useState<ProductFormValues>(() => formValuesOf(product))
  const [errors, setErrors] = useState<ProductFormErrors>({})
  const choices = parentChoices(categories, null)
  const hasUnit = values.measure !== "none"

  const save = useMutation({
    mutationFn: (changes: NonNullable<ReturnType<typeof productChanges>>) =>
      unwrap(
        api.PATCH("/api/products/{id}", {
          params: { path: { id: product.id } },
          body: changes,
          headers: { "Content-Type": "application/merge-patch+json" },
        }),
      ),
    onSuccess: async (saved) => {
      toast.success(t("product.settings.saved", { name: saved.name }))
      await invalidatePurchasing(queryClient, organizationId)
      onClose()
    },
    onError: async (error) => {
      setErrors(productFormErrors(error))
      if (error instanceof ApiError && STALE_CODES.includes(error.code ?? "")) {
        await Promise.all([
          invalidatePurchasing(queryClient, organizationId),
          queryClient.invalidateQueries({ queryKey: productCategoriesQuery(organizationId).queryKey }),
        ])
      }
    },
  })

  function update(next: Partial<ProductFormValues>) {
    setValues((current) => ({ ...current, ...next }))
  }

  function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setErrors({})
    const changes = productChanges(product, values)
    if (changes === null) return onClose()
    save.mutate(changes)
  }

  return (
    <form onSubmit={onSubmit} noValidate>
      <DialogHeader>
        <DialogTitle>{title}</DialogTitle>
        <DialogDescription>{t("product.settings.description")}</DialogDescription>
      </DialogHeader>
      <fieldset disabled={save.isPending} className="py-4">
        <FieldGroup>
          <Field data-invalid={errors.name ? true : undefined}>
            <FieldLabel htmlFor="product-name">{t("product.settings.name")}</FieldLabel>
            <Input
              id="product-name"
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
            <FieldLabel htmlFor="product-category">{t("product.settings.category")}</FieldLabel>
            <Select
              value={values.categoryId === "" ? NO_CATEGORY : values.categoryId}
              onValueChange={(value) => update({ categoryId: value === NO_CATEGORY ? "" : value })}
            >
              <SelectTrigger id="product-category" className="w-full">
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
            <FieldLabel htmlFor="product-measure">{t("product.settings.measure")}</FieldLabel>
            <Select value={values.measure} onValueChange={(value) => update({ measure: value as Measure })}>
              <SelectTrigger id="product-measure" className="w-full">
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
              <FieldLabel htmlFor="product-reference">
                {t("product.settings.reference", { unit: t(`analysis.unit.${values.measure as "volume" | "mass" | "count"}`) })}
              </FieldLabel>
              <Input
                id="product-reference"
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

          <Field orientation="horizontal">
            <Checkbox
              id="product-excluded"
              checked={values.excluded}
              onCheckedChange={(checked) => update({ excluded: checked === true })}
            />
            <FieldContent>
              <FieldLabel htmlFor="product-excluded">{t("product.settings.excluded")}</FieldLabel>
              <FieldDescription>{t("product.settings.excludedDescription")}</FieldDescription>
            </FieldContent>
          </Field>

          {errors.form && (
            <Field data-invalid>
              <FieldError>{errors.form}</FieldError>
            </Field>
          )}

          <FieldSeparator />
          <Field>
            <Button type="button" variant="outline" className="justify-start" onClick={onMerge}>
              <GitMergeIcon />
              {t("product.settings.merge")}
            </Button>
            <FieldDescription>{t("product.settings.mergeDescription")}</FieldDescription>
          </Field>
        </FieldGroup>
      </fieldset>
      <DialogFooter>
        <Button type="submit" disabled={save.isPending}>
          {save.isPending ? t("common.saving", { ns: "ui" }) : t("product.settings.save")}
        </Button>
      </DialogFooter>
    </form>
  )
}
