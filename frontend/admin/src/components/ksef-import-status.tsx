import { useEffect, useRef, useState } from "react"
import { ApiError, unwrap } from "@business-tool/api-client"
import { useMutation, useQueryClient, useSuspenseQuery } from "@tanstack/react-query"
import { Link } from "@tanstack/react-router"
import { DownloadCloudIcon, KeyRoundIcon } from "lucide-react"
import { toast } from "sonner"

import { Alert, AlertAction, AlertDescription, AlertTitle } from "@business-tool/ui/components/alert"
import { Button } from "@business-tool/ui/components/button"
import { Card, CardAction, CardContent, CardDescription, CardHeader, CardTitle } from "@business-tool/ui/components/card"
import { useDateTimeFormat } from "@business-tool/ui/hooks/use-organization-time-zone"

import { type Membership, can } from "@/lib/access"
import { api } from "@/lib/api"
import { importAvailability, isImportPending } from "@/lib/expenses"
import { type KsefConnection, ksefErrorLabel, ksefState } from "@/lib/ksef"
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
      toast.success("Import started", { description: "New invoices show up here when it finishes." })
    },
    onError: async (error) => {
      toast.error(
        error instanceof ApiError && error.status === 429 ? "An import was requested less than 15 minutes ago" : "Could not start the import",
        { description: error instanceof ApiError && error.status === 429 ? "Try again later." : error.message },
      )
      await queryClient.invalidateQueries({ queryKey: query.queryKey })
    },
  })

  if (!connection || !availability) return null
  if (availability.kind === "rejected") return <RejectedNotice connection={connection} membership={membership} />

  return (
    <Card size="sm">
      <CardHeader>
        <CardTitle>KSeF import</CardTitle>
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
              {importNow.isPending ? "Starting…" : "Import now"}
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
  if (pending) return <>Importing invoices from KSeF…</>
  if (!connection.lastSyncAt) return <>Purchase invoices are imported from KSeF every night. Nothing was imported yet.</>
  const counts = `${connection.lastSyncImportedCount} imported${connection.lastSyncSkippedCount > 0 ? `, ${connection.lastSyncSkippedCount} skipped` : ""}`
  return (
    <>
      Imported every night. Last run {format.dateTime(connection.lastSyncAt)}: {counts}.
    </>
  )
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
  return (
    <dl className="grid grid-cols-[auto_1fr] gap-x-6 gap-y-1 text-sm">
      <dt className="text-muted-foreground">Last run</dt>
      <dd>{connection.lastSyncAt ? format.dateTime(connection.lastSyncAt) : "Not yet"}</dd>
      <dt className="text-muted-foreground">Last success</dt>
      <dd>{connection.lastSyncSucceededAt ? format.dateTime(connection.lastSyncSucceededAt) : "Not yet"}</dd>
      {connection.lastSyncError && (
        <>
          <dt className="text-muted-foreground">Last error</dt>
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
          <dt className="text-muted-foreground">Import now</dt>
          <dd>
            {availability.reason === "rate-limited" ? "KSeF asked us to slow down. " : "Requested recently. "}
            Available again at {format.time(availability.until)}.
          </dd>
        </>
      )}
      {availability.kind === "no-token" && (
        <>
          <dt className="text-muted-foreground">Import</dt>
          <dd>
            Stopped: no KSeF token is saved.{" "}
            {can(membership.role, "ksefToken") ? (
              <Link to="/settings" hash="ksef" className="underline underline-offset-4">
                Save one in Settings
              </Link>
            ) : (
              "An owner saves one in Settings."
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
  const isOwner = can(membership.role, "ksefToken")
  const state = ksefState(connection)

  return (
    <Alert variant="destructive">
      <KeyRoundIcon />
      <AlertTitle>KSeF import stopped: the token was rejected</AlertTitle>
      <AlertDescription>
        <p>
          {state.hint}
          {connection.lastSyncSucceededAt && ` Invoices up to ${format.dateTime(connection.lastSyncSucceededAt)} are here.`}
        </p>
        {!isOwner && <p>Ask an owner to save a new token in Settings.</p>}
      </AlertDescription>
      {isOwner && (
        <AlertAction>
          <Button size="sm" variant="outline" asChild>
            <Link to="/settings" hash="ksef">
              Open KSeF settings
            </Link>
          </Button>
        </AlertAction>
      )}
    </Alert>
  )
}
