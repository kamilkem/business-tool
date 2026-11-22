import { type ReactNode, useRef, useState } from "react"
import { unwrap } from "@business-tool/api-client"
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"
import { Link, createFileRoute } from "@tanstack/react-router"
import {
  ArrowLeftIcon,
  CheckIcon,
  CircleAlertIcon,
  CircleCheckIcon,
  LoaderCircleIcon,
  LockIcon,
  MinusIcon,
  PlusIcon,
  TriangleAlertIcon,
} from "lucide-react"
import { useTranslation } from "react-i18next"

import { Alert, AlertDescription } from "@business-tool/ui/components/alert"
import { Button } from "@business-tool/ui/components/button"
import { Field, FieldError, FieldGroup, FieldLabel } from "@business-tool/ui/components/field"
import { Input } from "@business-tool/ui/components/input"
import { MoneyInput } from "@business-tool/ui/components/money-input"
import { Skeleton } from "@business-tool/ui/components/skeleton"
import { OrganizationCurrencyContext, useMoneyFormat } from "@business-tool/ui/hooks/use-organization-currency"
import { useDateTimeFormat } from "@business-tool/ui/hooks/use-organization-time-zone"
import { type MoneyParseError, moneyErrorMessage } from "@business-tool/ui/lib/money"
import { requestErrorMessage } from "@business-tool/ui/lib/request-error"
import { cn } from "@business-tool/ui/lib/utils"

import { api } from "@/lib/api"
import {
  type DayCloseDenomination,
  type DayCloseForm,
  type DayCloseReceipt,
  type DayCloseStorage,
  type DayCloseValues,
  canLeaveStep,
  dateChoices,
  initialValues,
  nonCashTotal,
  parsePieces,
  piecesTotal,
  recordDayCloseBody,
  stepPieces,
  stepsOf,
  storageTotals,
  withAmount,
  withPieces,
} from "@/lib/day-close"
import { dayCloseFormKey, dayCloseFormQuery } from "@/lib/queries"

export const Route = createFileRoute("/_app/close-day")({
  component: CloseDayScreen,
})

/** "Saturday, 21 November" (the locale decides the order). */
const LONG_DATE: Intl.DateTimeFormatOptions = { weekday: "long", day: "numeric", month: "long" }

/**
 * The end-of-day close on the phone (ADR 0033 §5, §6): the date and register, the deposits, the
 * counts, the non-cash amounts, a summary and the receipt. The person sees only what they enter: no
 * balance and no expected opening.
 */
function CloseDayScreen() {
  const { membership } = Route.useRouteContext()
  const { t } = useTranslation("timeclock")

  // The layout shows "no organization" instead of this screen.
  if (!membership) return null

  return (
    <main className="mx-auto flex w-full max-w-sm flex-1 flex-col gap-3 px-4 pb-4">
      <div className="flex items-center gap-1">
        <Button asChild variant="ghost" size="icon" className="-ml-2 size-11">
          <Link to="/" aria-label={t("common.backToClock")}>
            <ArrowLeftIcon />
          </Link>
        </Button>
        <h1 className="text-lg font-semibold">{t("closeDay.title")}</h1>
      </div>
      <CloseDayPanel organizationId={membership.organization.id} />
    </main>
  )
}

function CloseDayPanel({ organizationId }: { organizationId: string }) {
  const { t } = useTranslation("timeclock")
  const form = useQuery({ ...dayCloseFormQuery(organizationId), staleTime: 0 })
  // A fresh start after a refused close: the form is read again and every field emptied.
  const [attempt, setAttempt] = useState(0)

  if (form.isPending) {
    return (
      <div className="flex flex-col gap-3" aria-busy>
        <Skeleton className="h-24 w-full rounded-xl" />
        <Skeleton className="h-64 w-full rounded-xl" />
      </div>
    )
  }

  if (form.isError) {
    return (
      <div className="flex flex-col gap-3">
        <Alert variant="destructive">
          <CircleAlertIcon />
          <AlertDescription>{requestErrorMessage(form.error)}</AlertDescription>
        </Alert>
        <Button variant="outline" className="h-11" onClick={() => form.refetch()} disabled={form.isFetching}>
          {form.isFetching && <LoaderCircleIcon className="animate-spin" />}
          {t("common.tryAgain")}
        </Button>
      </div>
    )
  }

  return (
    <OrganizationCurrencyContext value={form.data.currency}>
      <CloseDay
        key={attempt}
        organizationId={organizationId}
        form={form.data}
        onStartAgain={async () => {
          await form.refetch()
          setAttempt((n) => n + 1)
        }}
      />
    </OrganizationCurrencyContext>
  )
}

function CloseDay({ organizationId, form, onStartAgain }: { organizationId: string; form: DayCloseForm; onStartAgain: () => Promise<void> }) {
  const { t } = useTranslation("timeclock")
  const format = useDateTimeFormat()
  const queryClient = useQueryClient()
  const [values, setValues] = useState<DayCloseValues>(() => initialValues(form))
  const [stepIndex, setStepIndex] = useState(0)
  const inFlight = useRef(false)

  const close = useMutation({
    mutationFn: () => unwrap(api.POST("/api/day-closes", { body: recordDayCloseBody(form, values) })),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: dayCloseFormKey(organizationId) })
    },
    onSettled: () => {
      inFlight.current = false
    },
  })

  if (!form.canClose) {
    return (
      <Notice icon={<LockIcon />} text={t("closeDay.cannotClose")} />
    )
  }

  // The date the day-end time gives is already closed: a manager corrects it (ADR 0033 §6).
  if (form.proposedDateClosed && !close.isSuccess) {
    const closed = form.proposedDateClosed
    return (
      <Notice
        icon={<LockIcon />}
        text={t("closeDay.alreadyClosed", {
          date: format.businessDate(form.proposedDate, LONG_DATE),
          name: `${closed.closedByFirstName} ${closed.closedByLastName}`,
          time: format.dateTime(closed.closedAt),
        })}
      />
    )
  }

  if (close.isSuccess) return <Receipt receipt={close.data} />

  const steps = stepsOf(form)
  const step = steps[Math.min(stepIndex, steps.length - 1)]!
  const last = stepIndex >= steps.length - 1
  const canGoOn = canLeaveStep(step, form, values)
  const register = form.registers.find((r) => r.id === values.registerId)

  function submit() {
    if (inFlight.current) return
    inFlight.current = true
    close.mutate()
  }

  return (
    <>
      <div className="flex flex-col">
        <div className="text-sm text-muted-foreground" aria-live="polite">
          {t("closeDay.stepOf", { step: stepIndex + 1, steps: steps.length })}
        </div>
        <div className="font-medium">
          {format.businessDate(values.date, LONG_DATE)}
          {register && <span className="text-muted-foreground"> · {register.name}</span>}
        </div>
      </div>

      <section className="flex flex-col gap-3 rounded-xl border bg-card p-4">
        {step.kind === "day" && <DayStep form={form} values={values} onChange={setValues} />}
        {step.kind === "storage" && (
          <StorageStep key={step.storage.id} storage={step.storage} denominations={form.denominations} values={values} onChange={setValues} />
        )}
        {step.kind === "payments" && <PaymentsStep form={form} values={values} onChange={setValues} />}
        {step.kind === "summary" && <Summary form={form} values={values} />}
      </section>

      {close.isError && (
        <div className="flex flex-col gap-2">
          <Alert variant="destructive">
            <CircleAlertIcon />
            <AlertDescription>{requestErrorMessage(close.error, { format })}</AlertDescription>
          </Alert>
          <Button variant="ghost" className="h-11" onClick={() => void onStartAgain()}>
            {t("closeDay.startAgain")}
          </Button>
        </div>
      )}

      <footer className="sticky bottom-0 -mx-4 mt-auto flex gap-2 bg-muted px-4 pt-2 pb-[max(1rem,env(safe-area-inset-bottom))]">
        {stepIndex > 0 && (
          <Button variant="outline" className="h-12 flex-1" onClick={() => setStepIndex(stepIndex - 1)} disabled={close.isPending}>
            {t("closeDay.back")}
          </Button>
        )}
        {last ? (
          <Button className="h-12 flex-[2]" onClick={submit} disabled={close.isPending}>
            {close.isPending && <LoaderCircleIcon className="animate-spin" />}
            {close.isPending ? t("closeDay.closing") : t("closeDay.submit")}
          </Button>
        ) : (
          <Button className="h-12 flex-[2]" onClick={() => setStepIndex(stepIndex + 1)} disabled={!canGoOn}>
            {t("closeDay.next")}
          </Button>
        )}
      </footer>
    </>
  )
}

function Notice({ icon, text }: { icon: ReactNode; text: string }) {
  const { t } = useTranslation("timeclock")
  return (
    <>
      <Alert>
        {icon}
        <AlertDescription>{text}</AlertDescription>
      </Alert>
      <Button asChild variant="outline" className="h-11">
        <Link to="/">{t("common.backToClock")}</Link>
      </Button>
    </>
  )
}

interface StepProps {
  form: DayCloseForm
  values: DayCloseValues
  onChange: (values: DayCloseValues) => void
}

/** The date (today or yesterday in `employee_picks` mode) and the register, when there is a choice. */
function DayStep({ form, values, onChange }: StepProps) {
  const { t } = useTranslation("timeclock")
  const format = useDateTimeFormat()
  const dates = dateChoices(form)

  return (
    <>
      <h2 className="text-base font-semibold">{t("closeDay.day.title")}</h2>
      {dates.length > 1 ? (
        <Choice
          label={t("closeDay.day.date")}
          options={dates.map((date) => ({
            id: date,
            name: t(date === form.today ? "closeDay.day.today" : "closeDay.day.yesterday"),
            detail: format.businessDate(date, LONG_DATE),
          }))}
          selected={values.date}
          onSelect={(date) => onChange({ ...values, date })}
        />
      ) : (
        <p className="text-sm text-muted-foreground">
          {t("closeDay.day.dateFixed", { date: format.businessDate(form.proposedDate, LONG_DATE) })}
        </p>
      )}
      {form.registers.length > 1 && (
        <Choice
          label={t("closeDay.day.register")}
          options={form.registers}
          selected={values.registerId}
          onSelect={(registerId) => onChange({ ...values, registerId })}
        />
      )}
    </>
  )
}

/** Big buttons, one per option, that act as radio buttons. */
function Choice({
  label,
  options,
  selected,
  onSelect,
}: {
  label: string
  options: readonly { id: string; name: string; detail?: string }[]
  selected: string | null
  onSelect: (id: string) => void
}) {
  return (
    <div role="radiogroup" aria-label={label} className="flex flex-col gap-2">
      <div className="text-sm font-medium">{label}</div>
      {options.map((option) => {
        const checked = option.id === selected
        return (
          <button
            key={option.id}
            type="button"
            role="radio"
            aria-checked={checked}
            onClick={() => onSelect(option.id)}
            className={cn(
              "flex min-h-12 items-center justify-between gap-2 rounded-lg border px-3 py-2 text-left outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50",
              checked ? "border-primary bg-primary/5" : "bg-background",
            )}
          >
            <span className="flex min-w-0 flex-col">
              <span className="font-medium break-words">{option.name}</span>
              {option.detail && <span className="text-sm text-muted-foreground">{option.detail}</span>}
            </span>
            {checked && <CheckIcon className="size-5 shrink-0" />}
          </button>
        )
      })}
    </div>
  )
}

/** A deposit storage: the pieces put in. A counted storage: the pieces that stay in it, every denomination. */
function StorageStep({
  storage,
  denominations,
  values,
  onChange,
}: {
  storage: DayCloseStorage
  denominations: readonly DayCloseDenomination[]
  values: DayCloseValues
  onChange: (values: DayCloseValues) => void
}) {
  const { t } = useTranslation("timeclock")
  const money = useMoneyFormat()
  const pieces = values.pieces[storage.id] ?? {}
  const deposit = storage.mode === "deposit"

  return (
    <>
      <div>
        <h2 className="text-base font-semibold break-words">{storage.name}</h2>
        <p className="text-sm text-muted-foreground">
          {deposit ? t("closeDay.storage.depositHint", { name: storage.name }) : t("closeDay.storage.countedHint", { name: storage.name })}
        </p>
      </div>
      <ul className="flex flex-col divide-y">
        {denominations.map((denomination) => (
          <PiecesRow
            key={denomination.id}
            label={money(denomination.value)}
            pieces={pieces[denomination.id] ?? 0}
            lineTotal={money(denomination.value * (pieces[denomination.id] ?? 0))}
            onChange={(next) => onChange(withPieces(values, storage.id, denomination.id, next))}
          />
        ))}
      </ul>
      <div className="flex items-baseline justify-between gap-2 border-t pt-3" aria-live="polite">
        <span className="font-medium">{deposit ? t("closeDay.storage.depositTotal") : t("closeDay.storage.countedTotal")}</span>
        <span className="text-xl font-semibold tabular-nums">{money(piecesTotal(denominations, pieces))}</span>
      </div>
    </>
  )
}

/** One denomination: − [pieces] +, with the line's value under the label. */
function PiecesRow({
  label,
  pieces,
  lineTotal,
  onChange,
}: {
  label: string
  pieces: number
  lineTotal: string
  onChange: (pieces: number | null) => void
}) {
  const { t } = useTranslation("timeclock")
  // The text as typed, so "" and "0" both read as zero without the field jumping while typing.
  const [text, setText] = useState(pieces === 0 ? "" : String(pieces))
  const invalid = parsePieces(text) === null

  function set(next: number) {
    setText(next === 0 ? "" : String(next))
    onChange(next)
  }

  return (
    <li className="flex items-center gap-2 py-2">
      <div className="flex min-w-0 flex-1 flex-col">
        <span className="font-medium tabular-nums">{label}</span>
        <span className="text-xs text-muted-foreground tabular-nums">{lineTotal}</span>
      </div>
      <Button
        variant="outline"
        size="icon"
        className="size-11"
        onClick={() => set(stepPieces(parsePieces(text) ?? pieces, -1))}
        disabled={pieces === 0 && !invalid}
        aria-label={t("closeDay.storage.fewer", { denomination: label })}
      >
        <MinusIcon />
      </Button>
      <Input
        type="text"
        inputMode="numeric"
        autoComplete="off"
        placeholder="0"
        className="h-11 w-16 text-center text-base tabular-nums"
        value={text}
        aria-invalid={invalid || undefined}
        aria-label={t("closeDay.storage.pieces", { denomination: label })}
        onChange={(event) => {
          setText(event.target.value)
          onChange(parsePieces(event.target.value))
        }}
      />
      <Button
        variant="outline"
        size="icon"
        className="size-11"
        onClick={() => set(stepPieces(parsePieces(text) ?? pieces, 1))}
        aria-label={t("closeDay.storage.more", { denomination: label })}
      >
        <PlusIcon />
      </Button>
    </li>
  )
}

/** One amount per active non-cash payment method; an empty field is zero. */
function PaymentsStep({ form, values, onChange }: StepProps) {
  const { t } = useTranslation("timeclock")
  const money = useMoneyFormat()
  const [errors, setErrors] = useState<Record<string, MoneyParseError | null>>({})

  return (
    <>
      <div>
        <h2 className="text-base font-semibold">{t("closeDay.payments.title")}</h2>
        <p className="text-sm text-muted-foreground">{t("closeDay.payments.hint")}</p>
      </div>
      <FieldGroup className="gap-4">
        {form.paymentMethods.map((method) => {
          const id = `payment-${method.id}`
          const error = errors[method.id] ?? null
          return (
            <Field key={method.id} data-invalid={error !== null || undefined}>
              <FieldLabel htmlFor={id} className="break-words">{method.name}</FieldLabel>
              <MoneyInput
                id={id}
                className="h-11 text-base"
                placeholder="0"
                currency={form.currency}
                value={values.amounts[method.id] ?? null}
                onValueChange={(amount, parseError) => {
                  setErrors((current) => ({ ...current, [method.id]: parseError }))
                  onChange(withAmount(values, method.id, amount, parseError !== null))
                }}
              />
              {error && <FieldError>{moneyErrorMessage(error, form.currency)}</FieldError>}
            </Field>
          )
        })}
      </FieldGroup>
      <div className="flex items-baseline justify-between gap-2 border-t pt-3" aria-live="polite">
        <span className="font-medium">{t("closeDay.payments.total")}</span>
        <span className="text-xl font-semibold tabular-nums">{money(nonCashTotal(form, values))}</span>
      </div>
    </>
  )
}

/** What will be sent: the totals per storage and the non-cash amounts. The cash income comes back with the receipt. */
function Summary({ form, values }: { form: DayCloseForm; values: DayCloseValues }) {
  const { t } = useTranslation("timeclock")
  const money = useMoneyFormat()

  return (
    <>
      <h2 className="text-base font-semibold">{t("closeDay.summary.title")}</h2>
      <Totals
        storages={storageTotals(form, values).map(({ storage, total }) => ({ id: storage.id, name: storage.name, mode: storage.mode, total }))}
        payments={form.paymentMethods.map((method) => ({ id: method.id, name: method.name, amount: values.amounts[method.id] ?? 0 }))}
        money={money}
      />
      <p className="text-sm text-muted-foreground">{t("closeDay.summary.cashIncomeLater")}</p>
    </>
  )
}

function Totals({
  storages,
  payments,
  money,
}: {
  storages: readonly { id: string; name: string; mode: DayCloseStorage["mode"]; total: number }[]
  payments: readonly { id: string; name: string; amount: number }[]
  money: (minor: number) => string
}) {
  const { t } = useTranslation("timeclock")
  return (
    <dl className="flex flex-col gap-2 text-sm">
      {storages.map((storage) => (
        <div key={storage.id} className="flex justify-between gap-3">
          <dt className="min-w-0 break-words">
            {t(storage.mode === "deposit" ? "closeDay.summary.deposited" : "closeDay.summary.counted", { name: storage.name })}
          </dt>
          <dd className="shrink-0 tabular-nums">{money(storage.total)}</dd>
        </div>
      ))}
      {payments.length > 0 && (
        <div className="border-t pt-2 text-xs font-medium text-muted-foreground uppercase">{t("closeDay.payments.title")}</div>
      )}
      {payments.map((payment) => (
        <div key={payment.id} className="flex justify-between gap-3">
          <dt className="min-w-0 break-words">{payment.name}</dt>
          <dd className="shrink-0 tabular-nums">{money(payment.amount)}</dd>
        </div>
      ))}
    </dl>
  )
}

/** The close as recorded: the date, the register, the cash income (a shortage flagged) and the totals. */
function Receipt({ receipt }: { receipt: DayCloseReceipt }) {
  const { t } = useTranslation("timeclock")
  const format = useDateTimeFormat()
  const money = useMoneyFormat()

  return (
    <>
      <section className="flex flex-col gap-3 rounded-xl border bg-card p-4" aria-live="polite">
        <div className="flex items-center gap-2 font-semibold">
          <CircleCheckIcon className="size-5 text-green-600" />
          {t("closeDay.receipt.title", { date: format.businessDate(receipt.date, LONG_DATE) })}
        </div>
        <p className="text-sm text-muted-foreground">
          {t("closeDay.receipt.closedAt", { time: format.time(receipt.closedAt) })}
          {receipt.registerName && ` · ${receipt.registerName}`}
        </p>
        <div>
          <div className="text-sm text-muted-foreground">{t("closeDay.receipt.cashIncome")}</div>
          <div className="text-3xl font-semibold tabular-nums tracking-tight">{money(receipt.cashIncome)}</div>
        </div>
        <Totals
          storages={receipt.storages.map((storage) => ({ id: storage.storageId, name: storage.name, mode: storage.mode, total: storage.total }))}
          payments={receipt.payments.map((payment) => ({
            id: payment.paymentMethodId,
            name: payment.name ?? t("closeDay.receipt.unknownMethod"),
            amount: payment.amount,
          }))}
          money={money}
        />
      </section>
      {receipt.shortage && (
        <Alert className="border-amber-300 bg-amber-50 text-amber-900 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-200">
          <TriangleAlertIcon />
          <AlertDescription className="text-inherit">{t("closeDay.receipt.shortage", { amount: money(-receipt.cashIncome) })}</AlertDescription>
        </Alert>
      )}
      <Button asChild className="h-12">
        <Link to="/">{t("common.backToClock")}</Link>
      </Button>
    </>
  )
}
