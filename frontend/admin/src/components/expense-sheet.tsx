import { useState } from "react"
import { unwrap } from "@business-tool/api-client"
import { useMutation, useQueryClient } from "@tanstack/react-query"
import { DownloadIcon, LockIcon, PencilIcon, Trash2Icon } from "lucide-react"
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
import { Badge } from "@business-tool/ui/components/badge"
import { Button } from "@business-tool/ui/components/button"
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from "@business-tool/ui/components/sheet"
import { Table, TableBody, TableCell, TableFooter, TableHead, TableHeader, TableRow } from "@business-tool/ui/components/table"
import { useLocale } from "@business-tool/ui/hooks/use-locale"
import { useDateTimeFormat } from "@business-tool/ui/hooks/use-organization-time-zone"
import { type MinorUnits, formatMoney } from "@business-tool/ui/lib/money"
import { cn } from "@business-tool/ui/lib/utils"
import { requestErrorMessage } from "@business-tool/ui/lib/request-error"

import { ExpenseCategorySelect } from "@/components/expense-category-select"
import { needsConfirmation } from "@/lib/category-suggestion"
import { api } from "@/lib/api"
import type { ExpenseCategory } from "@/lib/expense-categories"
import { isEditable } from "@/lib/expense-form"
import { type Expense, documentFileName, isCorrection, kindLabel, sourceLabel, vatRateLabel } from "@/lib/expenses"

interface ExpenseActions {
  organizationId: string
  activeCategories: readonly ExpenseCategory[]
  categorizing: boolean
  onCategorize: (expense: Expense, categoryId: string | null) => void
  /** Opens the form for an expense entered by hand. */
  onEdit: (expense: Expense) => void
}

/**
 * One expense in a drawer: what the invoice says, its VAT lines, where it came from, its category,
 * and the original document (the local copy, never fetched from KSeF again). An expense entered by
 * hand can be edited or deleted here; an imported one only categorized (#58).
 */
export function ExpenseSheet({
  expense,
  onClose,
  ...actions
}: ExpenseActions & {
  expense: Expense | null
  onClose: () => void
}) {
  return (
    <Sheet open={expense !== null} onOpenChange={(open) => !open && onClose()}>
      <SheetContent className="w-full gap-0 overflow-y-auto sm:max-w-lg">
        {expense && <ExpenseDetails expense={expense} onDeleted={onClose} {...actions} />}
      </SheetContent>
    </Sheet>
  )
}

function ExpenseDetails({
  expense,
  organizationId,
  activeCategories,
  categorizing,
  onCategorize,
  onEdit,
  onDeleted,
}: ExpenseActions & { expense: Expense; onDeleted: () => void }) {
  const format = useDateTimeFormat()
  const locale = useLocale()
  const { t } = useTranslation("admin")
  const money = (minor: MinorUnits) => formatMoney(minor, expense.currency, locale)
  const taxMoney = (minor: MinorUnits) => formatMoney(minor, expense.taxCurrency, locale)
  const foreign = expense.currency !== expense.taxCurrency
  const correction = isCorrection(expense.kind)

  return (
    <>
      <SheetHeader className="border-b">
        <div className="flex flex-wrap gap-1.5">
          <Badge variant="secondary">{sourceLabel(expense.source)}</Badge>
          <Badge variant={correction ? "destructive" : "outline"}>{kindLabel(expense.kind)}</Badge>
        </div>
        <SheetTitle className="text-lg">{expense.sellerName}</SheetTitle>
        <SheetDescription>
          {t("expenses.sheet.issued", {
            number: expense.invoiceNumber ?? t("expenses.sheet.noNumber"),
            date: format.businessDate(expense.issueDate),
          })}
        </SheetDescription>
        <p className={cn("text-2xl font-semibold tabular-nums", expense.grossTotal < 0 && "text-destructive")}>
          {money(expense.grossTotal)}
        </p>
        {isEditable(expense) ? (
          <ManualExpenseActions expense={expense} organizationId={organizationId} onEdit={onEdit} onDeleted={onDeleted} />
        ) : (
          <p className="flex items-center gap-1.5 text-xs text-muted-foreground">
            <LockIcon className="size-3.5" />
            {t("expenses.sheet.importedFrom", { source: sourceLabel(expense.source) })}
          </p>
        )}
      </SheetHeader>

      <div className="flex flex-col gap-6 p-4">
        <section className="flex flex-col gap-2">
          <h3 className="text-sm font-medium">{t("expenses.category")}</h3>
          <ExpenseCategorySelect
            expense={expense}
            activeCategories={activeCategories}
            disabled={categorizing}
            onChange={(categoryId) => onCategorize(expense, categoryId)}
            className="w-full"
          />
          {needsConfirmation(expense) && <p className="text-xs text-muted-foreground">{t("expenses.suggestedHint")}</p>}
        </section>

        <dl className="grid grid-cols-[auto_1fr] gap-x-6 gap-y-2 text-sm">
          <dt className="text-muted-foreground">{t("expenses.sheet.sellerTaxId")}</dt>
          <dd className="tabular-nums">{expense.sellerTaxId ?? "—"}</dd>
          <dt className="text-muted-foreground">{t("expenses.sheet.issueDate")}</dt>
          <dd>{format.businessDate(expense.issueDate)}</dd>
          <dt className="text-muted-foreground">{t("expenses.sheet.kind")}</dt>
          <dd>{kindLabel(expense.kind)}</dd>
          {expense.correctedExternalId && (
            <>
              <dt className="text-muted-foreground">{t("expenses.sheet.corrects")}</dt>
              <dd className="break-all">{expense.correctedExternalId}</dd>
            </>
          )}
          <dt className="text-muted-foreground">{t("settings.currency")}</dt>
          <dd>
            {expense.currency}
            {foreign && <span className="text-muted-foreground"> {t("expenses.sheet.vatSettledIn", { currency: expense.taxCurrency })}</span>}
          </dd>
          <dt className="text-muted-foreground">{t("expenses.sheet.source")}</dt>
          <dd>{sourceLabel(expense.source)}</dd>
          {expense.externalId && (
            <>
              <dt className="text-muted-foreground">{expense.source === "ksef" ? t("expenses.sheet.ksefNumber") : t("expenses.sheet.sourceId")}</dt>
              <dd className="break-all tabular-nums">{expense.externalId}</dd>
            </>
          )}
          <dt className="text-muted-foreground">{expense.source === "manual" ? t("expenses.sheet.entered") : t("expenses.sheet.imported")}</dt>
          <dd>{format.dateTime(expense.createdAt)}</dd>
        </dl>

        <section className="flex flex-col gap-2">
          <h3 className="text-sm font-medium">{t("income.vat.vat")}</h3>
          {expense.vatLines.length === 0 ? (
            <p className="text-sm text-muted-foreground">{t("expenses.sheet.noVat")}</p>
          ) : (
            <div className="rounded-lg border">
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead className="pl-3">{t("income.vat.rate")}</TableHead>
                    <TableHead className="text-right">{t("income.vat.net")}</TableHead>
                    <TableHead className="text-right">{t("income.vat.vat")}</TableHead>
                    {foreign && (
                      <TableHead className="text-right">{t("expenses.sheet.vatIn", { currency: expense.taxCurrency })}</TableHead>
                    )}
                    <TableHead className="pr-3 text-right">{t("income.vat.gross")}</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {expense.vatLines.map((line) => (
                    <TableRow key={line.rate}>
                      <TableCell className="pl-3">{vatRateLabel(line.rate)}</TableCell>
                      <TableCell className="text-right tabular-nums">{money(line.net)}</TableCell>
                      <TableCell className="text-right tabular-nums">{money(line.vat)}</TableCell>
                      {foreign && (
                        <TableCell className="text-right tabular-nums">
                          {line.vatInTaxCurrency === null ? "—" : taxMoney(line.vatInTaxCurrency)}
                        </TableCell>
                      )}
                      <TableCell className="pr-3 text-right tabular-nums">{money(line.net + line.vat)}</TableCell>
                    </TableRow>
                  ))}
                </TableBody>
                <TableFooter>
                  <TableRow>
                    <TableCell className="pl-3">{t("income.total")}</TableCell>
                    <TableCell className="text-right tabular-nums">{money(expense.netTotal)}</TableCell>
                    <TableCell className="text-right tabular-nums">{money(expense.vatTotal)}</TableCell>
                    {foreign && <TableCell />}
                    <TableCell className="pr-3 text-right tabular-nums">{money(expense.grossTotal)}</TableCell>
                  </TableRow>
                </TableFooter>
              </Table>
            </div>
          )}
        </section>

        <section className="flex flex-col gap-2">
          <h3 className="text-sm font-medium">{t("expenses.sheet.document")}</h3>
          {expense.hasDocument ? (
            <DocumentDownload expense={expense} />
          ) : (
            <p className="text-sm text-muted-foreground">
              {expense.source === "manual" ? t("expenses.sheet.noDocumentManual") : t("expenses.sheet.noDocument")}
            </p>
          )}
        </section>
      </div>
    </>
  )
}

/** Edit and Delete of an expense entered by hand (DELETE /expenses/{id} removes it for good). */
function ManualExpenseActions({
  expense,
  organizationId,
  onEdit,
  onDeleted,
}: {
  expense: Expense
  organizationId: string
  onEdit: (expense: Expense) => void
  onDeleted: () => void
}) {
  const queryClient = useQueryClient()
  const locale = useLocale()
  const { t } = useTranslation("admin")
  const [confirm, setConfirm] = useState(false)

  const remove = useMutation({
    mutationFn: () => unwrap(api.DELETE("/api/expenses/{id}", { params: { path: { id: expense.id } } })),
    onSuccess: async () => {
      setConfirm(false)
      onDeleted()
      toast.success(t("expenses.sheet.deleted"), { description: expense.sellerName })
      await queryClient.invalidateQueries({ queryKey: ["expenses", organizationId] })
    },
    onError: (error) => toast.error(t("expenses.sheet.deleteFailed"), { description: requestErrorMessage(error) }),
  })

  return (
    <div className="flex flex-wrap gap-2">
      <Button variant="outline" size="sm" onClick={() => onEdit(expense)}>
        <PencilIcon />
        {t("expenses.sheet.edit")}
      </Button>
      <Button variant="ghost" size="sm" className="text-destructive" disabled={remove.isPending} onClick={() => setConfirm(true)}>
        <Trash2Icon />
        {t("common.delete")}
      </Button>
      <AlertDialog open={confirm} onOpenChange={setConfirm}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>{t("expenses.sheet.deleteTitle", { seller: expense.sellerName })}</AlertDialogTitle>
            <AlertDialogDescription>
              {t("expenses.sheet.deleteDescription", { amount: formatMoney(expense.grossTotal, expense.currency, locale) })}
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>{t("common.cancel")}</AlertDialogCancel>
            <AlertDialogAction
              variant="destructive"
              disabled={remove.isPending}
              onClick={(event) => {
                event.preventDefault()
                remove.mutate()
              }}
            >
              {remove.isPending ? t("common.deleting") : t("common.delete")}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  )
}

/** Downloads the stored copy (GET /expenses/{id}/document): the API answers XML, not JSON. */
function DocumentDownload({ expense }: { expense: Expense }) {
  const { t } = useTranslation("admin")
  const [busy, setBusy] = useState(false)

  async function download() {
    setBusy(true)
    try {
      const blob = await unwrap(
        api.GET("/api/expenses/{id}/document", {
          params: { path: { id: expense.id } },
          headers: { Accept: "application/xml, application/octet-stream" },
          parseAs: "blob",
        }),
      )
      const url = URL.createObjectURL(blob)
      const link = document.createElement("a")
      link.href = url
      link.download = documentFileName(expense, blob.type)
      link.click()
      // Some browsers read the URL after click() returns.
      window.setTimeout(() => URL.revokeObjectURL(url), 10_000)
    } catch (error) {
      toast.error(t("expenses.sheet.downloadFailed"), { description: requestErrorMessage(error) })
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="flex flex-wrap items-center gap-3">
      <Button variant="outline" size="sm" disabled={busy} onClick={() => void download()}>
        <DownloadIcon />
        {busy ? t("expenses.sheet.downloading") : t("expenses.sheet.download")}
      </Button>
      {expense.documentFormat && <span className="text-sm text-muted-foreground">{expense.documentFormat}</span>}
    </div>
  )
}
