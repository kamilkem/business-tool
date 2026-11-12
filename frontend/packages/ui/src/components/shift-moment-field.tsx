import { useId } from "react"

import { Field, FieldDescription, FieldError, FieldLabel } from "./field"
import { Input } from "./input"
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "./select"
import { type Occurrence, type ShiftMoment, repeatedOffsets } from "../lib/shift-form"

/**
 * One end of a shift on the organization's wall clock: a date and a time, plus "which 02:30" when
 * the time happens twice on that day (the clocks go back).
 */
export function ShiftMomentField({
  label,
  name,
  value,
  error,
  description,
  timeZone,
  autoFocus,
  onChange,
}: {
  label: string
  name: string
  value: ShiftMoment
  error?: string
  description?: string
  timeZone: string
  /** Focus the time when the dialog opens (the clock-out of an open shift). */
  autoFocus?: boolean
  /** `dateChanged` is true when the person set the date themselves. */
  onChange: (value: ShiftMoment, dateChanged: boolean) => void
}) {
  const id = useId()
  const offsets = repeatedOffsets(value, timeZone)
  const invalid = error ? true : undefined

  return (
    <Field data-invalid={invalid}>
      <FieldLabel htmlFor={`${id}-time`}>{label}</FieldLabel>
      <div className="grid grid-cols-[1fr_8rem] gap-2">
        <Input
          id={`${id}-date`}
          name={`${name}Date`}
          type="date"
          aria-label={`${label} date`}
          aria-invalid={invalid}
          value={value.date}
          onChange={(e) => onChange({ ...value, date: e.target.value }, true)}
        />
        <Input
          id={`${id}-time`}
          name={`${name}Time`}
          type="time"
          autoFocus={autoFocus}
          aria-invalid={invalid}
          value={value.time}
          onChange={(e) => onChange({ ...value, time: e.target.value }, false)}
        />
      </div>
      {offsets && (
        <div className="flex flex-wrap items-center gap-2 text-sm">
          <span className="text-muted-foreground">{value.time} happens twice on this day (the clocks go back):</span>
          <Select
            value={value.occurrence}
            onValueChange={(occurrence) => onChange({ ...value, occurrence: occurrence as Occurrence }, false)}
          >
            <SelectTrigger size="sm" aria-label={`Which ${value.time}`}>
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="first">First, UTC{offsets[0]}</SelectItem>
              <SelectItem value="second">Second, UTC{offsets[1]}</SelectItem>
            </SelectContent>
          </Select>
        </div>
      )}
      {description && <FieldDescription>{description}</FieldDescription>}
      {error && <FieldError>{error}</FieldError>}
    </Field>
  )
}
