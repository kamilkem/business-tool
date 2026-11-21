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
import { MoneyInput } from "@business-tool/ui/components/money-input"
import { useLocale } from "@business-tool/ui/hooks/use-locale"
import { useOrganizationCurrency } from "@business-tool/ui/hooks/use-organization-currency"
import { type MinorUnits, formatMoney, moneyErrorMessage } from "@business-tool/ui/lib/money"

import { api } from "@/lib/api"
import { type Member, fullName } from "@/lib/members"
import {
  type DayRate,
  type FormErrors,
  type HourlyRate,
  type RateFormValues,
  apiFormErrors,
  withFieldError,
  rateFormErrors,
} from "@/lib/payroll"
import { invalidatePayRates, invalidatePayrollRuns } from "@/lib/queries"

export type PayRateTarget =
  | { kind: "hourly"; member: Member; rate?: HourlyRate }
  | { kind: "day"; member: Member; rate?: DayRate }

interface PayRateDialogProps {
  organizationId: string
  target: PayRateTarget | null
  onClose: () => void
}

/** Adds or corrects a member's hourly rate (from a date) or day rate (for one date), ADR 0027 §3. */
export function PayRateDialog({ organizationId, target, onClose }: PayRateDialogProps) {
  return (
    <Dialog open={target !== null} onOpenChange={(open) => !open && onClose()}>
      <DialogContent className="sm:max-w-md">
        {target && (
          <PayRateForm
            key={`${target.kind}-${target.member.userId}-${target.rate?.id ?? "new"}`}
            organizationId={organizationId}
            target={target}
            onClose={onClose}
          />
        )}
      </DialogContent>
    </Dialog>
  )
}

function PayRateForm({ organizationId, target, onClose }: { organizationId: string; target: PayRateTarget; onClose: () => void }) {
  const queryClient = useQueryClient()
  const currency = useOrganizationCurrency()
  const locale = useLocale()
  const [values, setValues] = useState<RateFormValues>(() => ({
    date: target.rate ? (target.kind === "hourly" ? target.rate.effectiveFrom : target.rate.date) : "",
    amount: target.rate?.amount ?? null,
  }))
  const [errors, setErrors] = useState<FormErrors>({})
  const name = fullName(target.member)
  const editing = target.rate !== undefined

  const save = useMutation({
    mutationFn: ({ date, amount }: { date: string; amount: MinorUnits }): Promise<HourlyRate | DayRate> => {
      const merge = { "Content-Type": "application/merge-patch+json" } as const
      if (target.kind === "hourly") {
        return target.rate
          ? unwrap(
              api.PATCH("/api/hourly-rates/{id}", {
                params: { path: { id: target.rate.id } },
                body: { effectiveFrom: date, amount },
                headers: merge,
              }),
            )
          : unwrap(api.POST("/api/hourly-rates", { body: { userId: target.member.userId, effectiveFrom: date, amount } }))
      }
      return target.rate
        ? unwrap(
            api.PATCH("/api/day-rates/{id}", {
              params: { path: { id: target.rate.id } },
              body: { date, amount },
              headers: merge,
            }),
          )
        : unwrap(api.POST("/api/day-rates", { body: { userId: target.member.userId, date, amount } }))
    },
    onSuccess: async (_, { amount }) => {
      toast.success(`${target.kind === "hourly" ? "Hourly rate" : "Day rate"} ${editing ? "saved" : "added"}`, {
        description: `${name}: ${formatMoney(amount, currency, locale)} per hour. Regenerate drafts to use it.`,
      })
      onClose()
      // A draft generated before the change no longer matches (approval would refuse it).
      await Promise.all([invalidatePayRates(queryClient, organizationId), invalidatePayrollRuns(queryClient, organizationId)])
    },
    onError: (error) =>
      setErrors(apiFormErrors(error, { effectiveFrom: "date", date: "date", amount: "amount", userId: "form" })),
  })

  function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const found = rateFormErrors(values)
    setErrors(found)
    if (Object.keys(found).length > 0 || values.amount === null) return
    save.mutate({ date: values.date, amount: values.amount })
  }

  const hourly = target.kind === "hourly"
  const title = `${editing ? "Change" : hourly ? "Set" : "Add"} ${hourly ? "hourly rate" : "day rate"}: ${name}`

  return (
    <form onSubmit={onSubmit} noValidate>
      <DialogHeader>
        <DialogTitle>{title}</DialogTitle>
        <DialogDescription>
          {hourly
            ? "The usual gross rate per hour from a date until the next rate. Earlier shifts keep the earlier rate."
            : "A different gross rate per hour for the shifts that start on one date, such as New Year's Eve."}
        </DialogDescription>
      </DialogHeader>
      <fieldset disabled={save.isPending} className="py-4">
        <FieldGroup>
          <Field data-invalid={errors.date ? true : undefined}>
            <FieldLabel htmlFor="rate-date">{hourly ? "From" : "Date"}</FieldLabel>
            <Input
              id="rate-date"
              type="date"
              value={values.date}
              onChange={(event) => setValues({ ...values, date: event.target.value })}
              aria-invalid={errors.date ? true : undefined}
              required
            />
            {errors.date ? (
              <FieldError>{errors.date}</FieldError>
            ) : (
              <FieldDescription>Dates in an approved payroll month can't change.</FieldDescription>
            )}
          </Field>
          <Field data-invalid={errors.amount ? true : undefined}>
            <FieldLabel htmlFor="rate-amount">Gross per hour</FieldLabel>
            <MoneyInput
              id="rate-amount"
              currency={currency}
              value={values.amount}
              aria-invalid={errors.amount ? true : undefined}
              onValueChange={(amount, error) => {
                setValues({ ...values, amount })
                setErrors((current) => withFieldError(current, "amount", error ? moneyErrorMessage(error, currency) : null))
              }}
            />
            {errors.amount && <FieldError>{errors.amount}</FieldError>}
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
          {save.isPending ? "Saving…" : editing ? "Save" : hourly ? "Set rate" : "Add day rate"}
        </Button>
      </DialogFooter>
    </form>
  )
}
