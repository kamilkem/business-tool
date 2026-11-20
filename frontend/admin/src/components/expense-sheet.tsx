import { useState } from "react"
import { unwrap } from "@business-tool/api-client"
import { DownloadIcon } from "lucide-react"
import { toast } from "sonner"

import { Badge } from "@business-tool/ui/components/badge"
import { Button } from "@business-tool/ui/components/button"
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from "@business-tool/ui/components/sheet"
import { Table, TableBody, TableCell, TableFooter, TableHead, TableHeader, TableRow } from "@business-tool/ui/components/table"
import { useLocale } from "@business-tool/ui/hooks/use-locale"
import { useDateTimeFormat } from "@business-tool/ui/hooks/use-organization-time-zone"
import { type MinorUnits, formatMoney } from "@business-tool/ui/lib/money"
import { cn } from "@business-tool/ui/lib/utils"

import { ExpenseCategorySelect } from "@/components/expense-category-select"
import { api } from "@/lib/api"
import type { ExpenseCategory } from "@/lib/expense-categories"
import { type Expense, documentFileName, isCorrection, kindLabel, sourceLabel, vatRateLabel } from "@/lib/expenses"

/**
 * One expense in a drawer: what the invoice says, its VAT lines, where it came from, its category,
 * and the original document (the local copy, never fetched from KSeF again).
 */
export function ExpenseSheet({
  expense,
  activeCategories,
  categorizing,
  onCategorize,
  onClose,
}: {
  expense: Expense | null
  activeCategories: readonly ExpenseCategory[]
  categorizing: boolean
  onCategorize: (expense: Expense, categoryId: string | null) => void
  onClose: () => void
}) {
  return (
    <Sheet open={expense !== null} onOpenChange={(open) => !open && onClose()}>
      <SheetContent className="w-full gap-0 overflow-y-auto sm:max-w-lg">
        {expense && (
          <ExpenseDetails
            expense={expense}
            activeCategories={activeCategories}
            categorizing={categorizing}
            onCategorize={onCategorize}
          />
        )}
      </SheetContent>
    </Sheet>
  )
}

function ExpenseDetails({
  expense,
  activeCategories,
  categorizing,
  onCategorize,
}: {
  expense: Expense
  activeCategories: readonly ExpenseCategory[]
  categorizing: boolean
  onCategorize: (expense: Expense, categoryId: string | null) => void
}) {
  const format = useDateTimeFormat()
  const locale = useLocale()
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
          {expense.invoiceNumber ?? "No invoice number"}, issued {format.businessDate(expense.issueDate)}
        </SheetDescription>
        <p className={cn("text-2xl font-semibold tabular-nums", expense.grossTotal < 0 && "text-destructive")}>
          {money(expense.grossTotal)}
        </p>
      </SheetHeader>

      <div className="flex flex-col gap-6 p-4">
        <section className="flex flex-col gap-2">
          <h3 className="text-sm font-medium">Category</h3>
          <ExpenseCategorySelect
            expense={expense}
            activeCategories={activeCategories}
            disabled={categorizing}
            onChange={(categoryId) => onCategorize(expense, categoryId)}
            className="w-full"
          />
        </section>

        <dl className="grid grid-cols-[auto_1fr] gap-x-6 gap-y-2 text-sm">
          <dt className="text-muted-foreground">Seller tax ID</dt>
          <dd className="tabular-nums">{expense.sellerTaxId ?? "—"}</dd>
          <dt className="text-muted-foreground">Issue date</dt>
          <dd>{format.businessDate(expense.issueDate)}</dd>
          <dt className="text-muted-foreground">Kind</dt>
          <dd>{kindLabel(expense.kind)}</dd>
          {expense.correctedExternalId && (
            <>
              <dt className="text-muted-foreground">Corrects</dt>
              <dd className="break-all">{expense.correctedExternalId}</dd>
            </>
          )}
          <dt className="text-muted-foreground">Currency</dt>
          <dd>
            {expense.currency}
            {foreign && <span className="text-muted-foreground"> (VAT settled in {expense.taxCurrency})</span>}
          </dd>
          <dt className="text-muted-foreground">Source</dt>
          <dd>{sourceLabel(expense.source)}</dd>
          {expense.externalId && (
            <>
              <dt className="text-muted-foreground">{expense.source === "ksef" ? "KSeF number" : "Source id"}</dt>
              <dd className="break-all tabular-nums">{expense.externalId}</dd>
            </>
          )}
          <dt className="text-muted-foreground">{expense.source === "manual" ? "Entered" : "Imported"}</dt>
          <dd>{format.dateTime(expense.createdAt)}</dd>
        </dl>

        <section className="flex flex-col gap-2">
          <h3 className="text-sm font-medium">VAT</h3>
          {expense.vatLines.length === 0 ? (
            <p className="text-sm text-muted-foreground">The invoice has no VAT breakdown.</p>
          ) : (
            <div className="rounded-lg border">
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead className="pl-3">Rate</TableHead>
                    <TableHead className="text-right">Net</TableHead>
                    <TableHead className="text-right">VAT</TableHead>
                    {foreign && <TableHead className="text-right">VAT in {expense.taxCurrency}</TableHead>}
                    <TableHead className="pr-3 text-right">Gross</TableHead>
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
                    <TableCell className="pl-3">Total</TableCell>
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
          <h3 className="text-sm font-medium">Original document</h3>
          {expense.hasDocument ? (
            <DocumentDownload expense={expense} />
          ) : (
            <p className="text-sm text-muted-foreground">
              {expense.source === "manual" ? "Entered by hand, without a document." : "No document is stored."}
            </p>
          )}
        </section>
      </div>
    </>
  )
}

/** Downloads the stored copy (GET /expenses/{id}/document): the API answers XML, not JSON. */
function DocumentDownload({ expense }: { expense: Expense }) {
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
      toast.error("Could not download the document", { description: error instanceof Error ? error.message : undefined })
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="flex flex-wrap items-center gap-3">
      <Button variant="outline" size="sm" disabled={busy} onClick={() => void download()}>
        <DownloadIcon />
        {busy ? "Downloading…" : "Download XML"}
      </Button>
      {expense.documentFormat && <span className="text-sm text-muted-foreground">{expense.documentFormat}</span>}
    </div>
  )
}
