import { useState, type FormEvent } from "react"
import { ApiError, unwrap } from "@business-tool/api-client"
import { useMutation, useQueryClient } from "@tanstack/react-query"
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
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@business-tool/ui/components/select"

import { api } from "@/lib/api"
import {
  type CategoryFormErrors,
  PRODUCT_CATEGORY_NAME_MAX_LENGTH,
  type ProductCategory,
  categoryFormErrors,
  parentChoices,
  withCategory,
} from "@/lib/product-categories"
import { productCategoriesQuery } from "@/lib/queries"

/** Refusals that mean the list on screen is out of date. */
const STALE_CODES = ["purchasing.product_category_not_found", "purchasing.product_category_parent_not_found"]

/** The select's value for "no parent"; a category's id is a UUID, so it cannot clash. */
const TOP_LEVEL = "top-level"

interface ProductCategoryDialogProps {
  organizationId: string
  open: boolean
  onOpenChange: (open: boolean) => void
  /** The organization's categories, for the parent picker. */
  categories: readonly ProductCategory[]
  /** The category to rename or move; without one, the dialog adds a new category. */
  category?: ProductCategory | null
  /** The parent a new category starts under; null for the top level. Ignored when editing. */
  parentId?: string | null
  /** Called with the saved category, e.g. to open the branch it is in. */
  onSaved?: (saved: ProductCategory) => void
}

/** Adds a category (top level or under one), or renames and moves one. */
export function ProductCategoryDialog({
  organizationId,
  open,
  onOpenChange,
  categories,
  category,
  parentId = null,
  onSaved,
}: ProductCategoryDialogProps) {
  const editing = category ?? null
  const { t } = useTranslation("admin")

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-md">
        {/* A new key per category and parent, so the fields start from its values. */}
        <CategoryForm
          key={editing?.id ?? `new-${parentId ?? TOP_LEVEL}`}
          organizationId={organizationId}
          categories={categories}
          editing={editing}
          initialParentId={editing ? editing.parentId : parentId}
          onClose={() => onOpenChange(false)}
          onSaved={onSaved}
          title={
            editing
              ? t("categories.dialog.editTitle", { name: editing.name })
              : parentId
                ? t("categories.addSub")
                : t("categories.add")
          }
        />
      </DialogContent>
    </Dialog>
  )
}

interface CategoryFormProps {
  organizationId: string
  categories: readonly ProductCategory[]
  editing: ProductCategory | null
  initialParentId: string | null
  title: string
  onClose: () => void
  onSaved?: (saved: ProductCategory) => void
}

function CategoryForm({ organizationId, categories, editing, initialParentId, title, onClose, onSaved }: CategoryFormProps) {
  const queryClient = useQueryClient()
  const { t } = useTranslation("admin")
  const [errors, setErrors] = useState<CategoryFormErrors>({})
  const [parent, setParent] = useState(initialParentId ?? TOP_LEVEL)
  const listKey = productCategoriesQuery(organizationId).queryKey
  const choices = parentChoices(categories, editing?.id ?? null)

  const save = useMutation({
    mutationFn: ({ name, parentId }: { name: string; parentId: string | null }) =>
      editing
        ? unwrap(
            api.PATCH("/api/product-categories/{id}", {
              params: { path: { id: editing.id } },
              body: { name, parentId },
              headers: { "Content-Type": "application/merge-patch+json" },
            }),
          )
        : unwrap(api.POST("/api/product-categories", { body: { name, parentId } })),
    onSuccess: (saved) => {
      queryClient.setQueryData(listKey, (list) => (list ? withCategory(list, saved) : list))
      toast.success(editing ? t("categories.toast.saved", { name: saved.name }) : t("categories.toast.added", { name: saved.name }))
      onSaved?.(saved)
      onClose()
    },
    onError: async (error) => {
      setErrors(categoryFormErrors(error))
      // The category or the new parent was deleted in the meantime: show what is there now.
      if (error instanceof ApiError && STALE_CODES.includes(error.code ?? "")) {
        await queryClient.invalidateQueries({ queryKey: listKey })
      }
    },
  })

  function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setErrors({})
    save.mutate({
      name: String(new FormData(event.currentTarget).get("name")),
      parentId: parent === TOP_LEVEL ? null : parent,
    })
  }

  return (
    <form onSubmit={onSubmit} noValidate>
      <DialogHeader>
        <DialogTitle>{title}</DialogTitle>
        <DialogDescription>{editing ? t("categories.dialog.editDescription") : t("categories.dialog.addDescription")}</DialogDescription>
      </DialogHeader>
      <fieldset disabled={save.isPending} className="py-4">
        <FieldGroup>
          <Field data-invalid={errors.name ? true : undefined}>
            <FieldLabel htmlFor="product-category-name">{t("categories.dialog.name")}</FieldLabel>
            <Input
              id="product-category-name"
              name="name"
              autoComplete="off"
              autoFocus
              defaultValue={editing?.name ?? ""}
              maxLength={PRODUCT_CATEGORY_NAME_MAX_LENGTH}
              aria-invalid={errors.name ? true : undefined}
              required
            />
            {errors.name ? (
              <FieldError>{errors.name}</FieldError>
            ) : (
              <FieldDescription>{t("categories.dialog.nameDescription", { count: PRODUCT_CATEGORY_NAME_MAX_LENGTH })}</FieldDescription>
            )}
          </Field>
          <Field>
            <FieldLabel htmlFor="product-category-parent">{t("categories.dialog.parent")}</FieldLabel>
            <Select value={parent} onValueChange={setParent}>
              <SelectTrigger id="product-category-parent" className="w-full">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value={TOP_LEVEL}>{t("categories.dialog.topLevel")}</SelectItem>
                {choices.map(({ category: choice, path }) => (
                  <SelectItem key={choice.id} value={choice.id}>
                    {path}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
            <FieldDescription>{editing ? t("categories.dialog.parentMoveDescription") : t("categories.dialog.parentDescription")}</FieldDescription>
          </Field>
          {errors.form && (
            <Field data-invalid>
              <FieldError>{errors.form}</FieldError>
            </Field>
          )}
        </FieldGroup>
      </fieldset>
      <DialogFooter>
        <Button type="submit" disabled={save.isPending}>
          {save.isPending ? t("common.saving", { ns: "ui" }) : editing ? t("categories.dialog.save") : t("categories.dialog.add")}
        </Button>
      </DialogFooter>
    </form>
  )
}
