import { useId, useState, type FormEvent } from "react"
import { cn } from "cn"
import { CalendarIcon, ChevronLeftIcon, ChevronRightIcon } from "lucide-react"

import { Button } from "./button"
import { Field, FieldError, FieldGroup, FieldLabel } from "./field"
import { Input } from "./input"
import { Popover, PopoverContent, PopoverTrigger } from "./popover"
import { Separator } from "./separator"
import { useDateTimeFormat } from "../hooks/use-organization-time-zone"
import { addDays, isWholeMonth, monthOf, periodError, shiftPeriod, type BusinessPeriod } from "../lib/date-time"

interface PeriodPickerProps {
  /** The selected period, inclusive business dates ("YYYY-MM-DD"), e.g. from the route's search params. */
  value: BusinessPeriod
  /** Called with the new period, as `{ from, to }` for the API's `?from=&to=`. */
  onChange: (period: BusinessPeriod) => void
  /** The longest custom period the screen's endpoint accepts (e.g. 92 days for `GET /shifts`). */
  maxDays?: number
  className?: string
}

/**
 * Picks a month (the default for income, expenses, payroll and shifts) or a custom range of
 * business dates. The arrows step to the previous or next month, or by the custom range's length.
 * "This month" is the month in the organization's time zone, not the browser's.
 */
export function PeriodPicker({ value, onChange, maxDays, className }: PeriodPickerProps) {
  const format = useDateTimeFormat()
  const [open, setOpen] = useState(false)
  const unit = isWholeMonth(value) ? "month" : "period"

  function choose(period: BusinessPeriod) {
    onChange(period)
    setOpen(false)
  }

  const thisMonth = format.thisMonth()
  const lastMonth = monthOf(addDays(thisMonth.from, -1))

  return (
    <div className={cn("flex items-center gap-1", className)} role="group" aria-label="Period">
      <Button variant="outline" size="icon" onClick={() => onChange(shiftPeriod(value, -1))} aria-label={`Previous ${unit}`}>
        <ChevronLeftIcon />
      </Button>
      <Popover open={open} onOpenChange={setOpen}>
        <PopoverTrigger asChild>
          <Button variant="outline" className="min-w-44 justify-start tabular-nums">
            <CalendarIcon />
            {format.period(value)}
          </Button>
        </PopoverTrigger>
        <PopoverContent align="start" className="w-80">
          <div className="grid grid-cols-2 gap-2">
            <Button variant="secondary" onClick={() => choose(thisMonth)}>
              This month
            </Button>
            <Button variant="secondary" onClick={() => choose(lastMonth)}>
              Last month
            </Button>
          </div>
          <Separator />
          {/* Remounted on every open, so it starts from the current value. */}
          {open && <CustomRange value={value} maxDays={maxDays} onApply={choose} />}
        </PopoverContent>
      </Popover>
      <Button variant="outline" size="icon" onClick={() => onChange(shiftPeriod(value, 1))} aria-label={`Next ${unit}`}>
        <ChevronRightIcon />
      </Button>
    </div>
  )
}

function CustomRange({
  value,
  maxDays,
  onApply,
}: {
  value: BusinessPeriod
  maxDays?: number
  onApply: (period: BusinessPeriod) => void
}) {
  const id = useId()
  const [from, setFrom] = useState(value.from)
  const [to, setTo] = useState(value.to)
  const [error, setError] = useState<string | null>(null)

  function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const message = periodError({ from, to }, maxDays)
    if (message) {
      setError(message)
      return
    }
    onApply({ from, to })
  }

  return (
    <form onSubmit={submit} noValidate>
      <FieldGroup className="gap-3">
        <div className="grid grid-cols-2 gap-2">
          <Field>
            <FieldLabel htmlFor={`${id}-from`}>From</FieldLabel>
            <Input id={`${id}-from`} type="date" value={from} max={to || undefined} onChange={(e) => setFrom(e.target.value)} />
          </Field>
          <Field>
            <FieldLabel htmlFor={`${id}-to`}>To</FieldLabel>
            <Input id={`${id}-to`} type="date" value={to} min={from || undefined} onChange={(e) => setTo(e.target.value)} />
          </Field>
        </div>
        {error && <FieldError>{error}</FieldError>}
        <Button type="submit">Show these dates</Button>
      </FieldGroup>
    </form>
  )
}
