import { useState, type FormEvent } from "react"
import { unwrap } from "@business-tool/api-client"
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

import { api } from "@/lib/api"
import {
  type PaymentMethod,
  type PaymentMethodFormErrors,
  paymentMethodFormErrors,
  withPaymentMethod,
} from "@/lib/payment-methods"
import { invalidateRevenue, paymentMethodsQuery } from "@/lib/queries"

interface PaymentMethodDialogProps {
  organizationId: string
  open: boolean
  onOpenChange: (open: boolean) => void
  /** The method to rename; without one, the dialog adds a new method at the end of the list. */
  method?: PaymentMethod | null
}

/** Adds a payment method, or renames one (active or deactivated). */
export function PaymentMethodDialog({ organizationId, open, onOpenChange, method }: PaymentMethodDialogProps) {
  const queryClient = useQueryClient()
  const { t } = useTranslation("admin")
  const [errors, setErrors] = useState<PaymentMethodFormErrors>({})
  const listKey = paymentMethodsQuery(organizationId).queryKey
  const renaming = method ?? null

  const save = useMutation({
    mutationFn: (name: string) =>
      renaming
        ? unwrap(
            api.PATCH("/api/payment-methods/{id}", {
              params: { path: { id: renaming.id } },
              body: { name },
              headers: { "Content-Type": "application/merge-patch+json" },
            }),
          )
        : unwrap(api.POST("/api/payment-methods", { body: { name } })),
    onSuccess: async (saved) => {
      queryClient.setQueryData(listKey, (list) => (list ? withPaymentMethod(list, saved) : list))
      // Entries and summaries show the method's current name.
      if (renaming) await invalidateRevenue(queryClient, organizationId)
      toast.success(renaming ? t("paymentMethods.toast.renamed", { name: saved.name }) : t("paymentMethods.toast.added", { name: saved.name }))
      close()
    },
    onError: (error, name) => setErrors(paymentMethodFormErrors(error, name, queryClient.getQueryData(listKey) ?? [])),
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
        {/* A new key per method, so the field starts from that method's name. */}
        <form key={renaming?.id ?? "new"} onSubmit={onSubmit} noValidate>
          <DialogHeader>
            <DialogTitle>
              {renaming ? t("paymentMethods.dialog.renameTitle", { name: renaming.name }) : t("paymentMethods.add")}
            </DialogTitle>
            <DialogDescription>
              {renaming ? t("paymentMethods.dialog.renameDescription") : t("paymentMethods.dialog.addDescription")}
            </DialogDescription>
          </DialogHeader>
          <fieldset disabled={save.isPending} className="py-4">
            <FieldGroup>
              <Field data-invalid={errors.name ? true : undefined}>
                <FieldLabel htmlFor="payment-method-name">{t("paymentMethods.dialog.name")}</FieldLabel>
                <Input
                  id="payment-method-name"
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
                  <FieldDescription>{t("paymentMethods.dialog.nameDescription", { count: 64 })}</FieldDescription>
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
              {save.isPending ? t("common.saving", { ns: "ui" }) : renaming ? t("paymentMethods.rename") : t("paymentMethods.add")}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  )
}
