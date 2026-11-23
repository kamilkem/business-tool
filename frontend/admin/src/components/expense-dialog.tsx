import { type FormEvent, useEffect, useState } from "react"
import { unwrap } from "@business-tool/api-client"
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"
import { TriangleAlertIcon } from "lucide-react"
import { Trans, useTranslation } from "react-i18next"
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
import {
  Field,
  FieldDescription,
  FieldError,
  FieldGroup,
  FieldLabel,
  FieldLegend,
  FieldSet,
} from "@business-tool/ui/components/field"
import { Input } from "@business-tool/ui/components/input"
import { MoneyInput } from "@business-tool/ui/components/money-input"
import { Select, SelectContent, SelectItem, SelectSeparator, SelectTrigger, SelectValue } from "@business-tool/ui/components/select"
import { useLocale } from "@business-tool/ui/hooks/use-locale"
import { useOrganizationCurrency } from "@business-tool/ui/hooks/use-organization-currency"
import { useDateTimeFormat } from "@business-tool/ui/hooks/use-organization-time-zone"
import { type MinorUnits, type MoneyParseError, formatMoney, moneyErrorMessage } from "@business-tool/ui/lib/money"

import { api } from "@/lib/api"
import { type SuggestionSeller, formCategory, suggestionSeller } from "@/lib/category-suggestion"
import type { ExpenseCategory } from "@/lib/expense-categories"
import {
  EXPENSE_KINDS,
  type ExpenseBody,
  type ExpenseFormErrors,
  type ExpenseFormValues,
  type ExpenseKind,
  type ExpenseVatLineValues,
  INVOICE_NUMBER_MAX_LENGTH,
  SELLER_NAME_MAX_LENGTH,
  SELLER_TAX_ID_MAX_LENGTH,
  breakdownMismatch,
  breakdownTotals,
  emptyExpenseForm,
  expenseBody,
  expenseFormErrors,
  expenseFormFrom,
  formNet,
  isForeign,
  normalizeCurrency,
} from "@/lib/expense-form"
import { type Expense, categoryOptions, isCorrection, kindLabel, vatRateLabel } from "@/lib/expenses"
import type { VatRate } from "@/lib/income"
import { expenseCategorySuggestionQuery } from "@/lib/queries"

/** A new expense, or a manual one to change. */
export type ExpenseTarget = { kind: "add" } | { kind: "edit"; expense: Expense }

interface ExpenseDialogProps {
  organizationId: string
  activeCategories: readonly ExpenseCategory[]
  /** The rates of the organization's country; none hides the VAT breakdown. */
  vatRates: readonly VatRate[]
  /** The dialog is open while there is a target. */
  target: ExpenseTarget | null
  onSaved: (expense: Expense) => void
  onClose: () => void
}

/** Adds an expense by hand (POST /expenses) or changes one (PATCH /expenses/{id}). */
export function ExpenseDialog({ target, onClose, ...props }: ExpenseDialogProps) {
  return (
    <Dialog open={target !== null} onOpenChange={(open) => !open && onClose()}>
      <DialogContent className="max-h-[calc(100dvh-2rem)] overflow-y-auto sm:max-w-2xl">
        {/* Keyed so each opening starts from the target's values. */}
        {target && (
          <ExpenseForm key={target.kind === "edit" ? target.expense.id : "add"} target={target} onDone={onClose} {...props} />
        )}
      </DialogContent>
    </Dialog>
  )
}

/** Radix Select values cannot be empty: this one stands for "no category". */
const NONE = "none"

function ExpenseForm({
  organizationId,
  activeCategories,
  vatRates,
  target,
  onSaved,
  onDone,
}: Omit<ExpenseDialogProps, "target" | "onClose"> & { target: ExpenseTarget; onDone: () => void }) {
  const queryClient = useQueryClient()
  const format = useDateTimeFormat()
  const locale = useLocale()
  const { t } = useTranslation("admin")
  const organizationCurrency = useOrganizationCurrency()
  const editing = target.kind === "edit" ? target.expense : null

  const [values, setValues] = useState<ExpenseFormValues>(() =>
    editing ? expenseFormFrom(editing) : emptyExpenseForm(organizationCurrency, format.today()),
  )
  const [errors, setErrors] = useState<ExpenseFormErrors>({})
  // Until a person picks a category (an edited expense with one counts), the seller's usual one is
  // pre-filled (#55) and saved with the form, which confirms it.
  const [categoryChosen, setCategoryChosen] = useState(editing?.categoryId != null)
  const seller = useDebounced(suggestionSeller(values), 400)
  const { data: suggestion } = useQuery({
    ...expenseCategorySuggestionQuery(organizationId, seller),
    enabled: seller !== null && !categoryChosen,
  })
  const category = formCategory({
    chosen: categoryChosen,
    categoryId: values.categoryId,
    suggestion: seller === null ? null : suggestion,
    activeCategories,
  })
  // Text in a money field that is not an amount, by field; the form is not sent while there is any.
  const [parseErrors, setParseErrors] = useState<Record<string, MoneyParseError>>({})

  const typedCurrency = normalizeCurrency(values.currency)
  // Amounts are typed in the expense's currency once it reads as a code.
  const currency = /^[A-Z]{3}$/.test(typedCurrency) ? typedCurrency : organizationCurrency
  const foreign = isForeign(values, organizationCurrency)
  const negative = isCorrection(values.kind)
  const money = (minor: MinorUnits) => formatMoney(minor, currency, locale)
  const categories = categoryOptions(activeCategories, editing ?? { categoryId: null, categoryName: null })

  function set<K extends keyof ExpenseFormValues>(field: K, value: ExpenseFormValues[K], ...cleared: string[]) {
    setValues((prev) => ({ ...prev, [field]: value }))
    clearErrors(field, ...cleared)
  }

  function clearErrors(...fields: string[]) {
    setErrors((prev) => {
      const next = { ...prev }
      for (const field of fields) delete next[field]
      delete next.form
      return next
    })
  }

  function setParseError(field: string, error: MoneyParseError | null) {
    setParseErrors((prev) => {
      const next = { ...prev }
      if (error) next[field] = error
      else delete next[field]
      return next
    })
  }

  function setAmount(field: "grossTotal" | "vatTotal", amount: MinorUnits | null, error: MoneyParseError | null) {
    set(field, amount)
    setParseError(field, error)
  }

  function setVat(rate: string, part: keyof ExpenseVatLineValues, amount: MinorUnits | null, error: MoneyParseError | null) {
    setValues((prev) => {
      const line = prev.vatLines[rate] ?? { net: null, vat: null, vatInTaxCurrency: null }
      return { ...prev, vatLines: { ...prev.vatLines, [rate]: { ...line, [part]: amount } } }
    })
    setParseError(`vat.${rate}.${part}`, error)
    clearErrors(`vat.${rate}.net`, `vat.${rate}.vat`, `vat.${rate}.vatInTaxCurrency`, "vatLines")
  }

  async function saved(expense: Expense, message: string) {
    await queryClient.invalidateQueries({ queryKey: ["expenses", organizationId] })
    toast.success(message, { description: expense.invoiceNumber ? `${expense.sellerName}, ${expense.invoiceNumber}` : expense.sellerName })
    onSaved(expense)
    onDone()
  }

  const enter = useMutation({
    mutationFn: (body: ExpenseBody) => unwrap(api.POST("/api/expenses", { body })),
    onSuccess: (expense) => saved(expense, t("expenseForm.added")),
    onError: (error, body) => setErrors(expenseFormErrors(error, body)),
  })

  const change = useMutation({
    mutationFn: ({ id, body }: { id: string; body: ExpenseBody }) =>
      unwrap(
        api.PATCH("/api/expenses/{id}", {
          params: { path: { id } },
          // The VAT lines are replaced whole; an empty list removes the breakdown.
          body,
          headers: { "Content-Type": "application/merge-patch+json" },
        }),
      ),
    onSuccess: (expense) => saved(expense, t("expenseForm.updated")),
    onError: (error, { body }) => setErrors(expenseFormErrors(error, body)),
  })

  const pending = enter.isPending || change.isPending

  function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (Object.keys(parseErrors).length > 0) return
    const result = expenseBody({ ...values, categoryId: category.categoryId }, vatRates, organizationCurrency)
    if (!result.ok) return setErrors(result.errors)
    setErrors({})
    if (editing) change.mutate({ id: editing.id, body: result.body })
    else enter.mutate(result.body)
  }

  /** A field's message: its unreadable text first, then what the form or the API said. */
  const errorOf = (field: string, fieldCurrency = currency) => {
    const parseError = parseErrors[field]
    return parseError ? moneyErrorMessage(parseError, fieldCurrency) : errors[field]
  }
  const textError = (field: string) => errors[field]

  const net = formNet(values)
  const sums = breakdownTotals(values, vatRates)
  const mismatch = breakdownMismatch(values, vatRates)

  return (
    <form onSubmit={onSubmit} noValidate>
      <DialogHeader>
        <DialogTitle>{editing ? t("expenseForm.editTitle", { seller: editing.sellerName }) : t("expenses.add")}</DialogTitle>
        <DialogDescription>
          {editing
            ? t("expenseForm.enteredOn", { when: format.dateTime(editing.createdAt) })
            : t("expenseForm.addDescription")}
        </DialogDescription>
      </DialogHeader>

      <fieldset disabled={pending} className="py-4">
        <FieldGroup>
          <div className="grid gap-4 sm:grid-cols-2">
            <Field data-invalid={textError("sellerName") ? true : undefined} className="sm:col-span-2">
              <FieldLabel htmlFor="expense-seller">{t("expenses.seller")}</FieldLabel>
              <Input
                id="expense-seller"
                value={values.sellerName}
                maxLength={SELLER_NAME_MAX_LENGTH}
                placeholder={t("expenseForm.sellerPlaceholder")}
                aria-invalid={textError("sellerName") ? true : undefined}
                onChange={(e) => set("sellerName", e.target.value)}
              />
              {textError("sellerName") && <FieldError>{textError("sellerName")}</FieldError>}
            </Field>
            <Field data-invalid={textError("sellerTaxId") ? true : undefined}>
              <FieldLabel htmlFor="expense-tax-id">{t("expenses.sheet.sellerTaxId")}</FieldLabel>
              <Input
                id="expense-tax-id"
                value={values.sellerTaxId}
                maxLength={SELLER_TAX_ID_MAX_LENGTH}
                placeholder={t("expenseForm.optional")}
                aria-invalid={textError("sellerTaxId") ? true : undefined}
                onChange={(e) => set("sellerTaxId", e.target.value)}
              />
              {textError("sellerTaxId") && <FieldError>{textError("sellerTaxId")}</FieldError>}
            </Field>
            <Field data-invalid={textError("invoiceNumber") ? true : undefined}>
              <FieldLabel htmlFor="expense-number">{t("expenseForm.number")}</FieldLabel>
              <Input
                id="expense-number"
                value={values.invoiceNumber}
                maxLength={INVOICE_NUMBER_MAX_LENGTH}
                placeholder={t("expenseForm.optional")}
                aria-invalid={textError("invoiceNumber") ? true : undefined}
                onChange={(e) => set("invoiceNumber", e.target.value)}
              />
              {textError("invoiceNumber") && <FieldError>{textError("invoiceNumber")}</FieldError>}
            </Field>
            <Field data-invalid={textError("issueDate") ? true : undefined}>
              <FieldLabel htmlFor="expense-date">{t("expenses.sheet.issueDate")}</FieldLabel>
              <Input
                id="expense-date"
                type="date"
                value={values.issueDate}
                aria-invalid={textError("issueDate") ? true : undefined}
                onChange={(e) => set("issueDate", e.target.value)}
              />
              {textError("issueDate") && <FieldError>{textError("issueDate")}</FieldError>}
            </Field>
            <Field data-invalid={textError("kind") ? true : undefined}>
              <FieldLabel htmlFor="expense-kind">{t("expenses.sheet.kind")}</FieldLabel>
              <Select value={values.kind} onValueChange={(kind) => set("kind", kind as ExpenseKind, "grossTotal", "vatTotal")}>
                <SelectTrigger id="expense-kind" className="w-full" aria-invalid={textError("kind") ? true : undefined}>
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {EXPENSE_KINDS.map((kind) => (
                    <SelectItem key={kind} value={kind}>
                      {kindLabel(kind)}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
              {textError("kind") && <FieldError>{textError("kind")}</FieldError>}
            </Field>
            <Field data-invalid={textError("currency") ? true : undefined}>
              <FieldLabel htmlFor="expense-currency">{t("settings.currency")}</FieldLabel>
              <Input
                id="expense-currency"
                value={values.currency}
                maxLength={3}
                autoCapitalize="characters"
                className="uppercase"
                aria-invalid={textError("currency") ? true : undefined}
                onChange={(e) => set("currency", e.target.value.toUpperCase())}
              />
              {foreign ? (
                <FieldDescription>{t("expenseForm.foreign", { currency: organizationCurrency })}</FieldDescription>
              ) : null}
              {textError("currency") && <FieldError>{textError("currency")}</FieldError>}
            </Field>
            <Field data-invalid={textError("categoryId") ? true : undefined}>
              <FieldLabel htmlFor="expense-category">{t("expenses.category")}</FieldLabel>
              <Select
                value={category.categoryId ?? NONE}
                onValueChange={(value) => {
                  setCategoryChosen(true)
                  set("categoryId", value === NONE ? null : value)
                }}
              >
                <SelectTrigger id="expense-category" className="w-full" aria-invalid={textError("categoryId") ? true : undefined}>
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value={NONE}>{t("expenses.uncategorized")}</SelectItem>
                  {categories.length > 0 && <SelectSeparator />}
                  {categories.map((option) => (
                    <SelectItem key={option.id} value={option.id} disabled={option.archived}>
                      {option.name}
                      {option.archived && <span className="text-muted-foreground"> {t("expenses.archived")}</span>}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
              {category.suggested && <FieldDescription>{t("expenseForm.categorySuggested")}</FieldDescription>}
              {textError("categoryId") && <FieldError>{textError("categoryId")}</FieldError>}
            </Field>
          </div>

          <FieldSet>
            <FieldLegend variant="label">{t("expenseForm.totals")}</FieldLegend>
            <FieldDescription>
              {negative ? t("expenseForm.totalsDescriptionCorrection") : t("expenseForm.totalsDescription")}
            </FieldDescription>
            <div className="grid gap-4 sm:grid-cols-3">
              <Field data-invalid={errorOf("grossTotal") ? true : undefined}>
                <FieldLabel htmlFor="expense-gross">{t("income.vat.gross")}</FieldLabel>
                <MoneyInput
                  id="expense-gross"
                  currency={currency}
                  allowNegative={negative}
                  value={values.grossTotal}
                  aria-invalid={errorOf("grossTotal") ? true : undefined}
                  onValueChange={(amount, error) => setAmount("grossTotal", amount, error)}
                />
                {errorOf("grossTotal") && <FieldError>{errorOf("grossTotal")}</FieldError>}
              </Field>
              <Field data-invalid={errorOf("vatTotal") ? true : undefined}>
                <FieldLabel htmlFor="expense-vat">{t("income.vat.vat")}</FieldLabel>
                <MoneyInput
                  id="expense-vat"
                  currency={currency}
                  allowNegative={negative}
                  value={values.vatTotal}
                  aria-invalid={errorOf("vatTotal") ? true : undefined}
                  onValueChange={(amount, error) => setAmount("vatTotal", amount, error)}
                />
                {errorOf("vatTotal") && <FieldError>{errorOf("vatTotal")}</FieldError>}
              </Field>
              <div className="flex flex-col gap-3">
                <span className="text-sm font-medium">{t("income.vat.net")}</span>
                <span className="flex h-9 items-center text-sm tabular-nums" data-testid="expense-net">
                  {net === null ? "—" : money(net)}
                </span>
              </div>
            </div>
          </FieldSet>

          {vatRates.length > 0 && (
            <FieldSet>
              <FieldLegend variant="label">{t("income.entry.vatBreakdown")}</FieldLegend>
              <FieldDescription>{t("expenseForm.breakdownDescription")}</FieldDescription>
              <div
                className={
                  foreign
                    ? "grid grid-cols-[3.5rem_1fr_1fr_1fr] items-start gap-x-3 gap-y-2"
                    : "grid grid-cols-[3.5rem_1fr_1fr] items-start gap-x-3 gap-y-2"
                }
              >
                <span />
                <span className="text-xs font-medium text-muted-foreground">{t("income.vat.net")}</span>
                <span className="text-xs font-medium text-muted-foreground">{t("income.vat.vat")}</span>
                {foreign && <span className="text-xs font-medium text-muted-foreground">{t("expenses.sheet.vatIn", { currency: organizationCurrency })}</span>}
                {vatRates.map((rate) => (
                  <VatRow
                    key={rate.code}
                    rate={rate}
                    currency={currency}
                    taxCurrency={organizationCurrency}
                    foreign={foreign}
                    negative={negative}
                    line={values.vatLines[rate.code] ?? { net: null, vat: null, vatInTaxCurrency: null }}
                    errorOf={(part) => errorOf(`vat.${rate.code}.${part}`, part === "vatInTaxCurrency" ? organizationCurrency : currency)}
                    onChange={(part, amount, parseError) => setVat(rate.code, part, amount, parseError)}
                  />
                ))}
              </div>
              {textError("vatLines") && <FieldError>{textError("vatLines")}</FieldError>}
              {sums && (
                <p className="text-sm">
                  <Trans
                    t={t}
                    i18nKey="expenseForm.breakdownSums"
                    values={{ net: money(sums.net), vat: money(sums.vat), gross: money(sums.gross) }}
                    components={{ amount: <span className="font-medium tabular-nums" /> }}
                  />
                </p>
              )}
              {sums && (mismatch || values.grossTotal === null || values.vatTotal === null) && (
                <div
                  data-testid="breakdown-mismatch"
                  className="flex flex-wrap items-start gap-2 rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-900 dark:bg-amber-950/40 dark:text-amber-200"
                >
                  <TriangleAlertIcon className="mt-0.5 size-4 shrink-0" />
                  <span className="flex-1">
                    {mismatch ? t("expenseForm.mismatch") : t("expenseForm.totalsEmpty")}
                  </span>
                  <Button
                    type="button"
                    variant="link"
                    className="h-auto p-0 text-inherit underline"
                    onClick={() => {
                      setValues((prev) => ({ ...prev, grossTotal: sums.gross, vatTotal: sums.vat }))
                      clearErrors("grossTotal", "vatTotal")
                    }}
                  >
                    {t("expenseForm.useBreakdown")}
                  </Button>
                </div>
              )}
            </FieldSet>
          )}

          {errors.form && (
            <Field data-invalid>
              <FieldError>{errors.form}</FieldError>
            </Field>
          )}
        </FieldGroup>
      </fieldset>

      <DialogFooter>
        <Button type="button" variant="outline" disabled={pending} onClick={onDone}>
          {t("common.cancel")}
        </Button>
        <Button type="submit" disabled={pending}>
          {pending ? t("common.saving", { ns: "ui" }) : editing ? t("common.save") : t("expenses.add")}
        </Button>
      </DialogFooter>
    </form>
  )
}

function VatRow({
  rate,
  currency,
  taxCurrency,
  foreign,
  negative,
  line,
  errorOf,
  onChange,
}: {
  rate: VatRate
  currency: string
  taxCurrency: string
  foreign: boolean
  negative: boolean
  line: ExpenseVatLineValues
  errorOf: (part: keyof ExpenseVatLineValues) => string | undefined
  onChange: (part: keyof ExpenseVatLineValues, amount: MinorUnits | null, error: MoneyParseError | null) => void
}) {
  const { t } = useTranslation("admin")
  const label = vatRateLabel(rate.code)
  // A rate without VAT (0%, exempt) always has 0 VAT; the API refuses anything else.
  const zero = (value: MinorUnits | null) => (rate.zeroVat ? (line.net === null ? null : 0) : value)

  const cell = (part: keyof ExpenseVatLineValues, ariaLabel: string, cellCurrency: string, value: MinorUnits | null, disabled = false) => (
    <div className="flex flex-col gap-1">
      <MoneyInput
        aria-label={ariaLabel}
        currency={cellCurrency}
        allowNegative={negative}
        value={value}
        disabled={disabled}
        placeholder={disabled ? t("income.entry.noVat") : undefined}
        aria-invalid={errorOf(part) ? true : undefined}
        onValueChange={(amount, error) => onChange(part, amount, error)}
      />
      {errorOf(part) && <FieldError>{errorOf(part)}</FieldError>}
    </div>
  )

  return (
    <>
      <span className="pt-2 text-sm font-medium tabular-nums">{label}</span>
      {cell("net", t("expenseForm.netAt", { rate: label }), currency, line.net)}
      {cell("vat", t("income.entry.vatAt", { rate: label }), currency, zero(line.vat), rate.zeroVat)}
      {foreign && cell("vatInTaxCurrency", t("expenseForm.vatInAt", { currency: taxCurrency, rate: label }), taxCurrency, zero(line.vatInTaxCurrency), rate.zeroVat)}
    </>
  )
}

/** The seller to ask about once typing has paused for the delay; compared by value, not identity. */
function useDebounced(seller: SuggestionSeller | null, delayMs: number): SuggestionSeller | null {
  const key = JSON.stringify(seller)
  const [debounced, setDebounced] = useState(key)
  useEffect(() => {
    const timer = window.setTimeout(() => setDebounced(key), delayMs)
    return () => window.clearTimeout(timer)
  }, [key, delayMs])
  return JSON.parse(debounced) as SuggestionSeller | null
}
