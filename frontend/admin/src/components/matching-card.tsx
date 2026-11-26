import { useEffect, useRef, useState } from "react"
import { unwrap } from "@business-tool/api-client"
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"
import { InfoIcon, LoaderCircleIcon, RefreshCwIcon, TriangleAlertIcon } from "lucide-react"
import { useTranslation } from "react-i18next"
import { toast } from "sonner"

import { Alert, AlertDescription, AlertTitle } from "@business-tool/ui/components/alert"
import { Button } from "@business-tool/ui/components/button"
import { Card, CardAction, CardContent, CardDescription, CardHeader, CardTitle } from "@business-tool/ui/components/card"
import { Skeleton } from "@business-tool/ui/components/skeleton"
import { useDateTimeFormat } from "@business-tool/ui/hooks/use-organization-time-zone"
import { requestErrorMessage } from "@business-tool/ui/lib/request-error"

import { api } from "@/lib/api"
import {
  MATCHING_POLL_MS,
  MATCHING_WATCH_MS,
  type MatchingRun,
  type MatchingStatus,
  type MatchingWatch,
  isBusy,
  matchAgainState,
  runKey,
} from "@/lib/matching"
import { invalidatePurchasing, matchingStatusQuery } from "@/lib/queries"

/**
 * Matching in the review queue (ADR 0046): how many items have no product, what the model did in its last run
 * (how many items it went through, assigned, suggested, found nothing for), and "Match again", which has the
 * model go through the items it found nothing for, now that the catalog may have grown. States the owner has to
 * know about get a notice: the model switched off on this server, and a last run that could not reach it.
 * The card never blocks the queue: while it loads or fails it shows that, and the rest of the page works.
 */
export function MatchingCard({ organizationId }: { organizationId: string }) {
  const { t } = useTranslation("admin")
  const queryClient = useQueryClient()
  const query = matchingStatusQuery(organizationId)
  // Set by a click: until a newer run shows up (or the time is up) the status is asked for every few seconds.
  const [watch, setWatch] = useState<MatchingWatch | null>(null)
  const status = useQuery({
    ...query,
    refetchInterval: (q) => (q.state.data && isBusy(q.state.data, watch) ? MATCHING_POLL_MS : false),
  })

  useEffect(() => {
    if (watch === null) return
    const timer = window.setTimeout(() => setWatch(null), MATCHING_WATCH_MS)
    return () => window.clearTimeout(timer)
  }, [watch])

  // A run started or ended (here, or by a new product or invoice): the queue and the proposals it changed are stale.
  const current = status.data ? runKey(status.data) : undefined
  const seen = useRef(current)
  useEffect(() => {
    if (current === undefined || seen.current === current) return
    const first = seen.current === undefined
    seen.current = current
    if (!first) void invalidatePurchasing(queryClient, organizationId)
  }, [current, organizationId, queryClient])

  const matchAgain = useMutation({
    mutationFn: () => unwrap(api.POST("/api/purchased-items/match")),
    onSuccess: (data, _, context) => {
      queryClient.setQueryData(query.queryKey, data)
      setWatch({ previousRunId: context?.previousRunId ?? null })
      toast.success(t("review.matching.toast.queued"), { description: t("review.matching.toast.queuedDescription") })
    },
    onError: (error) => toast.error(t("review.matching.toast.failed"), { description: requestErrorMessage(error) }),
    onMutate: () => ({ previousRunId: status.data?.lastRun?.id ?? null }),
  })

  // A refetch that fails keeps showing what was loaded; the error is for a status that never loaded.
  if (!status.data) {
    if (!status.isError) return <Skeleton className="h-28 w-full" />
    return (
      <Alert variant="destructive">
        <TriangleAlertIcon />
        <AlertTitle>{t("review.matching.error.title")}</AlertTitle>
        <AlertDescription className="flex flex-col items-start gap-3">
          <span>{requestErrorMessage(status.error)}</span>
          <Button variant="outline" size="sm" onClick={() => void status.refetch()}>
            {t("review.matching.error.retry")}
          </Button>
        </AlertDescription>
      </Alert>
    )
  }

  const data = status.data
  const state = matchAgainState(data, watch)
  const busy = state === "busy" || matchAgain.isPending

  return (
    <Card size="sm">
      <CardHeader>
        <CardTitle>{t("review.matching.title")}</CardTitle>
        <CardDescription>
          {data.waitingCount === 0 ? t("review.matching.nothingWaiting") : t("review.matching.waiting", { count: data.waitingCount })}
        </CardDescription>
        <CardAction>
          <Button variant="outline" size="sm" disabled={state === "model-off" || state === "nothing-waiting" || busy} onClick={() => matchAgain.mutate()}>
            {busy ? <LoaderCircleIcon className="animate-spin" /> : <RefreshCwIcon />}
            {busy ? t("review.matching.running") : t("review.matching.button")}
          </Button>
        </CardAction>
      </CardHeader>
      <CardContent className="flex flex-col gap-3">
        {!data.modelEnabled && (
          <Alert>
            <InfoIcon />
            <AlertTitle>{t("review.matching.off.title")}</AlertTitle>
            <AlertDescription>{t("review.matching.off.description")}</AlertDescription>
          </Alert>
        )}
        <LastRun status={data} />
      </CardContent>
    </Card>
  )
}

function LastRun({ status }: { status: MatchingStatus }) {
  const { t } = useTranslation("admin")
  const format = useDateTimeFormat()
  const run = status.lastRun
  if (run === null) return <p className="text-sm text-muted-foreground">{t("review.matching.neverRun")}</p>

  if (run.state === "running") {
    return <p className="text-sm text-muted-foreground">{t("review.matching.runningSince", { when: format.dateTime(run.startedAt) })}</p>
  }
  if (run.state === "interrupted") {
    return (
      <Alert variant="destructive">
        <TriangleAlertIcon />
        <AlertTitle>{t("review.matching.interrupted.title")}</AlertTitle>
        <AlertDescription>{t("review.matching.interrupted.description", { when: format.dateTime(run.startedAt) })}</AlertDescription>
      </Alert>
    )
  }
  if (run.state === "model_unavailable") {
    return (
      <>
        <Alert variant="destructive">
          <TriangleAlertIcon />
          <AlertTitle>{t("review.matching.unavailable.title")}</AlertTitle>
          <AlertDescription>
            {t("review.matching.unavailable.description", { when: format.dateTime(run.finishedAt ?? run.startedAt), count: run.interpreted })}
          </AlertDescription>
        </Alert>
        <RunCounts run={run} />
      </>
    )
  }
  return (
    <div className="flex flex-col gap-1 text-sm">
      <p className="text-muted-foreground">{t("review.matching.finished", { when: format.dateTime(run.finishedAt ?? run.startedAt) })}</p>
      <RunCounts run={run} />
    </div>
  )
}

/** What the model did in a run: how many items it went through and what came out. */
function RunCounts({ run }: { run: MatchingRun }) {
  const { t } = useTranslation("admin")
  if (run.interpreted === 0) return <p className="text-sm">{t("review.matching.counts.none")}</p>
  return (
    <ul className="grid grid-cols-2 gap-x-6 gap-y-1 text-sm sm:grid-cols-4" aria-label={t("review.matching.counts.label")}>
      <li>
        <span className="block text-xl font-semibold tabular-nums">{run.interpreted}</span>
        <span className="text-muted-foreground">{t("review.matching.counts.interpreted", { count: run.interpreted })}</span>
      </li>
      <li>
        <span className="block text-xl font-semibold tabular-nums">{run.assigned}</span>
        <span className="text-muted-foreground">{t("review.matching.counts.assigned", { count: run.assigned })}</span>
      </li>
      <li>
        <span className="block text-xl font-semibold tabular-nums">{run.suggested}</span>
        <span className="text-muted-foreground">{t("review.matching.counts.suggested", { count: run.suggested })}</span>
      </li>
      <li>
        <span className="block text-xl font-semibold tabular-nums">{run.foundNothing}</span>
        <span className="text-muted-foreground">{t("review.matching.counts.foundNothing", { count: run.foundNothing })}</span>
      </li>
    </ul>
  )
}
