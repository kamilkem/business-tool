import { useQuery } from "@tanstack/react-query"
import { Link, useLocation, useRouteContext } from "@tanstack/react-router"
import { useTranslation } from "react-i18next"

import { cn } from "@business-tool/ui/lib/utils"

import { badgeText, isOverviewPath } from "@/lib/analysis-nav"
import { productProposalsQuery, reviewQueueCountQuery } from "@/lib/queries"

const linkClass =
  "inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-sm font-medium text-muted-foreground transition-colors hover:text-foreground data-[status=active]:bg-background data-[status=active]:text-foreground data-[status=active]:shadow-sm"

/** What the overview tab adds to `linkClass` on a product's page, which the router does not count as the overview. */
const activeClass = "bg-background text-foreground shadow-sm"

/**
 * The Analysis section's pages: the overview, the review queue and the proposed products (#269). The
 * last two carry the number of items waiting for review and of open proposals, when any, from queries
 * the pages share (#246, ADR 0044).
 */
export function AnalysisNav() {
  const { t } = useTranslation("admin")
  const { membership } = useRouteContext({ from: "/_app" })
  const organizationId = membership.organization.id
  const inOverview = useLocation({ select: (location) => isOverviewPath(location.pathname) })
  // The badges are hints: while they load, or when they cannot, the tabs show without them.
  const { data: waiting } = useQuery(reviewQueueCountQuery(organizationId))
  const { data: proposals } = useQuery({
    ...productProposalsQuery(organizationId),
    select: (list) => list.length,
  })

  return (
    <nav aria-label={t("nav.analysis")} className="inline-flex w-fit max-w-full flex-wrap gap-1 rounded-lg bg-muted p-1">
      <Link
        to="/analysis"
        activeOptions={{ exact: true, includeSearch: false }}
        className={cn(linkClass, inOverview && activeClass)}
      >
        {t("analysis.nav.overview")}
      </Link>
      <Link to="/analysis/review" className={linkClass}>
        {t("analysis.nav.review")}
        <TabBadge count={waiting} label={(count) => t("analysis.nav.waiting", { count })} />
      </Link>
      <Link to="/analysis/proposals" className={linkClass}>
        {t("analysis.nav.proposals")}
        <TabBadge count={proposals} label={(count) => t("analysis.nav.proposed", { count })} />
      </Link>
    </nav>
  )
}

/** The count on a tab; the visible number is hidden from screen readers, which get the sentence instead. */
function TabBadge({ count, label }: { count: number | undefined; label: (count: number) => string }) {
  const text = badgeText(count)
  if (text === null || count === undefined) return null
  return (
    <>
      <span
        aria-hidden
        className="inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-primary px-1.5 text-xs font-semibold text-primary-foreground tabular-nums"
      >
        {text}
      </span>
      <span className="sr-only">{label(count)}</span>
    </>
  )
}
