import { useRef, useState } from "react"
import { unwrap } from "@business-tool/api-client"
import { useMutation, useQueryClient, useSuspenseQuery } from "@tanstack/react-query"
import { Link, createFileRoute, useNavigate } from "@tanstack/react-router"
import { ArrowLeftIcon, InfoIcon, LockIcon } from "lucide-react"
import { useTranslation } from "react-i18next"
import { toast } from "sonner"

import { Alert, AlertDescription } from "@business-tool/ui/components/alert"
import { Button } from "@business-tool/ui/components/button"
import { OrganizationCurrencyContext } from "@business-tool/ui/hooks/use-organization-currency"
import { useDateTimeFormat } from "@business-tool/ui/hooks/use-organization-time-zone"
import { requestErrorMessage } from "@business-tool/ui/lib/request-error"

import { DayCloseEditor } from "@/components/day-close-editor"
import { requireCapability } from "@/lib/access"
import { api } from "@/lib/api"
import { type DayCloseForm, LONG_DATE, recordDayCloseBody, recordEditor, valuesOfForm } from "@/lib/day-closes"
import { dayCloseFormQuery, invalidateDayCloses } from "@/lib/queries"

/*
 * A close recorded by an owner or manager in the admin (ADR 0033 §5, §6): every active storage and
 * non-cash method, as in the time clock, with any date up to today.
 */
export const Route = createFileRoute("/_app/cash-flow/closes/new")({
  beforeLoad: requireCapability("cashFlow"),
  loader: async ({ context }) => {
    await context.queryClient.ensureQueryData(dayCloseFormQuery(context.membership.organization.id))
  },
  component: RecordDayClosePage,
})

function RecordDayClosePage() {
  const { membership } = Route.useRouteContext()
  const { t } = useTranslation("admin")
  const organizationId = membership.organization.id
  // Always fresh: what is active, and the proposed date, change with every close.
  const { data: form } = useSuspenseQuery({ ...dayCloseFormQuery(organizationId), staleTime: 0 })

  return (
    <div className="flex flex-col gap-6">
      <div className="flex flex-col gap-3">
        <Button asChild variant="ghost" size="sm" className="-ml-2 w-fit">
          <Link to="/cash-flow/closes" search={{ from: undefined, to: undefined }}>
            <ArrowLeftIcon />
            {t("dayCloses.backToList")}
          </Link>
        </Button>
        <div>
          <h1 className="text-2xl font-semibold tracking-tight">{t("dayCloses.recordPage.title")}</h1>
          <p className="text-sm text-muted-foreground">{t("dayCloses.recordPage.description")}</p>
        </div>
      </div>
      {form.canClose ? (
        <OrganizationCurrencyContext value={form.currency}>
          <RecordDayClose organizationId={organizationId} form={form} />
        </OrganizationCurrencyContext>
      ) : (
        <Alert>
          <LockIcon />
          <AlertDescription>{t("dayCloses.recordPage.cannotClose")}</AlertDescription>
        </Alert>
      )}
    </div>
  )
}

function RecordDayClose({ organizationId, form }: { organizationId: string; form: DayCloseForm }) {
  const { t } = useTranslation("admin")
  const format = useDateTimeFormat()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const editor = recordEditor(form)
  const [values, setValues] = useState(() => valuesOfForm(form))
  const inFlight = useRef(false)
  const closed = form.proposedDateClosed

  const record = useMutation({
    mutationFn: () => unwrap(api.POST("/api/day-closes", { body: recordDayCloseBody(editor, values) })),
    onSuccess: async (receipt) => {
      await invalidateDayCloses(queryClient, organizationId)
      toast.success(t("dayCloses.recordPage.done", { date: format.businessDate(receipt.date, LONG_DATE) }))
      await navigate({ to: "/cash-flow/closes/$closeId", params: { closeId: receipt.id } })
    },
    onSettled: () => {
      inFlight.current = false
    },
  })

  return (
    <>
      {closed && (
        <Alert>
          <InfoIcon />
          <AlertDescription>
            <span>
              {t("dayCloses.recordPage.proposedClosed", {
                date: format.businessDate(form.proposedDate, LONG_DATE),
                name: `${closed.closedByFirstName} ${closed.closedByLastName}`,
                when: format.dateTime(closed.closedAt),
              })}{" "}
              <Link
                to="/cash-flow/closes/$closeId"
                params={{ closeId: closed.dayCloseId }}
                className="font-medium text-foreground underline underline-offset-4"
              >
                {t("dayCloses.recordPage.openClosed")}
              </Link>
            </span>
          </AlertDescription>
        </Alert>
      )}
      <DayCloseEditor
        editor={editor}
        currency={form.currency}
        values={values}
        onChange={(next) => {
          setValues(next)
          // The API's answer was about the values sent; it no longer applies once they change.
          if (record.isError) record.reset()
        }}
        today={form.today}
        pending={record.isPending}
        error={record.isError ? requestErrorMessage(record.error, { format }) : null}
        submitLabel={t("dayCloses.recordPage.save")}
        onSubmit={() => {
          if (inFlight.current) return
          inFlight.current = true
          record.mutate()
        }}
        onCancel={() => void navigate({ to: "/cash-flow/closes", search: { from: undefined, to: undefined } })}
      />
    </>
  )
}
