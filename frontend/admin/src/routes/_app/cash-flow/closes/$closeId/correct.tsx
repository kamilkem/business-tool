import { useRef, useState } from "react"
import { unwrap } from "@business-tool/api-client"
import { useMutation, useQueryClient, useSuspenseQuery } from "@tanstack/react-query"
import { Link, createFileRoute, useNavigate } from "@tanstack/react-router"
import { ArrowLeftIcon } from "lucide-react"
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
import { OrganizationCurrencyContext, useMoneyFormat } from "@business-tool/ui/hooks/use-organization-currency"
import { useDateTimeFormat } from "@business-tool/ui/hooks/use-organization-time-zone"
import { requestErrorMessage } from "@business-tool/ui/lib/request-error"

import { DayCloseEditor } from "@/components/day-close-editor"
import { requireCapability } from "@/lib/access"
import { api } from "@/lib/api"
import {
  type DayClose,
  type DayCloseForm,
  LONG_DATE,
  bookedCashOf,
  cashIncomeOf,
  correctDayCloseBody,
  correctionEditor,
  valuesOfClose,
} from "@/lib/day-closes"
import { dayCloseFormQuery, dayCloseQuery, invalidateDayCloses } from "@/lib/queries"

/*
 * Correcting an end-of-day close (ADR 0033 §7): its counts, deposits, non-cash amounts and register;
 * the date never changes. The revenue entry it booked and the next close of each storage follow.
 */
export const Route = createFileRoute("/_app/cash-flow/closes/$closeId/correct")({
  beforeLoad: requireCapability("cashFlow"),
  loader: async ({ context, params }) => {
    const organizationId = context.membership.organization.id
    await Promise.all([
      context.queryClient.ensureQueryData(dayCloseQuery(organizationId, params.closeId)),
      context.queryClient.ensureQueryData(dayCloseFormQuery(organizationId)),
    ])
  },
  component: CorrectDayClosePage,
})

function CorrectDayClosePage() {
  const { membership } = Route.useRouteContext()
  const { closeId } = Route.useParams()
  const organizationId = membership.organization.id
  const { data: close } = useSuspenseQuery(dayCloseQuery(organizationId, closeId))
  const { data: form } = useSuspenseQuery(dayCloseFormQuery(organizationId))

  return (
    <OrganizationCurrencyContext value={close.currency}>
      {/* A fresh editor whenever the close changes underneath, e.g. after a save elsewhere. */}
      <CorrectDayClose key={close.updatedAt ?? close.closedAt} organizationId={organizationId} close={close} form={form} />
    </OrganizationCurrencyContext>
  )
}

function CorrectDayClose({ organizationId, close, form }: { organizationId: string; close: DayClose; form: DayCloseForm }) {
  const { t } = useTranslation("admin")
  const format = useDateTimeFormat()
  const money = useMoneyFormat()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const editor = correctionEditor(close, form)
  const [values, setValues] = useState(() => valuesOfClose(close))
  const [confirming, setConfirming] = useState(false)
  const inFlight = useRef(false)
  const date = format.businessDate(close.date, LONG_DATE)
  const toDetails = () => navigate({ to: "/cash-flow/closes/$closeId", params: { closeId: close.id } })

  const correct = useMutation({
    mutationFn: () =>
      unwrap(api.PUT("/api/day-closes/{id}", { params: { path: { id: close.id } }, body: correctDayCloseBody(editor, values) })),
    onSuccess: async (corrected) => {
      queryClient.setQueryData(dayCloseQuery(organizationId, close.id).queryKey, corrected)
      void invalidateDayCloses(queryClient, organizationId)
      toast.success(t("dayCloses.correct.done", { date }))
      await toDetails()
    },
    onSettled: () => {
      inFlight.current = false
      setConfirming(false)
    },
  })

  const newIncome = cashIncomeOf(editor, values)

  return (
    <div className="flex flex-col gap-6">
      <div className="flex flex-col gap-3">
        <Button asChild variant="ghost" size="sm" className="-ml-2 w-fit">
          <Link to="/cash-flow/closes/$closeId" params={{ closeId: close.id }}>
            <ArrowLeftIcon />
            {t("dayCloses.backToClose")}
          </Link>
        </Button>
        <div>
          <h1 className="text-2xl font-semibold tracking-tight">{t("dayCloses.correct.title", { date })}</h1>
          <p className="text-sm text-muted-foreground">{t("dayCloses.correct.description")}</p>
        </div>
      </div>

      <DayCloseEditor
        editor={editor}
        currency={close.currency}
        values={values}
        onChange={(next) => {
          setValues(next)
          // The API's answer was about the values sent; it no longer applies once they change.
          if (correct.isError) correct.reset()
        }}
        today={form.today}
        pending={correct.isPending}
        error={correct.isError ? requestErrorMessage(correct.error, { format }) : null}
        submitLabel={t("dayCloses.correct.save")}
        onSubmit={() => setConfirming(true)}
        onCancel={() => void toDetails()}
      />

      <AlertDialog open={confirming} onOpenChange={(open) => !correct.isPending && setConfirming(open)}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>{t("dayCloses.correct.confirmTitle", { date })}</AlertDialogTitle>
            <AlertDialogDescription>
              {newIncome !== null &&
                `${t("dayCloses.correct.confirmIncome", {
                  from: money(close.cashIncome),
                  to: money(newIncome),
                  booked: money(bookedCashOf(newIncome)),
                })} `}
              {t("dayCloses.correct.confirmDescription")}
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel disabled={correct.isPending}>{t("common.cancel")}</AlertDialogCancel>
            <AlertDialogAction
              disabled={correct.isPending}
              onClick={(event) => {
                event.preventDefault()
                if (inFlight.current) return
                inFlight.current = true
                correct.mutate()
              }}
            >
              {t("dayCloses.correct.confirm")}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  )
}
