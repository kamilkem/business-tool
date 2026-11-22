import { type FormEvent, useState } from "react"
import { unwrap } from "@business-tool/api-client"
import { useMutation, useQueryClient } from "@tanstack/react-query"
import { CircleAlertIcon, LoaderCircleIcon, MinusIcon, PlusIcon } from "lucide-react"
import { useTranslation } from "react-i18next"
import { toast } from "sonner"

import { Alert, AlertDescription } from "@business-tool/ui/components/alert"
import { Badge } from "@business-tool/ui/components/badge"
import { Button } from "@business-tool/ui/components/button"
import { Checkbox } from "@business-tool/ui/components/checkbox"
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@business-tool/ui/components/dialog"
import { Field, FieldDescription, FieldError, FieldGroup, FieldLabel } from "@business-tool/ui/components/field"
import { Input } from "@business-tool/ui/components/input"
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@business-tool/ui/components/select"
import { ShiftMomentField } from "@business-tool/ui/components/shift-moment-field"
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@business-tool/ui/components/table"
import { Textarea } from "@business-tool/ui/components/textarea"
import { useMoneyFormat } from "@business-tool/ui/hooks/use-organization-currency"
import { useDateTimeFormat } from "@business-tool/ui/hooks/use-organization-time-zone"
import { parsePieces } from "@business-tool/ui/lib/cash-count"
import { requestErrorMessage } from "@business-tool/ui/lib/request-error"
import { cn } from "@business-tool/ui/lib/utils"

import { api } from "@/lib/api"
import {
  type CashBalance,
  type Direction,
  type FormDenomination,
  MOVEMENT_NOTE_MAX_LENGTH,
  type MovementFormValues,
  type MovementKind,
  type MovementProblem,
  type MovementRequest,
  balanceAfter,
  countDiscrepancy,
  emptyMovementForm,
  formDenominations,
  movementFormTotal,
  movementRequest,
  storageChoices,
} from "@/lib/cash-flow"
import type { Denomination } from "@/lib/denominations"
import { invalidateCashLedger } from "@/lib/queries"

export interface CashMovementDialogState {
  kind: MovementKind
  /** The storage to start with; the form lets the person pick another. */
  storageId: string
  direction?: Direction
}

/**
 * Adds cash to or takes it out of a storage, transfers it between two, or records an audit count of
 * a deposit storage (ADR 0033 §4): pieces per denomination with a live total, an optional note and
 * an optional earlier moment.
 */
export function CashMovementDialog({
  organizationId,
  state,
  onClose,
  balances,
  denominations,
}: {
  organizationId: string
  /** What to open; null closes the dialog. */
  state: CashMovementDialogState | null
  onClose: () => void
  balances: readonly CashBalance[]
  denominations: readonly Denomination[]
}) {
  // Kept while the dialog closes, so its title doesn't change during the animation.
  const [shown, setShown] = useState(state)
  if (state && state !== shown) setShown(state)

  return (
    <Dialog open={state !== null} onOpenChange={(open) => !open && onClose()}>
      <DialogContent className="max-h-[calc(100dvh-2rem)] overflow-y-auto sm:max-w-lg">
        {shown && (
          // Mounted on each open, so the form starts from the storage it was opened for.
          <MovementForm
            key={`${shown.kind}-${shown.storageId}-${shown.direction ?? ""}`}
            organizationId={organizationId}
            initial={shown}
            balances={balances}
            denominations={denominations}
            onDone={onClose}
          />
        )}
      </DialogContent>
    </Dialog>
  )
}

function MovementForm({
  organizationId,
  initial,
  balances,
  denominations,
  onDone,
}: {
  organizationId: string
  initial: CashMovementDialogState
  balances: readonly CashBalance[]
  denominations: readonly Denomination[]
  onDone: () => void
}) {
  const { t } = useTranslation("admin")
  const format = useDateTimeFormat()
  const money = useMoneyFormat()
  const queryClient = useQueryClient()
  const [values, setValues] = useState<MovementFormValues>(() => ({
    ...emptyMovementForm(initial.kind, initial.storageId, new Date(), format.timeZone),
    direction: initial.direction ?? "add",
  }))
  const [problems, setProblems] = useState<MovementProblem[]>([])
  const [error, setError] = useState<string | null>(null)
  const { kind } = values
  const { sources, targets } = storageChoices(kind, balances)
  const balance = balances.find((candidate) => candidate.storageId === values.storageId)
  const offered = formDenominations(values, denominations, balance)
  const total = movementFormTotal(values, offered)
  const discrepancy = countDiscrepancy(values, offered, balance)
  const after = balanceAfter(values, offered, balance)
  const target = balances.find((candidate) => candidate.storageId === values.targetStorageId)
  const has = (problem: MovementProblem) => problems.includes(problem)

  const save = useMutation({
    mutationFn: (request: MovementRequest) => {
      switch (request.kind) {
        case "adjustment":
          return unwrap(api.POST("/api/cash-movements/adjustments", { body: request.body }))
        case "transfer":
          return unwrap(api.POST("/api/cash-movements/transfers", { body: request.body }))
        case "count":
          return unwrap(api.POST("/api/cash-movements/counts", { body: request.body }))
      }
    },
    onSuccess: async (movement) => {
      await invalidateCashLedger(queryClient, organizationId)
      const amount = money(Math.abs(movement.total))
      toast.success(
        movement.kind === "transfer"
          ? t("cashFlow.toast.transferred", { amount, from: movement.storageName, to: movement.targetStorageName })
          : movement.kind === "count"
            ? t("cashFlow.toast.counted", { amount, name: movement.storageName })
            : movement.total < 0
              ? t("cashFlow.toast.removed", { amount, name: movement.storageName })
              : t("cashFlow.toast.added", { amount, name: movement.storageName }),
      )
      onDone()
    },
    onError: (failure) => setError(requestErrorMessage(failure, { format })),
  })

  function change(next: Partial<MovementFormValues>) {
    setValues((current) => ({ ...current, ...next }))
    setProblems([])
    setError(null)
  }

  function submit(event: FormEvent) {
    event.preventDefault()
    const result = movementRequest(values, offered, format.timeZone)
    if (!result.ok) {
      setProblems(result.problems)
      return
    }
    setError(null)
    save.mutate(result.request)
  }

  const pending = save.isPending
  const removing = kind === "adjustment" && values.direction === "remove"

  return (
    <form onSubmit={submit} noValidate>
      <DialogHeader>
        <DialogTitle>{t(`cashFlow.form.title.${kind}`)}</DialogTitle>
        <DialogDescription>{t(`cashFlow.form.description.${kind}`)}</DialogDescription>
      </DialogHeader>
      <fieldset disabled={pending} className="py-4">
        <FieldGroup>
          {kind === "adjustment" && (
            <div role="group" aria-label={t("cashFlow.form.direction")} className="grid grid-cols-2 gap-2">
              {(["add", "remove"] as const).map((direction) => (
                <Button
                  key={direction}
                  type="button"
                  variant={values.direction === direction ? "default" : "outline"}
                  aria-pressed={values.direction === direction}
                  disabled={direction === "add" && balance !== undefined && !balance.active}
                  onClick={() => change({ direction })}
                >
                  {direction === "add" ? <PlusIcon /> : <MinusIcon />}
                  {t(`cashFlow.form.${direction}`)}
                </Button>
              ))}
            </div>
          )}
          <div className={cn("grid gap-4", kind === "transfer" && "sm:grid-cols-2")}>
            <StorageSelect
              id="movement-storage"
              label={t(kind === "transfer" ? "cashFlow.form.from" : "cashFlow.form.storage")}
              value={values.storageId}
              choices={sources}
              onChange={(storageId) => {
                const chosen = balances.find((candidate) => candidate.storageId === storageId)
                // A deactivated storage is only emptied.
                change({ storageId, ...(chosen && !chosen.active && kind === "adjustment" ? { direction: "remove" } : {}) })
              }}
              error={has("storage") ? t("cashFlow.form.chooseStorage") : undefined}
              description={
                balance && !balance.active
                  ? t("cashFlow.form.deactivatedStorage")
                  : kind === "count"
                    ? t("cashFlow.form.countOnlyDeposit")
                    : undefined
              }
            />
            {kind === "transfer" && (
              <StorageSelect
                id="movement-target"
                label={t("cashFlow.form.to")}
                value={values.targetStorageId}
                choices={targets.filter((candidate) => candidate.storageId !== values.storageId)}
                onChange={(targetStorageId) => change({ targetStorageId })}
                error={
                  has("target")
                    ? t("cashFlow.form.chooseTarget")
                    : has("sameStorage")
                      ? t("cashFlow.form.sameStorage")
                      : undefined
                }
              />
            )}
          </div>

          <PiecesTable
            kind={kind}
            removing={removing}
            denominations={offered}
            values={values}
            onChange={(id, pieces) => change({ pieces: { ...values.pieces, [id]: pieces } })}
          />
          <dl className="grid gap-1 text-sm" aria-live="polite">
            <div className="flex justify-between gap-4 text-base font-semibold">
              <dt>{t(`cashFlow.form.total.${kind}`)}</dt>
              <dd className="tabular-nums">{money(total)}</dd>
            </div>
            {discrepancy !== null && (
              <div className="flex justify-between gap-4">
                <dt className="text-muted-foreground">{t("cashFlow.form.discrepancy")}</dt>
                <dd className={cn("tabular-nums", discrepancy < 0 && "text-amber-700 dark:text-amber-400")}>
                  {money(discrepancy)}
                </dd>
              </div>
            )}
            {after !== null && balance && kind !== "count" && (
              <div className="flex justify-between gap-4">
                <dt className="text-muted-foreground">{t("cashFlow.form.after", { name: balance.name })}</dt>
                <dd className={cn("tabular-nums", after < 0 && "text-amber-700 dark:text-amber-400")}>{money(after)}</dd>
              </div>
            )}
            {after !== null && kind === "transfer" && target && (
              <div className="flex justify-between gap-4">
                <dt className="text-muted-foreground">{t("cashFlow.form.after", { name: target.name })}</dt>
                <dd className="tabular-nums">{money(target.total + total)}</dd>
              </div>
            )}
          </dl>
          {has("pieces") && <FieldError>{t("cashFlow.form.fixPieces")}</FieldError>}
          {has("nothing") && <FieldError>{t("cashFlow.form.nothing")}</FieldError>}

          <Field data-invalid={has("note") || undefined}>
            <FieldLabel htmlFor="movement-note">{t("cashFlow.form.note")}</FieldLabel>
            <Textarea
              id="movement-note"
              rows={2}
              maxLength={MOVEMENT_NOTE_MAX_LENGTH}
              placeholder={t(`cashFlow.form.notePlaceholder.${removing ? "remove" : kind}`)}
              value={values.note}
              onChange={(event) => change({ note: event.target.value })}
            />
            {has("note") && <FieldError>{t("cashFlow.form.noteTooLong", { count: MOVEMENT_NOTE_MAX_LENGTH })}</FieldError>}
          </Field>

          <Field orientation="horizontal">
            <Checkbox
              id="movement-earlier"
              checked={values.earlier}
              onCheckedChange={(checked) => change({ earlier: checked === true })}
            />
            <FieldLabel htmlFor="movement-earlier" className="font-normal">
              {t("cashFlow.form.earlier")}
            </FieldLabel>
          </Field>
          {values.earlier && (
            <ShiftMomentField
              label={t("cashFlow.form.when")}
              name="occurredAt"
              value={values.moment}
              timeZone={format.timeZone}
              description={t("cashFlow.form.whenHint", { timeZone: format.timeZone })}
              error={
                has("momentInvalid")
                  ? t("cashFlow.form.momentInvalid")
                  : has("momentSkipped")
                    ? t("cashFlow.form.momentSkipped", { time: values.moment.time })
                    : has("momentInFuture")
                      ? t("cashFlow.form.momentInFuture")
                      : undefined
              }
              onChange={(moment) => change({ moment })}
            />
          )}

          {error && (
            <Alert variant="destructive">
              <CircleAlertIcon />
              <AlertDescription>{error}</AlertDescription>
            </Alert>
          )}
        </FieldGroup>
      </fieldset>
      <DialogFooter>
        <Button type="button" variant="outline" onClick={onDone} disabled={pending}>
          {t("common.cancel")}
        </Button>
        <Button type="submit" disabled={pending}>
          {pending && <LoaderCircleIcon className="animate-spin" />}
          {t(`cashFlow.form.submit.${removing ? "remove" : kind}`)}
        </Button>
      </DialogFooter>
    </form>
  )
}

function StorageSelect({
  id,
  label,
  value,
  choices,
  onChange,
  error,
  description,
}: {
  id: string
  label: string
  value: string
  choices: readonly CashBalance[]
  onChange: (storageId: string) => void
  error?: string
  description?: string
}) {
  const { t } = useTranslation("admin")
  return (
    <Field data-invalid={error ? true : undefined}>
      <FieldLabel htmlFor={id}>{label}</FieldLabel>
      <Select value={value} onValueChange={onChange}>
        <SelectTrigger id={id} className="w-full" aria-invalid={error ? true : undefined}>
          <SelectValue placeholder={t("cashFlow.form.chooseStorage")} />
        </SelectTrigger>
        <SelectContent>
          {choices.map((choice) => (
            <SelectItem key={choice.storageId} value={choice.storageId}>
              {choice.name}
              {!choice.active && ` (${t("cashFlow.balances.deactivated")})`}
            </SelectItem>
          ))}
        </SelectContent>
      </Select>
      {error ? <FieldError>{error}</FieldError> : description ? <FieldDescription>{description}</FieldDescription> : null}
    </Field>
  )
}

function PiecesTable({
  kind,
  removing,
  denominations,
  values,
  onChange,
}: {
  kind: MovementKind
  removing: boolean
  denominations: readonly FormDenomination[]
  values: MovementFormValues
  onChange: (denominationId: string, pieces: number | null) => void
}) {
  const { t } = useTranslation("admin")
  const money = useMoneyFormat()
  if (denominations.length === 0) {
    return <p className="text-sm text-muted-foreground">{t("cashFlow.form.noDenominations")}</p>
  }
  return (
    <Table>
      <TableHeader>
        <TableRow>
          <TableHead>{t("dayCloses.denomination")}</TableHead>
          <TableHead className="w-28 text-right">{t(kind === "count" ? "cashFlow.form.counted" : "dayCloses.pieces")}</TableHead>
          <TableHead className="text-right">{t("dayCloses.amount")}</TableHead>
        </TableRow>
      </TableHeader>
      <TableBody>
        {denominations.map((denomination) => {
          const pieces = values.pieces[denomination.id] ?? 0
          const label = money(denomination.value)
          return (
            <TableRow key={denomination.id}>
              <TableCell>
                <span className="font-medium tabular-nums">{label}</span>
                {!denomination.active && (
                  <Badge variant="outline" className="ml-1.5 border-amber-600/40 text-amber-800 dark:text-amber-300">
                    {t("cashFlow.balances.retired")}
                  </Badge>
                )}
                {denomination.held !== null && denomination.held !== 0 && (
                  <span className="block text-xs text-muted-foreground tabular-nums">
                    {t(kind === "count" ? "cashFlow.form.expected" : "cashFlow.form.holds", { count: denomination.held })}
                  </span>
                )}
              </TableCell>
              <TableCell className="py-1 text-right">
                <PiecesInput label={label} sign={removing ? "−" : null} onChange={(next) => onChange(denomination.id, next)} />
              </TableCell>
              <TableCell className={cn("text-right tabular-nums", (pieces ?? 0) === 0 && "text-muted-foreground")}>
                {money((removing ? -1 : 1) * denomination.value * (pieces ?? 0))}
              </TableCell>
            </TableRow>
          )
        })}
      </TableBody>
    </Table>
  )
}

function PiecesInput({ label, sign, onChange }: { label: string; sign: string | null; onChange: (pieces: number | null) => void }) {
  const { t } = useTranslation("admin")
  // The text as typed, so "" and "0" both read as zero without the field jumping while typing.
  const [text, setText] = useState("")
  const invalid = parsePieces(text) === null
  return (
    <div className="ml-auto flex w-24 items-center gap-1">
      {sign && (
        <span aria-hidden className="text-muted-foreground">
          {sign}
        </span>
      )}
      <Input
        type="text"
        inputMode="numeric"
        autoComplete="off"
        placeholder="0"
        className="h-8 text-right tabular-nums"
        value={text}
        aria-invalid={invalid || undefined}
        aria-label={t("dayCloses.editor.piecesOf", { denomination: label })}
        onChange={(event) => {
          setText(event.target.value)
          onChange(parsePieces(event.target.value))
        }}
      />
    </div>
  )
}
