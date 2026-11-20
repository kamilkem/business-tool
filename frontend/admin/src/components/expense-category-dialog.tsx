import { useState, type FormEvent } from "react"
import { unwrap } from "@business-tool/api-client"
import { useMutation, useQueryClient } from "@tanstack/react-query"
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

import { api } from "@/lib/api"
import {
  type CategoryFormErrors,
  type ExpenseCategory,
  categoryFormErrors,
  withCategory,
} from "@/lib/expense-categories"
import { expenseCategoriesQuery } from "@/lib/queries"

interface ExpenseCategoryDialogProps {
  organizationId: string
  open: boolean
  onOpenChange: (open: boolean) => void
  /** The category to rename; without one, the dialog adds a new category. */
  category?: ExpenseCategory | null
}

/** Adds an expense category, or renames one (active or archived). */
export function ExpenseCategoryDialog({ organizationId, open, onOpenChange, category }: ExpenseCategoryDialogProps) {
  const queryClient = useQueryClient()
  const [errors, setErrors] = useState<CategoryFormErrors>({})
  const listKey = expenseCategoriesQuery(organizationId, true).queryKey
  const renaming = category ?? null

  const save = useMutation({
    mutationFn: (name: string) =>
      renaming
        ? unwrap(
            api.PATCH("/api/expense-categories/{id}", {
              params: { path: { id: renaming.id } },
              body: { name },
              headers: { "Content-Type": "application/merge-patch+json" },
            }),
          )
        : unwrap(api.POST("/api/expense-categories", { body: { name } })),
    onSuccess: async (saved) => {
      queryClient.setQueryData(listKey, (list) => (list ? withCategory(list, saved) : list))
      // The active-only list (category pickers) is out of date too.
      await queryClient.invalidateQueries({ queryKey: expenseCategoriesQuery(organizationId, false).queryKey })
      toast.success(renaming ? `Category renamed to ${saved.name}` : `Category ${saved.name} added`)
      close()
    },
    onError: (error, name) => setErrors(categoryFormErrors(error, name, queryClient.getQueryData(listKey) ?? [])),
  })

  function close() {
    setErrors({})
    onOpenChange(false)
  }

  function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setErrors({})
    save.mutate(String(new FormData(event.currentTarget).get("name")))
  }

  return (
    <Dialog open={open} onOpenChange={(next) => (next ? onOpenChange(true) : close())}>
      <DialogContent className="sm:max-w-md">
        {/* A new key per category, so the field starts from that category's name. */}
        <form key={renaming?.id ?? "new"} onSubmit={onSubmit} noValidate>
          <DialogHeader>
            <DialogTitle>{renaming ? `Rename ${renaming.name}` : "Add category"}</DialogTitle>
            <DialogDescription>
              {renaming
                ? "Expenses in this category, past ones included, show the new name."
                : "What an expense was for, such as rent or drinks. You sort purchase invoices into categories."}
            </DialogDescription>
          </DialogHeader>
          <fieldset disabled={save.isPending} className="py-4">
            <FieldGroup>
              <Field data-invalid={errors.name ? true : undefined}>
                <FieldLabel htmlFor="expense-category-name">Name</FieldLabel>
                <Input
                  id="expense-category-name"
                  name="name"
                  autoComplete="off"
                  defaultValue={renaming?.name ?? ""}
                  maxLength={64}
                  aria-invalid={errors.name ? true : undefined}
                  required
                />
                {errors.name ? (
                  <FieldError>{errors.name}</FieldError>
                ) : (
                  <FieldDescription>Up to 64 characters, different from your other categories.</FieldDescription>
                )}
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
              {save.isPending ? "Saving…" : renaming ? "Rename" : "Add category"}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  )
}
