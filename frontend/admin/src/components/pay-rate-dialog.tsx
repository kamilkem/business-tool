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
import { MoneyInput } from "@business-tool/ui/components/money-input"
import { useLocale } from "@business-tool/ui/hooks/use-locale"
import { useOrganizationCurrency } from "@business-tool/ui/hooks/use-organization-currency"
import { type MinorUnits, formatMoney, moneyErrorMessage } from "@business-tool/ui/lib/money"

import { api } from "@/lib/api"
import { type Member, fullName, memberNames } from "@/lib/members"
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
  const { t } = useTranslation("admin")
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
      toast.success(t(`payroll.rate.toast.${target.kind}${editing ? "Saved" : "Added"}`), {
        description: t("payroll.rate.toast.description", { name, amount: formatMoney(amount, currency, locale) }),
      })
      onClose()
      // A draft generated before the change no longer matches (approval would refuse it).
      await Promise.all([invalidatePayRates(queryClient, organizationId), invalidatePayrollRuns(queryClient, organizationId)])
    },
    onError: (error) =>
      setErrors(
        apiFormErrors(
          error,
          { effectiveFrom: "date", date: "date", amount: "amount", userId: "form" },
          { names: memberNames([target.member]) },
        ),
      ),
  })

  function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const found = rateFormErrors(values)
    setErrors(found)
    if (Object.keys(found).length > 0 || values.amount === null) return
    save.mutate({ date: values.date, amount: values.amount })
  }

  const hourly = target.kind === "hourly"
  const title = t(`payroll.rate.title.${hourly ? "hourly" : "day"}${editing ? "Change" : "New"}`, { name })

  return (
    <form onSubmit={onSubmit} noValidate>
      <DialogHeader>
        <DialogTitle>{title}</DialogTitle>
        <DialogDescription>
          {hourly ? t("payroll.rate.hourlyDescription") : t("payroll.rate.dayDescription")}
        </DialogDescription>
      </DialogHeader>
      <fieldset disabled={save.isPending} className="py-4">
        <FieldGroup>
          <Field data-invalid={errors.date ? true : undefined}>
            <FieldLabel htmlFor="rate-date">{hourly ? t("payroll.rate.from") : t("payroll.rate.date")}</FieldLabel>
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
              <FieldDescription>{t("payroll.rate.lockedDates")}</FieldDescription>
            )}
          </Field>
          <Field data-invalid={errors.amount ? true : undefined}>
            <FieldLabel htmlFor="rate-amount">{t("payroll.rate.amount")}</FieldLabel>
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
          {save.isPending ? t("common.saving", { ns: "ui" }) : editing ? t("common.save") : hourly ? t("payroll.rate.setRate") : t("payroll.rate.addDayRate")}
        </Button>
      </DialogFooter>
    </form>
  )
}
