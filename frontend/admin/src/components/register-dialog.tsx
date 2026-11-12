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
import { registersQuery } from "@/lib/queries"
import { type Register, type RegisterFormErrors, registerFormErrors, withRegister } from "@/lib/registers"

interface RegisterDialogProps {
  organizationId: string
  open: boolean
  onOpenChange: (open: boolean) => void
  /** The register to rename; without one, the dialog adds a new register. */
  register?: Register | null
}

/** Adds a register, or renames one (active or deactivated). */
export function RegisterDialog({ organizationId, open, onOpenChange, register }: RegisterDialogProps) {
  const queryClient = useQueryClient()
  const [errors, setErrors] = useState<RegisterFormErrors>({})
  const listKey = registersQuery(organizationId, true).queryKey
  const renaming = register ?? null

  const save = useMutation({
    mutationFn: (name: string) =>
      renaming
        ? unwrap(
            api.PATCH("/api/registers/{id}", {
              params: { path: { id: renaming.id } },
              body: { name },
              headers: { "Content-Type": "application/merge-patch+json" },
            }),
          )
        : unwrap(api.POST("/api/registers", { body: { name } })),
    onSuccess: async (saved) => {
      queryClient.setQueryData(listKey, (list) => (list ? withRegister(list, saved) : list))
      // The active-only list (the Income page's entry form) is out of date too.
      await queryClient.invalidateQueries({ queryKey: registersQuery(organizationId, false).queryKey })
      toast.success(renaming ? `Register renamed to ${saved.name}` : `Register ${saved.name} added`)
      close()
    },
    onError: (error, name) => setErrors(registerFormErrors(error, name, queryClient.getQueryData(listKey) ?? [])),
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
        {/* A new key per register, so the field starts from that register's name. */}
        <form key={renaming?.id ?? "new"} onSubmit={onSubmit} noValidate>
          <DialogHeader>
            <DialogTitle>{renaming ? `Rename ${renaming.name}` : "Add register"}</DialogTitle>
            <DialogDescription>
              {renaming
                ? "Its revenue entries, past ones included, show the new name."
                : "A till with its own fiscal printer. You enter one daily report per register."}
            </DialogDescription>
          </DialogHeader>
          <fieldset disabled={save.isPending} className="py-4">
            <FieldGroup>
              <Field data-invalid={errors.name ? true : undefined}>
                <FieldLabel htmlFor="register-name">Name</FieldLabel>
                <Input
                  id="register-name"
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
                  <FieldDescription>Up to 64 characters, different from your other registers.</FieldDescription>
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
              {save.isPending ? "Saving…" : renaming ? "Rename" : "Add register"}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  )
}
