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
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@business-tool/ui/components/select"
import { moneyErrorMessage } from "@business-tool/ui/lib/money"

import { api } from "@/lib/api"
import { type Member, fullName } from "@/lib/members"
import {
  ADJUSTMENT_LABEL_MAX_LENGTH,
  type AdjustmentFormValues,
  type FormErrors,
  type PaycheckAdjustment,
  type PayrollRun,
  adjustmentFormErrors,
  apiFormErrors,
  withFieldError,
} from "@/lib/payroll"
import { invalidatePayrollRuns, payrollRunQuery } from "@/lib/queries"

export type AdjustmentTarget = { userId?: string; adjustment?: PaycheckAdjustment }

interface PaycheckAdjustmentDialogProps {
  organizationId: string
  run: PayrollRun
  /** Everyone who may get an adjustment: members, active or deactivated (ADR 0027 §6). */
  members: readonly Member[]
  target: AdjustmentTarget | null
  onClose: () => void
}

/** Adds a bonus (+) or a deduction such as an advance (−) to a paycheck on a draft, or corrects one. */
export function PaycheckAdjustmentDialog({ organizationId, run, members, target, onClose }: PaycheckAdjustmentDialogProps) {
  return (
    <Dialog open={target !== null} onOpenChange={(open) => !open && onClose()}>
      <DialogContent className="sm:max-w-md">
        {target && (
          <AdjustmentForm
            key={target.adjustment?.id ?? `new-${target.userId ?? ""}`}
            organizationId={organizationId}
            run={run}
            members={members}
            target={target}
            onClose={onClose}
          />
        )}
      </DialogContent>
    </Dialog>
  )
}

function AdjustmentForm({
  organizationId,
  run,
  members,
  target,
  onClose,
}: Omit<PaycheckAdjustmentDialogProps, "target"> & { target: AdjustmentTarget }) {
  const queryClient = useQueryClient()
  const editing = target.adjustment
  const [values, setValues] = useState<AdjustmentFormValues>({
    userId: target.userId ?? "",
    label: editing?.label ?? "",
    amount: editing?.amount ?? null,
  })
  const [errors, setErrors] = useState<FormErrors>({})

  const save = useMutation({
    mutationFn: async ({ userId, label, amount }: { userId: string; label: string; amount: number }) => {
      if (editing) {
        return unwrap(
          api.PATCH("/api/payroll-runs/{id}/adjustments/{adjustmentId}", {
            params: { path: { id: run.id, adjustmentId: editing.id } },
            body: { label, amount },
            headers: { "Content-Type": "application/merge-patch+json" },
          }),
        )
      }
      return unwrap(
        api.POST("/api/payroll-runs/{id}/adjustments", { params: { path: { id: run.id } }, body: { userId, label, amount } }),
      )
    },
    onSuccess: async (saved) => {
      queryClient.setQueryData(payrollRunQuery(organizationId, run.id).queryKey, saved)
      toast.success(editing ? "Adjustment saved" : "Adjustment added")
      onClose()
      await invalidatePayrollRuns(queryClient, organizationId)
    },
    onError: (error) => setErrors(apiFormErrors(error, { userId: "userId", label: "label", amount: "amount" })),
  })

  function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const found = adjustmentFormErrors(values)
    setErrors(found)
    if (Object.keys(found).length > 0 || values.amount === null) return
    save.mutate({ userId: values.userId, label: values.label.trim(), amount: values.amount })
  }

  const person = members.find((m) => m.userId === values.userId)

  return (
    <form onSubmit={onSubmit} noValidate>
      <DialogHeader>
        <DialogTitle>{editing ? "Change adjustment" : "Add adjustment"}</DialogTitle>
        <DialogDescription>
          A bonus adds to the month's pay; an advance already paid takes off it. Adjustments stay when the run is
          regenerated.
        </DialogDescription>
      </DialogHeader>
      <fieldset disabled={save.isPending} className="py-4">
        <FieldGroup>
          <Field data-invalid={errors.userId ? true : undefined}>
            <FieldLabel htmlFor="adjustment-person">Person</FieldLabel>
            {editing || target.userId ? (
              <Input id="adjustment-person" value={person ? fullName(person) : "Former member"} readOnly disabled />
            ) : (
              <Select value={values.userId} onValueChange={(userId) => setValues({ ...values, userId })}>
                <SelectTrigger id="adjustment-person" className="w-full" aria-invalid={errors.userId ? true : undefined}>
                  <SelectValue placeholder="Choose a person" />
                </SelectTrigger>
                <SelectContent>
                  {members.map((member) => (
                    <SelectItem key={member.userId} value={member.userId}>
                      {fullName(member)}
                      {member.deactivatedAt !== null && " (deactivated)"}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            )}
            {errors.userId && <FieldError>{errors.userId}</FieldError>}
          </Field>
          <Field data-invalid={errors.label ? true : undefined}>
            <FieldLabel htmlFor="adjustment-label">What for</FieldLabel>
            <Input
              id="adjustment-label"
              value={values.label}
              maxLength={ADJUSTMENT_LABEL_MAX_LENGTH}
              placeholder="Bonus, Advance paid 10 Oct…"
              autoComplete="off"
              onChange={(event) => setValues({ ...values, label: event.target.value })}
              aria-invalid={errors.label ? true : undefined}
            />
            {errors.label && <FieldError>{errors.label}</FieldError>}
          </Field>
          <Field data-invalid={errors.amount ? true : undefined}>
            <FieldLabel htmlFor="adjustment-amount">Amount</FieldLabel>
            <MoneyInput
              id="adjustment-amount"
              currency={run.currency}
              allowNegative
              value={values.amount}
              aria-invalid={errors.amount ? true : undefined}
              onValueChange={(amount, error) => {
                setValues({ ...values, amount })
                setErrors((current) => withFieldError(current, "amount", error ? moneyErrorMessage(error, run.currency) : null))
              }}
            />
            {errors.amount ? (
              <FieldError>{errors.amount}</FieldError>
            ) : (
              <FieldDescription>Gross. Use a minus for a deduction, e.g. −200.</FieldDescription>
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
          {save.isPending ? "Saving…" : editing ? "Save" : "Add adjustment"}
        </Button>
      </DialogFooter>
    </form>
  )
}
