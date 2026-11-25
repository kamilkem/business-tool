import { useEffect } from "react"
import { useQuery } from "@tanstack/react-query"
import { toast } from "sonner"

import { requestErrorMessage } from "@business-tool/ui/lib/request-error"

import { ExpenseSheet } from "@/components/expense-sheet"
import { expenseQuery } from "@/lib/queries"

interface InvoiceSheetProps {
  organizationId: string
  /** The invoice to show, or null for none. */
  invoiceId: string | null
  onClose: () => void
}

/**
 * The purchase invoice a purchase is on, in the drawer of the Expenses list, opened from another
 * screen. It opens once the invoice has loaded. The invoices that have items are imported, so
 * there is nothing to edit here.
 */
export function InvoiceSheet({ organizationId, invoiceId, onClose }: InvoiceSheetProps) {
  const invoice = useQuery({ ...expenseQuery(organizationId, invoiceId ?? ""), enabled: invoiceId !== null })

  useEffect(() => {
    if (invoice.isError && invoiceId !== null) {
      toast.error(requestErrorMessage(invoice.error))
      onClose()
    }
  }, [invoice.isError, invoice.error, invoiceId, onClose])

  return (
    <ExpenseSheet
      expense={invoiceId !== null && invoice.data ? invoice.data : null}
      organizationId={organizationId}
      onEdit={() => undefined}
      onClose={onClose}
    />
  )
}
