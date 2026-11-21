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
import { Field, FieldError, FieldGroup, FieldLabel } from "@business-tool/ui/components/field"
import { Input } from "@business-tool/ui/components/input"
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@business-tool/ui/components/select"
import { useLocale } from "@business-tool/ui/hooks/use-locale"
import { useDateTimeFormat } from "@business-tool/ui/hooks/use-organization-time-zone"
import { formatMoney } from "@business-tool/ui/lib/money"

import { api } from "@/lib/api"
import {
  type FormErrors,
  PAYMENT_METHOD_LABELS,
  type PaymentFormValues,
  type PaymentMethod,
  type PaycheckRow,
  type PayrollRun,
  apiFormErrors,
  paymentFormErrors,
} from "@/lib/payroll"
import { invalidatePayrollRuns, payrollRunQuery } from "@/lib/queries"

interface PaycheckPaymentDialogProps {
  organizationId: string
  run: PayrollRun
  row: PaycheckRow | null
  onClose: () => void
}

/** Marks a paycheck of an approved run paid, with the day and the method (ADR 0027 §9). Owners only. */
export function PaycheckPaymentDialog({ organizationId, run, row, onClose }: PaycheckPaymentDialogProps) {
  return (
    <Dialog open={row !== null} onOpenChange={(open) => !open && onClose()}>
      <DialogContent className="sm:max-w-md">
        {row && <PaymentForm key={row.paycheck.id} organizationId={organizationId} run={run} row={row} onClose={onClose} />}
      </DialogContent>
    </Dialog>
  )
}

function PaymentForm({ organizationId, run, row, onClose }: Omit<PaycheckPaymentDialogProps, "row"> & { row: PaycheckRow }) {
  const queryClient = useQueryClient()
  const format = useDateTimeFormat()
  const locale = useLocale()
  const today = format.today()
  const [values, setValues] = useState<PaymentFormValues>({ paidOn: today, method: "" })
  const [errors, setErrors] = useState<FormErrors>({})

  const pay = useMutation({
    mutationFn: ({ paidOn, method }: { paidOn: string; method: PaymentMethod }) =>
      unwrap(
        api.POST("/api/payroll-runs/{id}/paychecks/{paycheckId}/payment", {
          params: { path: { id: run.id, paycheckId: row.paycheck.id } },
          body: { paidOn, method },
        }),
      ),
    onSuccess: async (saved) => {
      queryClient.setQueryData(payrollRunQuery(organizationId, run.id).queryKey, saved)
      toast.success(`${row.name}: marked paid`)
      onClose()
      await invalidatePayrollRuns(queryClient, organizationId)
    },
    onError: (error) => setErrors(apiFormErrors(error, { paidOn: "paidOn", method: "method" })),
  })

  function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const found = paymentFormErrors(values, today)
    setErrors(found)
    if (Object.keys(found).length > 0 || values.method === "") return
    pay.mutate({ paidOn: values.paidOn, method: values.method })
  }

  return (
    <form onSubmit={onSubmit} noValidate>
      <DialogHeader>
        <DialogTitle>Mark paid: {row.name}</DialogTitle>
        <DialogDescription>
          {formatMoney(row.paycheck.total, run.currency, locale)} gross for {format.period({ from: run.firstDate, to: run.lastDate })}.
          Once a paycheck is marked paid, the run can't be reopened.
        </DialogDescription>
      </DialogHeader>
      <fieldset disabled={pay.isPending} className="py-4">
        <FieldGroup>
          <Field data-invalid={errors.paidOn ? true : undefined}>
            <FieldLabel htmlFor="payment-date">Paid on</FieldLabel>
            <Input
              id="payment-date"
              type="date"
              max={today}
              value={values.paidOn}
              onChange={(event) => setValues({ ...values, paidOn: event.target.value })}
              aria-invalid={errors.paidOn ? true : undefined}
            />
            {errors.paidOn && <FieldError>{errors.paidOn}</FieldError>}
          </Field>
          <Field data-invalid={errors.method ? true : undefined}>
            <FieldLabel htmlFor="payment-method">Method</FieldLabel>
            <Select value={values.method} onValueChange={(method) => setValues({ ...values, method: method as PaymentMethod })}>
              <SelectTrigger id="payment-method" className="w-full" aria-invalid={errors.method ? true : undefined}>
                <SelectValue placeholder="Choose how it was paid" />
              </SelectTrigger>
              <SelectContent>
                {(Object.keys(PAYMENT_METHOD_LABELS) as PaymentMethod[]).map((method) => (
                  <SelectItem key={method} value={method}>
                    {PAYMENT_METHOD_LABELS[method]}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
            {errors.method && <FieldError>{errors.method}</FieldError>}
          </Field>
          {errors.form && (
            <Field data-invalid>
              <FieldError>{errors.form}</FieldError>
            </Field>
          )}
        </FieldGroup>
      </fieldset>
      <DialogFooter>
        <Button type="submit" disabled={pay.isPending}>
          {pay.isPending ? "Saving…" : "Mark paid"}
        </Button>
      </DialogFooter>
    </form>
  )
}
