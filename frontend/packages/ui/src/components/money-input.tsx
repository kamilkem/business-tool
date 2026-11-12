import * as React from "react"
import { cn } from "cn"

import { Input } from "./input"
import { useLocale } from "../hooks/use-locale"
import {
  currencySymbol,
  formatMoneyAmount,
  parseMoney,
  type MinorUnits,
  type MoneyParseError,
} from "../lib/money"

type MoneyInputProps = Omit<
  React.ComponentProps<"input">,
  "type" | "value" | "defaultValue" | "onChange" | "inputMode"
> & {
  /** The amount in minor units (grosze for PLN), or null for an empty field. */
  value: MinorUnits | null
  /**
   * Called on every keystroke with the amount, or null with the reason when the text is not an
   * amount (null without a reason when the field is empty). Show `moneyErrorMessage(error, currency)`
   * in a `<FieldError>`.
   */
  onValueChange: (minor: MinorUnits | null, error: MoneyParseError | null) => void
  /** ISO 4217 code of the amount, usually `useOrganizationCurrency()`. */
  currency: string
  /** Defaults to `useLocale()`. */
  locale?: string
  /** Accept amounts below zero ("-12,50"). */
  allowNegative?: boolean
}

/**
 * A text field for an amount of money: the user types a decimal ("12,50", "12.50", "1 234,50") and
 * the form gets integer minor units (1250), never a float (ADR 0010). The currency symbol sits in
 * the field; on blur the text is rewritten in the locale's style. Put it in a `<Field>` with a
 * `<FieldLabel htmlFor>`, like any input.
 */
function MoneyInput({
  value,
  onValueChange,
  currency,
  locale: localeProp,
  allowNegative = false,
  className,
  style,
  onBlur,
  "aria-invalid": ariaInvalid,
  "aria-describedby": ariaDescribedBy,
  ...props
}: MoneyInputProps) {
  const contextLocale = useLocale()
  const locale = localeProp ?? contextLocale
  const symbolId = React.useId()

  const display = (minor: MinorUnits | null) => (minor === null ? "" : formatMoneyAmount(minor, currency, locale))
  const [text, setText] = React.useState(() => display(value))
  const parsed = parseMoney(text, currency, locale, { allowNegative })
  // What this field last told the form: the amount, or null for an empty or unreadable text.
  const emitted = parsed.ok ? parsed.minor : null

  // A new value from outside (a reset, a loaded record) replaces the text; the value this field
  // just emitted does not, so "12," stays "12," while the user types.
  const [previousValue, setPreviousValue] = React.useState(value)
  if (value !== previousValue) {
    setPreviousValue(value)
    if (value !== emitted) setText(display(value))
  }

  const { symbol, before } = currencySymbol(currency, locale)
  // Room for the symbol inside the field, by its length.
  const symbolSpace = { [before ? "paddingInlineStart" : "paddingInlineEnd"]: `calc(${symbol.length}ch + 1rem)` }

  return (
    <div className="relative w-full">
      <Input
        type="text"
        // iOS's decimal keypad has no minus sign, so a field that takes negatives gets the full keyboard.
        inputMode={allowNegative ? "text" : "decimal"}
        autoComplete="off"
        spellCheck={false}
        value={text}
        aria-invalid={ariaInvalid ?? (!parsed.ok || undefined)}
        aria-describedby={[symbolId, ariaDescribedBy].filter(Boolean).join(" ")}
        className={cn("tabular-nums", before ? "" : "text-right", className)}
        style={{ ...symbolSpace, ...style }}
        onChange={(event) => {
          const next = event.target.value
          const result = parseMoney(next, currency, locale, { allowNegative })
          setText(next)
          onValueChange(result.ok ? result.minor : null, result.ok ? null : result.error)
        }}
        onBlur={(event) => {
          if (parsed.ok && parsed.minor !== null) setText(display(parsed.minor))
          onBlur?.(event)
        }}
        {...props}
      />
      <span
        id={symbolId}
        className={cn(
          "pointer-events-none absolute inset-y-0 flex items-center text-sm text-muted-foreground",
          before ? "left-2.5" : "right-2.5",
        )}
      >
        {symbol}
      </span>
    </div>
  )
}

export { MoneyInput, type MoneyInputProps }
