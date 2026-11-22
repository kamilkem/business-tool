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
import { MoneyInput } from "@business-tool/ui/components/money-input"
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@business-tool/ui/components/select"
import { useMoneyFormat, useOrganizationCurrency } from "@business-tool/ui/hooks/use-organization-currency"
import { type MoneyParseError, moneyErrorMessage } from "@business-tool/ui/lib/money"

import { api } from "@/lib/api"
import {
  DENOMINATION_KINDS,
  type DenominationFormErrors,
  type DenominationKind,
  denominationFormErrors,
  withDenomination,
} from "@/lib/denominations"
import { denominationsQuery, invalidateDayCloseForm } from "@/lib/queries"

interface DenominationDialogProps {
  organizationId: string
  open: boolean
  onOpenChange: (open: boolean) => void
}

/** Adds a coin or note of the organization's currency. */
export function DenominationDialog({ organizationId, open, onOpenChange }: DenominationDialogProps) {
  const queryClient = useQueryClient()
  const { t } = useTranslation("admin")
  const money = useMoneyFormat()
  const [errors, setErrors] = useState<DenominationFormErrors>({})
  const listKey = denominationsQuery(organizationId).queryKey

  const add = useMutation({
    mutationFn: (body: { value: number; kind: DenominationKind }) => unwrap(api.POST("/api/denominations", { body })),
    onSuccess: async (saved) => {
      queryClient.setQueryData(listKey, (list) => (list ? withDenomination(list, saved) : list))
      await invalidateDayCloseForm(queryClient, organizationId)
      toast.success(t("denominations.toast.added", { value: money(saved.value) }))
      close()
    },
    onError: (error, { value }) =>
      setErrors(denominationFormErrors(error, value, money(value), queryClient.getQueryData(listKey) ?? [])),
  })

  function close() {
    setErrors({})
    onOpenChange(false)
  }

  return (
    <Dialog open={open} onOpenChange={(next) => (next ? onOpenChange(true) : close())}>
      <DialogContent className="sm:max-w-md">
        {/* The content mounts on each open, so the form starts empty. */}
        <DenominationForm
          errors={errors}
          pending={add.isPending}
          onInvalid={setErrors}
          onSubmit={(values) => {
            setErrors({})
            add.mutate(values)
          }}
        />
      </DialogContent>
    </Dialog>
  )
}

interface DenominationFormProps {
  errors: DenominationFormErrors
  pending: boolean
  /** A value the form would not send: shown without asking the API. */
  onInvalid: (errors: DenominationFormErrors) => void
  onSubmit: (values: { value: number; kind: DenominationKind }) => void
}

function DenominationForm({ errors, pending, onInvalid, onSubmit }: DenominationFormProps) {
  const { t } = useTranslation("admin")
  const currency = useOrganizationCurrency()
  const [value, setValue] = useState<number | null>(null)
  const [parseError, setParseError] = useState<MoneyParseError | null>(null)
  const [kind, setKind] = useState<DenominationKind>("coin")

  function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (parseError) return onInvalid({ value: moneyErrorMessage(parseError, currency) })
    if (value === null || value <= 0) return onInvalid({ value: t("denominations.error.valueRequired") })
    onSubmit({ value, kind })
  }

  const valueError = parseError ? moneyErrorMessage(parseError, currency) : errors.value

  return (
    <form onSubmit={submit} noValidate>
      <DialogHeader>
        <DialogTitle>{t("denominations.add")}</DialogTitle>
        <DialogDescription>{t("denominations.dialog.description", { currency })}</DialogDescription>
      </DialogHeader>
      <fieldset disabled={pending} className="py-4">
        <FieldGroup>
          <Field data-invalid={valueError ? true : undefined}>
            <FieldLabel htmlFor="denomination-value">{t("denominations.dialog.value")}</FieldLabel>
            <MoneyInput
              id="denomination-value"
              currency={currency}
              value={value}
              onValueChange={(minor, error) => {
                setValue(minor)
                setParseError(error)
              }}
              aria-invalid={valueError ? true : undefined}
              required
            />
            {valueError ? (
              <FieldError>{valueError}</FieldError>
            ) : (
              <FieldDescription>{t("denominations.dialog.valueDescription")}</FieldDescription>
            )}
          </Field>
          <Field data-invalid={errors.kind ? true : undefined}>
            <FieldLabel htmlFor="denomination-kind">{t("denominations.dialog.kind")}</FieldLabel>
            <Select value={kind} onValueChange={(next) => setKind(next as DenominationKind)}>
              <SelectTrigger id="denomination-kind" className="w-full" aria-invalid={errors.kind ? true : undefined}>
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                {DENOMINATION_KINDS.map((option) => (
                  <SelectItem key={option} value={option}>
                    {t(`denominations.kind.${option}`)}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
            {errors.kind && <FieldError>{errors.kind}</FieldError>}
          </Field>
          {errors.form && (
            <Field data-invalid>
              <FieldError>{errors.form}</FieldError>
            </Field>
          )}
        </FieldGroup>
      </fieldset>
      <DialogFooter>
        <Button type="submit" disabled={pending}>
          {pending ? t("common.saving", { ns: "ui" }) : t("denominations.add")}
        </Button>
      </DialogFooter>
    </form>
  )
}
