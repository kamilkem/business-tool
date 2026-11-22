import type { components } from "@business-tool/api-client"
import { MAX_AMOUNT, type CountedDenomination, type Pieces, piecesTotal } from "@business-tool/ui/lib/cash-count"
import { type BusinessDate, compareBusinessDates, isBusinessDate, periodError } from "@business-tool/ui/lib/date-time"
import type { MinorUnits } from "@business-tool/ui/lib/money"

/*
 * The admin's review of end-of-day closes (ADR 0033 §6, §7): the list by period, one close with
 * what the ledger expected, its correction and a close recorded by a manager.
 */

export type DayClose = components["schemas"]["DayClose"]
export type DayCloseStorageItem = components["schemas"]["DayCloseStorageItem"]
export type DayCloseForm = components["schemas"]["DayCloseForm"]
export type CorrectDayCloseInput = components["schemas"]["DayClose.CorrectDayCloseInput"]
export type RecordDayCloseInput = components["schemas"]["DayCloseReceipt.RecordDayCloseInput"]
export type StorageMode = DayCloseStorageItem["mode"]

/** "Saturday, 21 November 2026" (the locale decides the order). */
export const LONG_DATE: Intl.DateTimeFormatOptions = { weekday: "long", day: "numeric", month: "long", year: "numeric" }

/** The longest period the list may show (the API's `ListDayClosesQuery::MAX_DAYS`). */
export const DAY_CLOSES_MAX_DAYS = 92

// --- The list ----------------------------------------------------------------------------------

export interface DayClosesSearch {
  from: BusinessDate | undefined
  to: BusinessDate | undefined
}

/** The list's period from the URL; anything unreadable or too long falls back to this month. */
export function validateDayClosesSearch(search: Record<string, unknown>): DayClosesSearch {
  const { from, to } = search
  if (
    typeof from === "string" &&
    typeof to === "string" &&
    isBusinessDate(from) &&
    isBusinessDate(to) &&
    periodError({ from, to }, DAY_CLOSES_MAX_DAYS) === null
  ) {
    return { from, to }
  }
  return { from: undefined, to: undefined }
}

export interface ColumnOf {
  id: string
  /** The current name; null for a payment method no longer offered. */
  name: string | null
}

export interface DayClosesTable {
  /** Every storage any close of the period covered, by name. */
  storages: (ColumnOf & { mode: StorageMode })[]
  /** Every non-cash payment method any close of the period has, in the order they first appear. */
  payments: ColumnOf[]
  /** The closes, latest first, as the API lists them. */
  rows: {
    close: DayClose
    /** Each storage column's total, null where the close did not cover the storage. */
    storageTotals: (MinorUnits | null)[]
    /** Each payment column's amount, null where the close has no line for the method. */
    payments: (MinorUnits | null)[]
  }[]
  /** Σ positive cash income: what the closes booked as cash (a shortage is booked as 0). */
  bookedCash: MinorUnits
  /** How many closes of the period are flagged as a shortage. */
  shortages: number
}

/**
 * The list's columns: one per storage and per non-cash method that any close of the period has, so
 * a storage added or removed mid-month only leaves blanks.
 */
export function dayClosesTable(closes: readonly DayClose[]): DayClosesTable {
  const storages = new Map<string, ColumnOf & { mode: StorageMode }>()
  const payments = new Map<string, ColumnOf>()
  for (const close of closes) {
    for (const storage of close.storages) {
      if (!storages.has(storage.storageId)) storages.set(storage.storageId, { id: storage.storageId, name: storage.name, mode: storage.mode })
    }
    for (const line of close.payments) {
      if (!payments.has(line.paymentMethodId)) payments.set(line.paymentMethodId, { id: line.paymentMethodId, name: line.name })
    }
  }
  const storageColumns = [...storages.values()].sort((a, b) => (a.name ?? "").localeCompare(b.name ?? ""))
  const paymentColumns = [...payments.values()]

  return {
    storages: storageColumns,
    payments: paymentColumns,
    rows: closes.map((close) => ({
      close,
      storageTotals: storageColumns.map((column) => close.storages.find((s) => s.storageId === column.id)?.total ?? null),
      payments: paymentColumns.map((column) => close.payments.find((p) => p.paymentMethodId === column.id)?.amount ?? null),
    })),
    bookedCash: closes.reduce((total, close) => total + Math.max(0, close.cashIncome), 0),
    shortages: closes.filter((close) => close.shortage).length,
  }
}

/** The close's non-cash amounts added up. */
export function nonCashTotalOf(close: DayClose): MinorUnits {
  return close.payments.reduce((total, line) => total + line.amount, 0)
}

/** What the close books on the cash payment method: the cash income, or 0 for a shortage (ADR 0033 §6). */
export function bookedCashOf(cashIncome: MinorUnits): MinorUnits {
  return Math.max(0, cashIncome)
}

// --- The editor: a correction, or a close recorded in the admin -------------------------------

export interface EditorDenomination extends CountedDenomination {
  kind: "coin" | "note"
}

export interface EditorStorage {
  id: string
  name: string
  mode: StorageMode
  /** The denominations offered for this storage, lowest first. */
  denominations: EditorDenomination[]
  /** A counted storage's balance just before the close; null for a deposit storage or a new close. */
  expectedOpening: MinorUnits | null
}

export interface EditorOption {
  id: string
  /** Null for a register or payment method that is no longer offered. */
  name: string | null
}

/** What the editor offers: the storages, the non-cash methods and the registers. */
export interface DayCloseEditor {
  kind: "correct" | "record"
  storages: EditorStorage[]
  payments: EditorOption[]
  registers: EditorOption[]
}

/** What the person has entered. */
export interface DayCloseValues {
  date: BusinessDate
  registerId: string | null
  /** Pieces by storage id: what stays in a counted storage, what was put into a deposit one. */
  pieces: Readonly<Record<string, Pieces>>
  /** Non-cash amounts by payment method id, in minor units; null while the field is empty. */
  amounts: Readonly<Record<string, MinorUnits | null>>
  /** Payment methods whose field holds text that is not an amount. */
  invalidAmounts: readonly string[]
  /** `<storageId>:<denominationId>` of the fields whose text is not a number of pieces. */
  invalidPieces: readonly string[]
}

function byValue(a: EditorDenomination, b: EditorDenomination): number {
  return a.value - b.value
}

/**
 * The correction of a close covers what the close covered (ADR 0033 §7): its storages in their mode
 * at the close, its non-cash methods, and the denominations that are active or already in the close.
 * The register is the close's own, even if deactivated since, or an active one.
 */
export function correctionEditor(close: DayClose, form: DayCloseForm): DayCloseEditor {
  return {
    kind: "correct",
    storages: close.storages.map((storage) => {
      const denominations = new Map<string, EditorDenomination>()
      for (const line of storage.lines) denominations.set(line.denominationId, { id: line.denominationId, value: line.value, kind: line.kind })
      if (form.currency === close.currency) {
        for (const d of form.denominations) if (!denominations.has(d.id)) denominations.set(d.id, { id: d.id, value: d.value, kind: d.kind })
      }
      return {
        id: storage.storageId,
        name: storage.name,
        mode: storage.mode,
        denominations: [...denominations.values()].sort(byValue),
        expectedOpening: storage.expectedOpening,
      }
    }),
    payments: close.payments.map((line) => ({ id: line.paymentMethodId, name: line.name })),
    registers: form.registers.some((register) => register.id === close.registerId)
      ? form.registers
      : [{ id: close.registerId, name: close.registerName }, ...form.registers],
  }
}

/** A close recorded in the admin covers what the time clock's would: every active storage and non-cash method. */
export function recordEditor(form: DayCloseForm): DayCloseEditor {
  const denominations = [...form.denominations].sort(byValue)
  return {
    kind: "record",
    storages: form.storages.map((storage) => ({ ...storage, denominations, expectedOpening: null })),
    payments: form.paymentMethods,
    registers: form.registers,
  }
}

/** The close as it is: its date, register, pieces and amounts. */
export function valuesOfClose(close: DayClose): DayCloseValues {
  return {
    date: close.date,
    registerId: close.registerId,
    pieces: Object.fromEntries(
      close.storages.map((storage) => [storage.storageId, Object.fromEntries(storage.lines.map((line) => [line.denominationId, line.pieces]))]),
    ),
    amounts: Object.fromEntries(close.payments.map((line) => [line.paymentMethodId, line.amount])),
    invalidAmounts: [],
    invalidPieces: [],
  }
}

/** A new close: the proposed date, the preselected register, nothing counted. */
export function valuesOfForm(form: DayCloseForm): DayCloseValues {
  return {
    date: form.proposedDate,
    registerId: form.preselectedRegisterId ?? (form.registers.length === 1 ? form.registers[0]!.id : null),
    pieces: {},
    amounts: {},
    invalidAmounts: [],
    invalidPieces: [],
  }
}

/**
 * Sets a storage's pieces of one denomination; null when the field holds text that is not a number
 * of pieces, which counts as zero and blocks saving until it is fixed.
 */
export function withPieces(values: DayCloseValues, storageId: string, denominationId: string, pieces: number | null): DayCloseValues {
  const key = `${storageId}:${denominationId}`
  const others = values.invalidPieces.filter((invalid) => invalid !== key)
  return {
    ...values,
    pieces: { ...values.pieces, [storageId]: { ...values.pieces[storageId], [denominationId]: pieces ?? 0 } },
    invalidPieces: pieces === null ? [...others, key] : others,
  }
}

/** Sets a non-cash amount, remembering whether the field holds an amount at all. */
export function withAmount(values: DayCloseValues, methodId: string, amount: MinorUnits | null, invalid: boolean): DayCloseValues {
  const others = values.invalidAmounts.filter((id) => id !== methodId)
  return {
    ...values,
    amounts: { ...values.amounts, [methodId]: amount },
    invalidAmounts: invalid ? [...others, methodId] : others,
  }
}

/** The value of a storage's pieces, over the denominations the editor offers for it. */
export function storageTotal(storage: EditorStorage, values: DayCloseValues): MinorUnits {
  return piecesTotal(storage.denominations, values.pieces[storage.id])
}

/**
 * What a storage adds to the cash income: a counted storage its count minus the expected opening, a
 * deposit storage what was put in. Null for a counted storage of a new close, whose expected opening
 * only the API knows.
 */
export function storageCashIncome(storage: EditorStorage, values: DayCloseValues): MinorUnits | null {
  const total = storageTotal(storage, values)
  if (storage.mode === "deposit") return total
  return storage.expectedOpening === null ? null : total - storage.expectedOpening
}

/**
 * The cash income the values give (ADR 0033 §6): Σ counted (count − expected opening) + Σ deposits.
 * A correction leaves the ledger before the close as it is, so the expected openings hold and this is
 * what the API will compute. Null for a new close.
 */
export function cashIncomeOf(editor: DayCloseEditor, values: DayCloseValues): MinorUnits | null {
  let total = 0
  for (const storage of editor.storages) {
    const income = storageCashIncome(storage, values)
    if (income === null) return null
    total += income
  }
  return total
}

/** The non-cash amounts entered, added up; an empty field is zero. */
export function nonCashTotal(editor: DayCloseEditor, values: DayCloseValues): MinorUnits {
  return editor.payments.reduce((total, method) => total + (values.amounts[method.id] ?? 0), 0)
}

export type DayCloseProblem = "register" | "date" | "pieces" | "amounts"

/**
 * Why the values can't be sent yet: no register, a date that is not a date or is after today (the
 * API refuses a future close, ADR 0033 §5), text that is not a number of pieces, or an amount that
 * is not one or is too large. An empty list means it can be sent.
 */
export function problemsOf(editor: DayCloseEditor, values: DayCloseValues, today: BusinessDate): DayCloseProblem[] {
  const problems: DayCloseProblem[] = []
  if (values.registerId === null || !editor.registers.some((register) => register.id === values.registerId)) problems.push("register")
  if (editor.kind === "record" && (!isBusinessDate(values.date) || compareBusinessDates(values.date, today) > 0)) problems.push("date")
  if (values.invalidPieces.length > 0) problems.push("pieces")
  if (values.invalidAmounts.length > 0 || editor.payments.some((method) => (values.amounts[method.id] ?? 0) > MAX_AMOUNT)) {
    problems.push("amounts")
  }
  return problems
}

/** Every storage once: a counted storage with every denomination offered (zeros included), a deposit storage only what was put in. */
function storagesBody(editor: DayCloseEditor, values: DayCloseValues): CorrectDayCloseInput["storages"] {
  return editor.storages.map((storage) => {
    const pieces = values.pieces[storage.id] ?? {}
    const lines = storage.denominations.map((denomination) => ({ denominationId: denomination.id, pieces: pieces[denomination.id] ?? 0 }))
    return { storageId: storage.id, lines: storage.mode === "counted" ? lines : lines.filter((line) => line.pieces > 0) }
  })
}

function paymentsBody(editor: DayCloseEditor, values: DayCloseValues): CorrectDayCloseInput["payments"] {
  return editor.payments.map((method) => ({ paymentMethodId: method.id, amount: values.amounts[method.id] ?? 0 }))
}

/** The body of `PUT /day-closes/{id}`: everything but the date, which never changes. */
export function correctDayCloseBody(editor: DayCloseEditor, values: DayCloseValues): CorrectDayCloseInput {
  if (values.registerId === null) throw new Error("A close needs a register.")
  return { registerId: values.registerId, storages: storagesBody(editor, values), payments: paymentsBody(editor, values) }
}

/** The body of `POST /day-closes`, with the date the manager picked (any date up to today). */
export function recordDayCloseBody(editor: DayCloseEditor, values: DayCloseValues): RecordDayCloseInput {
  if (values.registerId === null) throw new Error("A close needs a register.")
  return {
    registerId: values.registerId,
    date: values.date,
    storages: storagesBody(editor, values),
    payments: paymentsBody(editor, values),
  }
}

