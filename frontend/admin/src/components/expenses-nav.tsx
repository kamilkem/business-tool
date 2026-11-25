import { useQuery } from "@tanstack/react-query"
import { Link, useRouteContext } from "@tanstack/react-router"
import { useTranslation } from "react-i18next"

import { reviewQueueCountQuery } from "@/lib/queries"

const linkClass =
  "inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-sm font-medium text-muted-foreground transition-colors hover:text-foreground data-[status=active]:bg-background data-[status=active]:text-foreground data-[status=active]:shadow-sm"

/** The most the badge writes out; more is "99+". */
const BADGE_MAX = 99

/**
 * The Expenses module's pages: the purchase invoices, the purchase analysis and the product category
 * tree. The Analiza tab carries the number of items waiting in the review queue (#246), when any.
 */
export function ExpensesNav() {
  const { t } = useTranslation("admin")
  const { membership } = useRouteContext({ from: "/_app" })
  // The badge is a hint: while it loads, or when it cannot, the tab shows without it.
  const { data: waiting } = useQuery(reviewQueueCountQuery(membership.organization.id))

  return (
    <nav aria-label={t("nav.expenses")} className="inline-flex w-fit gap-1 rounded-lg bg-muted p-1">
      <Link to="/expenses" activeOptions={{ exact: true, includeSearch: false }} className={linkClass}>
        {t("expenses.nav.invoices")}
      </Link>
      <Link to="/expenses/analysis" className={linkClass}>
        {t("expenses.nav.analysis")}
        {waiting !== undefined && waiting > 0 && (
          <>
            <span
              aria-hidden
              className="inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-primary px-1.5 text-xs font-semibold text-primary-foreground tabular-nums"
            >
              {waiting > BADGE_MAX ? `${BADGE_MAX}+` : waiting}
            </span>
            <span className="sr-only">{t("expenses.nav.waiting", { count: waiting })}</span>
          </>
        )}
      </Link>
      <Link to="/expenses/categories" className={linkClass}>
        {t("expenses.nav.categories")}
      </Link>
    </nav>
  )
}
