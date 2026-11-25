import { useState, type FormEvent } from "react"
import { unwrap } from "@business-tool/api-client"
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"
import { useTranslation } from "react-i18next"
import { toast } from "sonner"

import { Button } from "@business-tool/ui/components/button"
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
import { Skeleton } from "@business-tool/ui/components/skeleton"
import { requestErrorMessage } from "@business-tool/ui/lib/request-error"

import { ProductPicker } from "@/components/product-picker"
import { api } from "@/lib/api"
import { type ProductCategory } from "@/lib/product-categories"
import { type Product, normalizeDecimal } from "@/lib/product-prices"
import { invalidatePurchasing, productsQuery } from "@/lib/queries"

/** What the dialog needs of an item: a price point and a purchased item both have it. */
export interface ItemRef {
  id: string
  /** As printed on the invoice. */
  name: string
  packageSize: string | null
}

interface PurchasedItemDialogProps {
  organizationId: string
  /** The item to move or correct; the dialog is closed without one. */
  item: ItemRef | null
  /** The product the item is on now. */
  product: Product
  categories: readonly ProductCategory[]
  onClose: () => void
}

/**
 * One purchased item of the product (ADR 0041 §3, #242): move it to another product, take it off the
 * product (it goes back to the items without one), or set its package size, which decides whether
 * it counts in the prices. A change by a person confirms the link.
 */
export function PurchasedItemDialog({ organizationId, item, product, categories, onClose }: PurchasedItemDialogProps) {
  return (
    <Dialog open={item !== null} onOpenChange={(open) => !open && onClose()}>
      <DialogContent className="max-h-[calc(100dvh-2rem)] overflow-y-auto sm:max-w-md">
        {item && <ItemForm key={item.id} organizationId={organizationId} item={item} product={product} categories={categories} onClose={onClose} />}
      </DialogContent>
    </Dialog>
  )
}

function ItemForm({ organizationId, item, product, categories, onClose }: Omit<PurchasedItemDialogProps, "item"> & { item: ItemRef }) {
  const { t } = useTranslation("admin")
  const queryClient = useQueryClient()
  const products = useQuery(productsQuery(organizationId))
  const [targetId, setTargetId] = useState<string | null>(null)
  const [packageSize, setPackageSize] = useState(item.packageSize ?? "")
  const [error, setError] = useState<string | null>(null)
  const target = products.data?.find((candidate) => candidate.id === targetId) ?? null
  const newPackageSize = normalizeDecimal(packageSize)
  const sizeChanged = newPackageSize !== item.packageSize
  const canSave = target !== null || sizeChanged

  const save = useMutation({
    mutationFn: (body: { productId?: string | null; packageSize?: string | null }) =>
      unwrap(
        api.PATCH("/api/purchased-items/{id}", {
          params: { path: { id: item.id } },
          body,
          headers: { "Content-Type": "application/merge-patch+json" },
        }),
      ),
    onSuccess: async (_, body) => {
      toast.success(
        body.productId === undefined
          ? t("product.item.toast.size")
          : body.productId === null
            ? t("product.item.toast.removed")
            : t("product.item.toast.moved", { name: target?.name ?? "" }),
      )
      await invalidatePurchasing(queryClient, organizationId)
      onClose()
    },
    onError: (failure) => setError(requestErrorMessage(failure)),
  })

  function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setError(null)
    save.mutate({
      ...(target ? { productId: target.id } : {}),
      ...(sizeChanged ? { packageSize: newPackageSize } : {}),
    })
  }

  return (
    <form onSubmit={onSubmit} noValidate>
      <DialogHeader>
        <DialogTitle>{t("product.item.title")}</DialogTitle>
        <DialogDescription className="break-words">{t("product.item.description", { name: item.name })}</DialogDescription>
      </DialogHeader>
      <fieldset disabled={save.isPending} className="py-4">
        <FieldGroup>
          <Field>
            <FieldLabel>{t("product.item.moveTo")}</FieldLabel>
            {products.isPending ? (
              <Skeleton className="h-40 w-full" />
            ) : products.isError ? (
              <FieldError>{requestErrorMessage(products.error)}</FieldError>
            ) : (
              <ProductPicker products={products.data} categories={categories} exceptId={product.id} value={targetId} onChange={setTargetId} />
            )}
          </Field>
          <Field data-invalid={error ? true : undefined}>
            <FieldLabel htmlFor="item-package-size">{t("product.item.packageSize")}</FieldLabel>
            <Input
              id="item-package-size"
              value={packageSize}
              onChange={(event) => setPackageSize(event.target.value)}
              inputMode="decimal"
              autoComplete="off"
              aria-invalid={error ? true : undefined}
            />
            <FieldDescription>{t("product.item.packageSizeDescription")}</FieldDescription>
            {error && <FieldError>{error}</FieldError>}
          </Field>
        </FieldGroup>
      </fieldset>
      <DialogFooter className="gap-2 sm:justify-between">
        <Button type="button" variant="outline" disabled={save.isPending} onClick={() => save.mutate({ productId: null })}>
          {t("product.item.remove")}
        </Button>
        <Button type="submit" disabled={save.isPending || !canSave}>
          {save.isPending ? t("common.saving", { ns: "ui" }) : target ? t("product.item.move") : t("product.item.save")}
        </Button>
      </DialogFooter>
    </form>
  )
}
