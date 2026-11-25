import { useEffect, useRef, useState } from "react"
import { ApiError, unwrap } from "@business-tool/api-client"
import { useMutation, useQueryClient, useSuspenseQuery } from "@tanstack/react-query"
import { Link } from "@tanstack/react-router"
import { DownloadCloudIcon, KeyRoundIcon } from "lucide-react"
import { useTranslation } from "react-i18next"
import { toast } from "sonner"

import { Alert, AlertAction, AlertDescription, AlertTitle } from "@business-tool/ui/components/alert"
import { Button } from "@business-tool/ui/components/button"
import { Card, CardAction, CardContent, CardDescription, CardHeader, CardTitle } from "@business-tool/ui/components/card"
import { useDateTimeFormat } from "@business-tool/ui/hooks/use-organization-time-zone"
import { requestErrorMessage } from "@business-tool/ui/lib/request-error"

import { type Membership, can } from "@/lib/access"
import { api } from "@/lib/api"
import { importAvailability, isImportPending } from "@/lib/expenses"
import { type KsefConnection, ksefBacklogLabel, ksefErrorLabel, ksefImportMethodLabel, ksefState } from "@/lib/ksef"
import { ksefConnectionQuery } from "@/lib/queries"

/** How often the status is asked for while a requested import has not run yet. */
const PENDING_POLL_MS = 5_000

/**
 * The KSeF import of the Expenses page (ADR 0023 §5, ADR 0025): when it last ran and succeeded, why
 * it last failed, and "Import now", which the API accepts once every 15 minutes. A rejected token
 * gets a notice instead, pointing owners to the KSeF settings. Rendered for organizations in Poland
 * with a KSeF connection only.
 */
export function KsefImportStatus({ membership }: { membership: Membership }) {
  const organizationId = membership.organization.id
  const query = ksefConnectionQuery(organizationId)
  const queryClient = useQueryClient()
  const { t } = useTranslation("admin")
  const { data: connection } = useSuspenseQuery({
    ...query,
    refetchInterval: (q) => (isImportPending(q.state.data ?? null, new Date()) ? PENDING_POLL_MS : false),
  })
  // When "Import now" turns back on; kept in state because it depends on the time it re-renders at.
  const [waitUntil, setWaitUntil] = useState<number | null>(null)
  const now = useNow(waitUntil)
  const availability = connection ? importAvailability(connection, now) : null
  const until = availability?.kind === "wait" ? availability.until.getTime() : null
  if (until !== waitUntil) setWaitUntil(until)

  // A run finished (here or by the schedule): the lists may have new invoices.
  const lastSyncAt = connection?.lastSyncAt ?? null
  const seenSyncAt = useRef(lastSyncAt)
  useEffect(() => {
    if (seenSyncAt.current !== lastSyncAt) {
      seenSyncAt.current = lastSyncAt
      void queryClient.invalidateQueries({ queryKey: ["expenses", organizationId] })
    }
  }, [lastSyncAt, organizationId, queryClient])

  const importNow = useMutation({
    mutationFn: () => unwrap(api.POST("/api/ksef-connection/import")),
    onSuccess: (data) => {
      queryClient.setQueryData(query.queryKey, data)
      toast.success(t("ksef.import.started"), { description: t("ksef.import.startedDescription") })
    },
    onError: async (error) => {
      toast.error(
        error instanceof ApiError && error.status === 429 ? t("ksef.import.tooSoon") : t("ksef.import.failed"),
        { description: requestErrorMessage(error) },
      )
      await queryClient.invalidateQueries({ queryKey: query.queryKey })
    },
  })

  if (!connection || !availability) return null
  if (availability.kind === "rejected") return <RejectedNotice connection={connection} membership={membership} />

  return (
    <Card size="sm">
      <CardHeader>
        <CardTitle>{t("ksef.import.title")}</CardTitle>
        <CardDescription>
          <ImportSummary connection={connection} pending={isImportPending(connection, now)} />
        </CardDescription>
        {availability.kind !== "no-token" && (
          <CardAction>
            <Button
              variant="outline"
              size="sm"
              disabled={availability.kind !== "ready" || importNow.isPending}
              onClick={() => importNow.mutate()}
            >
              <DownloadCloudIcon />
              {importNow.isPending ? t("ksef.import.starting") : t("ksef.import.now")}
            </Button>
          </CardAction>
        )}
      </CardHeader>
      <CardContent>
        <ImportDetails connection={connection} availability={availability} membership={membership} />
      </CardContent>
    </Card>
  )
}

/** How often the time the status is judged against moves on, besides the moment `at` (epoch ms). */
const CLOCK_TICK_MS = 30_000

/**
 * The current time, refreshed every half minute and at `at`, so a button disabled until then turns
 * back on by itself and a pending import stops being shown as pending.
 */
function useNow(at: number | null): Date {
  const [now, setNow] = useState(() => new Date())
  useEffect(() => {
    const interval = window.setInterval(() => setNow(new Date()), CLOCK_TICK_MS)
    return () => window.clearInterval(interval)
  }, [])
  useEffect(() => {
    if (at === null) return
    const timer = window.setTimeout(() => setNow(new Date()), Math.max(0, at - Date.now()) + 500)
    return () => window.clearTimeout(timer)
  }, [at])
  return now
}

function ImportSummary({ connection, pending }: { connection: KsefConnection; pending: boolean }) {
  const format = useDateTimeFormat()
  const { t } = useTranslation("admin")
  if (pending) return <>{t("ksef.import.importing")}</>
  if (!connection.lastSyncAt) return <>{t("ksef.import.never")}</>
  const imported = t("ksef.import.imported", { count: connection.lastSyncImportedCount })
  const counts =
    connection.lastSyncSkippedCount > 0
      ? `${imported}, ${t("ksef.import.skipped", { count: connection.lastSyncSkippedCount })}`
      : imported
  return <>{t("ksef.import.lastRun", { when: format.dateTime(connection.lastSyncAt), counts })}</>
}

function ImportDetails({
  connection,
  availability,
  membership,
}: {
  connection: KsefConnection
  availability: ReturnType<typeof importAvailability>
  membership: Membership
}) {
  const format = useDateTimeFormat()
  const { t } = useTranslation("admin")
  const backlog = ksefBacklogLabel(connection)
  const method = ksefImportMethodLabel(connection.lastImportMethod)
  return (
    <dl className="grid grid-cols-[auto_1fr] gap-x-6 gap-y-1 text-sm">
      <dt className="text-muted-foreground">{t("ksef.import.lastRunLabel")}</dt>
      <dd>{connection.lastSyncAt ? format.dateTime(connection.lastSyncAt) : t("ksef.card.notYet")}</dd>
      <dt className="text-muted-foreground">{t("ksef.import.lastSuccess")}</dt>
      <dd>{connection.lastSyncSucceededAt ? format.dateTime(connection.lastSyncSucceededAt) : t("ksef.card.notYet")}</dd>
      {method && (
        <>
          <dt className="text-muted-foreground">{t("ksef.import.methodLabel")}</dt>
          <dd>{method}</dd>
        </>
      )}
      {backlog && (
        <>
          <dt className="text-muted-foreground">{t("ksef.import.leftLabel")}</dt>
          <dd>{backlog}</dd>
        </>
      )}
      {connection.importEstimatedAt && (
        <>
          <dt className="text-muted-foreground">{t("ksef.import.estimateLabel")}</dt>
          <dd>{t("ksef.import.estimate", { when: format.dateTime(connection.importEstimatedAt) })}</dd>
        </>
      )}
      {connection.lastSyncError && (
        <>
          <dt className="text-muted-foreground">{t("ksef.import.lastError")}</dt>
          <dd className={connection.lastSyncError === "invoice_unreadable" ? "text-amber-600 dark:text-amber-500" : "text-destructive"}>
            {ksefErrorLabel(connection.lastSyncError)}
            {connection.lastSyncErrorMessage && (
              <span className="block break-all text-muted-foreground">{connection.lastSyncErrorMessage}</span>
            )}
          </dd>
        </>
      )}
      {availability.kind === "wait" && (
        <>
          <dt className="text-muted-foreground">{t("ksef.import.now")}</dt>
          <dd>
            {t(availability.reason === "rate-limited" ? "ksef.import.waitRateLimited" : "ksef.import.waitRecent", {
              time: format.time(availability.until),
            })}
          </dd>
        </>
      )}
      {availability.kind === "no-token" && (
        <>
          <dt className="text-muted-foreground">{t("ksef.import.import")}</dt>
          <dd>
            {t("ksef.import.noToken")}{" "}
            {can(membership.role, "ksefToken") ? (
              <Link to="/settings" hash="ksef" className="underline underline-offset-4">
                {t("ksef.import.saveInSettings")}
              </Link>
            ) : (
              t("ksef.import.ownerSaves")
            )}
          </dd>
        </>
      )}
    </dl>
  )
}

/** KSeF refused the token, or it no longer decrypts (`credentials_rejected`): nothing is imported until an owner saves a new one. */
function RejectedNotice({ connection, membership }: { connection: KsefConnection; membership: Membership }) {
  const format = useDateTimeFormat()
  const { t } = useTranslation("admin")
  const isOwner = can(membership.role, "ksefToken")
  const state = ksefState(connection)

  return (
    <Alert variant="destructive">
      <KeyRoundIcon />
      <AlertTitle>{t("ksef.rejected.title")}</AlertTitle>
      <AlertDescription>
        <p>
          {state.hint}
          {connection.lastSyncSucceededAt && ` ${t("ksef.rejected.upTo", { when: format.dateTime(connection.lastSyncSucceededAt) })}`}
        </p>
        {!isOwner && <p>{t("ksef.rejected.askOwner")}</p>}
      </AlertDescription>
      {isOwner && (
        <AlertAction>
          <Button size="sm" variant="outline" asChild>
            <Link to="/settings" hash="ksef">
              {t("ksef.rejected.openSettings")}
            </Link>
          </Button>
        </AlertAction>
      )}
    </Alert>
  )
}
