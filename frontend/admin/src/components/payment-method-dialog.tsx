import { useState, type FormEvent } from "react"
import { unwrap } from "@business-tool/api-client"
import { useMutation, useQueryClient } from "@tanstack/react-query"
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

import { api } from "@/lib/api"
import {
  type PaymentMethod,
  type PaymentMethodFormErrors,
  otherActiveCashMethod,
  paymentMethodFormErrors,
  withPaymentMethod,
} from "@/lib/payment-methods"
import { invalidateRevenue, paymentMethodsQuery } from "@/lib/queries"

interface PaymentMethodDialogProps {
  organizationId: string
  open: boolean
  onOpenChange: (open: boolean) => void
  /** The method to edit; without one, the dialog adds a new method at the end of the list. */
  method?: PaymentMethod | null
}

/**
 * Adds a payment method, or edits one (active or deactivated): its name, and whether it is the
 * cash method the end-of-day close books the counted cash on (ADR 0033 §9).
 */
export function PaymentMethodDialog({ organizationId, open, onOpenChange, method }: PaymentMethodDialogProps) {
  const queryClient = useQueryClient()
  const { t } = useTranslation("admin")
  const [errors, setErrors] = useState<PaymentMethodFormErrors>({})
  const listKey = paymentMethodsQuery(organizationId).queryKey
  const editing = method ?? null
  const otherCash = otherActiveCashMethod(queryClient.getQueryData(listKey) ?? [], editing)

  const save = useMutation({
    mutationFn: ({ name, cash }: { name: string; cash: boolean }) =>
      editing
        ? unwrap(
            api.PATCH("/api/payment-methods/{id}", {
              params: { path: { id: editing.id } },
              body: { name, cash },
              headers: { "Content-Type": "application/merge-patch+json" },
            }),
          )
        : unwrap(api.POST("/api/payment-methods", { body: { name, cash } })),
    onSuccess: async (saved) => {
      queryClient.setQueryData(listKey, (list) => (list ? withPaymentMethod(list, saved) : list))
      // Entries and summaries show the method's current name.
      if (editing) await invalidateRevenue(queryClient, organizationId)
      toast.success(editing ? t("paymentMethods.toast.saved", { name: saved.name }) : t("paymentMethods.toast.added", { name: saved.name }))
      close()
    },
    onError: (error, { name }) => setErrors(paymentMethodFormErrors(error, name, queryClient.getQueryData(listKey) ?? [])),
  })

  function close() {
    setErrors({})
    onOpenChange(false)
  }

  function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setErrors({})
    const form = new FormData(event.currentTarget)
    save.mutate({ name: String(form.get("name")), cash: form.get("cash") === "on" })
  }

  return (
    <Dialog open={open} onOpenChange={(next) => (next ? onOpenChange(true) : close())}>
      <DialogContent className="sm:max-w-md">
        {/* A new key per method, so the field starts from that method's name. */}
        <form key={editing?.id ?? "new"} onSubmit={onSubmit} noValidate>
          <DialogHeader>
            <DialogTitle>
              {editing ? t("paymentMethods.dialog.editTitle", { name: editing.name }) : t("paymentMethods.add")}
            </DialogTitle>
            <DialogDescription>
              {editing ? t("paymentMethods.dialog.editDescription") : t("paymentMethods.dialog.addDescription")}
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
                  defaultValue={editing?.name ?? ""}
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
              <Field orientation="horizontal" data-invalid={errors.cash ? true : undefined}>
                <Checkbox
                  id="payment-method-cash"
                  name="cash"
                  defaultChecked={editing?.cash ?? false}
                  aria-invalid={errors.cash ? true : undefined}
                />
                <FieldContent>
                  <FieldLabel htmlFor="payment-method-cash">{t("paymentMethods.dialog.cash")}</FieldLabel>
                  {errors.cash ? (
                    <FieldError>{errors.cash}</FieldError>
                  ) : (
                    <FieldDescription>
                      {otherCash && !editing?.cash
                        ? t("paymentMethods.dialog.cashTakenHint", { name: otherCash.name })
                        : t("paymentMethods.dialog.cashDescription")}
                    </FieldDescription>
                  )}
                </FieldContent>
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
              {save.isPending ? t("common.saving", { ns: "ui" }) : editing ? t("common.save") : t("paymentMethods.add")}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  )
}
