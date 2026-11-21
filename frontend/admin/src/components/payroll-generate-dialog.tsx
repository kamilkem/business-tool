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
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@business-tool/ui/components/select"
import { useDateTimeFormat } from "@business-tool/ui/hooks/use-organization-time-zone"
import type { BusinessPeriod } from "@business-tool/ui/lib/date-time"

import { api } from "@/lib/api"
import {
  type FormErrors,
  type PayrollRun,
  ROUNDING_DIRECTIONS,
  ROUNDING_UNITS,
  type RoundingDirection,
  type RoundingUnit,
  apiFormErrors,
  monthKey,
} from "@/lib/payroll"
import { invalidatePayrollRuns, payrollRunQuery } from "@/lib/queries"

interface PayrollGenerateDialogProps {
  organizationId: string
  period: BusinessPeriod
  /** The draft to regenerate; without one, a new draft of the month is generated. */
  run: PayrollRun | null
  open: boolean
  onClose: () => void
}

/**
 * Generates the month's draft from the ended shifts and the rates, or regenerates one, with the
 * rounding of each shift's length chosen now and kept on the run (ADR 0027 §6).
 */
export function PayrollGenerateDialog({ organizationId, period, run, open, onClose }: PayrollGenerateDialogProps) {
  return (
    <Dialog open={open} onOpenChange={(next) => !next && onClose()}>
      <DialogContent className="sm:max-w-md">
        {open && <GenerateForm organizationId={organizationId} period={period} run={run} onClose={onClose} />}
      </DialogContent>
    </Dialog>
  )
}

function GenerateForm({ organizationId, period, run, onClose }: Omit<PayrollGenerateDialogProps, "open">) {
  const queryClient = useQueryClient()
  const format = useDateTimeFormat()
  const [unit, setUnit] = useState<RoundingUnit>(run?.roundingUnit ?? "none")
  const [direction, setDirection] = useState<RoundingDirection>(run?.roundingDirection ?? "nearest")
  const [errors, setErrors] = useState<FormErrors>({})

  const generate = useMutation({
    mutationFn: () =>
      run
        ? unwrap(
            api.POST("/api/payroll-runs/{id}/regenerate", {
              params: { path: { id: run.id } },
              body: { roundingUnit: unit, roundingDirection: direction },
            }),
          )
        : unwrap(
            api.POST("/api/payroll-runs", {
              body: { month: monthKey(period), roundingUnit: unit, roundingDirection: direction },
            }),
          ),
    onSuccess: async (saved) => {
      queryClient.setQueryData(payrollRunQuery(organizationId, saved.id).queryKey, saved)
      toast.success(run ? "Draft regenerated" : "Draft generated", { description: format.period(period) })
      onClose()
      await invalidatePayrollRuns(queryClient, organizationId)
    },
    onError: (error) => setErrors(apiFormErrors(error, { month: "form", roundingUnit: "unit", roundingDirection: "direction" })),
  })

  function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setErrors({})
    generate.mutate()
  }

  return (
    <form onSubmit={onSubmit} noValidate>
      <DialogHeader>
        <DialogTitle>
          {run ? "Regenerate" : "Generate"} payroll: {format.period(period)}
        </DialogTitle>
        <DialogDescription>
          {run
            ? "Rebuilds the paychecks from the current shifts and rates. Adjustments stay."
            : "One paycheck per person with ended shifts this month, one line per shift, at each shift's rate."}
        </DialogDescription>
      </DialogHeader>
      <fieldset disabled={generate.isPending} className="py-4">
        <FieldGroup>
          <Field data-invalid={errors.unit ? true : undefined}>
            <FieldLabel htmlFor="rounding-unit">Round each shift to</FieldLabel>
            <Select value={unit} onValueChange={(value) => setUnit(value as RoundingUnit)}>
              <SelectTrigger id="rounding-unit" className="w-full">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                {ROUNDING_UNITS.map((option) => (
                  <SelectItem key={option.value} value={option.value}>
                    {option.label}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
            {errors.unit && <FieldError>{errors.unit}</FieldError>}
          </Field>
          <Field data-invalid={errors.direction ? true : undefined} data-disabled={unit === "none" ? true : undefined}>
            <FieldLabel htmlFor="rounding-direction">Direction</FieldLabel>
            <Select value={direction} onValueChange={(value) => setDirection(value as RoundingDirection)} disabled={unit === "none"}>
              <SelectTrigger id="rounding-direction" className="w-full">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                {ROUNDING_DIRECTIONS.map((option) => (
                  <SelectItem key={option.value} value={option.value}>
                    {option.label}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
            {errors.direction ? (
              <FieldError>{errors.direction}</FieldError>
            ) : (
              <FieldDescription>
                {unit === "none" ? "Shifts are paid to the second." : "To the nearest rounds a half up."}
              </FieldDescription>
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
        <Button type="submit" disabled={generate.isPending}>
          {generate.isPending ? "Working…" : run ? "Regenerate" : "Generate draft"}
        </Button>
      </DialogFooter>
    </form>
  )
}
