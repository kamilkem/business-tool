import { Link } from "@tanstack/react-router"
import { useTranslation } from "react-i18next"

const linkClass =
  "rounded-md px-3 py-1.5 text-sm font-medium text-muted-foreground transition-colors hover:text-foreground data-[status=active]:bg-background data-[status=active]:text-foreground data-[status=active]:shadow-sm"

/** The Cash flow module's pages: the end-of-day closes, and the storages and denominations they count. */
export function CashFlowNav() {
  const { t } = useTranslation("admin")
  return (
    <nav aria-label={t("modules.cashFlow.title")} className="inline-flex w-fit max-w-full flex-wrap gap-1 rounded-lg bg-muted p-1">
      <Link to="/cash-flow/closes" search={{ from: undefined, to: undefined }} activeOptions={{ includeSearch: false }} className={linkClass}>
        {t("cashFlow.nav.closes")}
      </Link>
      <Link to="/cash-flow/storages" className={linkClass}>
        {t("cashFlow.nav.storages")}
      </Link>
      <Link to="/cash-flow/denominations" className={linkClass}>
        {t("cashFlow.nav.denominations")}
      </Link>
    </nav>
  )
}
