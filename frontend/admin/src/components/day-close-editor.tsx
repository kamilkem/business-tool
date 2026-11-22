import { type FormEvent, useState } from "react"
import { CircleAlertIcon, LoaderCircleIcon, TriangleAlertIcon } from "lucide-react"
import { useTranslation } from "react-i18next"

import { Alert, AlertDescription } from "@business-tool/ui/components/alert"
import { Badge } from "@business-tool/ui/components/badge"
import { Button } from "@business-tool/ui/components/button"
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@business-tool/ui/components/card"
import { Field, FieldDescription, FieldError, FieldGroup, FieldLabel } from "@business-tool/ui/components/field"
import { Input } from "@business-tool/ui/components/input"
import { MoneyInput } from "@business-tool/ui/components/money-input"
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@business-tool/ui/components/select"
import { Table, TableBody, TableCell, TableFooter, TableHead, TableHeader, TableRow } from "@business-tool/ui/components/table"
import { useMoneyFormat } from "@business-tool/ui/hooks/use-organization-currency"
import { parsePieces } from "@business-tool/ui/lib/cash-count"
import type { BusinessDate } from "@business-tool/ui/lib/date-time"
import { type MoneyParseError, moneyErrorMessage } from "@business-tool/ui/lib/money"
import { cn } from "@business-tool/ui/lib/utils"

import {
  type DayCloseEditor as Editor,
  type DayCloseValues,
  type EditorStorage,
  cashIncomeOf,
  nonCashTotal,
  problemsOf,
  storageCashIncome,
  storageTotal,
  withAmount,
  withPieces,
} from "@/lib/day-closes"

/**
 * The counts, deposits, non-cash amounts and register of a close (ADR 0033 §6, §7): a correction of
 * an existing close (its date never changes) or a close a manager records in the admin (any date up
 * to today). A correction shows the cash income as it will be; a new close's comes from the API.
 */
export function DayCloseEditor({
  editor,
  currency,
  values,
  onChange,
  today,
  pending,
  error,
  submitLabel,
  onSubmit,
  onCancel,
}: {
  editor: Editor
  currency: string
  values: DayCloseValues
  onChange: (values: DayCloseValues) => void
  today: BusinessDate
  pending: boolean
  /** The API's answer to the last attempt, translated. */
  error: string | null
  submitLabel: string
  onSubmit: () => void
  onCancel: () => void
}) {
  const { t } = useTranslation("admin")
  const money = useMoneyFormat()
  const [submitted, setSubmitted] = useState(false)
  const [amountErrors, setAmountErrors] = useState<Record<string, MoneyParseError | null>>({})
  const problems = problemsOf(editor, values, today)
  const cashIncome = cashIncomeOf(editor, values)
  const deposits = editor.storages.filter((storage) => storage.mode === "deposit")
  const counted = editor.storages.filter((storage) => storage.mode === "counted")

  function submit(event: FormEvent) {
    event.preventDefault()
    setSubmitted(true)
    if (problems.length === 0) onSubmit()
  }

  return (
    <form onSubmit={submit} noValidate className="flex flex-col gap-6">
      <fieldset disabled={pending} className="flex flex-col gap-6">
        <Card>
          <CardContent>
            <FieldGroup className="grid gap-4 sm:grid-cols-2">
              {editor.kind === "record" && (
                <Field data-invalid={(submitted && problems.includes("date")) || undefined}>
                  <FieldLabel htmlFor="close-date">{t("dayCloses.editor.date")}</FieldLabel>
                  <Input
                    id="close-date"
                    type="date"
                    max={today}
                    value={values.date}
                    onChange={(event) => onChange({ ...values, date: event.target.value })}
                  />
                  {submitted && problems.includes("date") ? (
                    <FieldError>{t("dayCloses.editor.dateUpToToday")}</FieldError>
                  ) : (
                    <FieldDescription>{t("dayCloses.editor.dateHint")}</FieldDescription>
                  )}
                </Field>
              )}
              <Field data-invalid={(submitted && problems.includes("register")) || undefined}>
                <FieldLabel htmlFor="close-register">{t("dayCloses.editor.register")}</FieldLabel>
                <Select value={values.registerId ?? ""} onValueChange={(registerId) => onChange({ ...values, registerId })}>
                  <SelectTrigger id="close-register" className="w-full">
                    <SelectValue placeholder={t("dayCloses.editor.chooseRegister")} />
                  </SelectTrigger>
                  <SelectContent>
                    {editor.registers.map((register) => (
                      <SelectItem key={register.id} value={register.id}>
                        {register.name ?? t("dayCloses.unknownRegister")}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
                {submitted && problems.includes("register") ? (
                  <FieldError>{t("dayCloses.editor.chooseRegister")}</FieldError>
                ) : (
                  <FieldDescription>{t("dayCloses.editor.registerHint")}</FieldDescription>
                )}
              </Field>
            </FieldGroup>
          </CardContent>
        </Card>

        <div className="grid gap-4 lg:grid-cols-2">
          {[...deposits, ...counted].map((storage) => (
            <StorageCard key={storage.id} storage={storage} values={values} onChange={onChange} />
          ))}
        </div>

        {editor.payments.length > 0 && (
          <Card>
            <CardHeader>
              <CardTitle>{t("dayCloses.nonCash")}</CardTitle>
              <CardDescription>{t("dayCloses.editor.nonCashHint")}</CardDescription>
            </CardHeader>
            <CardContent>
              <FieldGroup className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                {editor.payments.map((method) => {
                  const id = `close-payment-${method.id}`
                  const parseError = amountErrors[method.id] ?? null
                  return (
                    <Field key={method.id} data-invalid={parseError !== null || undefined}>
                      <FieldLabel htmlFor={id} className="break-words">
                        {method.name ?? t("dayCloses.unknownMethod")}
                      </FieldLabel>
                      <MoneyInput
                        id={id}
                        placeholder="0"
                        currency={currency}
                        value={values.amounts[method.id] ?? null}
                        onValueChange={(amount, error) => {
                          setAmountErrors((current) => ({ ...current, [method.id]: error }))
                          onChange(withAmount(values, method.id, amount, error !== null))
                        }}
                      />
                      {parseError && <FieldError>{moneyErrorMessage(parseError, currency)}</FieldError>}
                    </Field>
                  )
                })}
              </FieldGroup>
            </CardContent>
          </Card>
        )}
      </fieldset>

      <Card className="sticky bottom-0 z-10 gap-3 py-4 shadow-md">
        <CardContent className="flex flex-wrap items-center justify-between gap-4">
          <dl className="flex flex-wrap gap-x-8 gap-y-2" aria-live="polite">
            <div>
              <dt className="text-sm text-muted-foreground">{t("dayCloses.cashIncome")}</dt>
              <dd className={cn("text-xl font-semibold tabular-nums", cashIncome !== null && cashIncome < 0 && "text-amber-700 dark:text-amber-400")}>
                {cashIncome === null ? t("dayCloses.editor.cashIncomeLater") : money(cashIncome)}
              </dd>
            </div>
            {editor.payments.length > 0 && (
              <div>
                <dt className="text-sm text-muted-foreground">{t("dayCloses.nonCash")}</dt>
                <dd className="text-xl font-semibold tabular-nums">{money(nonCashTotal(editor, values))}</dd>
              </div>
            )}
          </dl>
          <div className="flex gap-2">
            <Button type="button" variant="outline" onClick={onCancel} disabled={pending}>
              {t("common.cancel")}
            </Button>
            <Button type="submit" disabled={pending || (submitted && problems.length > 0)}>
              {pending && <LoaderCircleIcon className="animate-spin" />}
              {submitLabel}
            </Button>
          </div>
          {cashIncome !== null && cashIncome < 0 && (
            <p className="flex w-full items-start gap-2 text-sm text-amber-800 dark:text-amber-300">
              <TriangleAlertIcon className="mt-0.5 size-4 shrink-0" />
              {t("dayCloses.editor.shortage", { amount: money(-cashIncome) })}
            </p>
          )}
          {submitted && (problems.includes("pieces") || problems.includes("amounts")) && (
            <p role="alert" className="w-full text-sm text-destructive">
              {t("dayCloses.editor.fixFields")}
            </p>
          )}
          {error && (
            <Alert variant="destructive" className="w-full">
              <CircleAlertIcon />
              <AlertDescription>{error}</AlertDescription>
            </Alert>
          )}
        </CardContent>
      </Card>
    </form>
  )
}

/** One storage: a field of pieces per denomination, the total and, for a counted storage, its expected opening. */
function StorageCard({
  storage,
  values,
  onChange,
}: {
  storage: EditorStorage
  values: DayCloseValues
  onChange: (values: DayCloseValues) => void
}) {
  const { t } = useTranslation("admin")
  const money = useMoneyFormat()
  const pieces = values.pieces[storage.id] ?? {}
  const counted = storage.mode === "counted"
  const income = storageCashIncome(storage, values)

  return (
    <Card className="gap-0 pb-0">
      <CardHeader className="pb-4">
        <CardTitle className="flex items-center gap-2">
          <span className="break-words">{storage.name}</span>
          <Badge variant="secondary">{t(`dayCloses.mode.${storage.mode}`)}</Badge>
        </CardTitle>
        <CardDescription>{t(counted ? "dayCloses.editor.countedHint" : "dayCloses.editor.depositHint")}</CardDescription>
      </CardHeader>
      <CardContent className="px-0">
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead className="pl-6">{t("dayCloses.denomination")}</TableHead>
              <TableHead className="w-32 text-right">{t("dayCloses.pieces")}</TableHead>
              <TableHead className="pr-6 text-right">{t("dayCloses.amount")}</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {storage.denominations.map((denomination) => (
              <PiecesRow
                key={denomination.id}
                label={money(denomination.value)}
                pieces={pieces[denomination.id] ?? 0}
                amount={money(denomination.value * (pieces[denomination.id] ?? 0))}
                onChange={(next) => onChange(withPieces(values, storage.id, denomination.id, next))}
              />
            ))}
          </TableBody>
          <TableFooter>
            <TableRow>
              <TableCell className="pl-6" colSpan={2}>
                {t(counted ? "dayCloses.detail.counted" : "dayCloses.detail.deposited")}
              </TableCell>
              <TableCell className="pr-6 text-right tabular-nums">{money(storageTotal(storage, values))}</TableCell>
            </TableRow>
            {counted && storage.expectedOpening !== null && (
              <TableRow>
                <TableCell className="pl-6 font-normal text-muted-foreground" colSpan={2}>
                  {t("dayCloses.detail.expectedOpening")}
                </TableCell>
                <TableCell className="pr-6 text-right font-normal text-muted-foreground tabular-nums">
                  {money(storage.expectedOpening)}
                </TableCell>
              </TableRow>
            )}
            {income !== null && counted && (
              <TableRow>
                <TableCell className="pl-6" colSpan={2}>
                  {t("dayCloses.detail.storageCashIncome")}
                </TableCell>
                <TableCell className={cn("pr-6 text-right tabular-nums", income < 0 && "text-amber-700 dark:text-amber-400")}>
                  {money(income)}
                </TableCell>
              </TableRow>
            )}
          </TableFooter>
        </Table>
      </CardContent>
    </Card>
  )
}

function PiecesRow({
  label,
  pieces,
  amount,
  onChange,
}: {
  label: string
  pieces: number
  amount: string
  onChange: (pieces: number | null) => void
}) {
  const { t } = useTranslation("admin")
  // The text as typed, so "" and "0" both read as zero without the field jumping while typing.
  const [text, setText] = useState(pieces === 0 ? "" : String(pieces))
  const invalid = parsePieces(text) === null

  return (
    <TableRow>
      <TableCell className="pl-6 font-medium tabular-nums">{label}</TableCell>
      <TableCell className="py-1 text-right">
        <Input
          type="text"
          inputMode="numeric"
          autoComplete="off"
          placeholder="0"
          className="ml-auto h-8 w-24 text-right tabular-nums"
          value={text}
          aria-invalid={invalid || undefined}
          aria-label={t("dayCloses.editor.piecesOf", { denomination: label })}
          onChange={(event) => {
            setText(event.target.value)
            onChange(parsePieces(event.target.value))
          }}
        />
      </TableCell>
      <TableCell className={cn("pr-6 text-right tabular-nums", pieces === 0 && "text-muted-foreground")}>{amount}</TableCell>
    </TableRow>
  )
}
