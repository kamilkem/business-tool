import { useState, type FormEvent } from "react"
import { unwrap } from "@business-tool/api-client"
import { useMutation, useQueryClient } from "@tanstack/react-query"
import { Link } from "@tanstack/react-router"
import { Trash2Icon, TriangleAlertIcon } from "lucide-react"
import { useTranslation } from "react-i18next"
import { toast } from "sonner"

import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from "@business-tool/ui/components/alert-dialog"
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
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@business-tool/ui/components/select"
import { Textarea } from "@business-tool/ui/components/textarea"
import { useLocale } from "@business-tool/ui/hooks/use-locale"
import { useOrganizationCurrency } from "@business-tool/ui/hooks/use-organization-currency"
import { useDateTimeFormat } from "@business-tool/ui/hooks/use-organization-time-zone"
import { type MinorUnits, type MoneyParseError, formatMoney, moneyErrorMessage } from "@business-tool/ui/lib/money"
import { requestErrorMessage } from "@business-tool/ui/lib/request-error"

import { api } from "@/lib/api"
import {
  type EntryFormErrors,
  type EntryFormValues,
  type EntryLines,
  NOTE_MAX_LENGTH,
  type RevenueEntry,
  type VatLineValues,
  type VatRate,
  emptyEntryForm,
  entryFormErrors,
  entryFormFrom,
  entryLines,
  formPaymentsTotal,
  formVatGross,
  isDuplicateEntry,
  vatDifference,
  vatRateLabel,
} from "@/lib/income"
import { type PaymentMethod, entryFormMethods } from "@/lib/payment-methods"
import { invalidateRevenue, revenueEntriesQuery } from "@/lib/queries"
import type { Register } from "@/lib/registers"

/** A new entry (optionally for a register and day already picked, e.g. an empty cell) or an existing one. */
export type RevenueEntryTarget =
  | { kind: "add"; registerId?: string; businessDate?: string }
  | { kind: "edit"; entry: RevenueEntry }

interface RevenueEntryDialogProps {
  organizationId: string
  /** The registers that take new entries (GET /registers): a deactivated one is refused (422). */
  activeRegisters: Register[]
  /**
   * The organization's payment methods, deactivated ones included (GET /payment-methods): the form
   * offers the active ones in order, and keeps a deactivated one only on an entry that has it.
   */
  paymentMethods: PaymentMethod[]
  /** The rates of the organization's country; none hides the VAT breakdown. */
  vatRates: VatRate[]
  /** Who recorded or changed an entry: a name, or null for the signed-in user. */
  nameOf: (userId: string) => string | null
  /** The dialog is open while there is a target. */
  target: RevenueEntryTarget | null
  /** Switches the dialog to another target (the existing entry after a 409). */
  onTargetChange: (target: RevenueEntryTarget) => void
  onClose: () => void
}

export function RevenueEntryDialog({ target, onClose, ...props }: RevenueEntryDialogProps) {
  return (
    <Dialog open={target !== null} onOpenChange={(open) => !open && onClose()}>
      <DialogContent className="max-h-[calc(100dvh-2rem)] overflow-y-auto sm:max-w-xl">
        {/* Keyed so each opening starts from the target's values. */}
        {target && (
          <EntryForm
            key={target.kind === "edit" ? target.entry.id : `add-${target.registerId ?? ""}-${target.businessDate ?? ""}`}
            target={target}
            onDone={onClose}
            {...props}
          />
        )}
      </DialogContent>
    </Dialog>
  )
}

function EntryForm({
  organizationId,
  activeRegisters,
  paymentMethods,
  vatRates,
  nameOf,
  target,
  onTargetChange,
  onDone,
}: Omit<RevenueEntryDialogProps, "target" | "onClose"> & { target: RevenueEntryTarget; onDone: () => void }) {
  const queryClient = useQueryClient()
  const format = useDateTimeFormat()
  const locale = useLocale()
  const { t } = useTranslation("admin")
  const organizationCurrency = useOrganizationCurrency()
  const editing = target.kind === "edit" ? target.entry : null
  // An entry keeps the currency it was recorded in (ADR 0022 §5).
  const currency = editing?.currency ?? organizationCurrency
  const money = (minor: MinorUnits) => formatMoney(minor, currency, locale)
  const today = format.today()
  // Fixed while the form is open: a method deactivated meanwhile keeps its row (the API refuses it).
  const [methods] = useState(() => entryFormMethods(paymentMethods, editing?.payments))

  const [values, setValues] = useState<EntryFormValues>(() => {
    if (editing) return entryFormFrom(editing)
    const registerId =
      target.kind === "add" && target.registerId
        ? target.registerId
        : activeRegisters.length === 1
          ? (activeRegisters[0]?.id ?? "")
          : ""
    return emptyEntryForm(registerId, (target.kind === "add" && target.businessDate) || today)
  })
  const [errors, setErrors] = useState<EntryFormErrors>({})
  // Text in a money field that is not an amount, by field; the form is not sent while there is any.
  const [parseErrors, setParseErrors] = useState<Record<string, MoneyParseError>>({})
  const [duplicate, setDuplicate] = useState(false)
  const [confirmDelete, setConfirmDelete] = useState(false)

  const registerName = (id: string) => activeRegisters.find((r) => r.id === id)?.name ?? t("income.entry.thisRegister")

  function clearErrors(...fields: string[]) {
    setErrors((prev) => {
      const next = { ...prev }
      for (const field of fields) delete next[field]
      delete next.form
      return next
    })
    setDuplicate(false)
  }

  function setParseError(field: string, error: MoneyParseError | null) {
    setParseErrors((prev) => {
      const next = { ...prev }
      if (error) next[field] = error
      else delete next[field]
      return next
    })
  }

  function setPayment(methodId: string, amount: MinorUnits | null, error: MoneyParseError | null) {
    setValues((prev) => ({ ...prev, payments: { ...prev.payments, [methodId]: amount } }))
    setParseError(`payment.${methodId}`, error)
    clearErrors(`payment.${methodId}`, "payments")
  }

  function setVat(rate: string, part: keyof VatLineValues, amount: MinorUnits | null, error: MoneyParseError | null) {
    setValues((prev) => {
      const line = prev.vatLines[rate] ?? { gross: null, vat: null }
      return { ...prev, vatLines: { ...prev.vatLines, [rate]: { ...line, [part]: amount } } }
    })
    setParseError(`vat.${rate}.${part}`, error)
    clearErrors(`vat.${rate}.gross`, `vat.${rate}.vat`, "vatLines")
  }

  async function saved(entry: RevenueEntry, message: string) {
    await invalidateRevenue(queryClient, organizationId)
    toast.success(message)
    onDone()
    return entry
  }

  function onError(error: Error, lines: EntryLines) {
    if (isDuplicateEntry(error)) {
      setDuplicate(true)
      return
    }
    setErrors(entryFormErrors(error, lines))
  }

  const record = useMutation({
    mutationFn: (lines: EntryLines) =>
      unwrap(
        api.POST("/api/revenue-entries", {
          body: {
            registerId: values.registerId,
            businessDate: values.businessDate,
            ...lines,
            note: values.note.trim() || null,
          },
        }),
      ),
    onSuccess: (entry) =>
      saved(entry, t("income.entry.toast.added", { register: entry.registerName, date: format.businessDate(entry.businessDate) })),
    onError,
  })

  const update = useMutation({
    mutationFn: ({ id, lines }: { id: string; lines: EntryLines }) =>
      unwrap(
        api.PATCH("/api/revenue-entries/{id}", {
          params: { path: { id } },
          // The lists are replaced whole; an empty vatLines removes the breakdown.
          body: { ...lines, note: values.note.trim() || null },
          headers: { "Content-Type": "application/merge-patch+json" },
        }),
      ),
    onSuccess: (entry) =>
      saved(entry, t("income.entry.toast.updated", { register: entry.registerName, date: format.businessDate(entry.businessDate) })),
    onError: (error, { lines }) => onError(error, lines),
  })

  const remove = useMutation({
    mutationFn: (entry: RevenueEntry) =>
      unwrap(api.DELETE("/api/revenue-entries/{id}", { params: { path: { id: entry.id } } })),
    onSuccess: async (_, entry) => {
      await invalidateRevenue(queryClient, organizationId)
      toast.success(t("income.entry.toast.deleted", { register: entry.registerName, date: format.businessDate(entry.businessDate) }))
      onDone()
    },
    onError: (error) => setErrors({ form: requestErrorMessage(error) }),
  })

  const openExisting = useMutation({
    mutationFn: () =>
      queryClient.fetchQuery(
        revenueEntriesQuery(organizationId, { from: values.businessDate, to: values.businessDate }, values.registerId),
      ),
    onSuccess: (entries) => {
      const existing = entries[0]
      if (existing) onTargetChange({ kind: "edit", entry: existing })
      else setErrors({ form: t("income.entry.deletedMeanwhile") })
    },
    onError: (error) => setErrors({ form: requestErrorMessage(error) }),
  })

  const pending = record.isPending || update.isPending || remove.isPending

  function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setDuplicate(false)
    if (Object.keys(parseErrors).length > 0) return

    const result = entryLines(values, methods, vatRates, today)
    if (!result.ok) return setErrors(result.errors)
    setErrors({})
    if (editing) update.mutate({ id: editing.id, lines: result.lines })
    else record.mutate(result.lines)
  }

  /** A field's message: its unreadable text first, then what the form or the API said. */
  const errorOf = (field: string) => {
    const parseError = parseErrors[field]
    return parseError ? moneyErrorMessage(parseError, currency) : errors[field]
  }

  const paymentsTotal = formPaymentsTotal(values, methods)
  const vatGross = formVatGross(values, vatRates)
  const difference = vatDifference(paymentsTotal, vatGross.total, vatGross.hasLines)
  const by = (userId: string, when: string, key: "createdBy" | "updatedBy") => {
    const name = nameOf(userId)
    return name === null ? t(`income.entry.${key}You`, { when }) : t(`income.entry.${key}`, { name, when })
  }

  return (
    <form onSubmit={onSubmit} noValidate>
      <DialogHeader>
        <DialogTitle>
          {editing ? `${editing.registerName}, ${format.businessDate(editing.businessDate)}` : t("income.entry.addTitle")}
        </DialogTitle>
        <DialogDescription>
          {editing ? (
            <>
              {by(editing.createdBy, format.dateTime(editing.createdAt), "createdBy")}
              {editing.updatedBy && editing.updatedAt && `; ${by(editing.updatedBy, format.dateTime(editing.updatedAt), "updatedBy")}`}
              . {t("income.entry.fixed")}
            </>
          ) : (
            t("income.entry.addDescription")
          )}
        </DialogDescription>
      </DialogHeader>

      <fieldset disabled={pending} className="py-4">
        <FieldGroup>
          {!editing && (
            <div className="grid gap-4 sm:grid-cols-2">
              <Field data-invalid={errors.registerId ? true : undefined}>
                <FieldLabel htmlFor="entry-register">{t("income.entry.register")}</FieldLabel>
                <Select
                  value={values.registerId}
                  onValueChange={(registerId) => {
                    setValues((prev) => ({ ...prev, registerId }))
                    clearErrors("registerId")
                  }}
                >
                  <SelectTrigger
                    id="entry-register"
                    className="w-full"
                    aria-invalid={errors.registerId ? true : undefined}
                  >
                    <SelectValue placeholder={t("income.entry.chooseRegister")} />
                  </SelectTrigger>
                  <SelectContent>
                    {activeRegisters.map((register) => (
                      <SelectItem key={register.id} value={register.id}>
                        {register.name}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
                {errors.registerId && <FieldError>{errors.registerId}</FieldError>}
              </Field>
              <Field data-invalid={errors.businessDate ? true : undefined}>
                <FieldLabel htmlFor="entry-date">{t("income.days.day")}</FieldLabel>
                <Input
                  id="entry-date"
                  type="date"
                  max={today}
                  value={values.businessDate}
                  aria-invalid={errors.businessDate ? true : undefined}
                  onChange={(e) => {
                    const businessDate = e.target.value
                    setValues((prev) => ({ ...prev, businessDate }))
                    clearErrors("businessDate")
                  }}
                />
                {errors.businessDate && <FieldError>{errors.businessDate}</FieldError>}
              </Field>
            </div>
          )}

          {duplicate && (
            <div
              role="alert"
              className="flex flex-wrap items-center gap-x-2 gap-y-1 rounded-md border border-destructive/40 bg-destructive/5 px-3 py-2 text-sm"
            >
              <span>
                {t("income.entry.duplicate", { register: registerName(values.registerId), date: format.businessDate(values.businessDate) })}
              </span>
              <Button
                type="button"
                variant="link"
                className="h-auto p-0"
                disabled={openExisting.isPending}
                onClick={() => openExisting.mutate()}
              >
                {t("income.entry.openExisting")}
              </Button>
            </div>
          )}

          <FieldSet>
            <FieldLegend variant="label">{t("income.summary.payments")}</FieldLegend>
            <FieldDescription>{t("income.entry.paymentsDescription")}</FieldDescription>
            {methods.length === 0 ? (
              <div role="alert" className="flex items-start gap-2 rounded-md border px-3 py-2 text-sm">
                <TriangleAlertIcon className="mt-0.5 size-4 shrink-0 text-amber-600" />
                <span>
                  {t("income.entry.noPaymentMethods")}{" "}
                  <Link to="/income/payment-methods" className="font-medium underline underline-offset-4">
                    {t("income.entry.managePaymentMethods")}
                  </Link>
                </span>
              </div>
            ) : (
              <div className="grid gap-4 sm:grid-cols-2">
                {methods.map((method) => {
                  const error = errorOf(`payment.${method.id}`)
                  return (
                    <Field key={method.id} data-invalid={error ? true : undefined}>
                      <FieldLabel htmlFor={`entry-payment-${method.id}`}>{method.name}</FieldLabel>
                      <MoneyInput
                        id={`entry-payment-${method.id}`}
                        currency={currency}
                        value={values.payments[method.id] ?? null}
                        aria-invalid={error ? true : undefined}
                        onValueChange={(amount, parseError) => setPayment(method.id, amount, parseError)}
                      />
                      {error ? (
                        <FieldError>{error}</FieldError>
                      ) : (
                        !method.active && <FieldDescription>{t("income.entry.deactivatedMethod")}</FieldDescription>
                      )}
                    </Field>
                  )
                })}
              </div>
            )}
            {errors.payments && <FieldError>{errors.payments}</FieldError>}
            <p className="text-sm">
              {t("income.total")} <span className="font-medium tabular-nums">{money(paymentsTotal)}</span>
            </p>
          </FieldSet>

          {vatRates.length > 0 && (
            <FieldSet>
              <FieldLegend variant="label">{t("income.entry.vatBreakdown")}</FieldLegend>
              <FieldDescription>{t("income.entry.vatBreakdownDescription")}</FieldDescription>
              <div className="grid grid-cols-[3.5rem_1fr_1fr] items-start gap-x-3 gap-y-2 sm:grid-cols-[3.5rem_1fr_1fr_6rem]">
                <span />
                <span className="text-xs font-medium text-muted-foreground">{t("income.vat.gross")}</span>
                <span className="text-xs font-medium text-muted-foreground">{t("income.vat.vat")}</span>
                <span className="hidden text-right text-xs font-medium text-muted-foreground sm:block">{t("income.vat.net")}</span>
                {vatRates.map((rate) => (
                  <VatRow
                    key={rate.code}
                    rate={rate}
                    currency={currency}
                    line={values.vatLines[rate.code] ?? { gross: null, vat: null }}
                    grossError={errorOf(`vat.${rate.code}.gross`)}
                    vatError={errorOf(`vat.${rate.code}.vat`)}
                    onChange={(part, amount, parseError) => setVat(rate.code, part, amount, parseError)}
                  />
                ))}
              </div>
              {errors.vatLines && <FieldError>{errors.vatLines}</FieldError>}
              {vatGross.hasLines && (
                <p className="text-sm">
                  {t("income.entry.grossTotal")} <span className="font-medium tabular-nums">{money(vatGross.total)}</span>
                </p>
              )}
              {difference !== null && (
                <p
                  data-testid="vat-difference"
                  className="flex items-start gap-2 rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-900 dark:bg-amber-950/40 dark:text-amber-200"
                >
                  <TriangleAlertIcon className="mt-0.5 size-4 shrink-0" />
                  <span>
                    {t(difference > 0 ? "income.entry.vatMore" : "income.entry.vatLess", { amount: money(Math.abs(difference)) })}
                  </span>
                </p>
              )}
            </FieldSet>
          )}

          <Field data-invalid={errors.note ? true : undefined}>
            <FieldLabel htmlFor="entry-note">{t("time.shifts.note")}</FieldLabel>
            <Textarea
              id="entry-note"
              value={values.note}
              maxLength={NOTE_MAX_LENGTH}
              placeholder={t("income.entry.notePlaceholder")}
              aria-invalid={errors.note ? true : undefined}
              onChange={(e) => {
                const note = e.target.value
                setValues((prev) => ({ ...prev, note }))
                clearErrors("note")
              }}
            />
            {errors.note && <FieldError>{errors.note}</FieldError>}
          </Field>

          {errors.form && (
            <Field data-invalid>
              <FieldError>{errors.form}</FieldError>
            </Field>
          )}
        </FieldGroup>
      </fieldset>

      <DialogFooter className="sm:justify-between">
        {editing ? (
          <Button type="button" variant="ghost" className="text-destructive" disabled={pending} onClick={() => setConfirmDelete(true)}>
            <Trash2Icon />
            {t("common.delete")}
          </Button>
        ) : (
          <span />
        )}
        <Button type="submit" disabled={pending}>
          {record.isPending || update.isPending ? t("common.saving", { ns: "ui" }) : editing ? t("common.save") : t("income.addEntry")}
        </Button>
      </DialogFooter>

      {editing && (
        <AlertDialog open={confirmDelete} onOpenChange={setConfirmDelete}>
          <AlertDialogContent>
            <AlertDialogHeader>
              <AlertDialogTitle>
                {t("income.entry.deleteTitle", { register: editing.registerName, date: format.businessDate(editing.businessDate) })}
              </AlertDialogTitle>
              <AlertDialogDescription>
                {t("income.entry.deleteDescription", { amount: money(editing.paymentsTotal) })}
              </AlertDialogDescription>
            </AlertDialogHeader>
            <AlertDialogFooter>
              <AlertDialogCancel>{t("common.cancel")}</AlertDialogCancel>
              <AlertDialogAction variant="destructive" onClick={() => remove.mutate(editing)}>
                {t("common.delete")}
              </AlertDialogAction>
            </AlertDialogFooter>
          </AlertDialogContent>
        </AlertDialog>
      )}
    </form>
  )
}

function VatRow({
  rate,
  currency,
  line,
  grossError,
  vatError,
  onChange,
}: {
  rate: VatRate
  currency: string
  line: VatLineValues
  grossError?: string
  vatError?: string
  onChange: (part: keyof VatLineValues, amount: MinorUnits | null, error: MoneyParseError | null) => void
}) {
  const locale = useLocale()
  const { t } = useTranslation("admin")
  const label = vatRateLabel(rate.code)
  // A rate without VAT (0%, exempt) always has 0 VAT; the API refuses anything else.
  const vat = rate.zeroVat ? (line.gross === null ? null : 0) : line.vat
  const net = line.gross !== null && vat !== null && vat <= line.gross ? line.gross - vat : null

  return (
    <>
      <span className="pt-2 text-sm font-medium tabular-nums">{label}</span>
      <div className="flex flex-col gap-1">
        <MoneyInput
          aria-label={t("income.entry.grossAt", { rate: label })}
          currency={currency}
          value={line.gross}
          aria-invalid={grossError ? true : undefined}
          onValueChange={(amount, error) => onChange("gross", amount, error)}
        />
        {grossError && <FieldError>{grossError}</FieldError>}
      </div>
      <div className="flex flex-col gap-1">
        <MoneyInput
          aria-label={t("income.entry.vatAt", { rate: label })}
          currency={currency}
          value={vat}
          disabled={rate.zeroVat}
          placeholder={rate.zeroVat ? t("income.entry.noVat") : undefined}
          aria-invalid={vatError ? true : undefined}
          onValueChange={(amount, error) => onChange("vat", amount, error)}
        />
        {vatError && <FieldError>{vatError}</FieldError>}
      </div>
      <span className="hidden pt-2 text-right text-sm text-muted-foreground tabular-nums sm:block">
        {net === null ? "—" : formatMoney(net, currency, locale)}
      </span>
    </>
  )
}
