import { useState } from "react"
import { unwrap } from "@business-tool/api-client"
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"
import { useTranslation } from "react-i18next"
import { toast } from "sonner"

import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from "@business-tool/ui/components/alert-dialog"
import { Button } from "@business-tool/ui/components/button"
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@business-tool/ui/components/dialog"
import { Skeleton } from "@business-tool/ui/components/skeleton"
import { requestErrorMessage } from "@business-tool/ui/lib/request-error"

import { ProductPicker } from "@/components/product-picker"
import { api } from "@/lib/api"
import { type ProductCategory } from "@/lib/product-categories"
import { type Product } from "@/lib/product-prices"
import { invalidatePurchasing, productsQuery } from "@/lib/queries"

interface ProductMergeDialogProps {
  organizationId: string
  product: Product
  categories: readonly ProductCategory[]
  open: boolean
  onOpenChange: (open: boolean) => void
  /** Called with the product that took over, once the merge went through. */
  onMerged: (into: Product) => void
}

/**
 * Merges the product into another (ADR 0041 §4): its items, and the aliases matching keeps, move to
 * the other product and this one is deleted. Picking the target and confirming are two steps, as
 * it cannot be undone.
 */
export function ProductMergeDialog({ organizationId, product, categories, open, onOpenChange, onMerged }: ProductMergeDialogProps) {
  const { t } = useTranslation("admin")
  const queryClient = useQueryClient()
  const products = useQuery({ ...productsQuery(organizationId), enabled: open })
  const [targetId, setTargetId] = useState<string | null>(null)
  const [confirming, setConfirming] = useState(false)
  const target = products.data?.find((candidate) => candidate.id === targetId) ?? null

  const merge = useMutation({
    mutationFn: (into: string) =>
      unwrap(api.POST("/api/products/{id}/merge", { params: { path: { id: product.id } }, body: { into } })),
    onSuccess: async (into) => {
      toast.success(t("product.merge.toast", { from: product.name, into: into.name }))
      await invalidatePurchasing(queryClient, organizationId)
      setConfirming(false)
      onOpenChange(false)
      onMerged(into)
    },
    onError: async (error) => {
      toast.error(requestErrorMessage(error))
      setConfirming(false)
      // The target may be gone: show the list as it is now.
      await queryClient.invalidateQueries({ queryKey: productsQuery(organizationId).queryKey })
    },
  })

  return (
    <>
      <Dialog
        open={open}
        onOpenChange={(next) => {
          if (!next) setTargetId(null)
          onOpenChange(next)
        }}
      >
        <DialogContent className="sm:max-w-md">
          <DialogHeader>
            <DialogTitle>{t("product.merge.title", { name: product.name })}</DialogTitle>
            <DialogDescription>{t("product.merge.description", { name: product.name })}</DialogDescription>
          </DialogHeader>
          {products.isPending ? (
            <Skeleton className="h-40 w-full" />
          ) : products.isError ? (
            <p className="text-sm text-destructive">{requestErrorMessage(products.error)}</p>
          ) : (
            <ProductPicker products={products.data} categories={categories} exceptId={product.id} value={targetId} onChange={setTargetId} />
          )}
          <DialogFooter>
            <Button variant="destructive" disabled={target === null} onClick={() => setConfirming(true)}>
              {t("product.merge.action")}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <AlertDialog open={confirming} onOpenChange={setConfirming}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>{t("product.merge.confirmTitle", { from: product.name, into: target?.name ?? "" })}</AlertDialogTitle>
            <AlertDialogDescription>{t("product.merge.confirmDescription", { from: product.name, into: target?.name ?? "" })}</AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel disabled={merge.isPending}>{t("product.cancel")}</AlertDialogCancel>
            <AlertDialogAction
              variant="destructive"
              disabled={merge.isPending || target === null}
              onClick={(event) => {
                event.preventDefault()
                if (target) merge.mutate(target.id)
              }}
            >
              {merge.isPending ? t("common.saving", { ns: "ui" }) : t("product.merge.confirm")}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </>
  )
}
